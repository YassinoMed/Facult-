<?php

namespace App\Services;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\MensReference;

/**
 * Service de mesure de l'expérience et d'amélioration continue (LOOP 22).
 *
 * Principes stricts (§LOOP 22) :
 * 1. Définition et formule explicites pour chaque métrique.
 * 2. Source de données unifiée et vérifiée en base.
 * 3. Minimisation des données : agrégation pure, zéro PII (aucun nom, CIN ou contact).
 * 4. Rigueur statistique : zéro corrélation vendue comme causalité, mention explicite des biais d'observation.
 * 5. Scoping strict par faculté et période d'observation.
 */
class ExperienceInscriptionService
{
    /**
     * Calcule l'ensemble des indicateurs d'expérience agrégés.
     *
     * @return array<string, mixed>
     */
    public function syntheseExperience(?int $faculteId = null, ?int $anneeId = null): array
    {
        $corrections = $this->tauxCorrection($faculteId, $anneeId);

        return [
            'indicateurs' => [
                'completude_premier_depot' => [
                    'libelle' => 'Complétude au premier dépôt',
                    'valeur' => $this->completudePremierDepot($faculteId, $anneeId),
                    'unite' => '%',
                    'formule' => '100 × (Dossiers déposés sans demande de correction / Total dossiers déposés)',
                    'source' => 'etudiants ⟕ dossier_corrections',
                    'interpretation' => 'Mesure la capacité des candidats à déposer un dossier immédiatement exploitable sans aller-retour administratif.',
                ],
                'taux_abandon_mesurable' => [
                    'libelle' => 'Taux d’abandon mesurable',
                    'valeur' => $this->tauxAbandonMesurable($faculteId, $anneeId),
                    'unite' => '%',
                    'formule' => '100 × (Identifications MENS sans dossier finalisé / Total candidats identifiés MENS)',
                    'source' => 'mens_references ⟕ etudiants (par CIN normalisé)',
                    'interpretation' => 'Indicateur non invasif calculé sans pistage publicitaire. Reflète les abandons en amont du dépôt final.',
                ],
                'taux_dossiers_avec_correction' => [
                    'libelle' => 'Taux de dossiers nécessitant correction',
                    'valeur' => $corrections['taux_dossiers_avec_correction'],
                    'unite' => '%',
                    'formule' => '100 × (Dossiers avec au moins 1 correction / Total dossiers déposés)',
                    'source' => 'dossier_corrections (groupé par etudiant_id)',
                    'interpretation' => 'Mesure la friction résiduelle du parcours nécessitant une rectification manuelle.',
                ],
                'taux_corrections_appliquees' => [
                    'libelle' => 'Taux de corrections appliquées',
                    'valeur' => $corrections['taux_corrections_appliquees'],
                    'unite' => '%',
                    'formule' => '100 × (Corrections appliquées / Total corrections décidées [appliquées + rejetées])',
                    'source' => 'dossier_corrections (statut serveur appliquee / rejetee)',
                    'interpretation' => 'Mesure l’efficacité du cycle de remédiation étudiante (proposée → corrigée).',
                ],
                'delai_moyen_traitement_jours' => [
                    'libelle' => 'Délai moyen de traitement',
                    'valeur' => $this->delaiMoyenTraitementJours($faculteId, $anneeId),
                    'unite' => 'jours',
                    'formule' => 'Moyenne arithmétique (date de 1ère décision valide − date de dépôt created_at)',
                    'source' => 'etudiants.created_at → situations_etudiants.date_effet (type=statut, valeur=valide)',
                    'interpretation' => 'Délai effectif d’instruction administrative par la scolarité.',
                ],
                'taux_anomalies_techniques' => [
                    'libelle' => 'Taux d’anomalies bloquantes',
                    'valeur' => $this->tauxAnomaliesTechniques($faculteId, $anneeId),
                    'unite' => '%',
                    'formule' => '100 × (Dossiers avec anomalie bloquante de vérification / Total dossiers vérifiés)',
                    'source' => 'dossier_verifications (statut_global = ANOMALIE_BLOQUANTE)',
                    'interpretation' => 'Mesure les blocages d’intégrité bloquant l’avancement du dossier.',
                ],
            ],
            'cycle_amelioration' => [
                'garde_fou_causalite' => 'Attention : une corrélation statistique n’implique pas une causalité univoque. Par exemple, une baisse du délai de traitement peut découler d’un volume plus faible sur une période donnée plutôt que d’une simplification du formulaire.',
                'recommandations' => [
                    'Si la complétude au premier dépôt est < 70%, clarifier les consignes des justificatifs requis.',
                    'Si le taux de correction sur un champ dépasse 15%, ajouter une aide contextuelle au niveau de ce champ.',
                    'Si le délai moyen dépasse 5 jours ouvrés, mobiliser des renforts d’instruction ou réorganiser la file administrative.',
                ],
            ],
        ];
    }

