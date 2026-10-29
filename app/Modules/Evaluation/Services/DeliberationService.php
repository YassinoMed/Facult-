<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\CalculationRun;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Services\CommunicationDispatcher;
use App\Services\PopulationService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Délibérations / jurys (§35) : préparation automatique (étudiants +
 * notes + moyennes + UE + crédits + règles + anomalies), tableau,
 * décisions, verrouillage, publication. Opérations critiques en
 * transaction + optimistic locking (updated_at).
 */
class DeliberationService
{
    public function __construct(
        private CalculEvaluationService $calculs,
        private RegleEvaluationService $regles,
        private GradeCheckSuite $controles,
    ) {}

    /**
     * Simulation (§14.10) : mêmes calculs et anomalies que `preparer`,
     * SANS écrire (ni décisions, ni statut). Ne modifie rien.
     *
     * @return array{etudiants: int, decisions: array<int, array<string, mixed>>, anomalies: array<int, array<string, mixed>>, regle: array<string, mixed>}
     */
    public function simuler(Deliberation $deliberation): array
    {
        $compile = $this->compiler($deliberation);

        // Traçabilité (Phase 14 §14.5) : la simulation elle-même est
        // enregistrée — sans écrire aucun résultat officiel.
        $this->enregistrerRun($deliberation, 'simulation', $compile, auth()->id());

        return $compile;
    }

    public function preparer(Deliberation $deliberation): Deliberation
    {
        return DB::transaction(function () use ($deliberation) {
            // Verrou pessimiste : deux préparations concurrentes ne
            // dupliquent jamais les décisions (delete+insert idempotent).
            $deliberation = Deliberation::whereKey($deliberation->id)->lockForUpdate()->firstOrFail();

            if ($deliberation->estVerrouillee()) {
                throw new DomainException('Délibération verrouillée.');
            }

            if (! $deliberation->peutTransiterVers('prepared')) {
                throw new DomainException("Préparation interdite depuis « {$deliberation->statut} ».");
            }

            $compile = $this->compiler($deliberation);

            DecisionDeliberation::where('deliberation_id', $deliberation->id)->delete();

            foreach (array_chunk($compile['decisions'], 500) as $lot) {
                DecisionDeliberation::insert($lot);
            }

            $deliberation->update([
                'statut' => 'prepared',
                'regles_appliquees' => $compile['regle'],
                'anomalies' => array_merge(
                    $compile['anomalies'],
                    // Contrôles notes (Phase 14 §14.4) : informatifs,
                    // jamais bloquants pour le jury.
                    $this->controles->controler($deliberation)
                ),
                // Une préparation fraîche lève l'invalidation antérieure.
                'resultats_invalides_le' => null,
            ]);

            $this->enregistrerRun($deliberation, 'officiel', $compile, auth()->id());

            EvaluationChanged::dispatch('deliberation.preparee', $deliberation->id);

            return $deliberation->fresh();
        });
    }

