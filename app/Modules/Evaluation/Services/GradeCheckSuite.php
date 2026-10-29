<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Note;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use Illuminate\Support\Facades\DB;

/**
 * Suite de contrôles des notes (Phase 14 §14.4) : anomalies
 * NON bloquantes (sévérité + action corrective), fusionnées au
 * snapshot de préparation sans bloquer le jury — seuls les types
 * `note_non_validee`/`sans_note` du compilateur restent bloquants.
 * Requêtes agrégées, jamais de N+1.
 *
 * @return array<int, array{etudiant_id: ?int, type: string, bloquant: bool, message: string}>
 */
class GradeCheckSuite
{
    public function controler(Deliberation $deliberation): array
    {
        $anneeId = $deliberation->annee_universitaire_id;
        $anomalies = [];

        $horsBornes = Note::whereHas('inscriptionAcademique', fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->where(fn ($q) => $q->where('note', '<', 0)->orWhere('note', '>', 20))
            ->count();

        if ($horsBornes > 0) {
            $anomalies[] = $this->anomalie(null, 'note_hors_bornes',
                "{$horsBornes} note(s) hors bornes 0–20 : corrigez avant de délibérer.");
        }

        $sansCoefficient = Note::whereHas('inscriptionAcademique', fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->where('coefficient', '<=', 0)
            ->count();

        if ($sansCoefficient > 0) {
            $anomalies[] = $this->anomalie(null, 'coefficient_nul',
                "{$sansCoefficient} note(s) à coefficient nul : pondération à vérifier.");
        }

        $candidatsSansNote = Candidat::where('statut', 'present')
            ->whereHas('epreuve', fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('evaluation_notes')
                    ->where(function ($sub) {
                        $sub->whereColumn('evaluation_notes.candidat_id', 'evaluation_candidats.id')
                            ->orWhere(function ($sub2) {
                                $sub2->whereColumn('evaluation_notes.epreuve_id', 'evaluation_candidats.epreuve_id')
                                    ->whereColumn('evaluation_notes.etudiant_id', 'evaluation_candidats.etudiant_id');
                            });
                    });
            })
            ->count();

        if ($candidatsSansNote > 0) {
            $anomalies[] = $this->anomalie(null, 'candidat_sans_note',
                "{$candidatsSansNote} candidat(s) présent(s) sans note saisie.");
        }

        // Barème libre (note_max) : une note d'épreuve au-delà du
        // maximum autorisé est une vraie anomalie, quel que soit le
        // barème (lot 2 — le CHECK 0..20 ne couvre que `notes`).
        $horsBareme = NoteEpreuve::whereNotNull('valeur')
            ->whereHas('epreuve', fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->where(fn ($q) => $q
                ->whereColumn('valeur', '<', 0)
                ->orWhereColumn('valeur', '>', 'note_max'))
            ->count();

        if ($horsBareme > 0) {
            $anomalies[] = $this->anomalie(null, 'note_epreuve_hors_bareme',
                "{$horsBareme} note(s) d'épreuve hors barème (0..note_max) : corrigez avant publication.");
        }

        foreach ($this->epreuvesAnormales($anneeId) as $alerte) {
            $anomalies[] = $this->anomalie(null, 'moyenne_epreuve_anormale', $alerte);
        }

        foreach ($this->ecartsSession($anneeId) as $alerte) {
            $anomalies[] = $this->anomalie(null, 'ecart_session', $alerte);
        }

        return $anomalies;
    }

    /** @return array<int, string> */
    private function epreuvesAnormales(int $anneeId): array
    {
        $alertes = [];

        // Barème libre (lot 2) : la moyenne est jugée en RATIO du
        // note_max de l'épreuve (25–80 %), plus de plage 5–16 codée
        // en dur qui générait des faux positifs hors barème /20.
        $stats = NoteEpreuve::query()
            ->join('evaluation_epreuves as epreuve', 'epreuve.id', '=', 'evaluation_notes.epreuve_id')
            ->whereNotNull('evaluation_notes.valeur')
            ->where('epreuve.annee_universitaire_id', $anneeId)
            ->selectRaw('evaluation_notes.epreuve_id, AVG(evaluation_notes.valeur) as moyenne, COUNT(*) as total, SUM(evaluation_notes.valeur = 0) as zeros, SUM(evaluation_notes.valeur >= epreuve.note_max) as maxs, MAX(epreuve.note_max) as note_max')
            ->groupBy('evaluation_notes.epreuve_id')
            ->get();

        $epreuveIds = $stats->pluck('epreuve_id')->filter()->values();
        $epreuves = $epreuveIds->isNotEmpty()
            ? Epreuve::with('matiere')->findMany($epreuveIds)->keyBy('id')
            : collect();

        foreach ($stats as $ligne) {
            $epreuve = $epreuves->get($ligne->epreuve_id);

            if ($epreuve === null) {
                continue;
            }

            $libelle = $epreuve->matiere?->code ?? "épreuve #{$epreuve->id}";
            $noteMax = (float) $ligne->note_max;
            $ratio = $noteMax > 0 ? (float) $ligne->moyenne / $noteMax : null;

            if ($ratio !== null && ($ratio < 0.25 || $ratio > 0.8)) {
                $alertes[] = "{$libelle} : moyenne ".round((float) $ligne->moyenne, 2)." sur {$noteMax} hors plage neutre (25–80 % du barème).";
            }

            if ((int) $ligne->zeros === (int) $ligne->total || (int) $ligne->maxs === (int) $ligne->total) {
                $alertes[] = "{$libelle} : toutes les notes sont identiques (0 ou maximum du barème).";
            }
        }

        return $alertes;
    }

    /** @return array<int, string> */
    private function ecartsSession(int $anneeId): array
    {
        $alertes = [];

        $moyennes = Note::whereHas('inscriptionAcademique', fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->selectRaw('matiere_id, session, AVG(note) as moyenne')
            ->groupBy('matiere_id', 'session')
            ->get()
            ->groupBy('matiere_id');

        foreach ($moyennes as $matiereId => $lignes) {
            $normale = $lignes->firstWhere('session', 'normale');
            $rattrapage = $lignes->firstWhere('session', 'rattrapage');

            if ($normale === null || $rattrapage === null) {
                continue;
            }

            if (abs((float) $normale->moyenne - (float) $rattrapage->moyenne) >= 5) {
                $alertes[] = "Matière #{$matiereId} : écart ≥ 5 entre session normale et rattrapage.";
            }
        }

        return $alertes;
    }

    /** @return array{etudiant_id: ?int, type: string, bloquant: bool, message: string} */
    private function anomalie(?int $etudiantId, string $type, string $message): array
    {
        return ['etudiant_id' => $etudiantId, 'type' => $type, 'bloquant' => false, 'message' => $message];
    }
}
