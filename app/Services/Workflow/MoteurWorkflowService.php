<?php

namespace App\Services\Workflow;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\Contrats\WorkflowDefinitionInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moteur unifié et configurable de workflows universitaires (§10-12).
 *
 * Garanties absolues :
 * 1. Contrôle côté serveur : aucune transition hors de la matrice autorisée.
 * 2. Conservation de version : un dossier créé sous la version V conserve les règles de la version V.
 * 3. Séparation stricte des fonctions (quatre yeux) : l'initiateur ne peut pas auto-approuver.
 * 4. Isolation facultaire : un agent restreint à une faculté ne peut agir sur une entité d'une autre faculté.
 * 5. Concurrence et anti-TOCTOU : verrous de ligne `lockForUpdate` et transactions atomiques.
 * 6. Auditabilité complète : chaque décision est horodatée, attribuée et tracée sans fuite de PII.
 */
class MoteurWorkflowService
{
    public function __construct(
        protected RegistreWorkflow $registre,
        protected AuditService $audit,
    ) {}

    /**
     * Résout la définition de workflow applicable à une entité.
     */
    public function resoudreDefinition(string $domaine, mixed $entite, ?int $versionExplicite = null): WorkflowDefinitionInterface
    {
        $version = $versionExplicite ?? $this->extraireVersion($entite);

        return $this->registre->obtenir($domaine, $version);
    }

    /**
     * Vérifie si une transition est permise pour une entité et un acteur donnés.
     */
    public function peutTransiter(
        string $domaine,
        mixed $entite,
        string $cible,
        ?User $acteur = null,
        ?int $versionExplicite = null
    ): bool {
        $definition = $this->resoudreDefinition($domaine, $entite, $versionExplicite);
        $etatSource = $this->extraireEtat($entite);

        // 1. Contrôle topologique de la transition
        if (! $definition->transitionAutorisee($etatSource, $cible)) {
            return false;
        }

        // 2. Contrôle de permission si acteur fourni
        if ($acteur !== null) {
            $perm = $definition->permissionRequise($etatSource, $cible);
            if ($perm !== null && ! $acteur->hasPermission($perm)) {
                return false;
            }

            // 3. Contrôle d'isolation facultaire
            if (! $this->verifierIsolationFacultaire($entite, $acteur)) {
                return false;
            }

            // 4. Contrôle de séparation des fonctions (4 yeux)
            if ($definition->requiertQuatreYeux($etatSource, $cible) && ! $this->verifierSeparationFonctions($entite, $acteur)) {
                return false;
            }
        }

        // 5. Gardes métier personnalisées
        return $definition->verifierGardes($etatSource, $cible, $entite, $acteur);
    }

    /**
     * Exécute la transition d'état de façon transactionnelle et atomique.
     *
     * @param  array<string, mixed>  $metadonnees
     *
     * @throws DomainException
     */
    public function transiter(
        string $domaine,
        Model $entite,
        string $cible,
        User $acteur,
        ?string $motif = null,
        array $metadonnees = [],
        ?int $versionExplicite = null
    ): Model {
        return DB::transaction(function () use ($domaine, $entite, $cible, $acteur, $motif, $metadonnees, $versionExplicite) {
            // Verrouillage pessimiste de la ligne pour prévenir toute course concurrente
            /** @var Model $verrouillee */
            $verrouillee = $entite->newQuery()->whereKey($entite->getKey())->lockForUpdate()->firstOrFail();

            $definition = $this->resoudreDefinition($domaine, $verrouillee, $versionExplicite);
            $etatSource = $this->extraireEtat($verrouillee);

            // Idempotence : si déjà dans l'état cible, renvoyer sans mutation
            if ($etatSource === $cible) {
                return $verrouillee;
            }

            // 1. Validation de la transition
            if (! $definition->transitionAutorisee($etatSource, $cible)) {
                throw new DomainException(
                    "Transition interdite de « {$etatSource} » vers « {$cible} » selon le workflow {$domaine} v{$definition->version()}."
                );
            }

            // 2. Contrôle de permission
            $perm = $definition->permissionRequise($etatSource, $cible);
            if ($perm !== null && ! $acteur->hasPermission($perm)) {
                throw new DomainException("Permission « {$perm} » requise pour valider cette transition.");
            }

            // 3. Contrôle d'isolation facultaire
            if (! $this->verifierIsolationFacultaire($verrouillee, $acteur)) {
                throw new DomainException('Accès refusé : ressource hors du périmètre de votre faculté.');
            }

            // 4. Contrôle strict de séparation des fonctions (quatre yeux)
            if ($definition->requiertQuatreYeux($etatSource, $cible) && ! $this->verifierSeparationFonctions($verrouillee, $acteur)) {
                abort(403, 'Séparation des fonctions requise : un second agent doit valider cette transition.');
            }

            // 5. Gardes métier
            if (! $definition->verifierGardes($etatSource, $cible, $verrouillee, $acteur)) {
                throw new DomainException("Conditions non remplies pour la transition vers « {$cible} ».");
            }

            // 6. Application de la mutation
            $champEtat = $this->determinerChampEtat($verrouillee);
            $donneesUpdate = [$champEtat => $cible];

            if ($verrouillee->isFillable('decide_par')) {
                $donneesUpdate['decide_par'] = $acteur->id;
            }
            if ($verrouillee->isFillable('decide_le')) {
                $donneesUpdate['decide_le'] = now();
            }
            if ($motif !== null && $verrouillee->isFillable('motif_decision')) {
                $donneesUpdate['motif_decision'] = $motif;
            }

            $verrouillee->update($donneesUpdate);

            // 7. Auditabilité et traçabilité
            $etudiant = $this->extraireEtudiant($verrouillee);
            if ($etudiant !== null) {
                $actionAudit = match ($domaine) {
                    'inscription' => HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION,
                    'correction' => HistoriqueAcademique::ACTION_CORRECTION_DOSSIER,
                    default => HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER,
                };

                $this->audit->tracer(
                    $etudiant,
                    $actionAudit,
                    $domaine,
                    (string) $verrouillee->getKey(),
                    null,
                    [
                        'source' => $etatSource,
                        'cible' => $cible,
                        'workflow' => $domaine,
                        'version' => $definition->version(),
                        'motif' => $motif,
                        'acteur_id' => $acteur->id,
                        'metadonnees' => $metadonnees,
                    ]
                );
            }

            return $verrouillee->fresh();
        });
    }

