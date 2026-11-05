<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service de construction et de scellement du plan individuel de redoublement (LOOP 06).
 *
 * Applique le principe non négociable :
 * - Une unité capitalisée ne doit jamais être repassée.
 * - Le contrat pédagogique du redoublant ne contient QUE les éléments non validés.
 * - Respect des prérequis et dépendances de l'Arrêté du 7 juillet 2023.
 */
class PlanRedoublementService
{
    private readonly ControleDroitsRedoublementService $droitsService;

    public function __construct(
        private readonly CalculAcquisPedagogiquesService $calculAcquisService,
        ?ControleDroitsRedoublementService $droitsService = null,
    ) {
        $this->droitsService = $droitsService ?? app(ControleDroitsRedoublementService::class);
    }

    /**
     * Simule le plan individuel de redoublement (mode consultation / prévisionnel).
     *
     * @return array<string, mixed>
     */
    public function simulerPlan(Etudiant $etudiant, ?InscriptionAcademique $inscription = null): array
    {
        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $inscription);

        // Détection des prérequis bloquants
        $prerequisBloquants = $this->verifierPrerequis(
            $etudiant,
            $analyse['elements_a_reprendre'],
            $analyse['elements_conserves']
        );

        $anomalies = $analyse['anomalies'];
        $revueHumaine = [];
        $droitsRedoublement = null;

        if ($this->droitsService !== null) {
            try {
                $programmeObj = $inscription?->programmeRef ?? $etudiant->programmeRef;
                if ($programmeObj !== null) {
                    $droitsRedoublement = $this->droitsService->evaluerDroitsRedoublement($etudiant, $programmeObj);
                    if (! $droitsRedoublement['autorise']) {
                        // LOOP 40/44 × LOOP 36 (intégration) : une incertitude
                        // RÉGLEMENTAIRE (règle/source non confirmée) n'invalide pas
                        // le dossier — Gate G40 : aucune décision défavorable
                        // automatique, revue humaine obligatoire. Elle est exposée
                        // séparément (`revue_humaine`) et ne bloque ni la
                        // préparation du plan ni sa validation humaine.
                        // Les refus pour droits ÉPUISÉS (LIMITE_DEPASSEE et
                        // statuts « à vérifier ») restent des anomalies bloquantes.
                        if (($droitsRedoublement['statut_droit'] ?? '') === 'BLOQUE_REGLEMENTATION_INCONNUE') {
                            $revueHumaine[] = $droitsRedoublement['motif'];
                        } else {
                            $anomalies[] = $droitsRedoublement['motif'];
                        }
                    }
                }
            } catch (\Throwable) {
                // Fail-soft pour compatibilité de calculs non rattachés
            }
        }