    /**
     * 1. Complétude au premier dépôt (%) :
     * % de dossiers déposés qui n'ont fait l'objet d'aucune demande de correction.
     */
    public function completudePremierDepot(?int $faculteId = null, ?int $anneeId = null): ?float
    {
        $query = Etudiant::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($anneeId !== null, fn ($q) => $q->whereHas('inscriptionsAcademiques', fn ($i) => $i->where('annee_universitaire_id', $anneeId)));

        $totalDossiers = (clone $query)->count();
        if ($totalDossiers === 0) {
            return null;
        }

        $sansCorrection = (clone $query)
            ->doesntHave('corrections')
            ->count();

        return round(100 * $sansCorrection / $totalDossiers, 1);
    }

    /**
     * 2. Taux d’abandon mesurable (%) :
     * % de candidats identifiés dans le référentiel MENS qui n'ont pas finalisé leur inscription.
     * Respecte la minimisation : calcul purement agrégé par comparaison d'ensembles.
     */
    public function tauxAbandonMesurable(?int $faculteId = null, ?int $anneeId = null): ?float
    {
        // Année académique de référence
        $anneeLibelle = $anneeId !== null
            ? AnneeUniversitaire::find($anneeId)?->libelle
            : AnneeUniversitaire::where('active', true)->value('libelle');

        if (! $anneeLibelle) {
            return null;
        }

        $candidatsTotal = MensReference::where('annee', $anneeLibelle)->count();
        if ($candidatsTotal === 0) {
            return null;
        }

        // Candidats MENS ayant un dossier déposé correspondant
        $candidatsInscrits = MensReference::where('annee', $anneeLibelle)
            ->whereExists(function ($q) use ($faculteId) {
                $q->selectRaw('1')
                    ->from('etudiants')
                    ->whereColumn('etudiants.cin', 'mens_references.cin_normalise')
                    ->when($faculteId !== null, fn ($eq) => $eq->where('etudiants.faculte_id', $faculteId));
            })
            ->count();

        $abandons = max(0, $candidatsTotal - $candidatsInscrits);

        return round(100 * $abandons / $candidatsTotal, 1);
    }

    /**
     * 3. Taux de correction (%) :
     * - Taux de dossiers avec au moins 1 correction demandée.
     * - Taux de corrections appliquées parmi celles décidées.
     *
     * @return array{taux_dossiers_avec_correction: ?float, taux_corrections_appliquees: ?float}
     */
    public function tauxCorrection(?int $faculteId = null, ?int $anneeId = null): array
    {
        $etudiantsQuery = Etudiant::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($anneeId !== null, fn ($q) => $q->whereHas('inscriptionsAcademiques', fn ($i) => $i->where('annee_universitaire_id', $anneeId)));

        $totalDossiers = (clone $etudiantsQuery)->count();
        $dossiersAvecCorrection = (clone $etudiantsQuery)->has('corrections')->count();

        $tauxDossiers = $totalDossiers > 0
            ? round(100 * $dossiersAvecCorrection / $totalDossiers, 1)
            : null;

        // Taux de corrections appliquées
        $correctionsQuery = DossierCorrection::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)));

        $decidees = (clone $correctionsQuery)->whereIn('statut', ['appliquee', 'rejetee'])->count();
        $appliquees = (clone $correctionsQuery)->where('statut', 'appliquee')->count();

        $tauxAppliquees = $decidees > 0
            ? round(100 * $appliquees / $decidees, 1)
            : null;

        return [
            'taux_dossiers_avec_correction' => $tauxDossiers,
            'taux_corrections_appliquees' => $tauxAppliquees,
        ];
    }

    /**
     * 4. Délai moyen de traitement (en jours) :
     * Moyenne arithmétique entre la création du dossier et la date d'effet de validation.
     */
    public function delaiMoyenTraitementJours(?int $faculteId = null, ?int $anneeId = null): ?float
    {
        $etudiants = Etudiant::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($anneeId !== null, fn ($q) => $q->whereHas('inscriptionsAcademiques', fn ($i) => $i->where('annee_universitaire_id', $anneeId)))
            ->where('statut', 'valide')
            ->whereHas('situations', fn ($q) => $q->where('type', 'statut')->where('nouvelle_valeur', 'valide'))
            ->with(['situations' => fn ($q) => $q->where('type', 'statut')->where('nouvelle_valeur', 'valide')->orderBy('date_effet')])
            ->get(['id', 'created_at']);

        if ($etudiants->isEmpty()) {
            return null;
        }

        $durees = $etudiants->map(function (Etudiant $e) {
            $premiereValidation = $e->situations->first();
            if (! $premiereValidation) {
                return null;
            }

            return $e->created_at->diffInDays($premiereValidation->date_effet);
        })->filter(fn ($d) => $d !== null && $d >= 0);

        return $durees->isNotEmpty() ? round($durees->avg(), 1) : null;
    }

    /**
     * 5. Taux d’anomalies techniques bloquantes (%) :
     * % de dossiers vérifiés ayant déclenché un statut d'anomalie bloquante.
     */
    public function tauxAnomaliesTechniques(?int $faculteId = null, ?int $anneeId = null): ?float
    {
        $verifications = DossierVerification::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)));

        $totalVerifies = (clone $verifications)->count();
        if ($totalVerifies === 0) {
            return null;
        }

        $bloquantes = (clone $verifications)
            ->whereIn('verdict', ['BLOQUANT', 'ANOMALIE_BLOQUANTE'])
            ->count();

        return round(100 * $bloquantes / $totalVerifies, 1);
    }
}
