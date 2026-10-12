<?php

namespace App\Services\Io;

use App\Models\Note;

/**
 * Export détaillé des notes — filtres multicritères (§70) : faculté,
 * filière, année, niveau, semestre, groupe, matière, session, intervalles
 * de notes, statuts, identification individuelle. Toutes les valeurs
 * passent par la whitelist ExportFilters (§84) — CIN incluse uniquement
 * pour le rapprochement (export scolarité autorisé, masquée sinon).
 */
class GradesExporter
{
    /**
     * @param  array<string, mixed>  $filtres  clés épurées (ExportFilters::epurer)
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    public function exporter(array $filtres, ?int $limite = null): array
    {
        $entetes = ['matricule', 'cin', 'nom', 'prenom', 'groupe', 'annee', 'niveau', 'semestre', 'matiere', 'note', 'coefficient', 'credits', 'session'];

        $query = Note::query()
            ->with([
                'etudiant.dossierAcademique',
                'inscriptionAcademique.anneeUniversitaire',
                'semestre',
                'matiere',
            ])
            ->orderBy('matiere_id');

        $this->appliquerFiltres($query, $filtres);

        $lignes = [];
        foreach ($limite !== null ? $query->limit($limite)->get() : $query->get() as $note) {
            $lignes[] = [
                'matricule' => $note->etudiant->dossierAcademique?->matricule ?? '',
                'cin' => $note->etudiant->cin,
                'nom' => $note->etudiant->nom,
                'prenom' => $note->etudiant->prenom,
                'groupe' => $note->inscriptionAcademique->groupe ?? '',
                'annee' => $note->inscriptionAcademique->anneeUniversitaire->libelle,
                'niveau' => $note->inscriptionAcademique->niveau,
                'semestre' => 'S'.$note->semestre->numero,
                'matiere' => $note->matiere->code,
                'note' => $note->note,
                'coefficient' => $note->coefficient,
                'credits' => $note->credits,
                'session' => $note->session,
            ];
        }

        return compact('entetes', 'lignes');
    }

    /**
     * Filtres multicritères — liste blanche stricte (§84).
     */
    private function appliquerFiltres($query, array $filtres): void
    {
        if (! empty($filtres['faculte'])) {
            $query->whereHas('etudiant.faculte', fn ($q) => $q->where('code', mb_strtoupper($filtres['faculte'])));
        }
        if (! empty($filtres['filiere'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('filiere', 'like', '%'.trim($filtres['filiere']).'%'));
        }
        if (! empty($filtres['groupe'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('groupe', $filtres['groupe']));
        }
        if (! empty($filtres['annee'])) {
            $query->whereHas('inscriptionAcademique.anneeUniversitaire', fn ($q) => $q->where('libelle', $filtres['annee']));
        }
        if (! empty($filtres['niveau'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('niveau', $filtres['niveau']));
        }
        if (! empty($filtres['semestre'])) {
            $query->whereHas('semestre', fn ($q) => $q->where('numero', (int) $filtres['semestre']));
        }
        if (! empty($filtres['matiere'])) {
            $query->whereHas('matiere', fn ($q) => $q->where('code', mb_strtoupper($filtres['matiere'])));
        }
        if (! empty($filtres['session']) && $filtres['session'] !== 'toutes') {
            $query->where('session', $filtres['session']);
        }
        if (! empty($filtres['statut'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('statut', $filtres['statut']));
        }
        if (! empty($filtres['statut_academique'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('statut', $filtres['statut_academique']));
        }
        if (! empty($filtres['decision'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('decision', 'like', match ($filtres['decision']) {
                'admis' => 'Admis%',
                'ajourne' => 'Ajourné%',
                'rattrapage' => '%rattrapage%',
                default => '%',
            }));
        }
        if (! empty($filtres['moyenne_min'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('moyenne_annuelle', '>=', $filtres['moyenne_min']));
        }
        if (! empty($filtres['moyenne_max'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('moyenne_annuelle', '<=', $filtres['moyenne_max']));
        }
        if (! empty($filtres['note_min'])) {
            $query->where('note', '>=', $filtres['note_min']);
        }
        if (! empty($filtres['note_max'])) {
            $query->where('note', '<=', $filtres['note_max']);
        }
        if (! empty($filtres['cin'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('cin', mb_strtoupper($filtres['cin'])));
        }
        if (! empty($filtres['nom'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('nom', 'like', '%'.trim($filtres['nom']).'%'));
        }
        if (! empty($filtres['prenom'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('prenom', 'like', '%'.trim($filtres['prenom']).'%'));
        }
        if (! empty($filtres['num_inscription'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('num_inscription', $filtres['num_inscription']));
        }
        if (! empty($filtres['matricule'])) {
            $query->whereHas('etudiant.dossierAcademique', fn ($q) => $q->where('matricule', $filtres['matricule']));
        }
    }
}