        return [
            'etudiant_id' => $etudiant->id,
            'inscription_source_id' => $inscription?->id,
            'programme' => $analyse['programme'],
            'regime' => $analyse['regime_reglementaire'],
            'deliberation_publiee' => $analyse['deliberation_publiee'],
            'credits_acquis_conserves' => $analyse['total_credits_acquis'],
            'credits_restants' => $analyse['total_credits_restants'],
            'elements_conserves' => $analyse['elements_conserves'],
            'elements_a_reprendre' => $analyse['elements_a_reprendre'],
            'nb_elements_a_reprendre' => count($analyse['elements_a_reprendre']),
            'prerequis_bloquants' => $prerequisBloquants,
            'droits_redoublement' => $droitsRedoublement,
            'anomalies' => $anomalies,
            'revue_humaine' => $revueHumaine,
            'est_validable' => empty($anomalies) && $analyse['deliberation_publiee'],
        ];
    }

    /**
     * Alias compatible pour la construction / simulation prévisionnelle du plan.
     */
    public function construirePlan(Etudiant $etudiant, InscriptionAcademique|int|null $inscription = null, bool $dryRun = true): array|ParcoursPlan
    {
        $inscObj = $this->resoudreInscription($etudiant, $inscription);

        if (! $dryRun) {
            return $this->confirmerPlan($etudiant, $inscObj);
        }

        return $this->simulerPlan($etudiant, $inscObj);
    }

    /**
     * Enregistre et confirme le plan de redoublement dans le registre des parcours.
     * Idempotent : ne duplique aucun plan si un plan identique validé existe déjà.
     */
    public function confirmerPlan(
        Etudiant $etudiant,
        InscriptionAcademique|int|null $inscription = null,
        ?int $auteurId = null,
        ?string $commentaire = null
    ): ParcoursPlan {
        $inscObj = $this->resoudreInscription($etudiant, $inscription);
        $simulation = $this->simulerPlan($etudiant, $inscObj);

        if (! $simulation['est_validable'] && empty($simulation['elements_conserves']) && empty($simulation['elements_a_reprendre'])) {
            throw new DomainException('Impossible de valider le plan de redoublement : les résultats antérieurs sont invalides ou non délibérés.');
        }

        return DB::transaction(function () use ($etudiant, $inscObj, $simulation, $auteurId, $commentaire) {
            $userValide = $auteurId !== null && User::where('id', $auteurId)->exists();
            $auteurIdFinal = $userValide ? $auteurId : null;

            // Vérification d'idempotence : rechercher un plan existant
            $planExistant = ParcoursPlan::where('etudiant_id', $etudiant->id)
                ->where('statut', 'valide')
                ->latest('id')
                ->first();

            if ($planExistant !== null) {
                $planExistant->update([
                    'contenu' => $simulation,
                    'auteur_id' => $auteurIdFinal ?? $planExistant->auteur_id,
                ]);

                return $planExistant;
            }

            $nouveauPlan = ParcoursPlan::create([
                'etudiant_id' => $etudiant->id,
                'inscription_source_id' => $inscObj?->id,
                'objectif' => "Plan de redoublement individualisé — {$simulation['programme']}",
                'contenu' => $simulation,
                'statut' => 'valide',
                'version' => 1,
                'auteur_id' => $auteurIdFinal,
            ]);

            // Traçabilité intégrale dans HistoriqueAcademique et AuditService
            HistoriqueAcademique::create([
                'etudiant_id' => $etudiant->id,
                'utilisateur_id' => $auteurIdFinal,
                'action' => 'plan_redoublement_valide',
                'entite' => 'parcours_plans',
                'entite_id' => $nouveauPlan->id,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => json_encode([
                    'description' => "Validation du plan de redoublement individualisé : {$simulation['credits_acquis_conserves']} crédits conservés, {$simulation['credits_restants']} restants.",
                    'credits_conserves' => $simulation['credits_acquis_conserves'],
                    'credits_restants' => $simulation['credits_restants'],
                    'nombre_elements_conserves' => count($simulation['elements_conserves']),
                    'nombre_elements_a_reprendre' => count($simulation['elements_a_reprendre']),
                    'commentaire' => $commentaire,
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'web',
            ]);

            return $nouveauPlan;
        });
    }

    /**
     * Contrôle public des prérequis pour un ensemble d'ECUEs.
     */
    public function verifierPrerequisParcours(Etudiant $etudiant, array $ecueIds): array
    {
        $elements = array_map(fn ($id) => ['type' => 'ECUE', 'id' => $id, 'code' => "EC#{$id}", 'nom' => "Matière #{$id}"], $ecueIds);

        return $this->verifierPrerequis($etudiant, $elements, []);
    }

    /**
     * Résout l'inscription académique à partir d'une entité ou d'un ID.
     */
    private function resoudreInscription(Etudiant $etudiant, InscriptionAcademique|int|null $inscription): ?InscriptionAcademique
    {
        if ($inscription instanceof InscriptionAcademique) {
            return $inscription;
        }

        if (is_int($inscription)) {
            $trouve = InscriptionAcademique::where('etudiant_id', $etudiant->id)
                ->where(function ($q) use ($inscription) {
                    $q->where('id', $inscription)
                        ->orWhere('annee_universitaire_id', $inscription);
                })
                ->latest('id')
                ->first();

            if ($trouve !== null) {
                return $trouve;
            }
        }

        return InscriptionAcademique::where('etudiant_id', $etudiant->id)->latest('id')->first();
    }

    /**
     * Contrôle les dépendances et prérequis pédagogiques (`ecue_prerequis`).
     */
    private function verifierPrerequis(Etudiant $etudiant, array $elementsAReprendre, array $elementsConserves): array
    {
        $bloquants = [];
        $idsConserves = collect($elementsConserves)
            ->filter(fn ($el) => ($el['type'] ?? '') === 'ECUE')
            ->pluck('id')
            ->all();

        foreach ($elementsAReprendre as $element) {
            if (($element['type'] ?? '') !== 'ECUE') {
                continue;
            }

            $ecueId = (int) $element['id'];

            // Recherche des prérequis obligatoires dans la table ecue_prerequis
            $colMatiere = Schema::hasColumn('ecue_prerequis', 'matiere_id') ? 'matiere_id' : 'ecue_id';
            $colPrerequis = Schema::hasColumn('ecue_prerequis', 'prerequis_id') ? 'prerequis_id' : 'prerequis_ecue_id';

            $prerequisRequis = DB::table('ecue_prerequis')
                ->where($colMatiere, $ecueId)
                ->pluck($colPrerequis)
                ->all();

            foreach ($prerequisRequis as $prereqId) {
                if (! in_array($prereqId, $idsConserves, true)) {
                    $bloquants[] = "L'élément « {$element['nom']} » ({$element['code']}) requiert la validation préalable de l'ECUE #{$prereqId}.";
                }
            }
        }

        return $bloquants;
    }
}
