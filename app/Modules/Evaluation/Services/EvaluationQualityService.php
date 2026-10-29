<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use Illuminate\Support\Facades\Gate;

/**
 * Service centralisé de contrôle de la qualité et détection des anomalies
 * (LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02 — Lot 1).
 *
 * Détecte les anomalies de notes et d'épreuves sans N+1, avec gravité,
 * actions directes et résolutions groupées sécurisées.
 */
class EvaluationQualityService
{
    public function __construct(
        private SaisieNoteService $saisieService
    ) {}

    /**
     * Détecte toutes les anomalies filtrées selon le périmètre facultaire et les critères.
     *
     * @param  array{type?: string, gravite?: string, session_id?: int, epreuve_id?: int, recherche?: string}  $filtres
     * @return array<int, array<string, mixed>>
     */
    public function detecterAnomalies(?int $faculteId = null, array $filtres = []): array
    {
        $anomalies = [];

        // 1. Candidats présents sans note saisie (ou note nulle)
        $anomalies = array_merge($anomalies, $this->detecterCandidatsSansNote($faculteId, $filtres));

        // 2. Notes hors bornes (valeur < 0 ou > note_max)
        $anomalies = array_merge($anomalies, $this->detecterNotesHorsBornes($faculteId, $filtres));

        // 3. Notes soumises en attente de validation
        $anomalies = array_merge($anomalies, $this->detecterNotesEnAttente($faculteId, $filtres));

        // 4. Notes validées désynchronisées avec la note officielle
        $anomalies = array_merge($anomalies, $this->detecterNotesDesynchronisees($faculteId, $filtres));

        // 5. Candidats absents avec une note attribuée
        $anomalies = array_merge($anomalies, $this->detecterAbsentsAvecNote($faculteId, $filtres));

        // 6. Coefficients nuls ou négatifs
        $anomalies = array_merge($anomalies, $this->detecterCoefficientsIncoherents($faculteId, $filtres));

        // Filtrage en mémoire par recherche si précisé
        if (! empty($filtres['recherche'])) {
            $recherche = mb_strtolower(trim((string) $filtres['recherche']));
            $anomalies = array_values(array_filter($anomalies, function ($a) use ($recherche) {
                return str_contains(mb_strtolower($a['libelle']), $recherche)
                    || str_contains(mb_strtolower($a['perimetre']), $recherche)
                    || str_contains(mb_strtolower((string) ($a['etudiant_ref'] ?? '')), $recherche);
            }));
        }

        // Filtrage par gravité si précisé
        if (! empty($filtres['gravite'])) {
            $anomalies = array_values(array_filter($anomalies, fn ($a) => $a['gravite'] === $filtres['gravite']));
        }

        // Filtrage par type si précisé
        if (! empty($filtres['type'])) {
            $anomalies = array_values(array_filter($anomalies, fn ($a) => $a['type'] === $filtres['type']));
        }

        return $anomalies;
    }

    /**
     * LOT 03 (dette LOT 05) : KPI dérivés d'une liste déjà détectée —
     * une seule passe de détection quand l'appelant possède déjà les
     * anomalies (page anomalies sans filtre), au lieu d'un recalcul
     * complet à chaque appel. Sémantique identique à `kpis()`.
     *
     * @param  array<int, array<string, mixed>>  $anomalies
     * @return array{total: int, bloquante: int, avertissement: int, info: int}
     */
    public function kpisDepuisAnomalies(array $anomalies): array
    {
        $kpis = [
            'total' => count($anomalies),
            'bloquante' => 0,
            'avertissement' => 0,
            'info' => 0,
        ];

        foreach ($anomalies as $a) {
            $gravite = $a['gravite'] ?? 'info';
            if (isset($kpis[$gravite])) {
                $kpis[$gravite]++;
            }
        }

        return $kpis;
    }

    /**
     * Calcul des compteurs KPI d'anomalies par niveau de gravité.
     *
     * @return array{total: int, bloquante: int, avertissement: int, info: int}
     */
    public function kpis(?int $faculteId = null): array
    {
        return $this->kpisDepuisAnomalies($this->detecterAnomalies($faculteId));
    }

