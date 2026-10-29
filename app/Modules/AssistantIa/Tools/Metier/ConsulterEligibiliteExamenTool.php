<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Services\ServiceEligibiliteExamen;

/**
 * Outil métier : Consultation de l'éligibilité officielle d'un étudiant à une épreuve.
 * Réutilise rigoureusement le ServiceEligibiliteExamen central.
 * Applique une isolation BOLA (périmètre de faculté) et IDOR (l'étudiant ne peut consulter que son propre compte).
 */
class ConsulterEligibiliteExamenTool implements OutilIaInterface
{
    public function __construct(
        protected ?ServiceEligibiliteExamen $serviceEligibilite = null
    ) {
        $this->serviceEligibilite = $serviceEligibilite ?? app(ServiceEligibiliteExamen::class);
    }

    public function nom(): string
    {
        return 'consulter_eligibilite_examen';
    }

    public function description(): string
    {
        return 'Vérifie et explique l\'éligibilité réglementaire d\'un étudiant à une épreuve d\'examen donnée selon le service officiel UniRegistre.';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'epreuve_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant numérique de l\'épreuve d\'examen concernée',
                ],
                'etudiant_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant numérique de l\'étudiant',
                ],
            ],
            'required' => ['epreuve_id', 'etudiant_id'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $epreuveId = (int) ($parametres['epreuve_id'] ?? 0);
        $etudiantId = (int) ($parametres['etudiant_id'] ?? 0);

        if ($epreuveId <= 0 || $etudiantId <= 0) {
            return ResultatOutil::echec("Les identifiants d'épreuve et d'étudiant sont obligatoires et doivent être des entiers strictement positifs.");
        }

        $etudiant = Etudiant::find($etudiantId);
        if (! $etudiant) {
            return ResultatOutil::echec("Étudiant #{$etudiantId} introuvable.");
        }

        $epreuve = Epreuve::with(['matiere', 'session', 'examen'])->find($epreuveId);
        if (! $epreuve) {
            return ResultatOutil::echec("Épreuve #{$epreuveId} introuvable.");
        }

        // 1. Contrôle IDOR : Un profil étudiant ne peut consulter QUE son propre dossier
        if ($utilisateur->role === 'etudiant') {
            $estMemeCompte = ($utilisateur->email && $utilisateur->email === $etudiant->email)
                || ((int) $utilisateur->id === (int) ($etudiant->user_id ?? 0))
                || ((int) session('etudiant_auth_id', 0) === (int) $etudiant->id);

            if (! $estMemeCompte) {
                throw new SecuriteIaException("Accès refusé : vous n'êtes pas autorisé à consulter les données d'éligibilité d'un autre étudiant.");
            }
        } elseif (! $utilisateur->hasPermission('assistant_ia.mode_metier') && ! $utilisateur->estSuperAdmin()) {
            throw new SecuriteIaException("Vous ne disposez pas de l'habilitation requise pour vérifier l'éligibilité aux épreuves.");
        }

        // 2. Contrôle BOLA : Périmètre facultaire de l'agent
        if ($utilisateur->faculteRestreinteId() !== null) {
            FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $etudiant->faculte_id);

            $faculteEpreuve = $epreuve->examen?->session?->faculte_id ?? $epreuve->examen?->faculte_id;
            if ($faculteEpreuve !== null) {
                FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $faculteEpreuve);
            }
        }

        // 3. Appel du service métier officiel UniRegistre (lecture seule, 0 calcul autonome par le LLM)
        $decision = $this->serviceEligibilite->evaluer($epreuve, $etudiant);

        // 4. Structuration minimale de la réponse (0 PII, 0 CIN)
        $donnees = [
            'decision' => $decision->decision,
            'est_eligible' => $decision->decision === 'eligible',
            'source_decision' => $decision->source,
            'regle_appliquee' => $decision->regle,
            'motif' => $decision->motif,
            'donnees_completes' => $decision->donneesCompletes,
            'matiere_code' => $epreuve->matiere?->code ?? 'N/A',
            'matiere_nom' => $epreuve->matiere?->nom ?? 'N/A',
            'epreuve_titre' => $epreuve->titre ?? 'Épreuve #'.$epreuve->id,
            'session_type' => $epreuve->session?->type ?? 'N/A',
        ];

        $resume = "Éligibilité à l'épreuve #{$epreuveId} : statut '{$decision->decision}' (Règle : {$decision->regle}) — {$decision->motif}";

        $sources = [
            [
                'titre' => 'Service Central d’Éligibilité aux Examens',
                'url_ou_chemin' => 'app/Modules/Evaluation/Services/ServiceEligibiliteExamen.php',
                'source' => 'business_procedure',
            ],
            [
                'titre' => 'Guide Officiel des Délibérations et Examens UniRegistre',
                'url_ou_chemin' => 'docs/regles-metier/deliberations-examens.md',
                'source' => 'business_procedure',
            ],
        ];

        return ResultatOutil::succes($donnees, $resume, $sources);
    }
}
