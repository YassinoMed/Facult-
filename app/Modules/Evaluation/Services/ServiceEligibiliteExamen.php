<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Modules\Evaluation\DTO\DecisionEligibiliteExamen;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Schedule\Models\Group;
use Illuminate\Support\Collection;

/**
 * Service central d’éligibilité aux épreuves.
 *
 * Règle de sécurité : une appartenance à un groupe pédagogique ne prouve
 * jamais l’éligibilité. La décision repose sur les inscriptions académique
 * et pédagogique réellement actives pour l’année et le module de l’épreuve.
 *
 * Les règles non encore validées par le métier sont traitées en anomalie,
 * jamais en autorisation implicite.
 */
final class ServiceEligibiliteExamen
{
    public const REGLE_DONNEES_EPREUVE_COMPLETES = 'donnees_epreuve_completes';

    public const REGLE_SESSION_PRINCIPALE = 'session_principale';

    public const REGLE_RATTRAPAGE_OFFICIELLE = 'regle_rattrapage_officielle';

    public const REGLE_INSCRIPTION_ACADEMIQUE_ACTIVE = 'inscription_academique_active';

    public const REGLE_SUSPENSION_ACTIVE = 'suspension_active';

    public const REGLE_INSCRIPTION_MODULE_ACTIVE = 'inscription_module_active';

    public const REGLE_COHERENCE_PROGRAMME = 'coherence_programme';

    /**
     * Statuts d’inscription académique considérés comme actifs.
     * Ces valeurs viennent de la migration existante
     * `2026_09_24_100005_create_inscriptions_academiques_table.php`.
     */
    private const STATUTS_ACADEMIQUES_ACTIFS = ['en_cours', 'valide', 'inscrit'];

    /**
     * Statut d’inscription pédagogique officiellement utilisé par le
     * module Évaluation et le service d’inscription pédagogique.
     */
    private const STATUT_PEDAGOGIQUE_ACTIF = 'inscrit';