    /**
     * Résolution groupée sécurisée d'anomalies.
     *
     * @param  array<int, string>  $anomalieIds
     * @return array{resolus: int, ignores: int, erreurs: array<int, string>}
     */
    public function resoudreGroupee(array $anomalieIds, User $user, string $action): array
    {
        $toutes = collect($this->detecterAnomalies($user->faculteRestreinteId()))->keyBy('id');
        $resolus = 0;
        $ignores = 0;
        $erreurs = [];

        foreach ($anomalieIds as $id) {
            $anomalie = $toutes->get($id);
            if (! $anomalie || ! ($anomalie['resoluble_automatiquement'] ?? false)) {
                $ignores++;

                continue;
            }

            try {
                if ($action === 'synchroniser' && $anomalie['type'] === 'note_desynchronisee') {
                    $note = NoteEpreuve::find($anomalie['note_epreuve_id']);
                    if ($note && $note->statut === 'validated' && Gate::forUser($user)->allows('validerNote', $note)) {
                        $this->saisieService->resynchroniserNoteOfficielle($note, (int) $user->id);
                        $resolus++;
                    } else {
                        $ignores++;
                    }
                } elseif ($action === 'valider' && $anomalie['type'] === 'note_en_attente') {
                    $note = NoteEpreuve::find($anomalie['note_epreuve_id']);
                    if ($note && in_array($note->statut, ['submitted', 'entered'], true) && Gate::forUser($user)->allows('validerNote', $note)) {
                        $this->saisieService->valider($note, (int) $user->id, 'Validation groupée depuis le centre de contrôle des anomalies');
                        $resolus++;
                    } else {
                        $ignores++;
                    }
                } else {
                    $ignores++;
                }
            } catch (\Throwable $e) {
                $erreurs[] = "Anomalie {$id} : {$e->getMessage()}";
            }
        }

        return [
            'resolus' => $resolus,
            'ignores' => $ignores,
            'erreurs' => $erreurs,
        ];
    }

    /**
     * Contrôle pré-délibération / pré-publication : renvoie la liste des anomalies bloquantes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function verifierPublication(Deliberation $deliberation): array
    {
        $epreuvesIds = $deliberation->session?->epreuves()->pluck('id')->all() ?? [];
        if (empty($epreuvesIds)) {
            return [];
        }

        $filtres = ['session_id' => $deliberation->session_id];
        $anomalies = $this->detecterAnomalies($deliberation->faculteId(), $filtres);

        return array_values(array_filter($anomalies, fn ($a) => $a['gravite'] === 'bloquante'));
    }

    /** @return array<int, array<string, mixed>> */
    private function detecterCandidatsSansNote(?int $faculteId, array $filtres): array
    {
        $query = Candidat::with(['etudiant', 'epreuve.matiere'])
            ->where('statut', 'present')
            ->whereDoesntHave('note', fn ($q) => $q->whereNotNull('valeur'));

        $this->appliquerFiltresEpreuve($query, $faculteId, $filtres);

        $candidats = $query->limit(200)->get();
        $resultats = [];

        foreach ($candidats as $c) {
            $epreuve = $c->epreuve;
            if (! $epreuve) {
                continue;
            }

            $etudiantNom = $c->etudiant ? "{$c->etudiant->nom} {$c->etudiant->prenom} ({$c->etudiant->matricule})" : "Candidat #{$c->id}";
            $perimetre = $epreuve->matiere?->nom ?? $epreuve->code ?? "Épreuve #{$epreuve->id}";

            $resultats[] = [
                'id' => md5("candidat_sans_note_{$c->id}_{$epreuve->id}"),
                'type' => 'candidat_sans_note',
                'gravite' => in_array($epreuve->statut, ['completed', 'locked'], true) ? 'bloquante' : 'avertissement',
                'libelle' => 'Candidat présent sans note saisie',
                'perimetre' => $perimetre,
                'etudiant_ref' => $etudiantNom,
                'epreuve_id' => $epreuve->id,
                'etudiant_id' => $c->etudiant_id,
                'candidat_id' => $c->id,
                'note_epreuve_id' => null,
                'date_detection' => now()->toIso8601String(),
                'action_url' => route('admin.evaluations.notes.saisie', $epreuve),
                'action_type' => 'saisie',
                'resoluble_automatiquement' => false,
            ];
        }

        return $resultats;
    }

