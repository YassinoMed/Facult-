<?php

namespace App\Services\Io\Exports;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Models\User;
use App\Services\AcademicCalculationService;
use App\Services\Io\GradesExporter;
use App\Services\Io\StudentsExporter;

/**
 * MOTEUR D'EXPORT ACADÉMIQUE MULTICRITÈRE UNIFIÉ (§90).
 *
 * Toutes les demandes — « les notes d'une matière pour un groupe »,
 * « tous les étudiants d'une faculté », « la synthèse d'une filière » —
 * passent par CE moteur unique, avec les mêmes règles de sécurité
 * (whitelist §84), d'autorisation (§85), de validation et d'audit.
 *
 * Types (§71) : students | grades | subject | group | averages | filiere | stats
 */
class AcademicExportEngine
{
    public function __construct(
        private readonly StudentsExporter $studentsExporter,
        private readonly GradesExporter $gradesExporter,
        private readonly ExportColumnPolicy $colonnes,
        private readonly AcademicCalculationService $calculs,
    ) {}

    /**
     * Construit le jeu de données pour un type et des filtres épurés.
     *
     * @param  array<string, mixed>  $filtres  clés déjà passées par ExportFilters::epurer()
     * @param  array<int, string>|null  $colonnes  colonnes personnalisées (§78)
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>, total: int}
     */
    public function construire(string $type, ?string $profil, array $filtres, ?User $utilisateur = null, ?array $colonnes = null, ?int $limite = null, ?string $granularite = null): array
    {
        $donnees = match ($type) {
            'students' => $this->finaliserStudents(
                $this->studentsExporter->exporter($profil ?? 'administratif', $filtres, $limite),
                $utilisateur, $profil ?? 'administratif', $colonnes,
            ),
            'grades' => $this->finaliserStudents(
                $this->gradesExporter->exporter($filtres, $limite),
                $utilisateur, null, $colonnes, 'grades',
            ),
            'subject' => $this->notesParMatiere($filtres, $utilisateur, $limite),
            'group' => $this->releveCollectif($filtres, $utilisateur, $limite),
            'averages' => $this->moyennes($filtres, $utilisateur, $granularite ?? 'semestre', $limite),
            'filiere' => $this->syntheseFiliere($filtres, $limite),
            'stats' => $this->statistiquesMatieres($filtres, $limite),
            default => throw new \InvalidArgumentException("Type d'export inconnu : {$type}"),
        };

        // Total du périmètre (avant toute limitation d'échantillon)
        $donnees['total'] = count($donnees['lignes']);

        return $donnees;
    }

    /* ────────────────────────────────────────────────────────────
     * §75 — Liste de notes par matière (session principale /
     * rattrapage / toutes : note validée = rattrapage remplace normale).
     * ──────────────────────────────────────────────────────────── */
    private function notesParMatiere(array $f, ?User $u, ?int $limite): array
    {
        $query = Note::query()
            ->with(['etudiant.dossierAcademique', 'inscriptionAcademique.anneeUniversitaire', 'semestre', 'matiere'])
            ->whereHas('matiere', fn ($q) => $q->where('code', mb_strtoupper($f['matiere'] ?? '')))
            ->orderBy('etudiant_id');

        $this->filtresNotes($query, $f, saufMatiere: true);

        $lignes = [];
        $notesBrutes = $limite !== null ? $query->limit($limite * 2)->get() : $query->get();

        // Session « toutes » : une ligne par étudiant avec la note VALIDÉE
        if (($f['session'] ?? 'toutes') === 'toutes') {
            $parEtudiant = $notesBrutes->groupBy('etudiant_id')->map(fn ($groupe) => $groupe->firstWhere('session', 'rattrapage')
                ?? $groupe->firstWhere('session', 'normale'));
            $notesBrutes = $parEtudiant->values();
        }

        foreach ($notesBrutes as $note) {
            $lignes[] = [
                'matricule' => $note->etudiant->dossierAcademique?->matricule ?? '',
                'nom' => $note->etudiant->nom,
                'prenom' => $note->etudiant->prenom,
                'groupe' => $note->inscriptionAcademique->groupe ?? '',
                'note' => $note->note,
                'coefficient' => $note->coefficient,
                'credits' => $note->credits,
                'session' => $note->session,
                'resultat' => (float) $note->note >= AcademicCalculationService::SEUIL_VALIDATION ? 'Validé' : 'Non validé',
            ];
        }

        return $this->colonnes->appliquer(
            ['entetes' => ['matricule', 'nom', 'prenom', 'groupe', 'note', 'coefficient', 'credits', 'session', 'resultat'], 'lignes' => $lignes],
            $u, 'subject', null,
        );
    }