    /**
     * Évalue un étudiant pour une épreuve.
     */
    public function evaluer(Epreuve $epreuve, Etudiant $etudiant): DecisionEligibiliteExamen
    {
        $epreuve->loadMissing(['matiere', 'session', 'examen']);

        if ($epreuve->matiere_id === null || $epreuve->matiere === null || $epreuve->session === null) {
            return DecisionEligibiliteExamen::anomalie(
                $etudiant,
                $epreuve,
                DecisionEligibiliteExamen::SOURCE_CONFIGURATION,
                self::REGLE_DONNEES_EPREUVE_COMPLETES,
                'Données d’épreuve incomplètes : module ou session manquant.',
            );
        }

        if ($epreuve->annee_universitaire_id === null) {
            return DecisionEligibiliteExamen::anomalie(
                $etudiant,
                $epreuve,
                DecisionEligibiliteExamen::SOURCE_CONFIGURATION,
                self::REGLE_DONNEES_EPREUVE_COMPLETES,
                'Données d’épreuve incomplètes : année universitaire manquante.',
            );
        }

        $session = $epreuve->session;

        if ($session->type === 'rattrapage') {
            return DecisionEligibiliteExamen::anomalie(
                $etudiant,
                $epreuve,
                DecisionEligibiliteExamen::SOURCE_SESSION,
                self::REGLE_RATTRAPAGE_OFFICIELLE,
                'Règle d’éligibilité en rattrapage non validée par le métier.',
            );
        }

        $inscriptionAcademique = InscriptionAcademique::query()
            ->where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
            ->whereIn('statut', self::STATUTS_ACADEMIQUES_ACTIFS)
            ->orderByDesc('id')
            ->first();

        if ($inscriptionAcademique === null) {
            return DecisionEligibiliteExamen::nonEligible(
                $etudiant,
                $epreuve,
                DecisionEligibiliteExamen::SOURCE_INSCRIPTION_ACADEMIQUE,
                self::REGLE_INSCRIPTION_ACADEMIQUE_ACTIVE,
                'Inscription académique active absente pour l’année de l’épreuve.',
            );
        }

        if ($etudiant->estSuspendu()) {
            return DecisionEligibiliteExamen::nonEligible(
                $etudiant,
                $epreuve,
                DecisionEligibiliteExamen::SOURCE_ETUDIANT,
                self::REGLE_SUSPENSION_ACTIVE,
                'Étudiant suspendu à la date d’évaluation.',
                inscriptionAcademiqueId: $inscriptionAcademique->id,
            );
        }

        $programmeEpreuve = $epreuve->examen?->programme_id;
        $programmeInscription = $inscriptionAcademique->programme_id;

        if ($programmeEpreuve !== null && $programmeInscription !== null
            && (int) $programmeEpreuve !== (int) $programmeInscription) {
            return DecisionEligibiliteExamen::anomalie(
                $etudiant,
                $epreuve,
                DecisionEligibiliteExamen::SOURCE_INSCRIPTION_ACADEMIQUE,
                self::REGLE_COHERENCE_PROGRAMME,
                'Programme de l’épreuve différent du programme de l’inscription académique.',
                inscriptionAcademiqueId: $inscriptionAcademique->id,
            );
        }

        $inscriptionPedagogique = InscriptionPedagogique::query()
            ->where('inscription_id', $inscriptionAcademique->id)
            ->where('matiere_id', $epreuve->matiere_id)
            ->where('statut', self::STATUT_PEDAGOGIQUE_ACTIF)
            ->orderByDesc('id')
            ->first();

        if ($inscriptionPedagogique === null) {
            return DecisionEligibiliteExamen::nonEligible(
                $etudiant,
                $epreuve,
                DecisionEligibiliteExamen::SOURCE_INSCRIPTION_PEDAGOGIQUE,
                self::REGLE_INSCRIPTION_MODULE_ACTIVE,
                'Inscription pédagogique active absente pour le module de l’épreuve.',
                inscriptionAcademiqueId: $inscriptionAcademique->id,
            );
        }

        return DecisionEligibiliteExamen::eligible(
            $etudiant,
            $epreuve,
            inscriptionAcademiqueId: $inscriptionAcademique->id,
            inscriptionPedagogiqueId: $inscriptionPedagogique->id,
            regle: self::REGLE_INSCRIPTION_MODULE_ACTIVE,
            motif: 'Inscriptions académique et pédagogique actives pour l’année et le module de l’épreuve.',
        );
    }

    /**
     * Évalue une population.
     *
     * Si un groupe est fourni, le groupe sert de périmètre organisationnel
     * uniquement : chaque étudiant reste évalué individuellement par le
     * module. Sans groupe, la population repose sur les inscriptions
     * pédagogiques actives au module.
     *
     * @return Collection<int, DecisionEligibiliteExamen>
     */
    public function evaluerPopulation(Epreuve $epreuve, ?Group $groupe = null): Collection
    {
        $etudiants = $this->population($epreuve, $groupe);

        return $etudiants
            ->sortBy('id')
            ->values()
            ->map(fn (Etudiant $etudiant) => $this->evaluer($epreuve, $etudiant));
    }

    /**
     * @return Collection<int, Etudiant>
     */
    private function population(Epreuve $epreuve, ?Group $groupe): Collection
    {
        if ($groupe !== null) {
            return Etudiant::query()
                ->where('groupe_id', $groupe->id)
                ->orderBy('id')
                ->get();
        }

        if ($epreuve->matiere_id === null || $epreuve->annee_universitaire_id === null) {
            return new Collection;
        }

        return InscriptionPedagogique::query()
            ->where('matiere_id', $epreuve->matiere_id)
            ->where('statut', self::STATUT_PEDAGOGIQUE_ACTIF)
            ->whereHas(
                'inscription',
                fn ($query) => $query
                    ->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
                    ->whereIn('statut', self::STATUTS_ACADEMIQUES_ACTIFS)
            )
            ->with('inscription.etudiant')
            ->get()
            ->map(fn (InscriptionPedagogique $inscription) => $inscription->inscription?->etudiant)
            ->filter()
            ->unique('id')
            ->values();
    }
}
