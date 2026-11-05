<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConsultationExportGroupeVirtuelService;

/**
 * Outil métier AssistantIa : Consultation des dérives de population d'une session d'examen.
 * Identifie les groupes virtuels dont les effectifs ont changé après génération ou validation.
 */
class ConsulterDerivesSessionTool implements OutilIaInterface
{
    public function __construct(
        protected ?ConsultationExportGroupeVirtuelService $serviceConsultation = null
    ) {
        $this->serviceConsultation = $serviceConsultation ?? app(ConsultationExportGroupeVirtuelService::class);
    }

    public function nom(): string
    {
        return 'consulter_derives_session';
    }

    public function description(): string
    {
        return 'Identifie et explique les dérives de population et incohérences pédagogiques des groupes virtuels d\'une session d\'examen donnée.';
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
                'session_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant numérique de la session d\'examen à inspecter',
                ],
                'matiere_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant optionnel d\'une matière spécifique à filtrer',
                ],
            ],
            'required' => ['session_id'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $sessionId = (int) ($parametres['session_id'] ?? 0);
        if ($sessionId <= 0) {
            return ResultatOutil::echec("L'identifiant de la session d'examen est obligatoire et doit être un entier positif.");
        }

        $session = SessionExamen::with('anneeUniversitaire')->find($sessionId);
        if (! $session) {
            return ResultatOutil::echec("Session d'examen #{$sessionId} introuvable.");
        }

        // Contrôle d'habilitation et BOLA facultaire
        if (! $utilisateur->hasPermission('assistant_ia.mode_metier') && ! $utilisateur->estSuperAdmin()) {
            throw new SecuriteIaException("Vous ne disposez pas de l'habilitation requise pour inspecter les dérives de session.");
        }

        $faculteRestreinte = $utilisateur->faculteRestreinteId();
        if ($faculteRestreinte !== null && $session->faculte_id !== null) {
            FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $session->faculte_id);
        }

        // Consultation via le service officiel UniRegistre
        $filtres = [
            'session_id' => $sessionId,
            'matiere_id' => ! empty($parametres['matiere_id']) ? (int) $parametres['matiere_id'] : null,
            'statut' => 'tous',
            'par_page' => 100,
        ];

        $donnees = $this->serviceConsultation->listerGroupesVirtuels($filtres, $utilisateur);
        $groupes = $donnees['lignes'];
        $kpis = $donnees['kpis'];

        $groupesAnomaux = [];

        foreach ($groupes as $g) {
            if (($g['en_derive'] ?? false) || ! ($g['conforme'] ?? false)) {
                $groupesAnomaux[] = [
                    'groupe_id' => (int) $g['id'],
                    'libelle' => (string) $g['libelle'],
                    'matiere' => (string) ($g['matiere_nom'] ?? 'N/A'),
                    'epreuve_id' => $g['epreuve_id'] ?? null,
                    'effectif_enregistre' => (int) ($g['effectif_enregistre'] ?? 0),
                    'effectif_eligible' => (int) ($g['effectif_eligible'] ?? 0),
                    'statut_validation' => (string) ($g['statut_validation'] ?? 'non_valide'),
                    'alertes' => array_slice((array) ($g['alertes'] ?? []), 0, 3),
                ];
            }
        }

        $nbTotal = (int) ($kpis['total_groupes'] ?? count($groupes));
        $nbDerives = (int) ($kpis['groupes_en_derive'] ?? 0);
        $nbAnomalies = (int) ($kpis['alertes_count'] ?? 0);
        $plafondAtteint = (bool) ($kpis['plafond_atteint'] ?? false);
        $detailsTronques = $nbTotal > count($groupes);

        $resume = "Session #{$sessionId} ({$session->nom}) : {$nbTotal} groupe(s) virtuel(s) analysé(s), "
            ."{$nbDerives} en dérive, {$nbAnomalies} avec anomalie(s). "
            .($nbDerives > 0
                ? '⚠️ Une revalidation ou resynchronisation est requise avant toute validation formelle.'
                : '✅ Aucun groupe en dérive détecté.');

        if ($plafondAtteint) {
            $resume .= ' Plafond de scan atteint : les compteurs d’anomalies sont partiels, affinez les filtres.';
        }

        if ($detailsTronques) {
            $resume .= ' Le détail est limité aux '.count($groupes).' premiers groupes ; les totaux restent exacts.';
        }

        $donneesSortie = [
            'session_id' => $sessionId,
            'session_nom' => $session->nom,
            'total_groupes' => $nbTotal,
            'groupes_en_derive_count' => $nbDerives,
            'groupes_avec_anomalies' => $nbAnomalies,
            'total_etudiants_eligibles' => (int) ($kpis['total_eligibles'] ?? 0),
            'etudiants_eligibles_uniques' => (int) ($kpis['etudiants_eligibles_uniques'] ?? 0),
            'total_exclus' => (int) ($kpis['total_exclus'] ?? 0),
            'groupes_sans_salle' => (int) ($kpis['groupes_sans_salle'] ?? 0),
            'groupes_depassement' => (int) ($kpis['groupes_depassement'] ?? 0),
            'anomalies_bloquantes' => (int) ($kpis['anomalies_bloquantes'] ?? 0),
            'plafond_atteint' => $plafondAtteint,
            'details_tronques' => $detailsTronques,
            'groupes_en_derive' => $groupesAnomaux,
            'action_recommandee' => ($nbDerives > 0 || $nbAnomalies > 0)
                ? 'Effectuer une resynchronisation des propositions en dérive depuis l\'écran d\'administration avant toute validation formelle.'
                : 'La cohorte de la session est conforme aux inscriptions actuelles.',
            'lecture_seule' => true,
            'aucune_mutation' => true,
        ];

        $sources = [
            [
                'titre' => 'Service de Consultation et Contrôle des Groupes Virtuels',
                'url_ou_chemin' => 'app/Modules/Evaluation/Services/ConsultationExportGroupeVirtuelService.php',
                'source' => 'business_procedure',
            ],
            [
                'titre' => 'Politique d\'Invariance et Traçabilité des Cohortes d\'Examen',
                'url_ou_chemin' => 'docs/regles-metier/deliberations-examens.md',
                'source' => 'business_procedure',
            ],
        ];

        return ResultatOutil::succes($donneesSortie, $resume, $sources);
    }
}