    /**
     * @return array{etudiants: int, decisions: array<int, array<string, mixed>>, anomalies: array<int, array<string, mixed>>, regle: array<string, mixed>}
     */
    private function compiler(Deliberation $deliberation): array
    {
        $etudiants = $this->etudiantsConcernes($deliberation);
        $anomalies = [];
        $decisions = [];
        $regle = $this->regles->resolve($deliberation->annee_universitaire_id, $deliberation->programme_id);
        $seuil = (float) ($regle['seuil_validation'] ?? 10);

        $etudiantIds = $etudiants->pluck('id')->all();

        $inscriptionsParEtudiant = InscriptionAcademique::whereIn('etudiant_id', $etudiantIds)
            ->where('annee_universitaire_id', $deliberation->annee_universitaire_id)
            ->with(['notes.matiere'])
            ->get()
            ->keyBy('etudiant_id');

        $notesNonValideesParEtudiant = NoteEpreuve::whereIn('etudiant_id', $etudiantIds)
            ->whereIn('statut', ['draft', 'entered', 'submitted'])
            ->whereNotNull('valeur')
            ->whereHas('epreuve', fn ($q) => $q->where('annee_universitaire_id', $deliberation->annee_universitaire_id))
            ->selectRaw('etudiant_id, COUNT(*) as total')
            ->groupBy('etudiant_id')
            ->pluck('total', 'etudiant_id');

        foreach ($etudiants as $etudiant) {
            $inscription = $inscriptionsParEtudiant->get($etudiant->id);

            $synthese = $this->calculs->syntheseEtudiant(
                $etudiant,
                $deliberation->annee_universitaire_id,
                $deliberation->programme_id,
                $inscription,
                $regle
            );

            $notesNonValidees = (int) ($notesNonValideesParEtudiant->get($etudiant->id) ?? 0);

            if ($notesNonValidees > 0) {
                $anomalies[] = [
                    'etudiant_id' => $etudiant->id,
                    'type' => 'note_non_validee',
                    'bloquant' => true,
                    'message' => "{$etudiant->nom_complet} : {$notesNonValidees} note(s) non validée(s).",
                ];
            }

            if ($synthese['moyenne'] === null) {
                $anomalies[] = [
                    'etudiant_id' => $etudiant->id,
                    'type' => 'sans_note',
                    'bloquant' => true,
                    'message' => "{$etudiant->nom_complet} : aucune note exploitable.",
                ];
            } elseif (abs($synthese['moyenne'] - $seuil) <= 0.5) {
                // Cas limite (Phase 14 §14.6) : à ±0,5 du seuil, le jury
                // arbitre — informatif, jamais bloquant.
                $anomalies[] = [
                    'etudiant_id' => $etudiant->id,
                    'type' => 'cas_limite',
                    'bloquant' => false,
                    'message' => "{$etudiant->nom_complet} : moyenne {$synthese['moyenne']} à ±0,5 du seuil {$seuil} — arbitrage jury.",
                ];
            }

            $decisions[] = [
                'deliberation_id' => $deliberation->id,
                'etudiant_id' => $etudiant->id,
                'inscription_academique_id' => $synthese['inscription_academique_id'],
                'moyenne' => $synthese['moyenne'],
                'credits_obtenus' => $synthese['credits_obtenus'],
                'credits_total' => $synthese['credits_total'],
                'ues_validees' => $synthese['ues_validees'],
                'ues_total' => $synthese['ues_total'],
                'decision' => $synthese['decision_proposee'],
                'publie' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return [
            'etudiants' => count($etudiants),
            'decisions' => $decisions,
            'anomalies' => $anomalies,
            'regle' => $regle,
        ];
    }

    /**
     * Invalidation après correction de note (Phase 14 §14.6) : toute
     * délibération NON verrouillée portant une décision pour cette
     * inscription doit être re-préparée avant validation.
     */
    public function marquerResultatsInvalides(InscriptionAcademique $inscription): void
    {
        Deliberation::whereHas('decisions', fn ($q) => $q
            ->where('inscription_academique_id', $inscription->id))
            ->whereNull('verrouillee_at')
            ->whereNotIn('statut', ['locked', 'archived'])
            ->update(['resultats_invalides_le' => now()]);
    }

    /**
     * @param  array{etudiants: int, decisions: array<int, array<string, mixed>>, anomalies: array<int, array<string, mixed>>, regle: array<string, mixed>}  $compile
     */
    private function enregistrerRun(Deliberation $deliberation, string $mode, array $compile, ?int $auteurId): void
    {
        CalculationRun::create([
            'deliberation_id' => $deliberation->id,
            'mode' => $mode,
            'regles' => $compile['regle'],
            'hash_entrees' => hash('sha256', json_encode([
                $compile['decisions'], $compile['regle'],
            ], JSON_UNESCAPED_UNICODE)),
            'resume' => [
                'etudiants' => $compile['etudiants'],
                'anomalies' => count($compile['anomalies']),
            ],
            'auteur_id' => $auteurId,
        ]);
    }

    /**
     * Décision du jury sur un étudiant (contrôle updated_at anti-concurrence §36).
     */
    public function decider(DecisionDeliberation $decision, string $nouvelleDecision, ?string $commentaire, string $updatedAt): DecisionDeliberation
    {
        return DB::transaction(function () use ($decision, $nouvelleDecision, $commentaire, $updatedAt) {
            $decision->loadMissing('deliberation');

            if ($decision->deliberation->estVerrouillee()) {
                throw new DomainException('Délibération verrouillée.');
            }

            if (! in_array($nouvelleDecision, DecisionDeliberation::DECISIONS, true)) {
                throw new DomainException('Décision inconnue.');
            }

            if ($decision->updated_at->toJSON() !== $updatedAt && $decision->updated_at->format('Y-m-d H:i:s') !== $updatedAt) {
                throw new DomainException('Données modifiées par un autre utilisateur : rechargez le tableau.');
            }

            $ancienne = $decision->decision;

            $decision->update(['decision' => $nouvelleDecision, 'commentaire' => $commentaire]);

            // Lot 4/6 : la décision du jury est traçable par valeurs
            // (codes de décision uniquement — aucune PII dans les logs).
            Log::info('deliberation.decision.modifiee', [
                'decision_id' => $decision->id,
                'deliberation_id' => $decision->deliberation_id,
                'ancienne' => $ancienne,
                'nouvelle' => $nouvelleDecision,
                'acteur_id' => auth()->id(),
            ]);

            EvaluationChanged::dispatch('deliberation.decision', $decision->deliberation_id);

            return $decision->fresh();
        });
    }

    public function valider(Deliberation $deliberation): Deliberation
    {
        return DB::transaction(function () use ($deliberation) {
            $deliberation = Deliberation::whereKey($deliberation->id)->lockForUpdate()->firstOrFail();

            // Résultats invalidés par une correction de note postérieure
            // (Phase 14 §14.6) : re-préparation exigée avant validation.
            if ($deliberation->resultats_invalides_le !== null) {
                throw new DomainException('Résultats invalidés par une correction de note : relancez la préparation.');
            }

            if (! $deliberation->peutTransiterVers('validated')) {
                throw new DomainException("Validation interdite depuis « {$deliberation->statut} ».");
            }

            // Quorum jury (§14.15) : président présent + 2 membres présents.
            $presents = $deliberation->membres()->where('present', true)->get();
            $presidentPresent = $presents->contains(fn ($m) => $m->fonction === 'president');

            if (! $presidentPresent || $presents->count() < 3) {
                throw new DomainException('Quorum jury insuffisant : président + 2 membres présents requis.');
            }

            if ($deliberation->anomaliesBloquantes() !== []) {
                throw new DomainException('Anomalies bloquantes : finalisation interdite.');
            }

            if ($deliberation->decisions()->where('decision', 'a_examiner')->exists()) {
                throw new DomainException('Des décisions restent à examiner.');
            }

            $deliberation->update(['statut' => 'validated', 'verrouillee_at' => now()]);

            EvaluationChanged::dispatch('deliberation.validee', $deliberation->id);

            return $deliberation->fresh();
        });
    }

    public function publier(Deliberation $deliberation): Deliberation
    {
        // Lot 4/6 : les notifications étudiantes partent APRÈS la
        // fermeture de la transaction — un échec de commit ne notifie
        // jamais une publication qui n'a pas eu lieu, et un échec de
        // diffusion ne remet pas en cause la publication (qui est déjà
        // persistée). Écritures sensibles : transaction + verrou.
        $etudiantIds = DB::transaction(function () use ($deliberation) {
            $deliberation = Deliberation::whereKey($deliberation->id)->lockForUpdate()->firstOrFail();

            if (! $deliberation->peutTransiterVers('published')) {
                throw new DomainException("Publication interdite depuis « {$deliberation->statut} ».");
            }

            if ($deliberation->statut !== 'validated') {
                throw new DomainException('Seule une délibération validée peut être publiée.');
            }

            $deliberation->decisions()->update(['publie' => true]);
            $deliberation->update(['statut' => 'published', 'published_at' => now()]);

            EvaluationChanged::dispatch('deliberation.publiee', $deliberation->id);

            // Phase 6 §36 : chaque candidat est notifié (sans PII
            // sensible : décision + moyenne, jamais de détail médical).
            return $deliberation->decisions()->pluck('etudiant_id')->all();
        });

        // Phase 15 §15.4 : diffusion via le centre (gabarit versionné,
        // préférences, texte contractuel inchangé).
        app(CommunicationDispatcher::class)->dispatcherPlusieurs(
            'deliberation.publiee',
            $etudiantIds,
            [],
            route('etudiant.dashboard'),
        );

        return $deliberation->fresh();
    }

    /** @return Collection<int, Etudiant> */
    private function etudiantsConcernes(Deliberation $deliberation)
    {
        // Population centrale (mêmes filtres : groupe + programme + année,
        // tri nom/prénom) — aucune query ad hoc.
        return app(PopulationService::class)->requete('students', [
            'groupe_id' => $deliberation->groupe_id,
            'programme_id' => $deliberation->programme_id,
            'annee_universitaire_id' => $deliberation->annee_universitaire_id,
            'tri' => 'nom',
            'sens' => 'asc',
        ], auth()->user())->get();
    }
}
