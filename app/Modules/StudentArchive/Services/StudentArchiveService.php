<?php

namespace App\Modules\StudentArchive\Services;

use App\Models\Etudiant;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\OnlineRequestDocument;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Modules\StudentArchive\Policies\SensitiveRecordPolicy;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\AcademicCalculationService;
use App\Services\AcademicProgressionService;
use App\Services\FicheEtudiantService;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Moteur archive (§53) : orchestration MINCE des sources existantes.
 * Méthodes publiques et réutilisables (Phase G PDF / Phase H API) :
 * academic(), registrations(), documents(), diplomas(), certificates().
 * Aucun calcul recréé, aucune écriture, aucune table.
 */
class StudentArchiveService
{
    public function __construct(
        private readonly FicheEtudiantService $fiche,
        private readonly ArchiveTimelineRegistry $timeline,
        private readonly AcademicCalculationService $calculs,
        private readonly AcademicProgressionService $progressions,
    ) {}

    /**
     * Dossiers sensibles (§6, §16) : UNIQUEMENT les types autorisés sont
     * retournés (non-divulgation : ni titre ni existence sinon). Jamais
     * chargés sur la page standard (§36-37 : onglet dédié uniquement).
     *
     * @return Collection<int, StudentSensitiveRecord>
     */
    public function sensibles(Etudiant $etudiant, ?User $utilisateur): Collection
    {
        $dossiers = StudentSensitiveRecord::where('etudiant_id', $etudiant->id)
            ->with('createur')->latest('id')->get();

        if ($utilisateur === null) {
            return collect();
        }

        $policy = app(SensitiveRecordPolicy::class);

        return $dossiers->filter(fn ($dossier) => $policy->voir($utilisateur, $dossier))->values();
    }

    /**
     * Lien de téléchargement d'un dossier sensible (§25) : GED source via
     * route archive (policies sources + permission sensible vérifiées par
     * l'appelant). Null si non communicable (ex. justificatif sans route).
     */
    public function lienTelechargementSensible(
        StudentSensitiveRecord $dossier,
        ?User $utilisateur,
    ): ?string {
        if ($dossier->document_source === null || $dossier->document_id === null) {
            return null;
        }

        $policy = app(SensitiveRecordPolicy::class);

        if ($utilisateur !== null && ! $policy->telecharger($utilisateur, $dossier)) {
            return null;
        }

        return route('admin.etudiants.archive.sensible.download', [$dossier->etudiant_id, $dossier]);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Etudiant $etudiant): array
    {
        $etudiant->loadMissing([
            'faculte', 'programmeRef', 'niveauRef', 'groupe',
            'inscriptionsAcademiques.anneeUniversitaire',
        ]);

        return [
            'etudiant' => $etudiant,
            'resume' => $this->fiche->resume($etudiant),
            'completude' => $this->fiche->completude($etudiant),
            'anomalies' => $this->fiche->anomalies($etudiant),
            'statistiques' => $this->statistics($etudiant),
            'progression' => $this->progression($etudiant),
        ];
    }

    /**
     * Progression LMD (Phase 8 §8) : évaluation dry-run sur la
     * dernière inscription (décision publiée exigée par le moteur).
     * Lecture seule — `appliquer` reste sur la fiche 360°.
     *
     * @return array<string, mixed>|null
     */
    public function progression(Etudiant $etudiant): ?array
    {
        $derniere = $etudiant->inscriptionsAcademiques()
            ->with('anneeUniversitaire')->orderByDesc('annee_universitaire_id')->first();

        if ($derniere === null) {
            return null;
        }

        $evaluation = $this->progressions->evaluer($etudiant, $derniere);

        return [
            'action' => $evaluation['action'],
            'decision' => $evaluation['decision'],
            'niveau_cible' => $evaluation['niveau_cible']?->code,
            'annee_cible' => $evaluation['annee_cible']?->libelle,
            'peut_progresser' => $evaluation['peut_progresser'],
            'blocages' => $evaluation['blocages'],
        ];
    }