    /** @return array<int, array<string, mixed>> */
    private function detecterNotesHorsBornes(?int $faculteId, array $filtres): array
    {
        $query = NoteEpreuve::with(['etudiant', 'epreuve.matiere'])
            ->whereNotNull('valeur')
            ->where(function ($q) {
                $q->where('valeur', '<', 0)
                    ->orWhereRaw('valeur > note_max')
                    ->orWhere('valeur', '>', 20)
                    ->orWhereHas('epreuve', fn ($e) => $e->whereColumn('evaluation_notes.valeur', '>', 'evaluation_epreuves.bareme'));
            });

        if ($faculteId !== null) {
            $query->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId));
        }

        if (! empty($filtres['epreuve_id'])) {
            $query->where('epreuve_id', (int) $filtres['epreuve_id']);
        }

        if (! empty($filtres['session_id'])) {
            $query->whereHas('epreuve', fn ($e) => $e->where('session_id', (int) $filtres['session_id']));
        }

        $notes = $query->limit(200)->get();
        $resultats = [];

        foreach ($notes as $n) {
            $epreuve = $n->epreuve;
            $etudiantNom = $n->etudiant ? "{$n->etudiant->nom} {$n->etudiant->prenom} ({$n->etudiant->matricule})" : "Étudiant #{$n->etudiant_id}";
            $perimetre = $epreuve?->matiere?->nom ?? $epreuve?->code ?? "Épreuve #{$n->epreuve_id}";

            $resultats[] = [
                'id' => md5("note_hors_bornes_{$n->id}"),
                'type' => 'note_hors_bornes',
                'gravite' => 'bloquante',
                'libelle' => "Note {$n->valeur} hors barème (barème maximum : {$n->note_max})",
                'perimetre' => $perimetre,
                'etudiant_ref' => $etudiantNom,
                'epreuve_id' => $n->epreuve_id,
                'etudiant_id' => $n->etudiant_id,
                'candidat_id' => $n->candidat_id,
                'note_epreuve_id' => $n->id,
                'date_detection' => now()->toIso8601String(),
                'action_url' => $epreuve ? route('admin.evaluations.notes.saisie', $epreuve) : '#',
                'action_type' => 'correction',
                'resoluble_automatiquement' => false,
            ];
        }

        return $resultats;
    }

    /** @return array<int, array<string, mixed>> */
    private function detecterNotesEnAttente(?int $faculteId, array $filtres): array
    {
        $query = NoteEpreuve::with(['etudiant', 'epreuve.matiere'])
            ->where('statut', 'submitted');

        if ($faculteId !== null) {
            $query->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId));
        }

        if (! empty($filtres['epreuve_id'])) {
            $query->where('epreuve_id', (int) $filtres['epreuve_id']);
        }

        if (! empty($filtres['session_id'])) {
            $query->whereHas('epreuve', fn ($e) => $e->where('session_id', (int) $filtres['session_id']));
        }

        $notes = $query->limit(200)->get();
        $resultats = [];

        foreach ($notes as $n) {
            $epreuve = $n->epreuve;
            $etudiantNom = $n->etudiant ? "{$n->etudiant->nom} {$n->etudiant->prenom} ({$n->etudiant->matricule})" : "Étudiant #{$n->etudiant_id}";
            $perimetre = $epreuve?->matiere?->nom ?? $epreuve?->code ?? "Épreuve #{$n->epreuve_id}";

            $resultats[] = [
                'id' => md5("note_en_attente_{$n->id}"),
                'type' => 'note_en_attente',
                'gravite' => 'avertissement',
                'libelle' => "Note soumise par l'enseignant en attente de validation officielle",
                'perimetre' => $perimetre,
                'etudiant_ref' => $etudiantNom,
                'epreuve_id' => $n->epreuve_id,
                'etudiant_id' => $n->etudiant_id,
                'candidat_id' => $n->candidat_id,
                'note_epreuve_id' => $n->id,
                'date_detection' => now()->toIso8601String(),
                'action_url' => $epreuve ? route('admin.evaluations.notes.saisie', $epreuve) : '#',
                'action_type' => 'validation',
                'resoluble_automatiquement' => true,
            ];
        }

        return $resultats;
    }

    /** @return array<int, array<string, mixed>> */
    private function detecterNotesDesynchronisees(?int $faculteId, array $filtres): array
    {
        $query = NoteEpreuve::with(['etudiant', 'epreuve.matiere', 'noteOfficielle'])
            ->where('statut', 'validated')
            ->where(function ($q) {
                $q->whereNull('note_id')
                    ->orWhereHas('noteOfficielle', function ($no) {
                        $no->whereRaw('notes.note != evaluation_notes.valeur');
                    });
            });

        if ($faculteId !== null) {
            $query->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId));
        }

        if (! empty($filtres['epreuve_id'])) {
            $query->where('epreuve_id', (int) $filtres['epreuve_id']);
        }

        if (! empty($filtres['session_id'])) {
            $query->whereHas('epreuve', fn ($e) => $e->where('session_id', (int) $filtres['session_id']));
        }

        $notes = $query->limit(200)->get();
        $resultats = [];

        foreach ($notes as $n) {
            $epreuve = $n->epreuve;
            $etudiantNom = $n->etudiant ? "{$n->etudiant->nom} {$n->etudiant->prenom} ({$n->etudiant->matricule})" : "Étudiant #{$n->etudiant_id}";
            $perimetre = $epreuve?->matiere?->nom ?? $epreuve?->code ?? "Épreuve #{$n->epreuve_id}";

            $resultats[] = [
                'id' => md5("note_desynchronisee_{$n->id}"),
                'type' => 'note_desynchronisee',
                'gravite' => 'bloquante',
                'libelle' => "Note validée ({$n->valeur}) non synchronisée avec le relevé officiel",
                'perimetre' => $perimetre,
                'etudiant_ref' => $etudiantNom,
                'epreuve_id' => $n->epreuve_id,
                'etudiant_id' => $n->etudiant_id,
                'candidat_id' => $n->candidat_id,
                'note_epreuve_id' => $n->id,
                'date_detection' => now()->toIso8601String(),
                'action_url' => $epreuve ? route('admin.evaluations.notes.saisie', $epreuve) : '#',
                'action_type' => 'synchronisation',
                'resoluble_automatiquement' => true,
            ];
        }

        return $resultats;
    }

    /** @return array<int, array<string, mixed>> */
    private function detecterAbsentsAvecNote(?int $faculteId, array $filtres): array
    {
        $query = Candidat::with(['etudiant', 'epreuve.matiere', 'note'])
            ->where('statut', 'absent')
            ->whereHas('note', fn ($q) => $q->whereNotNull('valeur'));

        $this->appliquerFiltresEpreuve($query, $faculteId, $filtres);

        $candidats = $query->limit(200)->get();
        $resultats = [];

        foreach ($candidats as $c) {
            $epreuve = $c->epreuve;
            $note = $c->note;
            $etudiantNom = $c->etudiant ? "{$c->etudiant->nom} {$c->etudiant->prenom} ({$c->etudiant->matricule})" : "Candidat #{$c->id}";
            $perimetre = $epreuve?->matiere?->nom ?? $epreuve?->code ?? "Épreuve #{$c->epreuve_id}";

            $resultats[] = [
                'id' => md5("absent_avec_note_{$c->id}"),
                'type' => 'absent_avec_note',
                'gravite' => 'bloquante',
                'libelle' => "Candidat déclaré absent avec note saisie ({$note?->valeur})",
                'perimetre' => $perimetre,
                'etudiant_ref' => $etudiantNom,
                'epreuve_id' => $c->epreuve_id,
                'etudiant_id' => $c->etudiant_id,
                'candidat_id' => $c->id,
                'note_epreuve_id' => $note?->id,
                'date_detection' => now()->toIso8601String(),
                'action_url' => $epreuve ? route('admin.evaluations.notes.saisie', $epreuve) : '#',
                'action_type' => 'correction',
                'resoluble_automatiquement' => false,
            ];
        }

        return $resultats;
    }

    /** @return array<int, array<string, mixed>> */
    private function detecterCoefficientsIncoherents(?int $faculteId, array $filtres): array
    {
        $query = Epreuve::with(['matiere', 'session'])
            ->where(function ($q) {
                $q->whereNull('coefficient')
                    ->orWhere('coefficient', '<=', 0);
            })
            ->where('statut', '!=', 'cancelled');

        if ($faculteId !== null) {
            $query->pourFaculteId($faculteId);
        }

        if (! empty($filtres['epreuve_id'])) {
            $query->where('id', (int) $filtres['epreuve_id']);
        }

        if (! empty($filtres['session_id'])) {
            $query->where('session_id', (int) $filtres['session_id']);
        }

        $epreuves = $query->limit(100)->get();
        $resultats = [];

        foreach ($epreuves as $e) {
            $perimetre = $e->matiere?->nom ?? $e->code ?? "Épreuve #{$e->id}";

            $resultats[] = [
                'id' => md5("coefficient_incoherent_{$e->id}"),
                'type' => 'coefficient_incoherent',
                'gravite' => 'avertissement',
                'libelle' => "Coefficient d'épreuve nul ou non défini (coefficient actuel : ".($e->coefficient ?? 'null').')',
                'perimetre' => $perimetre,
                'etudiant_ref' => null,
                'epreuve_id' => $e->id,
                'etudiant_id' => null,
                'candidat_id' => null,
                'note_epreuve_id' => null,
                'date_detection' => now()->toIso8601String(),
                'action_url' => route('admin.evaluations.epreuves.show', $e),
                'action_type' => 'correction',
                'resoluble_automatiquement' => false,
            ];
        }

        return $resultats;
    }

    /** Applique les filtres de périmètre faculté et session sur une requête liée à une épreuve. */
    private function appliquerFiltresEpreuve($query, ?int $faculteId, array $filtres): void
    {
        if ($faculteId !== null) {
            $query->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId));
        }

        if (! empty($filtres['epreuve_id'])) {
            $query->where('epreuve_id', (int) $filtres['epreuve_id']);
        }

        if (! empty($filtres['session_id'])) {
            $query->whereHas('epreuve', fn ($e) => $e->where('session_id', (int) $filtres['session_id']));
        }
    }
}