    /* ────────────────────────────────────────────────────────────
     * §74 — Relevé collectif du groupe : matières en colonnes
     * dynamiques selon le périmètre, + moyenne + crédits + décision.
     * ──────────────────────────────────────────────────────────── */
    private function releveCollectif(array $f, ?User $u, ?int $limite): array
    {
        $inscriptions = InscriptionAcademique::query()
            ->with(['etudiant.dossierAcademique', 'etudiant.faculte', 'anneeUniversitaire', 'semestres.notes.matiere'])
            ->orderBy('etudiant_id');

        $this->filtresInscriptions($inscriptions, $f);
        $inscriptions = $inscriptions->get();

        // Matières du périmètre : celles réellement notées dans le groupe
        $matieres = collect();
        foreach ($inscriptions as $inscription) {
            foreach ($inscription->semestres as $semestre) {
                if (! empty($f['semestre']) && (int) $f['semestre'] !== $semestre->numero) {
                    continue;
                }
                foreach ($semestre->notes as $note) {
                    $matieres[$note->matiere->code] = $note->matiere->code;
                }
            }
        }
        $matieres = $matieres->values()->sort()->values();

        $entetes = array_merge(['matricule', 'nom', 'prenom', 'groupe'], $matieres->all(), ['moyenne', 'credits', 'decision']);

        $lignes = [];
        foreach ($inscriptions->take($limite ?? PHP_INT_MAX) as $inscription) {
            $ligne = [
                'matricule' => $inscription->etudiant->dossierAcademique?->matricule ?? '',
                'nom' => $inscription->etudiant->nom,
                'prenom' => $inscription->etudiant->prenom,
                'groupe' => $inscription->groupe ?? '',
            ];

            foreach ($matieres as $code) {
                $note = null;
                foreach ($inscription->semestres as $semestre) {
                    $candidats = $semestre->notes->where('matiere.code', $code);
                    if ($candidats->isNotEmpty()) {
                        $note = $candidats->firstWhere('session', 'rattrapage') ?? $candidats->firstWhere('session', 'normale');
                        if (! empty($f['semestre']) && (int) $f['semestre'] !== $semestre->numero) {
                            $note = null;
                        }
                        if ($note !== null) {
                            break;
                        }
                    }
                }
                $ligne[$code] = $note !== null ? number_format((float) $note->note, 2) : '—';
            }

            // Moyenne/crédits du semestre visé, sinon de l'année
            if (! empty($f['semestre'])) {
                $semestre = $inscription->semestres->firstWhere('numero', (int) $f['semestre']);
                $ligne['moyenne'] = $semestre?->moyenne !== null ? number_format((float) $semestre->moyenne, 2) : '—';
                $ligne['credits'] = $semestre?->credits_obtenus ?? 0;
                $ligne['decision'] = $semestre?->decision ?? '—';
            } else {
                $ligne['moyenne'] = $inscription->moyenne_annuelle !== null ? number_format((float) $inscription->moyenne_annuelle, 2) : '—';
                $ligne['credits'] = $inscription->credits_obtenus;
                $ligne['decision'] = $inscription->decision ?? '—';
            }

            $lignes[] = $ligne;
        }

        // Colonnes personnalisées uniquement sur la partie fixe
        return ['entetes' => $entetes, 'lignes' => $lignes];
    }

    /* ────────────────────────────────────────────────────────────
     * §73 — Moyennes à 4 niveaux : matière / semestre / année / générale
     * ──────────────────────────────────────────────────────────── */
    private function moyennes(array $f, ?User $u, string $granularite, ?int $limite): array
    {
        return match ($granularite) {
            'matiere' => $this->moyennesParMatiere($f, $limite),
            'semestre' => $this->moyennesSemestrielles($f, $limite),
            'annee' => $this->moyennesAnnuelles($f, $limite),
            'generale' => $this->moyennesGenerales($f, $limite),
            default => throw new \InvalidArgumentException('Granularité inconnue : '.$granularite),
        };
    }