    /**
     * Demandes de services (Phase 8 §8, Phase 9) : liste + compteurs
     * par statut, module-gardée. Lecture seule.
     *
     * @return array{ouvertes: int, demandes: Collection<int, OnlineRequest>}
     */
    public function demandes(Etudiant $etudiant): array
    {
        if (! app(ModuleRegistry::class)->estActif('online-requests')
            || ! class_exists(OnlineRequest::class)) {
            return ['ouvertes' => 0, 'demandes' => collect()];
        }

        $demandes = OnlineRequest::where('etudiant_id', $etudiant->id)
            ->with('type')->latest('id')->limit(20)->get();

        return [
            'ouvertes' => $demandes->whereIn('status', ['submitted', 'received', 'in_review', 'waiting_student'])->count(),
            'demandes' => $demandes,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return array{total: int, elements: Collection<int, array{date: mixed, categorie: string, titre: string, detail: ?string, lien: ?string}>}
     */
    public function timeline(Etudiant $etudiant, array $filtres = []): array
    {
        return $this->timeline->evenements($etudiant, $filtres);
    }

    /**
     * @return array<string, int>
     */
    public function statistics(Etudiant $etudiant): array
    {
        $etudiant->loadMissing('inscriptionsAcademiques', 'documentsAcademiques', 'notesAcademiques');

        return [
            'annees' => $etudiant->inscriptionsAcademiques->pluck('annee_universitaire_id')->unique()->count(),
            'inscriptions' => $etudiant->inscriptionsAcademiques->count(),
            'documents' => $etudiant->documentsAcademiques->count(),
            'notes' => $etudiant->notesAcademiques->count(),
        ];
    }

    /**
     * Projection académique (§5-15) : synthèse via AcademicCalculationService,
     * années → semestres (stored) → UE → ECUE + notes, décisions de jury.
     * Filtres server-side : annee_universitaire_id, semestre (numero),
     * ue_id, matiere_id, session, verrouillee (oui/non).
     *
     * @param  array<string, mixed>  $filtres
     * @return array{synthese: array<string, mixed>, annees: Collection<int, mixed>, decisions: Collection<int, mixed>}
     */
    public function academic(Etudiant $etudiant, array $filtres = []): array
    {
        $etudiant->loadMissing([
            'inscriptionsAcademiques.anneeUniversitaire',
            'inscriptionsAcademiques.semestres',
            'inscriptionsAcademiques.pedagogiques.matiere.ue',
            'inscriptionsAcademiques.pedagogiques.matiere.responsable',
        ]);

        $inscriptions = $etudiant->inscriptionsAcademiques
            ->when(isset($filtres['annee_universitaire_id']),
                fn ($c) => $c->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']))
            ->sortBy(fn ($i) => $i->anneeUniversitaire?->date_debut ?? '')->values();

        $annees = $inscriptions->map(function ($inscription) use ($filtres, $etudiant) {
            $semestres = $inscription->semestres
                ->when(isset($filtres['semestre']),
                    fn ($c) => $c->where('numero', (int) $filtres['semestre']))
                ->sortBy('numero')->values();

            $pedagogiques = $inscription->pedagogiques
                ->when(isset($filtres['matiere_id']),
                    fn ($c) => $c->where('matiere_id', (int) $filtres['matiere_id']))
                ->when(isset($filtres['ue_id']),
                    fn ($c) => $c->filter(fn ($p) => (int) ($p->matiere?->ue_id ?? 0) === (int) $filtres['ue_id']))
                ->values();

            $notes = $this->notesPour($etudiant, $inscription->id, $filtres);

            $couvertes = $pedagogiques->pluck('matiere_id')->all();
            $orphelines = $notes->whereNotIn('matiere_id', $couvertes)->map(fn ($note) => [
                'pedagogique' => null,
                'matiere' => $note->matiere,
                'note' => $note,
            ]);

            $ues = $pedagogiques
                ->map(fn ($p) => [
                    'pedagogique' => $p,
                    'matiere' => $p->matiere,
                    'note' => $notes->firstWhere('matiere_id', $p->matiere_id),
                ])
                ->toBase()
                ->merge($orphelines)
                ->groupBy(fn ($ligne) => $ligne['matiere']?->ue_id ?? 0)
                ->map(function ($lignes) use ($notes) {
                    $matiereIds = $lignes->map(fn ($ligne) => $ligne['matiere']?->id)->filter()->all();
                    $notesUe = $notes->whereIn('matiere_id', $matiereIds)->values();

                    return [
                        'ue' => $lignes->first()['matiere']?->ue,
                        'ecues' => $lignes->values(),
                        'moyenne' => $this->calculs->moyennePonderee($notesUe),
                        'credits' => $this->calculs->creditsObtenus(
                            $this->calculs->notesValideesParMatiere($notesUe)
                        ),
                    ];
                })->values();

            return [
                'inscription' => $inscription,
                'semestres' => $semestres,
                'ues' => $ues,
                'notes' => $notes,
            ];
        });

        $decisions = class_exists(DecisionDeliberation::class)
            ? DecisionDeliberation::where('etudiant_id', $etudiant->id)
                ->with(['deliberation.anneeUniversitaire', 'deliberation.session'])
                ->latest('id')->get()
            : collect();

        return [
            'synthese' => $this->calculs->synthese($inscriptions),
            'annees' => $annees,
            'decisions' => $decisions,
        ];
    }

    /**
     * Notes filtrées server-side (§11, §35) : query réutilisable (Phase G/H).
     *
     * @param  array<string, mixed>  $filtres
     */
    public function notesQuery(Etudiant $etudiant, array $filtres = []): Builder
    {
        $query = $etudiant->notesAcademiques()->getQuery()
            ->with(['matiere.ue', 'semestre', 'inscriptionAcademique.anneeUniversitaire'])
            ->orderByDesc('id');

        if (! empty($filtres['annee_universitaire_id'])) {
            $query->whereHas('inscriptionAcademique',
                fn ($q) => $q->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']));
        }

        if (! empty($filtres['matiere_id'])) {
            $query->where('matiere_id', (int) $filtres['matiere_id']);
        }

        if (! empty($filtres['ue_id'])) {
            $query->whereHas('matiere', fn ($q) => $q->where('ue_id', (int) $filtres['ue_id']));
        }

        if (! empty($filtres['semestre'])) {
            $query->whereHas('semestre', fn ($q) => $q->where('numero', (int) $filtres['semestre']));
        }

        if (! empty($filtres['session'])) {
            $query->where('session', $filtres['session']);
        }

        if (isset($filtres['verrouillee']) && $filtres['verrouillee'] !== '') {
            $filtres['verrouillee'] === 'oui'
                ? $query->whereNotNull('locked_at')
                : $query->whereNull('locked_at');
        }

        return $query;
    }

    /**
     * Inscriptions détaillées (§16-18) : inscription + groupe/sous-groupe de
     * l'année (affectations) + situations datées de l'année (parcours).
     *
     * @return Collection<int, array{inscription: mixed, affectation: mixed, situations: Collection<int, mixed>}>
     */
    public function registrations(Etudiant $etudiant): Collection
    {
        $etudiant->loadMissing([
            'inscriptionsAcademiques.anneeUniversitaire',
            'inscriptionsAcademiques.programmeRef',
            'inscriptionsAcademiques.niveauRef',
            'affectations.groupe',
            'affectations.sousGroupe',
            'situations',
        ]);

        return $etudiant->inscriptionsAcademiques
            ->sortBy(fn ($i) => $i->anneeUniversitaire?->date_debut ?? '')->values()
            ->map(fn ($inscription) => [
                'inscription' => $inscription,
                'affectation' => $etudiant->affectations
                    ->firstWhere('annee_universitaire_id', $inscription->annee_universitaire_id),
                'situations' => $etudiant->situations->filter(
                    fn ($s) => $s->date_effet !== null
                        && $inscription->anneeUniversitaire !== null
                        && $s->date_effet->between(
                            $inscription->anneeUniversitaire->date_debut,
                            $inscription->anneeUniversitaire->date_fin
                        )
                )->values(),
            ]);
    }

    /**
     * Documents projetés (§19-23) : GED cœur + dossiers (module) + pièces et
     * attestations demandes (module). Normalisé pour Phase G.
     * Confidentialité : aucun niveau médical n'existe (§52 : audit séparé).
     *
     * @param  array<string, mixed>  $filtres  (annee_universitaire_id, type, search)
     * @return Collection<int, array{source: string, titre: string, type: string, categorie: string, reference: ?string, annee: ?string, date: mixed, statut: ?string, telechargement: ?string}>
     */
    public function documents(Etudiant $etudiant, array $filtres = [], ?User $utilisateur = null): Collection
    {
        $peutTelecharger = $utilisateur === null
            || $utilisateur->can('documents.download');

        $etudiant->loadMissing('documentsAcademiques.anneeUniversitaire');

        $coeur = $etudiant->documentsAcademiques
            ->when(isset($filtres['annee_universitaire_id']),
                fn ($c) => $c->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']))
            ->when(isset($filtres['type']),
                fn ($c) => $c->where('type_document', $filtres['type']))
            ->when(! empty($filtres['search']),
                fn ($c) => $c->filter(fn ($d) => mb_stripos((string) $d->nom_original, (string) $filtres['search']) !== false))
            ->map(fn ($document) => [
                'source' => 'ged',
                'titre' => $document->nom_original,
                'type' => $document->type_document,
                'categorie' => $this->categorieDocument($document->type_document),
                'reference' => null,
                'annee' => $document->anneeUniversitaire?->libelle,
                'date' => $document->created_at,
                'statut' => null,
                'officiel' => in_array($document->type_document, ['diplome', 'releve_notes'], true),
                'telechargement' => $peutTelecharger
                    ? route('admin.archives.documents.download', $document) : null,
            ]);

        $dossiers = $this->documentsDossiers($etudiant, $filtres, $utilisateur);
        $demandes = $this->documentsDemandes($etudiant, $filtres, $utilisateur);
        $officiels = $this->documentsOfficiels($etudiant, $filtres, $utilisateur);

        // Eloquent Collection of arrays would crash merge() (getKey) :
        // base dès qu'une source secondaire est non vide (latent,
        // révélé par la 4e source `officiel`).
        return $coeur->toBase()->merge($dossiers)->merge($demandes)->merge($officiels)
            ->sortByDesc(fn ($d) => (string) $d['date'])->values();
    }

    /**
     * Documents officiels générés (Phase 7) : registre GeneratedDocument
     * (relevés, attestations — versionnés, hashés, révoquables).
     * Gated `student_dossiers.pdf` comme la génération.
     *
     * @return Collection<int, array{source: string, titre: string, type: string, categorie: string, reference: ?string, annee: ?string, date: mixed, statut: ?string, telechargement: ?string}>
     */
    private function documentsOfficiels(Etudiant $etudiant, array $filtres, ?User $utilisateur): Collection
    {
        if (! app(ModuleRegistry::class)->estActif('student-dossiers')) {
            return collect();
        }

        if ($utilisateur !== null && ! $utilisateur->can('student_dossiers.pdf')) {
            return collect();
        }

        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->first();

        if ($dossier === null) {
            return collect();
        }

        return GeneratedDocument::where('student_dossier_id', $dossier->id)
            ->whereIn('type', array_keys(config('documents-officiels.types', [])))
            ->when(isset($filtres['type']),
                fn ($q) => $q->where('type', $filtres['type']))
            ->orderByDesc('id')->get()
            ->map(fn ($document) => [
                'source' => 'officiel',
                'titre' => $document->reference,
                'type' => $document->type,
                'categorie' => 'Officiel',
                'reference' => $document->reference,
                'annee' => null,
                'date' => $document->created_at,
                'statut' => $document->statut,
                'officiel' => true,
                'telechargement' => $document->statut === 'revoque' ? null
                    : route('admin.documents-officiels.download', $document),
            ]);
    }

    /**
     * Diplômes projetés (§24) : décisions de jury admis* + documents type
     * diplome. Lecture seule — aucun `student_diplomas_archive`.
     *
     * @return array{decisions: Collection<int, mixed>, documents: Collection<int, mixed>}
     */
    public function diplomas(Etudiant $etudiant): array
    {
        $tous = $this->documents($etudiant);

        return [
            'decisions' => class_exists(DecisionDeliberation::class)
                ? DecisionDeliberation::where('etudiant_id', $etudiant->id)
                    ->whereIn('decision', ['admis', 'admis_compensation', 'admis_conditionnel'])
                    ->with(['deliberation.anneeUniversitaire', 'deliberation.programme'])
                    ->latest('id')->get()
                : collect(),
            'documents' => $tous->where('type', 'diplome')->values(),
        ];
    }

    /**
     * Certificats projetés (§23, §25) : attestations/certificats GED +
     * attestations générées des demandes + documents dossiers compatibles.
     *
     * @return Collection<int, array{source: string, titre: string, type: string, categorie: string, reference: ?string, annee: ?string, date: mixed, statut: ?string, telechargement: ?string}>
     */
    public function certificates(Etudiant $etudiant, ?User $utilisateur = null): Collection
    {
        return $this->documents($etudiant, [], $utilisateur)
            ->filter(fn ($d) => in_array($d['type'], ['attestation', 'certificat'], true))
            ->values();
    }

    /** @return Collection<int, Note> */
    private function notesPour(Etudiant $etudiant, int $inscriptionId, array $filtres): Collection
    {
        $query = $etudiant->notesAcademiques()->getQuery()
            ->where('inscription_academique_id', $inscriptionId)
            ->with(['matiere.ue', 'matiere.responsable', 'semestre']);

        if (! empty($filtres['matiere_id'])) {
            $query->where('matiere_id', (int) $filtres['matiere_id']);
        }

        if (! empty($filtres['ue_id'])) {
            $query->whereHas('matiere', fn ($q) => $q->where('ue_id', (int) $filtres['ue_id']));
        }

        if (! empty($filtres['semestre'])) {
            $query->whereHas('semestre', fn ($q) => $q->where('numero', (int) $filtres['semestre']));
        }

        if (! empty($filtres['session'])) {
            $query->where('session', $filtres['session']);
        }

        return $query->orderBy('matiere_id')->orderBy('session')->get();
    }

    private function categorieDocument(?string $type): string
    {
        return match ($type) {
            'diplome' => 'Diplômes',
            'releve_notes' => 'Relevés',
            'attestation', 'certificat' => 'Certificats',
            'convention' => 'Stages',
            default => 'Autres',
        };
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return Collection<int, array{source: string, titre: string, type: string, categorie: string, reference: ?string, annee: ?string, date: mixed, statut: ?string, telechargement: ?string}>
     */
    private function documentsDossiers(Etudiant $etudiant, array $filtres, ?User $utilisateur): Collection
    {
        if (! app(ModuleRegistry::class)->estActif('student-dossiers')) {
            return collect();
        }

        $peutVoir = $utilisateur === null || $utilisateur->can('student_documents.view');
        $peutTelecharger = $utilisateur === null || $utilisateur->can('student_documents.download');

        if (! $peutVoir) {
            return collect();
        }

        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->first();

        if ($dossier === null) {
            return collect();
        }

        $dossier->loadMissing('documents');

        return $dossier->documents
            ->when(isset($filtres['type']),
                fn ($c) => $c->where('document_type', $filtres['type']))
            ->when(! empty($filtres['search']),
                fn ($c) => $c->filter(fn ($d) => mb_stripos((string) $d->title, (string) $filtres['search']) !== false))
            ->map(fn ($document) => [
                'source' => 'dossier',
                'titre' => $document->title,
                'type' => $document->document_type,
                'categorie' => ucfirst((string) $document->category),
                'reference' => null,
                'annee' => null,
                'date' => $document->created_at,
                'statut' => $document->status,
                'officiel' => in_array($document->document_type, ['diplome', 'releve_notes'], true),
                'telechargement' => $peutTelecharger
                    ? route('admin.student-dossiers.documents.download', [$dossier, $document]) : null,
            ]);
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return Collection<int, array{source: string, titre: string, type: string, categorie: string, reference: ?string, annee: ?string, date: mixed, statut: ?string, telechargement: ?string}>
     */
    private function documentsDemandes(Etudiant $etudiant, array $filtres, ?User $utilisateur): Collection
    {
        if (! app(ModuleRegistry::class)->estActif('online-requests')) {
            return collect();
        }

        $peutTelecharger = $utilisateur === null || $utilisateur->can('demandes.documents.download');

        if ($utilisateur !== null && ! $utilisateur->can('demandes.view')) {
            return collect();
        }

        return OnlineRequestDocument::whereHas(
            'demande', fn ($q) => $q->where('etudiant_id', $etudiant->id)
        )->with('demande')->latest('id')->get()->map(fn ($document) => [
            'source' => 'demande',
            'titre' => 'Attestation '.$document->reference,
            'type' => 'attestation',
            'categorie' => 'Certificats',
            'reference' => $document->reference,
            'annee' => null,
            'date' => $document->created_at,
            'statut' => null,
            'officiel' => true,
            'telechargement' => $peutTelecharger
                ? route('admin.demandes.document.download', $document->demande) : null,
        ]);
    }
}
