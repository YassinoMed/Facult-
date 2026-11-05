<?php

namespace App\Services\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GESTIONNAIRE DE L'HISTORIQUE DES TENTATIVES (LOOP 19).
 *
 * Conserve séparément chaque tentative d'examen de l'étudiant sans jamais écraser
 * les anciennes évaluations. Détermine le résultat définitif selon le régime du diplôme
 * avec justification explicite.
 */
class HistoriqueTentativesService
{
    /**
     * Enregistre une nouvelle tentative sans jamais écraser les précédentes.
     */
    public function enregistrerTentative(
        Etudiant $etudiant,
        Matiere $matiere,
        float $note,
        string $session,
        ?int $anneeId = null,
        ?string $observation = null,
        ?int $auteurId = null
    ): Note {
        if ($note < 0.0 || $note > 20.0) {
            throw new DomainException("Note invalide : {$note}/20. La note doit être comprise entre 0 et 20.");
        }

        $sessionNormalisee = strtolower($session);
        if (! in_array($sessionNormalisee, ['normale', 'principale', 'rattrapage'], true)) {
            $sessionNormalisee = 'normale';
        }

        $anneeUniversitaire = $anneeId !== null
            ? AnneeUniversitaire::find($anneeId)
            : AnneeUniversitaire::orderByDesc('date_debut')->first();

        return DB::transaction(function () use ($etudiant, $matiere, $note, $sessionNormalisee, $anneeUniversitaire, $observation, $auteurId) {
            $userValide = $auteurId !== null && User::where('id', $auteurId)->exists();
            $auteurFinal = $userValide ? $auteurId : null;

            // Création de l'enregistrement de note (append-only)
            $nouvelleNote = Note::create([
                'etudiant_id' => $etudiant->id,
                'inscription_academique_id' => $etudiant->inscriptionsAcademiques()->latest('id')->value('id'),
                'semestre_id' => $matiere->ue?->semestre_id,
                'matiere_id' => $matiere->id,
                'note' => $note,
                'coefficient' => $matiere->coefficient ?? 1.0,
                'credits' => $matiere->credits ?? 0,
                'session' => in_array($sessionNormalisee, ['normale', 'principale'], true) ? 'normale' : 'rattrapage',
                'observation' => $observation ?? "Nouvelle tentative enregistrée ({$sessionNormalisee})",
                'locked_at' => now(),
            ]);

            // Historisation immuable dans HistoriqueAcademique
            HistoriqueAcademique::create([
                'etudiant_id' => $etudiant->id,
                'utilisateur_id' => $auteurFinal,
                'action' => 'nouvelle_tentative_examen',
                'entite' => 'notes',
                'entite_id' => $nouvelleNote->id,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => json_encode([
                    'matiere_id' => $matiere->id,
                    'matiere_code' => $matiere->code,
                    'matiere_nom' => $matiere->nom,
                    'note' => $note,
                    'session' => $sessionNormalisee,
                    'annee' => $anneeUniversitaire?->libelle,
                    'observation' => $observation,
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'evaluations',
            ]);

            return $nouvelleNote;
        });
    }

    /**
     * Récupère l'historique complet et ordonné des tentatives pour un étudiant et une matière.
     *
     * @return Collection<int, Note>
     */
    public function recupererHistoriqueTentatives(Etudiant $etudiant, ?int $matiereId = null): Collection
    {
        return Note::where('etudiant_id', $etudiant->id)
            ->when($matiereId !== null, fn ($q) => $q->where('matiere_id', $matiereId))
            ->with(['matiere.ue', 'inscriptionAcademique.anneeUniversitaire'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Détermine la note retenue pour une matière selon le régime du diplôme.
     *
     * @param  array<string, mixed>  $regime
     * @return array{
     *     note_retenue: ?float,
     *     session_retenue: ?string,
     *     regle_appliquee: string,
     *     justification: string,
     *     historique: array
     * }
     */
    public function determinerNoteRetenue(Etudiant $etudiant, Matiere $matiere, array $regime): array
    {
        $tentatives = $this->recupererHistoriqueTentatives($etudiant, $matiere->id);

        if ($tentatives->isEmpty()) {
            return [
                'note_retenue' => null,
                'session_retenue' => null,
                'regle_appliquee' => 'Aucune tentative',
                'justification' => 'L\'étudiant ne s\'est jamais présenté à cette matière.',
                'historique' => [],
            ];
        }

        $regleRattrapage = $regime['regle_calcul_rattrapage'] ?? 'MEILLEURE_NOTE';

        $derniereTentativeNormale = $tentatives->filter(fn ($t) => in_array($t->session, ['normale', 'principale'], true))->last();
        $derniereTentativeRattrapage = $tentatives->filter(fn ($t) => $t->session === 'rattrapage')->last();

        $noteNormale = $derniereTentativeNormale ? (float) $derniereTentativeNormale->note : null;
        $noteRattrapage = $derniereTentativeRattrapage ? (float) $derniereTentativeRattrapage->note : null;

        if ($noteRattrapage === null) {
            $noteRetenue = $noteNormale;
            $sessionRetenue = 'normale';
            $regleAppliquee = 'Session principale unique';
            $justification = "Seule la note de session principale ({$noteNormale}/20) a été présentée.";
        } elseif ($noteNormale === null) {
            $noteRetenue = $noteRattrapage;
            $sessionRetenue = 'rattrapage';
            $regleAppliquee = 'Session de rattrapage unique';
            $justification = "Seule la note de session de rattrapage ({$noteRattrapage}/20) est enregistrée.";
        } else {
            // Deux notes disponibles : application de la règle du régime
            if ($regleRattrapage === 'MEILLEURE_NOTE') {
                if ($noteRattrapage >= $noteNormale) {
                    $noteRetenue = $noteRattrapage;
                    $sessionRetenue = 'rattrapage';
                } else {
                    $noteRetenue = $noteNormale;
                    $sessionRetenue = 'normale';
                }
                $regleAppliquee = 'Arrêté du 7 juillet 2023 - Art. 14 (Règle de la meilleure note)';
                $justification = "Application de la meilleure note entre la principale ({$noteNormale}) et le rattrapage ({$noteRattrapage}) : {$noteRetenue}/20 retenue.";
            } else {
                // Règle de substitution (note de rattrapage prévaut)
                $noteRetenue = $noteRattrapage;
                $sessionRetenue = 'rattrapage';
                $regleAppliquee = 'Régime d\'examen - Substitution par la note de rattrapage';
                $justification = "La note de la session de rattrapage ({$noteRattrapage}/20) se substitue à la note initiale ({$noteNormale}/20).";
            }
        }

        $historiqueFormate = $tentatives->map(fn ($t) => [
            'id' => $t->id,
            'session' => $t->session,
            'note' => (float) $t->note,
            'date' => $t->created_at?->format('d/m/Y H:i'),
            'observation' => $t->observation,
        ])->all();

        return [
            'note_retenue' => $noteRetenue,
            'session_retenue' => $sessionRetenue,
            'regle_appliquee' => $regleAppliquee,
            'justification' => $justification,
            'historique' => $historiqueFormate,
        ];
    }
}
