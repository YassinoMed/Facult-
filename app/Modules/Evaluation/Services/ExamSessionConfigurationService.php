<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service Enterprise de Gouvernance des Sessions et Configurations d'Examen (Menus 2 & 3).
 *
 * Applique :
 *  - La gouvernance des cycles de sessions (principale, rattrapage avec session d'origine obligatoire)
 *  - La cohérence temporelle et l'intégrité de verrouillage
 *  - La politique Mode A (pédagogique) vs Mode B (groupes temporaires par éligibilité)
 *  - La Règle R-05 & Décision D-02 : Limite stricte de 1 à 3 modules par configuration d'examen
 *  - Le contrôle des modalités d'épreuves (distinctes vs combinées)
 *  - L'isolation facultaire stricte
 */
class ExamSessionConfigurationService
{
    /** Limite réglementaire maximale de modules/ECUE par configuration d'examen (R-05 & D-02) */
    public const MAX_MODULES_PAR_CONFIGURATION = 3;

    public function __construct(
        private SessionExamenService $sessionExamenService,
    ) {}

    /**
     * Audite exhaustivement la conformité réglementaire et la readiness d'une session.
     *
     * @return array<string, mixed>
     */
    public function auditerSession(SessionExamen $session): array
    {
        $session->loadMissing(['anneeUniversitaire', 'origine', 'examens', 'epreuves']);

        $bloquants = [];
        $avertissements = [];

        // 1. Contrôle des dates de session
        if (! $session->date_debut || ! $session->date_fin) {
            $bloquants[] = 'Les dates de début et de fin de session sont obligatoires.';
        } elseif ($session->date_debut > $session->date_fin) {
            $bloquants[] = "La date de début ({$session->date_debut->format('d/m/Y')}) ne peut être postérieure à la date de fin ({$session->date_fin->format('d/m/Y')}).";
        } elseif ($session->anneeUniversitaire) {
            $annee = $session->anneeUniversitaire;
            if ($annee->date_debut && $session->date_debut < $annee->date_debut) {
                $bloquants[] = "La date de début est antérieure à l'année universitaire ({$annee->date_debut->format('d/m/Y')}).";
            }
            if ($annee->date_fin && $session->date_fin > $annee->date_fin) {
                $bloquants[] = "La date de fin est postérieure à l'année universitaire ({$annee->date_fin->format('d/m/Y')}).";
            }
        }

        // 2. Contrôle du rattachement des rattrapages
        if ($session->type === 'rattrapage') {
            if ($session->session_origine_id === null) {
                $bloquants[] = "Une session de rattrapage doit obligatoirement être rattachée à une session principale d'origine.";
            } elseif ($session->origine === null) {
                $bloquants[] = "La session principale d'origine sélectionnée (#{$session->session_origine_id}) n'existe pas.";
            } else {
                if ($session->origine->annee_universitaire_id !== $session->annee_universitaire_id) {
                    $bloquants[] = "La session d'origine ({$session->origine->nom}) appartient à une année universitaire différente.";
                }
                if ($session->origine->type !== 'principale') {
                    $avertissements[] = "La session d'origine sélectionnée n'est pas de type principale ({$session->origine->type}).";
                }
            }
        } elseif ($session->session_origine_id !== null) {
            $avertissements[] = "Une session de type '{$session->type}' ne devrait pas avoir de session d'origine associée.";
        }

        // 3. Readiness opérationnelle (salles, surveillants, candidats, capacités)
        $readiness = $this->sessionExamenService->readiness($session);
        foreach ($readiness['bloquants'] as $bloquant) {
            $bloquants[] = $bloquant;
        }

        // 4. Audit des examens rattachés (Règle 1 à 3 modules)
        $nbExamensHorsLimite = 0;
        foreach ($session->examens as $examen) {
            $nbEpreuves = $examen->epreuves()->count();
            if ($nbEpreuves > self::MAX_MODULES_PAR_CONFIGURATION) {
                $nbExamensHorsLimite++;
            }
        }

        if ($nbExamensHorsLimite > 0) {
            $bloquants[] = "{$nbExamensHorsLimite} examen(s) enfreignent la règle R-05 (maximum 3 modules autorisés par configuration).";
        }

        $estConforme = empty($bloquants);

        return [
            'session_id' => $session->id,
            'code' => $session->code,
            'nom' => $session->nom,
            'type' => $session->type,
            'statut' => $session->statut,
            'verrouillee' => $session->estVerrouillee(),
            'mode_organisation' => $session->modeOrganisation(),
            'mode_organisation_label' => $session->modeOrganisationLabel(),
            'conforme' => $estConforme,
            'bloquants' => $bloquants,
            'avertissements' => $avertissements,
            'readiness' => $readiness,
            'examens_count' => $session->examens->count(),
            'epreuves_count' => $session->epreuves->count(),
        ];
    }

    /**
     * Valide les données lors de la création ou modification d'une session.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws DomainException
     */
    public function validerDonneesSession(array $donnees, ?int $sessionIgnoreeId = null): void
    {
        if (empty($donnees['code'])) {
            throw new DomainException('Le code de session est obligatoire.');
        }

        if (empty($donnees['nom'])) {
            throw new DomainException('Le nom de session est obligatoire.');
        }

        if (empty($donnees['date_debut']) || empty($donnees['date_fin'])) {
            throw new DomainException('Les dates de début et de fin sont obligatoires.');
        }

        if ($donnees['date_debut'] > $donnees['date_fin']) {
            throw new DomainException("La date de début ({$donnees['date_debut']}) doit précéder la date de fin ({$donnees['date_fin']}).");
        }

        if (! in_array($donnees['type'] ?? '', SessionExamen::TYPES, true)) {
            throw new DomainException("Type de session invalide : '".($donnees['type'] ?? '')."'.");
        }

        // Vérification du rattrapage
        if ($donnees['type'] === 'rattrapage') {
            if (empty($donnees['session_origine_id'])) {
                throw new DomainException("Une session de rattrapage exige le rattachement à une session principale d'origine.");
            }

            /** @var SessionExamen|null $origine */
            $origine = SessionExamen::find($donnees['session_origine_id']);
            if (! $origine) {
                throw new DomainException("La session principale d'origine indiquée est introuvable.");
            }

            if (! empty($donnees['annee_universitaire_id']) && (int) $origine->annee_universitaire_id !== (int) $donnees['annee_universitaire_id']) {
                throw new DomainException("La session d'origine doit appartenir à la même année universitaire.");
            }
        }

        // Unicité du code dans l'année universitaire
        if (! empty($donnees['annee_universitaire_id'])) {
            $doublon = SessionExamen::where('annee_universitaire_id', $donnees['annee_universitaire_id'])
                ->where('code', $donnees['code'])
                ->when($sessionIgnoreeId !== null, fn ($q) => $q->where('id', '!=', $sessionIgnoreeId))
                ->exists();

            if ($doublon) {
                throw new DomainException("Le code session '{$donnees['code']}' est déjà utilisé pour cette année universitaire.");
            }
        }
    }

    /**
     * Audite exhaustivement les configurations d'examen (Menu 3).
     *
     * @return array<string, mixed>
     */
    public function auditerConfigurations(?int $sessionId = null, ?int $faculteId = null, ?int $anneeId = null): array
    {
        $examensQuery = Examen::query()
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->with(['session', 'programme.faculte', 'niveau', 'groupe', 'epreuves.matiere'])
            ->withCount('epreuves')
            ->orderByDesc('id');

        /** @var Collection<int, Examen> $examens */
        $examens = $examensQuery->get();

        $listeAuditee = [];
        $totalConformes = 0;
        $totalAnomalies = 0;
        $totalDistinctes = 0;
        $totalCombinees = 0;

        foreach ($examens as $examen) {
            $bloquants = [];
            $avertissements = [];
            $nbModules = (int) $examen->epreuves_count;

            // Règle R-05 & Décision D-02 : Limite 1 à 3 modules
            if ($nbModules > self::MAX_MODULES_PAR_CONFIGURATION) {
                $bloquants[] = "Dépassement de la règle R-05 : {$nbModules} modules rattachés (maximum 3 autorisés par configuration).";
            } elseif ($nbModules === 0) {
                $avertissements[] = 'Aucun module ou épreuve rattachée à cet examen.';
            }

            // Modalité d'épreuve : distincte par défaut, combinée si précisé
            $modalite = $examen->observations && str_contains(strtolower($examen->observations), 'combinée')
                ? 'combinee'
                : 'distincte';

            if ($modalite === 'combinee') {
                $totalCombinees++;
                if ($nbModules < 2) {
                    $avertissements[] = "Une épreuve combinée devrait comporter au moins 2 modules (actuellement {$nbModules}).";
                }
            } else {
                $totalDistinctes++;
            }

            // Cohérence de session
            if ($examen->session && $examen->session->annee_universitaire_id !== $examen->annee_universitaire_id) {
                $bloquants[] = "Incohérence d'année universitaire entre l'examen et sa session parente.";
            }

            // Cohérence de programme / faculté
            if ($examen->programme && $faculteId !== null && (int) $examen->programme->faculte_id !== $faculteId) {
                $bloquants[] = "L'examen appartient à un programme hors du périmètre facultaire autorisé.";
            }

            $estValide = empty($bloquants);
            if ($estValide) {
                $totalConformes++;
            } else {
                $totalAnomalies++;
            }

            $listeAuditee[] = [
                'examen' => $examen,
                'nb_modules' => $nbModules,
                'modalite' => $modalite,
                'valide' => $estValide,
                'bloquants' => $bloquants,
                'avertissements' => $avertissements,
            ];
        }

        return [
            'total_configurations' => $examens->count(),
            'total_conformes' => $totalConformes,
            'total_anomalies' => $totalAnomalies,
            'total_distinctes' => $totalDistinctes,
            'total_combinees' => $totalCombinees,
            'max_modules_autorise' => self::MAX_MODULES_PAR_CONFIGURATION,
            'liste_auditee' => $listeAuditee,
        ];
    }
}