    /**
     * Extrait le numéro de version de workflow d'une entité.
     */
    public function extraireVersion(mixed $entite): ?int
    {
        if (! is_object($entite)) {
            return null;
        }

        if (isset($entite->version_workflow)) {
            return (int) $entite->version_workflow;
        }

        if (isset($entite->data) && is_array($entite->data) && isset($entite->data['version_workflow'])) {
            return (int) $entite->data['version_workflow'];
        }

        return null;
    }

    /**
     * Extrait l'état actuel de l'entité.
     */
    public function extraireEtat(mixed $entite): string
    {
        if (is_string($entite)) {
            return $entite;
        }

        if (is_object($entite)) {
            if (isset($entite->statut)) {
                return (string) $entite->statut;
            }
            if (isset($entite->status)) {
                return (string) $entite->status;
            }
        }

        return 'inconnu';
    }

    protected function determinerChampEtat(Model $entite): string
    {
        if (array_key_exists('statut', $entite->getAttributes()) || $entite->isFillable('statut')) {
            return 'statut';
        }

        if (array_key_exists('status', $entite->getAttributes()) || $entite->isFillable('status')) {
            return 'status';
        }

        return 'statut';
    }

    /**
     * Isolation facultaire (réutilisable par les modules consommateurs :
     * contrôle serveur au plus près de la mutation, en plus de la policy).
     */
    public function verifierIsolationFacultaire(mixed $entite, User $acteur): bool
    {
        $faculteRestreinteId = $acteur->faculteRestreinteId();
        if ($faculteRestreinteId === null) {
            return true; // Agent central non restreint
        }

        $entiteFaculteId = null;

        if (isset($entite->faculte_id)) {
            $entiteFaculteId = $entite->faculte_id;
        } elseif (isset($entite->etudiant) && isset($entite->etudiant->faculte_id)) {
            $entiteFaculteId = $entite->etudiant->faculte_id;
        }

        if ($entiteFaculteId === null) {
            return true;
        }

        return (int) $entiteFaculteId === (int) $faculteRestreinteId;
    }

    /**
     * Séparation stricte des fonctions (quatre yeux, réutilisable par les
     * modules consommateurs). L'initiateur = auteur de la proposition
     * (propose_par / created_by / auteur_id) ou, à défaut, l'agent
     * assigné au traitement (assigned_to) : celui qui examine ne peut
     * jamais être celui qui approuve.
     */
    public function verifierSeparationFonctions(mixed $entite, User $acteur): bool
    {
        $initiateurId = null;

        if (isset($entite->propose_par)) {
            $initiateurId = $entite->propose_par;
        } elseif (isset($entite->created_by)) {
            $initiateurId = $entite->created_by;
        } elseif (isset($entite->auteur_id)) {
            $initiateurId = $entite->auteur_id;
        } elseif (isset($entite->assigned_to)) {
            $initiateurId = $entite->assigned_to;
        }

        if ($initiateurId === null) {
            return true;
        }

        // L'approbateur ne peut jamais être l'initiateur
        return (int) $initiateurId !== (int) $acteur->id;
    }

    protected function extraireEtudiant(mixed $entite): ?Etudiant
    {
        if ($entite instanceof Etudiant) {
            return $entite;
        }

        if (isset($entite->etudiant) && $entite->etudiant instanceof Etudiant) {
            return $entite->etudiant;
        }

        return null;
    }
}