    private function moyennesParMatiere(array $f, ?int $limite): array
    {
        $query = Note::query()->with(['etudiant.dossierAcademique', 'matiere', 'inscriptionAcademique.anneeUniversitaire'])
            ->orderBy('etudiant_id');
        $this->filtresNotes($query, $f);

        $lignes = [];
        $notes = $query->get();
        $valides = $notes->groupBy(fn ($n) => $n->etudiant_id.'-'.$n->matiere_id)
            ->map(fn ($g) => $g->firstWhere('session', 'rattrapage') ?? $g->firstWhere('session', 'normale'));

        foreach ($valides->take($limite ?? PHP_INT_MAX) as $note) {
            $lignes[] = [
                'matricule' => $note->etudiant->dossierAcademique?->matricule ?? '',
                'nom' => $note->etudiant->nom,
                'prenom' => $note->etudiant->prenom,
                'matiere' => $note->matiere->code,
                'note' => $note->note,
                'coefficient' => $note->coefficient,
            ];
        }

        return ['entetes' => ['matricule', 'nom', 'prenom', 'matiere', 'note', 'coefficient'], 'lignes' => $lignes];
    }

    private function moyennesSemestrielles(array $f, ?int $limite): array
    {
        $lignes = [];

        $inscriptions = InscriptionAcademique::query()
            ->with(['etudiant.dossierAcademique', 'anneeUniversitaire', 'semestres'])
            ->orderBy('etudiant_id');
        $this->filtresInscriptions($inscriptions, $f);

        foreach ($inscriptions->get()->take($limite ?? PHP_INT_MAX) as $inscription) {
            foreach ($inscription->semestres as $semestre) {
                if (! empty($f['semestre']) && (int) $f['semestre'] !== $semestre->numero) {
                    continue;
                }
                $lignes[] = [
                    'matricule' => $inscription->etudiant->dossierAcademique?->matricule ?? '',
                    'nom' => $inscription->etudiant->nom,
                    'prenom' => $inscription->etudiant->prenom,
                    'semestre' => 'S'.$semestre->numero.' — '.$inscription->anneeUniversitaire->libelle,
                    'moyenne' => $semestre->moyenne !== null ? number_format((float) $semestre->moyenne, 2) : '—',
                    'credits' => $semestre->credits_obtenus,
                ];
            }
        }

        return ['entetes' => ['matricule', 'nom', 'prenom', 'semestre', 'moyenne', 'credits'], 'lignes' => $lignes];
    }

    private function moyennesAnnuelles(array $f, ?int $limite): array
    {
        $query = InscriptionAcademique::query()
            ->with(['etudiant.dossierAcademique', 'anneeUniversitaire'])
            ->orderBy('etudiant_id');
        $this->filtresInscriptions($query, $f);

        $lignes = [];
        foreach ($query->get()->take($limite ?? PHP_INT_MAX) as $inscription) {
            $lignes[] = [
                'matricule' => $inscription->etudiant->dossierAcademique?->matricule ?? '',
                'nom' => $inscription->etudiant->nom,
                'prenom' => $inscription->etudiant->prenom,
                'annee' => $inscription->anneeUniversitaire->libelle,
                'moyenne' => $inscription->moyenne_annuelle !== null ? number_format((float) $inscription->moyenne_annuelle, 2) : '—',
                'credits' => $inscription->credits_obtenus,
                'decision' => $inscription->decision ?? '—',
            ];
        }

        return ['entetes' => ['matricule', 'nom', 'prenom', 'annee', 'moyenne', 'credits', 'decision'], 'lignes' => $lignes];
    }

    private function moyennesGenerales(array $f, ?int $limite): array
    {
        $query = Etudiant::query()->with('dossierAcademique')->orderBy('id');
        $this->filtresEtudiants($query, $f);

        $lignes = [];
        foreach ($query->get()->take($limite ?? PHP_INT_MAX) as $etudiant) {
            $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)->get();
            $synthese = $this->calculs->synthese($inscriptions);

            $lignes[] = [
                'matricule' => $etudiant->dossierAcademique?->matricule ?? '',
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'parcours' => $synthese['annees_total'],
                'moyenne_generale' => $synthese['moyenne_generale'] !== null ? number_format($synthese['moyenne_generale'], 2) : '—',
                'total_credits' => $synthese['credits_obtenus'],
            ];
        }

