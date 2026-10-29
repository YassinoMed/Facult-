<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Models\InscriptionPedagogique;
use App\Models\IntegrationLog;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\TypeEvaluation;
use App\Services\AuditService;
use App\Services\PopulationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Planification des épreuves (§28) : transaction + conflits bloquants +
 * génération des candidats depuis le groupe / les inscriptions pédagogiques +
 * cycle de vie, transitions de statut, verrouillage et émargement.
 */
class EpreuveService
{
    private AuditService $audit;

    public function __construct(
        private ConflitEpreuveService $conflits,
        ?AuditService $audit = null,
    ) {
        $this->audit = $audit ?? app(AuditService::class);
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function creer(Examen $examen, array $donnees): Epreuve
    {
        return DB::transaction(function () use ($examen, $donnees) {
            $session = SessionExamen::findOrFail($donnees['session_id'] ?? $examen->session_id);

            if ($session->estVerrouillee()) {
                throw new DomainException('Session verrouillée : création d’épreuve interdite.');
            }

            if (! $session->contientDate($donnees['date'])) {
                throw new DomainException('Épreuve hors période de sa session.');
            }

            $bloquants = $this->conflits->bloquants($this->conflits->detecter([
                'date' => $donnees['date'],
                'heure_debut' => $donnees['heure_debut'],
                'heure_fin' => $donnees['heure_fin'],
                'salle_id' => $donnees['salle_id'] ?? null,
                'groupe_id' => $donnees['groupe_id'] ?? $examen->groupe_id,
                'capacite_necessaire' => $donnees['capacite_necessaire'] ?? 0,
            ]));

            if ($bloquants !== []) {
                throw new DomainException($bloquants[0]['message']);
            }

            // Phase 5 §26 : garde service (génération auto et imports
            // ne passent pas par la Request) — type référencé et actif.
            if (($donnees['type_evaluation'] ?? null) !== null
                && ! TypeEvaluation::where('code', $donnees['type_evaluation'])->where('actif', true)->exists()
            ) {
                throw new DomainException("Type d’évaluation « {$donnees['type_evaluation']} » inconnu ou inactif.");
            }

            /** @var Epreuve $epreuve */
            $epreuve = Epreuve::create([
                ...$donnees,
                'examen_id' => $examen->id,
                'session_id' => $session->id,
                'annee_universitaire_id' => $examen->annee_universitaire_id,
                'groupe_id' => $donnees['groupe_id'] ?? $examen->groupe_id,
                'ue_id' => $donnees['ue_id'] ?? null,
                'coefficient' => $donnees['coefficient'] ?? 1,
                'bareme' => $donnees['bareme'] ?? 20,
                'note_max' => $donnees['note_max'] ?? 20,
                'statut' => 'scheduled',
            ]);

            $epreuve->refresh();

            $verifGroupe = app(CorrespondanceExamenService::class)
                ->verifierCoherenceStructurelleGroupe($epreuve, $epreuve->groupe);

            if (! $verifGroupe['conforme']) {
                throw new DomainException($verifGroupe['anomalies'][0]);
            }

            EvaluationChanged::dispatch('epreuve.creee', $epreuve->id);

            return $epreuve;
        });
    }

    /**
     * Modification d'une épreuve avec vérification des conflits.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function modifier(Epreuve $epreuve, array $donnees, int $auteurId): Epreuve
    {
        return DB::transaction(function () use ($epreuve, $donnees, $auteurId) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : modification interdite.');
            }

            $anciensAttributs = [
                'matiere_id' => $verrouillee->matiere_id,
                'groupe_id' => $verrouillee->groupe_id,
            ];

            $date = $donnees['date'] ?? $verrouillee->date->format('Y-m-d');
            $heureDebut = $donnees['heure_debut'] ?? substr((string) $verrouillee->heure_debut, 0, 5);
            $heureFin = $donnees['heure_fin'] ?? substr((string) $verrouillee->heure_fin, 0, 5);
            $salleId = array_key_exists('salle_id', $donnees) ? $donnees['salle_id'] : $verrouillee->salle_id;
            $groupeId = array_key_exists('groupe_id', $donnees) ? $donnees['groupe_id'] : $verrouillee->groupe_id;

            $bloquants = $this->conflits->bloquants($this->conflits->detecter([
                'date' => $date,
                'heure_debut' => $heureDebut,
                'heure_fin' => $heureFin,
                'salle_id' => $salleId,
                'groupe_id' => $groupeId,
                'capacite_necessaire' => $donnees['capacite_necessaire'] ?? $verrouillee->capacite_necessaire,
            ], $verrouillee->id));

            if ($bloquants !== []) {
                throw new DomainException($bloquants[0]['message']);
            }

            $verrouillee->update($donnees);
            $verrouillee->refresh();

            $correspondance = app(CorrespondanceExamenService::class);
            $verifGroupe = $correspondance->verifierCoherenceStructurelleGroupe($verrouillee, $verrouillee->groupe);
            if (! $verifGroupe['conforme']) {
                throw new DomainException($verifGroupe['anomalies'][0]);
            }

            $correspondance->recalculerApresModification($verrouillee, $anciensAttributs, $auteurId);

            IntegrationLog::tracer('evaluation', 'internal', 'exam.epreuve.updated', 'completed', null, 200, null, null, (string) Str::uuid(), 'EPR-'.$verrouillee->id);

            EvaluationChanged::dispatch('epreuve.modifiee', $verrouillee->id);

            return $verrouillee->fresh();
        });
    }

    /**
     * Transition d'état de l'épreuve côté serveur (§6).
     */
    public function transiter(Epreuve $epreuve, string $cible, int $auteurId): Epreuve
    {
        return DB::transaction(function () use ($epreuve, $cible) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cible, Epreuve::STATUTS, true) || ! $verrouillee->peutTransiterVers($cible)) {
                throw new DomainException("Transition interdite vers « {$cible} » depuis « {$verrouillee->statut} ».");
            }

            if ($cible === 'published') {
                if ($verrouillee->salle_id === null && ! $verrouillee->candidats()->whereNotNull('salle_id')->exists()) {
                    throw new DomainException("Impossible de publier l'épreuve : aucune salle assignée.");
                }

                app(CorrespondanceExamenService::class)->exigerConformitePublicationEpreuve($verrouillee);
            }

            $ancien = $verrouillee->statut;
            $verrouillee->update(['statut' => $cible]);

            IntegrationLog::tracer('evaluation', 'internal', 'exam.epreuve.status_changed', 'completed', null, 200, null, null, (string) Str::uuid(), 'EPR-'.$verrouillee->id);

            EvaluationChanged::dispatch('epreuve.statut_change', $verrouillee->id);

            return $verrouillee->fresh();
        });
    }

    /**
     * Verrouillage officiel de l'épreuve (§17).
     */
    public function verrouiller(Epreuve $epreuve, int $auteurId, ?string $motif = null): Epreuve
    {
        return DB::transaction(function () use ($epreuve) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve déjà verrouillée.');
            }

            if ($verrouillee->statut !== 'completed') {
                throw new DomainException("Verrouillage impossible : l'épreuve doit être achevée (completed) avant d'être verrouillée.");
            }

            $verrouillee->update(['statut' => 'locked']);

            IntegrationLog::tracer('evaluation', 'internal', 'exam.locked', 'completed', null, 200, null, null, (string) Str::uuid(), 'EPR-'.$verrouillee->id);

            EvaluationChanged::dispatch('epreuve.verrouillee', $verrouillee->id);

            return $verrouillee->fresh();
        });
    }

    /**
     * Enregistre l'émargement / la présence des candidats (§13).
     *
     * @param  array<int, array{candidat_id: int, statut: string, justification?: ?string}>  $presences
     * @return array{total: int, presents: int, absents: int, retards: int}
     */
    public function enregistrerEmargement(Epreuve $epreuve, array $presences, int $auteurId): array
    {
        return DB::transaction(function () use ($epreuve, $presences, $auteurId) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : émargement interdit.');
            }

            $total = 0;
            $presents = 0;
            $absents = 0;
            $retards = 0;
            $nonPointes = 0;

            foreach ($presences as $presence) {
                $candidatId = (int) $presence['candidat_id'];
                $statut = (string) $presence['statut'];
                $justification = isset($presence['justification']) ? (string) $presence['justification'] : null;

                if (! in_array($statut, Candidat::STATUTS, true)) {
                    throw new DomainException("Statut d'émargement « {$statut} » invalide.");
                }

                $candidat = Candidat::where('id', $candidatId)
                    ->where('epreuve_id', $verrouillee->id)
                    ->lockForUpdate()
                    ->first();

                if ($candidat !== null) {
                    $candidat->update([
                        'statut' => $statut,
                        'justification' => $justification,
                        'decide_par' => $auteurId,
                    ]);

                    $total++;
                    if ($statut === 'present') {
                        $presents++;
                    } elseif ($statut === 'retard') {
                        $retards++;
                    } elseif ($statut === Candidat::STATUT_NON_POINTE) {
                        $nonPointes++;
                    } else {
                        $absents++;
                    }
                }
            }

            IntegrationLog::tracer('evaluation', 'internal', 'exam.attendance.recorded', 'completed', null, 200, null, null, (string) Str::uuid(), 'EPR-'.$verrouillee->id);

            EvaluationChanged::dispatch('epreuve.emargement', $verrouillee->id);

            return [
                'total' => $total,
                'presents' => $presents,
                'absents' => $absents,
                'retards' => $retards,
                'non_pointes' => $nonPointes,
            ];
        });
    }

    /**
     * Génère les candidats depuis la population centrale (dataset
     * exam_candidates : groupe OU ECUE + année) : hors étudiants suspendus,
     * inscription pédagogique `inscrit` obligatoire pour le lien (règle
     * métier examen). Idempotent.
     */
    public function genererCandidats(Epreuve $epreuve): int
    {
        return DB::transaction(function () use ($epreuve) {
            if ($epreuve->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée.');
            }

            $correspondance = app(CorrespondanceExamenService::class);
            if ($correspondance->estModePedagogiqueStrict($epreuve, true) || $correspondance->groupeVirtuelActif($epreuve) !== null) {
                $bilan = $correspondance->synchroniserCandidatsEligibles($epreuve, auth()->id());

                return $bilan['crees'];
            }

            $populations = app(PopulationService::class);

            // Sémantique conservée : branche groupe = tous les étudiants du
            // groupe (lien pédagogique nullable) ; branche ECUE = seuls les
            // inscrits pédagogiques sont convoqués.
            $etudiants = $epreuve->groupe_id !== null
                ? $populations->requete('students_by_group', [
                    'groupe_id' => $epreuve->groupe_id,
                ], auth()->user())->get()
                : $populations->requete('exam_candidates', [
                    'matiere_id' => $epreuve->matiere_id,
                    'annee_universitaire_id' => $epreuve->annee_universitaire_id,
                ], auth()->user())->get()
                    ->filter(fn (Etudiant $e) => InscriptionPedagogique::where('matiere_id', $epreuve->matiere_id)
                        ->where('statut', 'inscrit')
                        ->whereHas('inscription', fn ($q) => $q->where('etudiant_id', $e->id))
                        ->exists());

            $crees = 0;

            foreach ($etudiants as $etudiant) {
                if ($etudiant->estSuspendu()) {
                    continue;
                }

                $pedagogique = InscriptionPedagogique::where('matiere_id', $epreuve->matiere_id)
                    ->where('statut', 'inscrit')
                    ->whereHas('inscription', fn ($q) => $q->where('etudiant_id', $etudiant->id))
                    ->first();

                $candidat = Candidat::firstOrCreate(
                    ['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id],
                    [
                        'inscription_pedagogique_id' => $pedagogique?->id,
                        'salle_id' => $epreuve->salle_id,

                        // DM-02 : statut initial « non pointé » — jamais
                        // déclaré présent avant le contrôle effectif.
                        'statut' => Candidat::STATUT_NON_POINTE,
                    ]
                );

                if ($candidat->wasRecentlyCreated) {
                    $crees++;
                }
            }

            EvaluationChanged::dispatch('epreuve.candidats', $epreuve->id);

            return $crees;
        });
    }
}
