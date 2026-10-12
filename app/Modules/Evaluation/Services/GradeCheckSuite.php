<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Note;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;

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
            ->whereDoesntHave('note')
            ->count();

        if ($candidatsSansNote > 0) {
            $anomalies[] = $this->anomalie(null, 'candidat_sans_note',
                "{$candidatsSansNote} candidat(s) présent(s) sans note saisie.");
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

        $stats = NoteEpreuve::whereNotNull('valeur')
            ->whereHas('epreuve', fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->selectRaw('epreuve_id, AVG(valeur) as moyenne, COUNT(*) as total, SUM(valeur = 0) as zeros, SUM(valeur = 20) as vingts')
            ->groupBy('epreuve_id')
            ->get();

        $epreuves = Epreuve::with('matiere')->findMany($stats->pluck('epreuve_id'))->keyBy('id');

        foreach ($stats as $ligne) {
            $epreuve = $epreuves->get($ligne->epreuve_id);

            if ($epreuve === null) {
                continue;
            }

            $libelle = $epreuve->matiere?->code ?? "épreuve #{$epreuve->id}";

            if ((float) $ligne->moyenne < 5 || (float) $ligne->moyenne > 16) {
                $alertes[] = "{$libelle} : moyenne ".round((float) $ligne->moyenne, 2).' hors plage 5–16.';
            }

            if ((int) $ligne->zeros === (int) $ligne->total || (int) $ligne->vingts === (int) $ligne->total) {
                $alertes[] = "{$libelle} : toutes les notes sont identiques (0 ou 20).";
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