        return ['entetes' => ['matricule', 'nom', 'prenom', 'parcours', 'moyenne_generale', 'total_credits'], 'lignes' => $lignes];
    }

    /* ────────────────────────────────────────────────────────────
     * §76 — Synthèse par filière : niveau × groupe (effectif, moyenne,
     * crédits, admis, ajournés).
     * ──────────────────────────────────────────────────────────── */
    private function syntheseFiliere(array $f, ?int $limite): array
    {
        $query = InscriptionAcademique::query()->with(['etudiant.faculte', 'anneeUniversitaire']);
        $this->filtresInscriptions($query, $f);

        $lignes = $query->get()
            ->groupBy(fn ($i) => $i->niveau.'|'.($i->groupe ?? '—'))
            ->map(function ($groupe, $cle) {
                [$niveau, $groupeNom] = explode('|', $cle);
                $notees = $groupe->filter(fn ($i) => $i->moyenne_annuelle !== null);

                return [
                    'niveau' => $niveau,
                    'groupe' => $groupeNom,
                    'effectif' => $groupe->count(),
                    'moyenne' => $notees->isNotEmpty() ? number_format((float) $notees->avg('moyenne_annuelle'), 2) : '—',
                    'credits' => (int) $notees->avg('credits_obtenus'),
                    'admis' => $groupe->where('statut', 'valide')->count(),
                    'ajournes' => $groupe->whereIn('statut', ['ajourne', 'abandon'])->count(),
                ];
            })
            ->values()
            ->take($limite ?? PHP_INT_MAX)
            ->all();

        return ['entetes' => ['niveau', 'groupe', 'effectif', 'moyenne', 'credits', 'admis', 'ajournes'], 'lignes' => $lignes];
    }

    /* ────────────────────────────────────────────────────────────
     * §86 — Statistiques par matière (agrégats : moyenne, taux de
     * validation, effectif noté) — privilégie l'agrégé sur l'individuel.
     * ──────────────────────────────────────────────────────────── */
    private function statistiquesMatieres(array $f, ?int $limite): array
    {
        $query = Note::query()->with(['matiere', 'inscriptionAcademique.anneeUniversitaire']);
        $this->filtresNotes($query, $f);

        $notes = $query->get();

        $lignes = $notes->groupBy(fn ($n) => $n->matiere->code)
            ->map(function ($groupe, $code) {
                $valides = $groupe->groupBy('etudiant_id')
                    ->map(fn ($g) => $g->firstWhere('session', 'rattrapage') ?? $g->firstWhere('session', 'normale'));

                return [
                    'faculte' => '',
                    'filiere' => $groupe->first()->inscriptionAcademique->filiere ?? '',
                    'niveau' => $groupe->first()->inscriptionAcademique->niveau,
                    'semestre' => 'S'.$groupe->first()->semestre?->numero,
                    'matiere' => $code,
                    'effectif' => $valides->count(),
                    'moyenne' => $valides->isNotEmpty() ? number_format((float) $valides->avg('note'), 2) : '—',
                    'taux_reussite' => $valides->isNotEmpty()
                        ? round($valides->filter(fn ($n) => (float) $n->note >= 10)->count() * 100 / $valides->count(), 1).'%'
                        : '—',
                    'nb_notes' => $groupe->count(),
                    'repartition' => $valides->filter(fn ($n) => (float) $n->note >= 10)->count().' validés / '
                        .$valides->filter(fn ($n) => (float) $n->note < 10)->count().' en échec',
                ];
            })
            ->values()
            ->take($limite ?? PHP_INT_MAX)
            ->all();

        return ['entetes' => ['faculte', 'filiere', 'niveau', 'semestre', 'matiere', 'effectif', 'moyenne', 'taux_reussite', 'nb_notes', 'repartition'], 'lignes' => $lignes];
    }

    /* ── Piles de filtres partagées (whitelist §84) ── */

    /**
     * §78/§85 : la personnalisation de colonnes ne s'applique pas au
     * profil « statistique » (déjà anonyme par construction) ; sans
     * colonnes personnalisées, le jeu de données reste inchangé
     * (compatibilité des profils §43) sauf retrait des colonnes
     * personnelles si l'utilisateur n'a pas la permission exports.personal.
     *
     * @param  array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}  $donnees
     */
    private function finaliserStudents(array $donnees, ?User $utilisateur, ?string $profil, ?array $colonnes, string $type = 'students'): array
    {
        if ($profil === 'statistique') {
            return $donnees; // agrégats anonymes — aucun retrait nécessaire
        }

        $personnelAutorise = $utilisateur !== null && $utilisateur->hasPermission('exports.personal');

        if ($colonnes === null && $personnelAutorise) {
            return $donnees; // périmètre complet autorisé
        }

        return $this->colonnes->appliquer($donnees, $utilisateur, $type, $colonnes);
    }

    private function filtresNotes($query, array $f, bool $saufMatiere = false): void
    {
        if (! empty($f['faculte'])) {
            $query->whereHas('etudiant.faculte', fn ($q) => $q->where('code', mb_strtoupper($f['faculte'])));
        }
        if (! $saufMatiere && ! empty($f['matiere'])) {
            $query->whereHas('matiere', fn ($q) => $q->where('code', mb_strtoupper($f['matiere'])));
        }
        if (! empty($f['annee'])) {
            $query->whereHas('inscriptionAcademique.anneeUniversitaire', fn ($q) => $q->where('libelle', $f['annee']));
        }
        if (! empty($f['niveau'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('niveau', $f['niveau']));
        }
        if (! empty($f['filiere'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('filiere', 'like', '%'.trim($f['filiere']).'%'));
        }
        if (! empty($f['groupe'])) {
            $query->whereHas('inscriptionAcademique', fn ($q) => $q->where('groupe', $f['groupe']));
        }
        if (! empty($f['semestre'])) {
            $query->whereHas('semestre', fn ($q) => $q->where('numero', (int) $f['semestre']));
        }
        if (! empty($f['session']) && $f['session'] !== 'toutes') {
            $query->where('session', $f['session']);
        }
        if (! empty($f['note_min'])) {
            $query->where('note', '>=', $f['note_min']);
        }
        if (! empty($f['note_max'])) {
            $query->where('note', '<=', $f['note_max']);
        }
        if (! empty($f['matricule'])) {
            $query->whereHas('etudiant.dossierAcademique', fn ($q) => $q->where('matricule', $f['matricule']));
        }
    }

    public function filtresInscriptions($query, array $f): void
    {
        if (! empty($f['faculte'])) {
            $query->whereHas('etudiant.faculte', fn ($q) => $q->where('code', mb_strtoupper($f['faculte'])));
        }
        if (! empty($f['annee'])) {
            $query->whereHas('anneeUniversitaire', fn ($q) => $q->where('libelle', $f['annee']));
        }
        if (! empty($f['niveau'])) {
            $query->where('niveau', $f['niveau']);
        }
        if (! empty($f['filiere'])) {
            $query->where('filiere', 'like', '%'.trim($f['filiere']).'%');
        }
        if (! empty($f['groupe'])) {
            $query->where('groupe', $f['groupe']);
        }
        if (! empty($f['statut'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('statut', $f['statut']));
        }
        if (! empty($f['statut_academique'])) {
            $query->where('statut', $f['statut_academique']);
        }
        if (! empty($f['moyenne_min'])) {
            $query->where('moyenne_annuelle', '>=', $f['moyenne_min']);
        }
        if (! empty($f['moyenne_max'])) {
            $query->where('moyenne_annuelle', '<=', $f['moyenne_max']);
        }
    }

    private function filtresEtudiants($query, array $f): void
    {
        if (! empty($f['faculte'])) {
            $query->whereHas('faculte', fn ($q) => $q->where('code', mb_strtoupper($f['faculte'])));
        }
        if (! empty($f['nom'])) {
            $query->where('nom', 'like', '%'.trim($f['nom']).'%');
        }
        if (! empty($f['matricule'])) {
            $query->whereHas('dossierAcademique', fn ($q) => $q->where('matricule', $f['matricule']));
        }
    }

    /**
     * Total = lignes du périmètre (les échantillons sont découpés côté
     * contrôleur de prévisualisation, pas par le moteur).
     */
}
