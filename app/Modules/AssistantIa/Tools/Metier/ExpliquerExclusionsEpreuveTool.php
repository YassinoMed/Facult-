<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Services\ConsultationExportGroupeVirtuelService;
use App\Modules\Evaluation\Services\ServiceEligibiliteExamen;

/**
 * Outil métier AssistantIa : Explication réglementaire des exclusions d'étudiants à une épreuve.
 * Détaille les motifs métier (inscriptions inactives, assiduité, absences, sanctions).
 */
class ExpliquerExclusionsEpreuveTool implements OutilIaInterface
{
    public function __construct(
        protected ?ConsultationExportGroupeVirtuelService $serviceConsultation = null,
        protected ?ServiceEligibiliteExamen $serviceEligibilite = null
    ) {
        $this->serviceConsultation = $serviceConsultation ?? app(ConsultationExportGroupeVirtuelService::class);
        $this->serviceEligibilite = $serviceEligibilite ?? app(ServiceEligibiliteExamen::class);
    }

    public function nom(): string
    {
        return 'expliquer_exclusions_epreuve';
    }

    public function description(): string
    {
        return 'Explique les motifs réglementaires et pédagogiques d\'exclusion des étudiants non retenus pour une épreuve d\'examen.';
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
                    'description' => 'Identifiant numérique de l\'épreuve d\'examen',
                ],
            ],
            'required' => ['epreuve_id'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $epreuveId = (int) ($parametres['epreuve_id'] ?? 0);
        if ($epreuveId <= 0) {
            return ResultatOutil::echec("L'identifiant de l'épreuve est obligatoire et doit être un entier positif.");
        }

        $epreuve = Epreuve::with(['matiere.ue.programme', 'examen.session', 'salle'])->find($epreuveId);
        if (! $epreuve) {
            return ResultatOutil::echec("Épreuve #{$epreuveId} introuvable.");
        }

        // Contrôle d'habilitation et BOLA facultaire
        if (! $utilisateur->hasPermission('assistant_ia.mode_metier') && ! $utilisateur->estSuperAdmin()) {
            throw new SecuriteIaException("Vous ne disposez pas de l'habilitation requise pour consulter les exclusions d'épreuves.");
        }

        $faculteRestreinte = $utilisateur->faculteRestreinteId();
        $faculteEpreuve = $epreuve->matiere?->ue?->programme?->faculte_id
            ?? $epreuve->examen?->programme?->faculte_id;

        if ($faculteRestreinte !== null && $faculteEpreuve !== null) {
            FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $faculteEpreuve);
        }

        $groupeVirtuel = GroupeVirtuelExamen::where('epreuve_id', $epreuveId)->orderByDesc('id')->first();

        $exclusDetailles = [];
        $repartitionMotifs = [];
        $effectifEligible = 0;
        $effectifTotal = 0;

        if ($groupeVirtuel) {
            $fiche = $this->serviceConsultation->construireFicheDetaillee($groupeVirtuel, $utilisateur);
            $effectifEligible = count($fiche['etudiants_eligibles']);
            $effectifTotal = (int) ($fiche['effectifs']['origine_total'] ?? $effectifEligible);

            foreach ($fiche['etudiants_exclus'] as $exclu) {
                $motif = (string) ($exclu['motif'] ?? 'Non éligible');
                $repartitionMotifs[$motif] = ($repartitionMotifs[$motif] ?? 0) + 1;

                // Anonymisation stricte : zéro CIN, nom tronqué
                $exclusDetailles[] = [
                    'num_etudiant' => (string) ($exclu['num_etudiant'] ?? '—'),
                    'groupe_origine' => (string) ($exclu['groupe_origine_code'] ?? '—'),
                    'decision' => (string) ($exclu['decision'] ?? 'non_eligible'),
                    'regle' => (string) ($exclu['regle'] ?? '—'),
                    'motif' => $motif,
                ];
            }
        }

        $nbExclus = count($exclusDetailles);
        $matiereLibelle = $epreuve->matiere?->nom ?? 'Matière #'.$epreuve->matiere_id;

        $resume = "Épreuve #{$epreuveId} ({$matiereLibelle}) : {$effectifEligible} étudiant(s) éligible(s), "
            ."{$nbExclus} étudiant(s) exclu(s) sur un effectif de référence de {$effectifTotal}.";

        $donneesSortie = [
            'epreuve_id' => $epreuveId,
            'epreuve_titre' => $epreuve->titre ?? 'Épreuve #'.$epreuveId,
            'matiere' => $matiereLibelle,
            'effectif_eligible' => $effectifEligible,
            'effectif_exclu' => $nbExclus,
            'repartition_par_motif' => $repartitionMotifs,
            'echantillon_exclus' => array_slice($exclusDetailles, 0, 15),
            'possibilites_regularisation' => [
                'Inscription pédagogique' => 'Vérifier la validation de l\'inscription au module dans le dossier académique.',
                'Assiduité' => 'Consulter les dérogations accordées par la commission pédagogique.',
                'Contentieux administratif' => 'Vérifier la régularisation auprès du service scolarité.',
            ],
        ];

        $sources = [
            [
                'titre' => 'Service Officiel d\'Éligibilité aux Examens',
                'url_ou_chemin' => 'app/Modules/Evaluation/Services/ServiceEligibiliteExamen.php',
                'source' => 'business_procedure',
            ],
            [
                'titre' => 'Règlement des Examens et Contrôle Continu UniRegistre',
                'url_ou_chemin' => 'docs/regles-metier/eligibilite-examens.md',
                'source' => 'business_procedure',
            ],
        ];

        return ResultatOutil::succes($donneesSortie, $resume, $sources);
    }
}
