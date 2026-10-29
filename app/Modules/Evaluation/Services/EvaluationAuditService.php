<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\NoteEpreuveHistorique;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Service d'audit, historique et comparaison différentielle des notes (LOT 6)
 *
 * Responsabilités :
 * - Traçabilité fine des modifications (qui, quand, pourquoi, motif obligatoire)
 * - Comparaison différentielle avant/après (valeurs, écarts, statuts)
 * - Détection des modifications sensibles postérieures à une validation ou publication
 * - Vérification de la cohérence avec la note officielle (table `notes`)
 * - Respect strict du périmètre faculté et préservation de l'anonymat lors des examens anonymes
 */
class EvaluationAuditService
{
    /**
     * Journal global paginé des modifications de notes avec filtres
     *
     * @param array{
     *     session_id?: int,
     *     epreuve_id?: int,
     *     etudiant_id?: int,
     *     auteur_id?: int,
     *     recherche?: string,
     *     date_debut?: string,
     *     date_fin?: string
     * } $filtres
     */
    public function journalGlobal(array $filtres, User $utilisateur, int $parPage = 25): LengthAwarePaginator
    {
        $query = NoteEpreuveHistorique::query()
            ->with([
                'auteur',
                'note.epreuve.matiere',
                'note.epreuve.session',
                'note.epreuve.groupe',
                'note.etudiant',
            ])
            ->orderByDesc('id');

        // Isolation faculté
        $faculteId = $utilisateur->faculteRestreinteId();
        if ($faculteId !== null) {
            $query->whereHas('note.epreuve', function (Builder $b) use ($faculteId) {
                $b->whereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId))
                    ->orWhereHas('matiere.ue.programme', fn ($p) => $p->where('faculte_id', $faculteId));
            });
        }

        // Filtres applicatifs
        if (! empty($filtres['session_id'])) {
            $query->whereHas('note.epreuve', fn ($b) => $b->where('session_id', $filtres['session_id']));
        }
        if (! empty($filtres['epreuve_id'])) {
            $query->whereHas('note', fn ($b) => $b->where('epreuve_id', $filtres['epreuve_id']));
        }
        if (! empty($filtres['etudiant_id'])) {
            $query->whereHas('note', fn ($b) => $b->where('etudiant_id', $filtres['etudiant_id']));
        }
        if (! empty($filtres['auteur_id'])) {
            $query->where('auteur_id', $filtres['auteur_id']);
        }
        if (! empty($filtres['date_debut'])) {
            $query->whereDate('created_at', '>=', $filtres['date_debut']);
        }
        if (! empty($filtres['date_fin'])) {
            $query->whereDate('created_at', '<=', $filtres['date_fin']);
        }
        if (! empty($filtres['recherche'])) {
            $recherche = trim($filtres['recherche']);
            $query->where(function (Builder $b) use ($recherche) {
                $b->where('motif', 'like', "%{$recherche}%")
                    ->orWhereHas('note.etudiant', function ($q) use ($recherche) {
                        $q->where('nom', 'like', "%{$recherche}%")
                            ->orWhere('prenom', 'like', "%{$recherche}%")
                            ->orWhere('num_inscription', 'like', "%{$recherche}%");
                    });
            });
        }

        return $query->paginate($parPage);
    }

    /**
     * Timeline complète et analyse différentielle pour une note spécifique
     *
     * @return array{
     *     note: NoteEpreuve,
     *     note_officielle: ?Note,
     *     historique: Collection,
     *     total_modifications: int,
     *     a_ete_modifiee_apres_validation: bool,
     *     statut_synchronisation: string
     * }
     */
    public function historiqueNote(NoteEpreuve $note, User $utilisateur): array
    {
        $this->verifierPerimetreNote($note, $utilisateur);

        $note->loadMissing([
            'epreuve.matiere',
            'epreuve.session',
            'epreuve.groupe',
            'etudiant',
            'saisiPar',
            'validePar',
            'historique.auteur',
        ]);

        $historique = $note->historique->sortByDesc('created_at')->values();

        // Calcul des différentiels enrichis
        $historiqueEnrichi = $historique->map(function (NoteEpreuveHistorique $item) {
            $ancienne = $item->ancienne_valeur !== null ? (float) $item->ancienne_valeur : null;
            $nouvelle = $item->nouvelle_valeur !== null ? (float) $item->nouvelle_valeur : null;
            $ecart = ($ancienne !== null && $nouvelle !== null) ? round($nouvelle - $ancienne, 2) : null;

            return [
                'id' => $item->id,
                'created_at' => $item->created_at,
                'auteur' => $item->auteur?->name ?? 'Système',
                'motif' => $item->motif,
                'ancienne_valeur' => $ancienne,
                'nouvelle_valeur' => $nouvelle,
                'ecart' => $ecart,
                'type_changement' => $ancienne === null ? 'creation' : ($nouvelle === null ? 'suppression' : 'modification'),
            ];
        });

        // Vérification synchronisation avec la source officielle centrale
        $noteOfficielle = null;
        $statutSynchro = 'non_applicable';
        if ($note->note_id !== null) {
            $noteOfficielle = Note::find($note->note_id);
            if ($noteOfficielle !== null) {
                $statutSynchro = ((string) $noteOfficielle->valeur === (string) $note->valeur)
                    ? 'synchronisee'
                    : 'desynchronisee';
            }
        } elseif ($note->statut === 'validated') {
            $statutSynchro = 'en_attente_synchro';
        }

        // LOT 05 — détection robuste par horodatage : une VRAIE modification
        // de valeur (ancienne_valeur renseignée) postérieure STRICTEMENT à la
        // dernière validation (`valide_at`, stampée par SaisieNoteService) est
        // signalée, quel que soit le motif texte (« Saisie (web) », « Saisie
        // anonyme NNN »…). Aucune analyse de chaîne : comparaison temporelle
        // stricte. Le retour en `entered` (F-01) conserve `valide_at` :
        // drapeau actif jusqu'à la revalidation, qui le réinitialise.
        // Fail-soft : colonne absente (migration non appliquée) ⇒ drapeau
        // inactif, comme AVANT ce lot (jamais d'erreur SQL).
        $valideAt = NoteEpreuve::existeColonneValideAt() ? $note->valide_at : null;
        $aEteModifieeApresValidation = $valideAt !== null
            && $historique->contains(function (NoteEpreuveHistorique $item) use ($valideAt): bool {
                return $item->ancienne_valeur !== null
                    && $item->created_at !== null
                    && $item->created_at->gt($valideAt);
            });

        return [
            'note' => $note,
            'note_officielle' => $noteOfficielle,
            'historique' => $historiqueEnrichi,
            'total_modifications' => $historique->count(),
            'a_ete_modifiee_apres_validation' => $aEteModifieeApresValidation,
            'statut_synchronisation' => $statutSynchro,
        ];
    }

    /**
     * Comparaison différentielle stricte entre deux révisions
     *
     * @return array{
     *     version_a: array,
     *     version_b: array,
     *     ecart_valeur: ?float,
     *     delai_secondes: ?int
     * }
     */
    public function comparerVersions(NoteEpreuve $note, int $versionAId, int $versionBId, User $utilisateur): array
    {
        $this->verifierPerimetreNote($note, $utilisateur);

        $versionA = NoteEpreuveHistorique::with('auteur')->where('evaluation_note_id', $note->id)->findOrFail($versionAId);
        $versionB = NoteEpreuveHistorique::with('auteur')->where('evaluation_note_id', $note->id)->findOrFail($versionBId);

        $valA = $versionA->nouvelle_valeur !== null ? (float) $versionA->nouvelle_valeur : null;
        $valB = $versionB->nouvelle_valeur !== null ? (float) $versionB->nouvelle_valeur : null;

        $ecart = ($valA !== null && $valB !== null) ? round($valB - $valA, 2) : null;
        $delai = ($versionA->created_at && $versionB->created_at)
            ? abs($versionB->created_at->diffInSeconds($versionA->created_at))
            : null;

        return [
            'version_a' => [
                'id' => $versionA->id,
                'valeur' => $valA,
                'motif' => $versionA->motif,
                'auteur' => $versionA->auteur?->name ?? 'Système',
                'date' => $versionA->created_at,
            ],
            'version_b' => [
                'id' => $versionB->id,
                'valeur' => $valB,
                'motif' => $versionB->motif,
                'auteur' => $versionB->auteur?->name ?? 'Système',
                'date' => $versionB->created_at,
            ],
            'ecart_valeur' => $ecart,
            'delai_secondes' => $delai,
        ];
    }

    /**
     * Statistiques globales d'audit pour une épreuve
     *
     * @return array{
     *     total_modifications: int,
     *     notes_modifiees_count: int,
     *     auteurs_distincts: int,
     *     motifs_frequents: array<string, int>
     * }
     */
    public function statistiquesEpreuve(Epreuve $epreuve, User $utilisateur): array
    {
        $faculteId = $utilisateur->faculteRestreinteId();
        if ($faculteId !== null) {
            $epreuve->loadMissing(['groupe.faculte', 'matiere.ue.programme.faculte']);
            $faculteEpreuve = $epreuve->groupe?->faculte_id ?? $epreuve->matiere?->ue?->programme?->faculte_id;
            if ($faculteEpreuve !== null && (int) $faculteEpreuve !== (int) $faculteId) {
                throw new DomainException('Accès refusé : épreuve hors périmètre faculté.');
            }
        }

        $historiques = NoteEpreuveHistorique::query()
            ->whereHas('note', fn ($b) => $b->where('epreuve_id', $epreuve->id))
            ->get();

        $motifs = [];
        foreach ($historiques as $hist) {
            $m = trim($hist->motif);
            $motifs[$m] = ($motifs[$m] ?? 0) + 1;
        }
        arsort($motifs);

        return [
            'total_modifications' => $historiques->count(),
            'notes_modifiees_count' => $historiques->pluck('evaluation_note_id')->unique()->count(),
            'auteurs_distincts' => $historiques->pluck('auteur_id')->filter()->unique()->count(),
            'motifs_frequents' => array_slice($motifs, 0, 5, true),
        ];
    }

    private function verifierPerimetreNote(NoteEpreuve $note, User $utilisateur): void
    {
        $faculteId = $utilisateur->faculteRestreinteId();
        if ($faculteId === null) {
            return;
        }

        $note->loadMissing(['epreuve.groupe.faculte', 'epreuve.matiere.ue.programme.faculte', 'etudiant.faculte']);
        $faculteNote = $note->epreuve?->groupe?->faculte_id
            ?? $note->epreuve?->matiere?->ue?->programme?->faculte_id
            ?? $note->etudiant?->faculte_id;

        if ($faculteNote !== null && (int) $faculteNote !== (int) $faculteId) {
            throw new DomainException('Accès refusé : note hors périmètre faculté.');
        }
    }
}
