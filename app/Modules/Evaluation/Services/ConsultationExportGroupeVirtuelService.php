<?php

namespace App\Modules\Evaluation\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\DTO\DecisionEligibiliteExamen;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\GroupeVirtuelMembre;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\Io\CsvSerializer;
use App\Services\Io\XlsxSerializer;
use App\Support\QrCodeSvg;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Service central de consultation, de contrôle de cohérence, d'export multi-formats
 * et d'impression professionnelle des groupes virtuels d'examens finaux.
 *
 * Garanties :
 * 1. Lecture seule stricte : aucune génération implicite, aucune mutation des groupes
 *    académiques d'origine, des inscriptions pédagogiques ou des candidats.
 * 2. Source de vérité unique : l'écran, le XLSX, le CSV, le PDF et l'impression HTML
 *    consomment exactement les mêmes données et appliquent les mêmes filtres et règles.
 * 3. Sécurité et isolation : contrôle systématique des permissions et du périmètre
 *    facultaire (anti-IDOR), protection contre l'injection de formules CSV/XLSX,
 *    zéro PII dans les journaux d'audit.
 * 4. Intégrité métier : vérification via ServiceEligibiliteExamen, CorrespondanceExamenService
 *    et PreparationExamenService ; détection de dérive de population après génération ou
 *    validation ; blocage des exports officiels en cas d'incohérence ou de dérive.
 */
class ConsultationExportGroupeVirtuelService
{
    public const FORMATS_EXPORT = ['xlsx', 'csv', 'pdf', 'html'];

    public const DOCUMENTS_GROUPE = ['fiche', 'etudiants', 'emargement'];

    public const DOCUMENTS_SELECTION = ['synthese_matiere', 'synthese_session'];

    public function __construct(
        private readonly ServiceEligibiliteExamen $serviceEligibilite,
        private readonly CorrespondanceExamenService $correspondanceService,
        private readonly PreparationExamenService $preparationService,
        private readonly GroupeVirtuelExamenService $groupeVirtuelService,
        private readonly CsvSerializer $csvSerializer,
        private readonly XlsxSerializer $xlsxSerializer,
        private readonly PdfGeneratorInterface $pdfGenerator,
    ) {}

    /**
     * Vérifie que l'utilisateur est autorisé à consulter les groupes virtuels d'examen.
     */
    public function verifierPermissionConsultation(User $user): void
    {
        if (! Gate::forUser($user)->allows('epreuves.view') && ! Gate::forUser($user)->allows('evaluations.view')) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à consulter les groupes virtuels d'examen.");
        }
    }

    /**
     * Vérifie que l'utilisateur a accès au groupe virtuel ciblé (permission + isolation facultaire).
     */
    public function verifierAccesGroupeVirtuel(User $user, GroupeVirtuelExamen $groupe): void
    {
        $this->verifierPermissionConsultation($user);

        $faculteRestreinte = $user->faculteRestreinteId();
        if ($faculteRestreinte === null) {
            return;
        }

        $faculteGroupe = $groupe->faculteId();
        if ($faculteGroupe !== null && (int) $faculteGroupe !== (int) $faculteRestreinte) {
            abort(403, "Accès refusé : ce groupe virtuel n'appartient pas à votre faculté.");
        }

        $sourcesIds = $groupe->identifiantsSources();
        if ($sourcesIds !== []) {
            $sourceHorsFaculte = Group::query()
                ->whereIn('id', $sourcesIds)
                ->whereNotNull('faculte_id')
                ->where('faculte_id', '!=', $faculteRestreinte)
                ->exists();

            if ($sourceHorsFaculte) {
                abort(403, 'Accès refusé : un groupe source appartient à une autre faculté.');
            }
        }
    }

    /**
     * Indique si l'utilisateur est habilité à voir les numéros d'anonymat.
     */
    public function peutVoirNumeroAnonyme(User $user): bool
    {
        return $user->hasPermission('notes.compostage.resolve');
    }

    /**
     * Normalise et valide les filtres de recherche et de sélection.
     *
     * @param  array<string, mixed>  $entrees
     * @return array{
     *     annee_id: ?int,
     *     session_id: ?int,
     *     faculte_id: ?int,
     *     programme_id: ?int,
     *     matiere_id: ?int,
     *     niveau_id: ?int,
     *     examen_id: ?int,
     *     epreuve_id: ?int,
     *     statut: ?string,
     *     recherche: ?string
     * }
     */
    public function normaliserFiltres(array $entrees, User $user): array
    {
        $faculteRestreinte = $user->faculteRestreinteId();

        $faculteDemandee = ! empty($entrees['faculte_id']) ? (int) $entrees['faculte_id'] : null;
        if ($faculteRestreinte !== null && $faculteDemandee !== null && $faculteDemandee !== (int) $faculteRestreinte) {
            abort(403, 'Accès refusé : vous ne pouvez pas filtrer sur une autre faculté.');
        }

        $statut = isset($entrees['statut']) && is_string($entrees['statut']) && trim($entrees['statut']) !== ''
            ? trim($entrees['statut'])
            : null;

        $statutsAutorises = ['propose', 'remplace', 'annule', 'valide', 'non_valide', 'publie', 'derive', 'tous'];
        if ($statut !== null && ! in_array($statut, $statutsAutorises, true)) {
            $statut = null;
        }

        $recherche = isset($entrees['recherche']) && is_string($entrees['recherche'])
            ? mb_substr(trim($entrees['recherche']), 0, 120)
            : null;

        return [
            'annee_id' => ! empty($entrees['annee_id']) ? (int) $entrees['annee_id'] : null,
            'session_id' => ! empty($entrees['session_id']) ? (int) $entrees['session_id'] : null,
            'faculte_id' => $faculteRestreinte !== null ? (int) $faculteRestreinte : $faculteDemandee,
            'programme_id' => ! empty($entrees['programme_id']) ? (int) $entrees['programme_id'] : null,
            'matiere_id' => ! empty($entrees['matiere_id']) ? (int) $entrees['matiere_id'] : null,
            'niveau_id' => ! empty($entrees['niveau_id']) ? (int) $entrees['niveau_id'] : null,
            'examen_id' => ! empty($entrees['examen_id']) ? (int) $entrees['examen_id'] : null,
            'epreuve_id' => ! empty($entrees['epreuve_id']) ? (int) $entrees['epreuve_id'] : null,
            'statut' => $statut,
            'recherche' => $recherche !== '' ? $recherche : null,
        ];
    }

    /**
     * Construit la requête Eloquent scopée et filtrée pour les groupes virtuels.
     *
     * @param  array<string, mixed>  $filtres
     */
    public function construireRequete(array $filtres, User $user): Builder
    {
        $this->verifierPermissionConsultation($user);
        $f = $this->normaliserFiltres($filtres, $user);

        // Vérification anti-IDOR sur session_id ou matiere_id si fournis
        if ($f['faculte_id'] !== null && $f['matiere_id'] !== null) {
            $matiere = Matiere::with('ue.programme')->find($f['matiere_id']);
            if ($matiere?->ue?->programme?->faculte_id !== null
                && (int) $matiere->ue->programme->faculte_id !== (int) $f['faculte_id']) {
                abort(403, "Accès refusé : cette matière n'appartient pas à votre faculté.");
            }
        }

        $query = GroupeVirtuelExamen::query()
            ->with([
                'examen.session.anneeUniversitaire',
                'examen.programme.faculte',
                'examen.niveau',
                'epreuve.session.anneeUniversitaire',
                'epreuve.matiere.ue.programme.faculte',
                'epreuve.salle',
                'matiere.ue.programme.faculte',
                'programme.faculte',
                'niveau',
                'createur',
                'membres.etudiant.groupe',
                'membres.groupeSource',
            ])
            ->withCount([
                'membres as total_membres_count',
                'membres as eligibles_count' => fn ($q) => $q->where('decision', GroupeVirtuelMembre::DECISION_ELIGIBLE),
                'membres as non_eligibles_count' => fn ($q) => $q->where('decision', GroupeVirtuelMembre::DECISION_NON_ELIGIBLE),
                'membres as anomalies_count' => fn ($q) => $q->where('decision', GroupeVirtuelMembre::DECISION_ANOMALIE),
            ]);

        if ($f['faculte_id'] !== null) {
            $query->pourFaculteId((int) $f['faculte_id']);
        }

        if ($f['annee_id'] !== null) {
            $anneeId = (int) $f['annee_id'];
            $query->where(function (Builder $q) use ($anneeId) {
                $q->whereHas('examen', fn ($ex) => $ex->where('annee_universitaire_id', $anneeId))
                    ->orWhereHas('epreuve', fn ($ep) => $ep->where('annee_universitaire_id', $anneeId));
            });
        }

        if ($f['session_id'] !== null) {
            $sessionId = (int) $f['session_id'];
            $query->where(function (Builder $q) use ($sessionId) {
                $q->whereHas('examen', fn ($ex) => $ex->where('session_id', $sessionId))
                    ->orWhereHas('epreuve', fn ($ep) => $ep->where('session_id', $sessionId));
            });
        }

        if ($f['programme_id'] !== null) {
            $programmeId = (int) $f['programme_id'];
            $query->where(function (Builder $q) use ($programmeId) {
                $q->where('programme_id', $programmeId)
                    ->orWhereHas('examen', fn ($ex) => $ex->where('programme_id', $programmeId))
                    ->orWhereHas('matiere.ue', fn ($ue) => $ue->where('programme_id', $programmeId));
            });
        }

        if ($f['matiere_id'] !== null) {
            $query->where('matiere_id', (int) $f['matiere_id']);
        }

        if ($f['niveau_id'] !== null) {
            $niveauId = (int) $f['niveau_id'];
            $query->where(function (Builder $q) use ($niveauId) {
                $q->where('niveau_id', $niveauId)
                    ->orWhereHas('examen', fn ($ex) => $ex->where('niveau_id', $niveauId));
            });
        }

        if ($f['examen_id'] !== null) {
            $query->where('examen_id', (int) $f['examen_id']);
        }

        if ($f['epreuve_id'] !== null) {
            $query->where('epreuve_id', (int) $f['epreuve_id']);
        }

        if ($f['statut'] !== null && $f['statut'] !== 'tous') {
            match ($f['statut']) {
                'propose', 'remplace', 'annule' => $query->where('statut', $f['statut']),
                'valide' => $query->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
                    ->whereHas('epreuve', fn ($ep) => $ep->whereNotNull('population_validated_at')),
                'non_valide' => $query->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
                    ->where(fn ($q) => $q->whereNull('epreuve_id')
                        ->orWhereHas('epreuve', fn ($ep) => $ep->whereNull('population_validated_at'))),
                'publie' => $query->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
                    ->where(fn ($q) => $q->whereHas('examen', fn ($ex) => $ex->whereIn('statut', ['published', 'ongoing', 'completed']))
                        ->orWhereHas('epreuve', fn ($ep) => $ep->whereIn('statut', ['published', 'ongoing', 'completed']))),
                default => null,
            };
        }

        if ($f['recherche'] !== null) {
            $terme = '%'.$f['recherche'].'%';
            $query->where(function (Builder $q) use ($terme) {
                $q->where('libelle', 'like', $terme)
                    ->orWhere('cle', 'like', $terme)
                    ->orWhere('filiere', 'like', $terme)
                    ->orWhereHas('matiere', fn ($m) => $m->where('code', 'like', $terme)->orWhere('nom', 'like', $terme));
            });
        }

        return $query->orderByDesc('id');
    }

    /**
     * Liste paginée enrichie des groupes virtuels pour l'interface de consultation (Phase B).
     *
     * @param  array<string, mixed>  $filtres
     * @return array{
     *     paginator: LengthAwarePaginator,
     *     lignes: array<int, array<string, mixed>>,
     *     kpis: array{total_groupes: int, actifs_proposes: int, valides: int, publies: int, total_eligibles: int, alertes_count: int},
     *     filtres_appliques: array<string, mixed>,
     *     referentiels: array<string, mixed>
     * }
     */
    public function listerGroupesVirtuels(array $filtres, User $user, int $parPage = 15): array
    {
        $f = $this->normaliserFiltres($filtres, $user);
        $query = $this->construireRequete($f, $user);

        // KPIs sur la sélection filtrée
        $tousPourKpis = (clone $query)->limit(200)->get();
        $lignesKpis = $tousPourKpis->map(fn (GroupeVirtuelExamen $gv) => $this->resumerGroupeVirtuel($gv));

        if ($f['statut'] === 'derive') {
            $idsEnDerive = $lignesKpis->filter(fn (array $item) => $item['en_derive'] || ! $item['conforme'])->pluck('id')->all();
            $query->whereIn('id', $idsEnDerive === [] ? [0] : $idsEnDerive);
            $lignesKpis = $lignesKpis->filter(fn (array $item) => $item['en_derive'] || ! $item['conforme'])->values();
        }

        $paginator = $query->paginate($parPage)->withQueryString();

        $lignes = collect($paginator->items())
            ->map(fn (GroupeVirtuelExamen $gv) => $this->resumerGroupeVirtuel($gv))
            ->values()
            ->all();

        $kpis = [
            'total_groupes' => $f['statut'] === 'derive' ? $lignesKpis->count() : $paginator->total(),
            'actifs_proposes' => $lignesKpis->where('statut_generation', GroupeVirtuelExamen::STATUT_PROPOSE)->count(),
            'valides' => $lignesKpis->where('statut_validation', 'valide')->count(),
            'publies' => $lignesKpis->where('statut_publication', 'publie')->count(),
            'total_eligibles' => (int) $lignesKpis->sum('effectif_eligible'),
            'alertes_count' => $lignesKpis->filter(fn (array $r) => ! $r['conforme'] || $r['en_derive'])->count(),
        ];

        return [
            'paginator' => $paginator,
            'lignes' => $lignes,
            'kpis' => $kpis,
            'filtres_appliques' => $f,
            'referentiels' => $this->chargerReferentielsFiltres($f['faculte_id'], $f['annee_id']),
        ];
    }

    /**
     * Résume un groupe virtuel pour le tableau principal et les synthèses.
     *
     * @return array<string, mixed>
     */
    public function resumerGroupeVirtuel(GroupeVirtuelExamen $groupe): array
    {
        $groupe->loadMissing([
            'examen.session.anneeUniversitaire',
            'examen.programme.faculte',
            'examen.niveau',
            'epreuve.session.anneeUniversitaire',
            'epreuve.matiere.ue.programme.faculte',
            'epreuve.salle',
            'matiere.ue.programme.faculte',
            'programme.faculte',
            'niveau',
            'createur',
            'membres.etudiant.groupe',
            'membres.groupeSource',
        ]);

        $epreuve = $groupe->epreuve;
        $examen = $groupe->examen ?? $epreuve?->examen;
        $session = $examen?->session ?? $epreuve?->session;
        $annee = $session?->anneeUniversitaire ?? $examen?->anneeUniversitaire ?? $epreuve?->anneeUniversitaire;
        $matiere = $groupe->matiere ?? $epreuve?->matiere;
        $programme = $groupe->programme ?? $examen?->programme ?? $matiere?->ue?->programme;
        $niveau = $groupe->niveau ?? $examen?->niveau;
        $faculte = $programme?->faculte;

        $sourcesIds = $groupe->identifiantsSources();
        $groupesSources = $sourcesIds === []
            ? collect()
            : Group::query()->whereIn('id', $sourcesIds)->with('faculte')->orderBy('code')->get();

        if ($faculte === null && $groupesSources->isNotEmpty()) {
            $faculte = $groupesSources->first()?->faculte;
        }

        // Vérification de cohérence et dérive
        $controle = $this->evaluerCoherenceEtDerive($groupe, $groupesSources);

        $statutGeneration = $groupe->statut;
        $statutGenerationLibelle = match ($statutGeneration) {
            GroupeVirtuelExamen::STATUT_PROPOSE => 'Proposition générée',
            GroupeVirtuelExamen::STATUT_REMPLACE => 'Remplacé (historique)',
            GroupeVirtuelExamen::STATUT_ANNULE => 'Annulé',
            default => ucfirst((string) $statutGeneration),
        };

        $dateValidation = $epreuve?->population_validated_at;
        if ($controle['en_derive']) {
            $statutValidation = 'derive';
            $statutValidationLibelle = 'Dérive détectée — revalidation requise';
        } elseif ($dateValidation !== null && $statutGeneration === GroupeVirtuelExamen::STATUT_PROPOSE) {
            $statutValidation = 'valide';
            $statutValidationLibelle = 'Population validée';
        } else {
            $statutValidation = 'non_valide';
            $statutValidationLibelle = 'Proposition non validée';
        }

        $statutExamen = $examen?->statut ?? $epreuve?->statut ?? 'draft';
        $estPublie = in_array($statutExamen, ['published', 'ongoing', 'completed'], true)
            && $statutGeneration === GroupeVirtuelExamen::STATUT_PROPOSE;

        $statutPublication = $estPublie ? 'publie' : 'non_publie';
        $statutPublicationLibelle = $estPublie ? 'Publié officiellement' : 'Non publié';

        $sourcesListe = $groupesSources->map(fn (Group $g) => [
            'id' => (int) $g->id,
            'code' => (string) $g->code,
            'nom' => (string) $g->nom,
        ])->values()->all();

        $sourcesCodes = $groupesSources->pluck('code')->implode(' + ');

        return [
            'id' => (int) $groupe->id,
            'uuid' => (string) $groupe->uuid,
            'cle' => (string) $groupe->cle,
            'cle_courte' => substr((string) $groupe->cle, 0, 10),
            'libelle' => (string) $groupe->libelle,
            'filiere' => $groupe->filiere,
            'annee_id' => $annee?->id,
            'annee_libelle' => $annee?->libelle ?? '—',
            'session_id' => $session?->id,
            'session_code' => $session?->code ?? '—',
            'session_nom' => $session?->nom ?? '—',
            'faculte_id' => $faculte?->id ?? $groupe->faculteId(),
            'faculte_code' => $faculte?->code ?? '—',
            'faculte_nom' => $faculte?->nom ?? '—',
            'programme_id' => $programme?->id,
            'programme_code' => $programme?->code ?? '—',
            'programme_nom' => $programme?->nom ?? '—',
            'niveau_id' => $niveau?->id,
            'niveau_code' => $niveau?->code ?? ($matiere?->niveau ?? '—'),
            'niveau_nom' => $niveau?->nom ?? ($matiere?->niveau ?? '—'),
            'matiere_id' => $matiere?->id,
            'matiere_code' => $matiere?->code ?? '—',
            'matiere_nom' => $matiere?->nom ?? '—',
            'examen_id' => $examen?->id,
            'examen_code' => $examen?->code ?? ('EX-'.$examen?->id),
            'examen_libelle' => $examen?->libelle ?? '—',
            'epreuve_id' => $epreuve?->id,
            'epreuve_code' => $epreuve?->code ?? ($epreuve ? 'EPR-'.$epreuve->id : '—'),
            'epreuve_date' => $epreuve?->date?->format('d/m/Y') ?? '—',
            'epreuve_horaire' => $epreuve ? (substr((string) $epreuve->heure_debut, 0, 5).' - '.substr((string) $epreuve->heure_fin, 0, 5)) : '—',
            'salle_id' => $epreuve?->salle?->id,
            'salle_code' => $epreuve?->salle?->code,
            'salle_nom' => $epreuve?->salle?->nom,
            'salle_capacite' => $epreuve?->salle?->capacite,
            'groupes_sources' => $sourcesListe,
            'groupes_sources_codes' => $sourcesCodes !== '' ? $sourcesCodes : '—',
            'effectif_attendu' => $controle['effectif_attendu'],
            'effectif_eligible' => $controle['effectif_eligible'],
            'effectif_enregistre' => $controle['effectif_enregistre'],
            'effectif_planifie' => $controle['effectif_planifie'],
            'effectif_exclu' => $controle['effectif_exclu'],
            'statut_generation' => $statutGeneration,
            'statut_generation_libelle' => $statutGenerationLibelle,
            'statut_validation' => $statutValidation,
            'statut_validation_libelle' => $statutValidationLibelle,
            'statut_publication' => $statutPublication,
            'statut_publication_libelle' => $statutPublicationLibelle,
            'est_proposition_non_validee' => $statutValidation !== 'valide' || ! $estPublie,
            'date_generation' => $groupe->created_at?->format('d/m/Y H:i') ?? '—',
            'date_validation' => $dateValidation?->format('d/m/Y H:i'),
            'date_publication' => $examen?->published_at?->format('d/m/Y H:i'),
            'cree_par_nom' => $groupe->createur?->name ?? 'Système',
            'conforme' => $controle['conforme'],
            'en_derive' => $controle['en_derive'],
            'export_officiel_autorise' => $controle['export_officiel_autorise'],
            'alertes' => $controle['alertes'],
            'bloquants' => $controle['bloquants'],
        ];
    }

    /**
     * Construit la fiche détaillée complète d'un groupe virtuel et la liste de ses étudiants (Phase C).
     *
     * @return array<string, mixed>
     */
    public function construireFicheDetaillee(GroupeVirtuelExamen $groupe, User $user): array
    {
        $this->verifierAccesGroupeVirtuel($user, $groupe);

        $groupe->loadMissing([
            'examen.session.anneeUniversitaire',
            'examen.programme.faculte',
            'examen.niveau',
            'epreuve.session.anneeUniversitaire',
            'epreuve.matiere.ue.programme.faculte',
            'epreuve.salle',
            'epreuve.salle',
            'epreuve.candidats.etudiant.groupe',
            'epreuve.candidats.salle',
            'matiere.ue.programme.faculte',
            'programme.faculte',
            'niveau',
            'createur',
            'remplacePar',
            'membres.etudiant.faculte',
            'membres.etudiant.groupe',
            'membres.groupeSource.programme',
            'membres.groupeSource.niveauRef',
        ]);

        $resume = $this->resumerGroupeVirtuel($groupe);
        $epreuve = $groupe->epreuve;
        $matiere = $groupe->matiere ?? $epreuve?->matiere;
        $sourcesIds = $groupe->identifiantsSources();

        $groupesSourcesModels = $sourcesIds === []
            ? collect()
            : Group::query()
                ->whereIn('id', $sourcesIds)
                ->with(['programme', 'niveauRef', 'faculte'])
                ->withCount('etudiants')
                ->orderBy('code')
                ->get()
                ->keyBy('id');

        $controle = $this->evaluerCoherenceEtDerive($groupe, $groupesSourcesModels->values());
        $peutVoirAnonyme = $this->peutVoirNumeroAnonyme($user);

        // Indexation des candidats planifiés sur l'épreuve pour récupérer salle/place/anonymat
        $candidatsParEtudiantId = $epreuve !== null
            ? $epreuve->candidats->keyBy(fn (Candidat $c) => (int) $c->etudiant_id)
            : collect();

        $membresEligibles = $groupe->membres
            ->where('decision', GroupeVirtuelMembre::DECISION_ELIGIBLE)
            ->sortBy(fn (GroupeVirtuelMembre $m) => mb_strtoupper(($m->etudiant?->nom ?? '').' '.($m->etudiant?->prenom ?? '')))
            ->values();

        $etudiantsEligibles = [];
        $index = 1;

        foreach ($membresEligibles as $membre) {
            $etudiant = $membre->etudiant;
            if ($etudiant === null) {
                continue;
            }

            /** @var Candidat|null $candidat */
            $candidat = $candidatsParEtudiantId->get((int) $etudiant->id);
            $groupeOrigine = $membre->groupeSource ?? $etudiant->groupe ?? $groupesSourcesModels->get((int) $membre->groupe_source_id);

            $encoreEligible = isset($controle['eligibles_actuels_par_id'][(int) $etudiant->id]);

            $ligneEtudiant = [
                'numero' => $index++,
                'etudiant_id' => (int) $etudiant->id,
                'num_etudiant' => (string) ($etudiant->num_inscription ?: ('ETU-'.$etudiant->id)),
                'nom' => (string) ($etudiant->nom ?? ''),
                'prenom' => (string) ($etudiant->prenom ?? ''),
                'nom_complet' => trim(mb_strtoupper((string) ($etudiant->nom ?? '')).' '.((string) ($etudiant->prenom ?? ''))),
                'faculte_code' => (string) ($etudiant->faculte?->code ?? $resume['faculte_code'] ?? '—'),
                'faculte_nom' => (string) ($etudiant->faculte?->nom ?? $resume['faculte_nom'] ?? '—'),
                'groupe_source_id' => $groupeOrigine?->id,
                'groupe_origine_code' => (string) ($groupeOrigine?->code ?? '—'),
                'groupe_origine_nom' => (string) ($groupeOrigine?->nom ?? '—'),
                'groupe_origine' => $groupeOrigine ? ($groupeOrigine->code.' — '.$groupeOrigine->nom) : '—',
                'matiere_code' => (string) ($matiere?->code ?? '—'),
                'matiere_nom' => (string) ($matiere?->nom ?? '—'),
                'matiere' => $matiere ? ($matiere->code.' — '.$matiere->nom) : '—',
                'statut_eligibilite' => $encoreEligible ? 'Éligible' : 'Dérive : non éligible actuellement',
                'est_eligible_actuel' => $encoreEligible,
                'regle' => (string) ($membre->regle ?? 'inscription_module_active'),
                'salle' => (string) ($candidat?->salleEffective()?->code ?? $epreuve?->salle?->code ?? '—'),
                'place' => (string) ($candidat?->place ?? '—'),
            ];

            if ($peutVoirAnonyme) {
                $ligneEtudiant['numero_anonyme'] = (string) ($candidat?->numero_anonyme ?? '—');
            }

            $etudiantsEligibles[] = $ligneEtudiant;
        }

        // Liste détaillée des exclusions (membres non_eligible / anomalie + exclus actuels des groupes sources)
        $etudiantsExclus = [];
        $exclusTraites = [];

        foreach ($groupe->membres->where('decision', '!=', GroupeVirtuelMembre::DECISION_ELIGIBLE) as $membreExclu) {
            $etudiant = $membreExclu->etudiant;
            $etudiantId = (int) $membreExclu->etudiant_id;
            $exclusTraites[$etudiantId] = true;
            $groupeOrigine = $membreExclu->groupeSource ?? $etudiant?->groupe;

            $etudiantsExclus[] = [
                'etudiant_id' => $etudiantId,
                'num_etudiant' => (string) ($etudiant?->num_inscription ?: ('ETU-'.$etudiantId)),
                'nom' => (string) ($etudiant?->nom ?? 'Introuvable'),
                'prenom' => (string) ($etudiant?->prenom ?? ''),
                'groupe_origine_code' => (string) ($groupeOrigine?->code ?? '—'),
                'decision' => (string) $membreExclu->decision,
                'regle' => (string) ($membreExclu->regle ?? '—'),
                'motif' => (string) ($membreExclu->motif ?: 'Étudiant non inscrit ou non éligible à la matière.'),
            ];
        }

        foreach ($controle['exclus_actuels'] as $excluActuel) {
            $etudiantId = (int) $excluActuel['etudiant_id'];
            if (isset($exclusTraites[$etudiantId])) {
                continue;
            }
            $exclusTraites[$etudiantId] = true;
            $etudiantsExclus[] = $excluActuel;
        }

        // Détail par groupe académique source
        $detailGroupesSources = [];
        foreach ($groupesSourcesModels as $gs) {
            $membresSource = $groupe->membres->where('groupe_source_id', $gs->id);
            $eligiblesSource = $membresSource->where('decision', GroupeVirtuelMembre::DECISION_ELIGIBLE)->count();
            $exclusSource = $membresSource->where('decision', '!=', GroupeVirtuelMembre::DECISION_ELIGIBLE)->count();

            $detailGroupesSources[] = [
                'id' => (int) $gs->id,
                'code' => (string) $gs->code,
                'nom' => (string) $gs->nom,
                'niveau' => (string) ($gs->niveauRef?->code ?? $gs->niveau ?? '—'),
                'programme' => (string) ($gs->programme?->code ?? '—'),
                'filiere' => (string) ($gs->filiere ?? '—'),
                'effectif_origine' => (int) ($gs->etudiants_count ?? 0),
                'eligibles_retenus' => $eligiblesSource,
                'exclus' => $exclusSource,
            ];
        }

        return [
            'groupe' => $groupe,
            'resume' => $resume,
            'peut_voir_anonyme' => $peutVoirAnonyme,
            'groupes_sources_detail' => $detailGroupesSources,
            'etudiants_eligibles' => $etudiantsEligibles,
            'etudiants_exclus' => $etudiantsExclus,
            'effectifs' => [
                'origine_total' => (int) collect($detailGroupesSources)->sum('effectif_origine'),
                'attendus_reference' => $controle['effectif_attendu'],
                'enregistres_eligibles' => count($etudiantsEligibles),
                'planifies_epreuve' => $controle['effectif_planifie'],
                'exclus_total' => count($etudiantsExclus),
            ],
            'explications_ecarts' => $controle['explications_ecarts'],
            'correspondance' => $controle['correspondance_epreuve'],
            'en_derive' => $controle['en_derive'],
            'derive_depuis_validation' => $controle['derive_depuis_validation'],
            'derive_depuis_generation' => $controle['derive_depuis_generation'],
            'conforme' => $controle['conforme'],
            'export_officiel_autorise' => $controle['export_officiel_autorise'],
            'alertes' => $controle['alertes'],
            'bloquants' => $controle['bloquants'],
        ];
    }

    /**
     * Évalue la cohérence Matière ↔ Groupes sources ↔ Membres du groupe virtuel ↔ Épreuve,
     * ainsi que la dérive de population depuis la génération ou la validation.
     *
     * @param  Collection<int, Group>  $groupesSources
     * @return array<string, mixed>
     */
    public function evaluerCoherenceEtDerive(GroupeVirtuelExamen $groupe, Collection $groupesSources): array
    {
        $epreuve = $groupe->epreuve;
        $matiereId = $groupe->matiere_id ?? $epreuve?->matiere_id;
        $sourcesIds = $groupe->identifiantsSources();

        $alertes = [];
        $bloquants = [];
        $explicationsEcarts = [];

        if ($matiereId === null) {
            $msg = 'Matière absente : le groupe virtuel doit être rattaché à une matière explicite.';
            $bloquants[] = $msg;
            $alertes[] = $msg;
        }

        if ($sourcesIds === [] || $groupesSources->count() !== count($sourcesIds)) {
            $msg = 'Groupes académiques sources manquants ou introuvables.';
            $bloquants[] = $msg;
            $alertes[] = $msg;
        }

        // 1. Cohérence académique entre les groupes sources et le périmètre
        if ($epreuve !== null) {
            $perimetre = $this->groupeVirtuelService->perimetreAcademique($epreuve);
            foreach ($groupesSources as $gs) {
                if (! $this->groupeVirtuelService->correspond($gs, $perimetre)) {
                    $msg = "Le groupe source {$gs->code} ne correspond pas au périmètre académique de l'épreuve (faculté, programme, niveau ou filière incompatible).";
                    $bloquants[] = $msg;
                    $alertes[] = $msg;
                }
            }
        }

        // 2. Calcul de la population actuellement éligible dans les groupes sources pour la matière
        $eligiblesActuelsParId = [];
        $exclusActuels = [];
        $totalOrigine = 0;

        if ($sourcesIds !== []) {
            $etudiantsSources = Etudiant::query()
                ->whereIn('groupe_id', $sourcesIds)
                ->with('groupe')
                ->orderBy('nom')
                ->orderBy('prenom')
                ->get();

            $totalOrigine = $etudiantsSources->count();

            foreach ($etudiantsSources as $etudiant) {
                if ($epreuve !== null) {
                    $decision = $this->serviceEligibilite->evaluer($epreuve, $etudiant);
                    if ($decision->decision === DecisionEligibiliteExamen::ELIGIBLE) {
                        $eligiblesActuelsParId[(int) $etudiant->id] = $etudiant;
                    } else {
                        $exclusActuels[] = [
                            'etudiant_id' => (int) $etudiant->id,
                            'num_etudiant' => (string) ($etudiant->num_inscription ?: ('ETU-'.$etudiant->id)),
                            'nom' => (string) ($etudiant->nom ?? ''),
                            'prenom' => (string) ($etudiant->prenom ?? ''),
                            'groupe_origine_code' => (string) ($etudiant->groupe?->code ?? '—'),
                            'decision' => (string) $decision->decision,
                            'regle' => (string) ($decision->regle ?? '—'),
                            'motif' => (string) ($decision->motif ?? 'Étudiant non éligible à la matière.'),
                        ];
                    }
                }
            }
        }

        // 3. Vérification de chaque membre enregistré comme éligible dans le groupe virtuel
        $membresEligibles = $groupe->membres->where('decision', GroupeVirtuelMembre::DECISION_ELIGIBLE);
        $idsMembresEligibles = $membresEligibles->pluck('etudiant_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $idsActuelsEligibles = array_keys($eligiblesActuelsParId);
        sort($idsActuelsEligibles);

        $membresInvalidesCount = 0;
        foreach ($membresEligibles as $membre) {
            $etudiant = $membre->etudiant;
            if ($etudiant === null) {
                $membresInvalidesCount++;

                continue;
            }

            if ($sourcesIds !== [] && ! in_array((int) $etudiant->groupe_id, $sourcesIds, true)) {
                $membresInvalidesCount++;
                $msg = "L'étudiant {$etudiant->num_inscription} appartient à un groupe hors du périmètre du groupe virtuel.";
                $bloquants[] = $msg;
                $alertes[] = $msg;

                continue;
            }

            if ($epreuve !== null && ! isset($eligiblesActuelsParId[(int) $etudiant->id])) {
                $membresInvalidesCount++;
            }
        }

        // 4. Détection de dérive depuis la génération du groupe virtuel
        $deriveDepuisGeneration = $epreuve !== null && ($idsMembresEligibles !== $idsActuelsEligibles);
        if ($deriveDepuisGeneration) {
            $ajoutCount = count(array_diff($idsActuelsEligibles, $idsMembresEligibles));
            $retraitCount = count(array_diff($idsMembresEligibles, $idsActuelsEligibles));
            $msg = "Dérive de population détectée depuis la génération du groupe virtuel ({$retraitCount} devenu(s) non éligible(s), {$ajoutCount} nouvellement éligible(s)). Une revalidation est requise.";
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        // 5. Détection de dérive depuis la validation de population sur l'épreuve (G4)
        $deriveDepuisValidation = $epreuve !== null && $this->preparationService->populationModifiee($epreuve);
        if ($deriveDepuisValidation) {
            $msg = "La population de l'épreuve a changé depuis sa validation officielle du "
                .($epreuve->population_validated_at?->format('d/m/Y H:i') ?? '—')
                .'. Veuillez revalider la population via le workflow de préparation.';
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        // 6. Contrôle CorrespondanceExamenService sur l'épreuve associée
        $correspondanceEpreuve = null;
        $effectifPlanifie = 0;

        if ($epreuve !== null) {
            $correspondanceEpreuve = $this->correspondanceService->analyserEpreuve($epreuve);
            $effectifPlanifie = (int) ($correspondanceEpreuve['compteurs']['planifies'] ?? 0);

            foreach ($correspondanceEpreuve['bloquants'] as $b) {
                // L'absence de génération préalable des candidats sur l'épreuve n'est pas un blocage
                // pour le groupe virtuel (qui précède et prépare la génération des candidats)
                if (str_contains($b, 'absents de la planification')) {
                    if (! in_array($b, $alertes, true)) {
                        $alertes[] = $b;
                    }

                    continue;
                }
                if (! in_array($b, $bloquants, true)) {
                    $bloquants[] = $b;
                }
                if (! in_array($b, $alertes, true)) {
                    $alertes[] = $b;
                }
            }
        }

        $effectifAttendu = $epreuve !== null ? count($idsActuelsEligibles) : count($idsMembresEligibles);
        $effectifEligible = count($idsMembresEligibles);
        $effectifExclu = $groupe->membres->where('decision', '!=', GroupeVirtuelMembre::DECISION_ELIGIBLE)->count();

        // Explication systématique des différences d'effectifs (Phase C)
        if ($totalOrigine !== $effectifEligible) {
            $diffExclus = max(0, $totalOrigine - $effectifEligible);
            $explicationsEcarts[] = "Sur {$totalOrigine} étudiant(s) dans les groupes académiques sources, {$effectifEligible} sont éligibles à la matière et {$diffExclus} sont exclus (non inscrits au module ou inscription académique inactive).";
        }

        if ($effectifAttendu !== $effectifEligible) {
            $explicationsEcarts[] = "Écart entre l'effectif éligible de référence actuel ({$effectifAttendu}) et l'effectif enregistré dans le groupe virtuel ({$effectifEligible}) : la population a évolué après la génération.";
        }

        if ($effectifPlanifie > 0 && $effectifPlanifie !== $effectifEligible) {
            $explicationsEcarts[] = "Écart entre les membres éligibles du groupe virtuel ({$effectifEligible}) et les candidats planifiés sur l'épreuve ({$effectifPlanifie}).";
        } elseif ($effectifPlanifie === 0 && $effectifEligible > 0) {
            $explicationsEcarts[] = "Les {$effectifEligible} étudiant(s) éligibles du groupe virtuel ne sont pas encore générés dans la liste des candidats planifiés de l'épreuve.";
        }

        if ($groupe->statut === GroupeVirtuelExamen::STATUT_ANNULE) {
            $msg = 'Ce groupe virtuel a été annulé et ne peut pas faire l’objet d’un export officiel.';
            $bloquants[] = $msg;
            $alertes[] = $msg;
        }

        $enDerive = $deriveDepuisGeneration || $deriveDepuisValidation;
        $conforme = $bloquants === [] && ! $enDerive && $membresInvalidesCount === 0;
        $exportOfficielAutorise = $conforme && $groupe->statut !== GroupeVirtuelExamen::STATUT_ANNULE;

        return [
            'effectif_attendu' => $effectifAttendu,
            'effectif_eligible' => $effectifEligible,
            'effectif_enregistre' => $groupe->membres->count(),
            'effectif_planifie' => $effectifPlanifie,
            'effectif_exclu' => $effectifExclu,
            'eligibles_actuels_par_id' => $eligiblesActuelsParId,
            'exclus_actuels' => $exclusActuels,
            'derive_depuis_generation' => $deriveDepuisGeneration,
            'derive_depuis_validation' => $deriveDepuisValidation,
            'en_derive' => $enDerive,
            'conforme' => $conforme,
            'export_officiel_autorise' => $exportOfficielAutorise,
            'alertes' => array_values(array_unique($alertes)),
            'bloquants' => array_values(array_unique($bloquants)),
            'explications_ecarts' => $explicationsEcarts,
            'correspondance_epreuve' => $correspondanceEpreuve,
        ];
    }

    /**
     * Réactualise un groupe virtuel en dérive :
     * 1. Archive l'ancienne proposition (statut = remplace)
     * 2. Régénère une nouvelle proposition propre à jour via GroupeVirtuelExamenService
     * 3. Lie l'ancienne proposition à la nouvelle via remplace_par_id
     */
    public function resynchroniserGroupeVirtuel(GroupeVirtuelExamen $groupe, int $auteurId): GroupeVirtuelExamen
    {
        $epreuve = $groupe->epreuve;
        if ($epreuve === null) {
            throw new DomainException("Impossible de réactualiser : l'épreuve associée est introuvable.");
        }

        return DB::transaction(function () use ($groupe, $epreuve, $auteurId) {
            $sourcesIds = $groupe->identifiantsSources();

            // 1. Archive le groupe en dérive
            $groupe->update([
                'statut' => GroupeVirtuelExamen::STATUT_REMPLACE,
            ]);

            // 2. Régénère une proposition fraîche à partir des inscrits éligibles actuels
            $nouveauGroupe = $this->groupeVirtuelService->proposer($epreuve, $sourcesIds, $auteurId);

            // 3. Relie l'historique de remplacement
            $groupe->update([
                'remplace_par_id' => $nouveauGroupe->id,
            ]);

            return $nouveauGroupe;
        });
    }

    /**
     * Exporte un groupe virtuel ou sa liste d'étudiants dans le format demandé (xlsx, csv, pdf, html).
     *
     * @return array{contenu: string, mime: string, nom_fichier: string, effectif: int}
     */
    public function exporterGroupeVirtuel(
        GroupeVirtuelExamen $groupe,
        string $format,
        string $type,
        User $user,
    ): array {
        $format = mb_strtolower(trim($format));
        if (! in_array($format, self::FORMATS_EXPORT, true)) {
            abort(404, "Format d'export non pris en charge : {$format}.");
        }

        $type = in_array($type, ['fiche', 'etudiants', 'emargement'], true) ? $type : 'etudiants';

        $fiche = $this->construireFicheDetaillee($groupe, $user);

        // Blocage de l'export officiel lorsque la correspondance est invalide ou en dérive (Phase C, F, G.11, G.12)
        if (! $fiche['export_officiel_autorise']) {
            $motif = implode(' ', $fiche['bloquants']) ?: CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE;
            throw new DomainException("Export officiel bloqué : {$motif}");
        }

        $dateStr = now()->format('Ymd');
        $identifiant = $groupe->id;

        if ($type === 'fiche') {
            $entetes = [
                'ID Groupe Virtuel',
                'Libellé',
                'Année universitaire',
                'Session',
                'Faculté',
                'Programme',
                'Niveau',
                'Matière',
                'Groupes sources',
                'Effectif attendu',
                'Effectif éligible',
                'Effectif exclu',
                'Statut génération',
                'Statut validation',
                'Statut publication',
                'Date génération',
            ];

            $r = $fiche['resume'];
            $lignes = [[
                'ID Groupe Virtuel' => (string) $r['id'],
                'Libellé' => (string) $r['libelle'],
                'Année universitaire' => (string) $r['annee_libelle'],
                'Session' => (string) ($r['session_code'].' - '.$r['session_nom']),
                'Faculté' => (string) $r['faculte_nom'],
                'Programme' => (string) $r['programme_code'],
                'Niveau' => (string) $r['niveau_code'],
                'Matière' => (string) ($r['matiere_code'].' - '.$r['matiere_nom']),
                'Groupes sources' => (string) $r['groupes_sources_codes'],
                'Effectif attendu' => (string) $r['effectif_attendu'],
                'Effectif éligible' => (string) $r['effectif_eligible'],
                'Effectif exclu' => (string) $r['effectif_exclu'],
                'Statut génération' => (string) $r['statut_generation_libelle'],
                'Statut validation' => (string) $r['statut_validation_libelle'],
                'Statut publication' => (string) $r['statut_publication_libelle'],
                'Date génération' => (string) $r['date_generation'],
            ]];

            $nomBase = "groupe_virtuel_examen_{$identifiant}_{$dateStr}";
            $documentPrint = 'fiche';
        } else {
            $entetes = [
                'N°',
                'Numéro étudiant',
                'Nom',
                'Prénom',
                'Faculté',
                'Groupe d\'origine',
                'Matière',
                'Statut d\'éligibilité',
            ];

            if ($fiche['peut_voir_anonyme']) {
                $entetes[] = 'Numéro d\'anonymat';
            }

            $lignes = [];
            foreach ($fiche['etudiants_eligibles'] as $etudiant) {
                $ligne = [
                    'N°' => (string) $etudiant['numero'],
                    'Numéro étudiant' => (string) $etudiant['num_etudiant'],
                    'Nom' => (string) $etudiant['nom'],
                    'Prénom' => (string) $etudiant['prenom'],
                    'Faculté' => (string) $etudiant['faculte_code'],
                    'Groupe d\'origine' => (string) $etudiant['groupe_origine_code'],
                    'Matière' => (string) $etudiant['matiere'],
                    'Statut d\'éligibilité' => (string) $etudiant['statut_eligibilite'],
                ];

                if ($fiche['peut_voir_anonyme']) {
                    $ligne['Numéro d\'anonymat'] = (string) ($etudiant['numero_anonyme'] ?? '—');
                }

                $lignes[] = $ligne;
            }

            $nomBase = "groupe_virtuel_etudiants_{$identifiant}_{$dateStr}";
            $documentPrint = $type === 'emargement' ? 'emargement' : 'etudiants';
        }

        return $this->serialiserExport(
            $format,
            $entetes,
            $lignes,
            $nomBase,
            fn () => $this->rendreDocumentGroupe($groupe, $documentPrint, $user, $format === 'pdf', $fiche),
            'export.groupe_virtuel.'.$type,
            'GV-'.$groupe->id,
        );
    }

    /**
     * Exporte une sélection de groupes virtuels (par session, par matière ou selon les filtres actifs).
     *
     * @param  array<string, mixed>  $filtres
     * @return array{contenu: string, mime: string, nom_fichier: string, effectif: int}
     */
    public function exporterSelection(
        array $filtres,
        string $format,
        User $user,
    ): array {
        $format = mb_strtolower(trim($format));
        if (! in_array($format, self::FORMATS_EXPORT, true)) {
            abort(404, "Format d'export non pris en charge : {$format}.");
        }

        $f = $this->normaliserFiltres($filtres, $user);
        $query = $this->construireRequete($f, $user);

        $groupes = [];
        $resumes = [];
        $bloquantsGlobaux = [];

        // Parcours par lot (chunk) pour maîtriser la mémoire sur les volumes importants
        $query->chunk(200, function ($lot) use (&$groupes, &$resumes, &$bloquantsGlobaux, $f) {
            foreach ($lot as $gv) {
                $resume = $this->resumerGroupeVirtuel($gv);
                if ($f['statut'] === 'derive' && ! $resume['en_derive'] && $resume['conforme']) {
                    continue;
                }
                $groupes[] = $gv;
                $resumes[] = $resume;

                if (! $resume['export_officiel_autorise']) {
                    foreach ($resume['bloquants'] as $b) {
                        $bloquantsGlobaux[] = "[{$resume['libelle']}] {$b}";
                    }
                }
            }
        });

        if ($bloquantsGlobaux !== []) {
            throw new DomainException(
                'Export officiel bloqué : '.implode(' | ', array_slice(array_values(array_unique($bloquantsGlobaux)), 0, 3))
            );
        }

        $entetes = [
            'ID',
            'Libellé Groupe Virtuel',
            'Année universitaire',
            'Session',
            'Faculté',
            'Programme',
            'Niveau',
            'Code Matière',
            'Matière',
            'Groupes d\'origine',
            'Effectif attendu',
            'Effectif éligible',
            'Effectif exclu',
            'Statut génération',
            'Statut validation',
            'Statut publication',
            'Date génération',
        ];

        $lignes = [];
        foreach ($resumes as $r) {
            $lignes[] = [
                'ID' => (string) $r['id'],
                'Libellé Groupe Virtuel' => (string) $r['libelle'],
                'Année universitaire' => (string) $r['annee_libelle'],
                'Session' => (string) ($r['session_code'] !== '—' ? $r['session_code'].' - '.$r['session_nom'] : $r['session_nom']),
                'Faculté' => (string) $r['faculte_code'],
                'Programme' => (string) $r['programme_code'],
                'Niveau' => (string) $r['niveau_code'],
                'Code Matière' => (string) $r['matiere_code'],
                'Matière' => (string) $r['matiere_nom'],
                'Groupes d\'origine' => (string) $r['groupes_sources_codes'],
                'Effectif attendu' => (string) $r['effectif_attendu'],
                'Effectif éligible' => (string) $r['effectif_eligible'],
                'Effectif exclu' => (string) $r['effectif_exclu'],
                'Statut génération' => (string) $r['statut_generation_libelle'],
                'Statut validation' => (string) $r['statut_validation_libelle'],
                'Statut publication' => (string) $r['statut_publication_libelle'],
                'Date génération' => (string) $r['date_generation'],
            ];
        }

        $dateStr = now()->format('Ymd');
        if ($f['matiere_id'] !== null) {
            $nomBase = 'groupes_virtuels_examens_matiere_'.$f['matiere_id'].'_'.$dateStr;
            $typeDoc = 'synthese_matiere';
        } else {
            $idSession = $f['session_id'] ?? 'toutes';
            $nomBase = 'groupes_virtuels_examens_session_'.$idSession.'_'.$dateStr;
            $typeDoc = 'synthese_session';
        }

        return $this->serialiserExport(
            $format,
            $entetes,
            $lignes,
            $nomBase,
            fn () => $this->rendreDocumentSelection($f, $typeDoc, $user, $format === 'pdf', $resumes),
            'export.groupes_virtuels.selection',
            'GV-SEL-'.($f['session_id'] ?? $f['matiere_id'] ?? 'ALL'),
        );
    }

    /**
     * Génère le rendu HTML imprimable (ou PDF) d'un document dédié à un groupe virtuel (Phase E) :
     * - fiche : Fiche récapitulative d'un groupe virtuel
     * - etudiants : Liste nominative des étudiants
     * - emargement : Feuille d'émargement avec cases de signature
     *
     * @param  array<string, mixed>|null  $fichePrecalculee
     */
    public function rendreDocumentGroupe(
        GroupeVirtuelExamen $groupe,
        string $document,
        User $user,
        bool $estPdf = false,
        ?array $fichePrecalculee = null,
    ): string {
        $document = in_array($document, self::DOCUMENTS_GROUPE, true) ? $document : 'fiche';
        $fiche = $fichePrecalculee ?? $this->construireFicheDetaillee($groupe, $user);

        if (! $fiche['export_officiel_autorise']) {
            $motif = implode(' ', $fiche['bloquants']) ?: CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE;
            throw new DomainException("Impression officielle bloquée : {$motif}");
        }

        $r = $fiche['resume'];
        $titreDocument = match ($document) {
            'fiche' => 'Fiche récapitulative du groupe virtuel d\'examen',
            'emargement' => 'Feuille d\'émargement — Groupe virtuel d\'examen',
            default => 'Liste nominative des étudiants — Groupe virtuel d\'examen',
        };

        $statutBandeau = $r['statut_publication'] === 'publie' && $r['statut_validation'] === 'valide'
            ? 'DOCUMENT OFFICIEL PUBLIÉ'
            : ($r['statut_validation'] === 'valide'
                ? 'POPULATION VALIDÉE — EN ATTENTE DE PUBLICATION'
                : 'PROPOSITION DE REGROUPEMENT VIRTUEL — NON VALIDÉE OFFICIELLEMENT');

        // Option B : Signature cryptographique et QR Code officiel de certification vectoriel
        $signaturePayload = 'UNIR-EXAM|GV:'.$groupe->id.'|EPR:'.($r['epreuve_id'] ?? '0').'|EFF:'.count($fiche['etudiants_eligibles']).'|SIG:'.substr(hash_hmac('sha256', (string) $groupe->cle.'|'.$groupe->id, config('app.key')), 0, 16);
        $qrCodeSvg = QrCodeSvg::generer($signaturePayload, 70);

        return view('modules.evaluation.groupes-virtuels.impression', [
            'modeDocument' => $document,
            'titreDocument' => $titreDocument,
            'statutBandeau' => $statutBandeau,
            'estPropositionNonValidee' => $r['est_proposition_non_validee'],
            'institution' => config('app.name', 'UniRegistre'),
            'dateEdition' => now()->format('d/m/Y à H:i'),
            'reference' => 'GV-DOC-'.$groupe->id.'-'.now()->format('Ymd'),
            'orientation' => $document === 'emargement' ? 'landscape' : 'portrait',
            'estPdf' => $estPdf,
            'qrCodeSvg' => $qrCodeSvg,
            'signaturePayload' => $signaturePayload,
            'fiche' => $fiche,
            'resume' => $r,
            'resumesSelection' => [],
            'syntheseParMatiere' => [],
            'effectifTotal' => count($fiche['etudiants_eligibles']),
        ])->render();
    }

    /**
     * Option B — Génère une archive ZIP contenant les feuilles d'émargement PDF officielles
     * de tous les groupes virtuels correspondant aux filtres actifs, avec bordereau récapitulatif.
     *
     * @param  array<string, mixed>  $filtres
     * @return array{chemin: string, nom_fichier: string, total_groupes: int, total_etudiants: int}
     */
    public function genererPackZipEmargements(array $filtres, User $user): array
    {
        $f = $this->normaliserFiltres($filtres, $user);
        $query = $this->construireRequete($f, $user);
        $groupes = $query->with(['matiere', 'epreuve.session', 'epreuve.salle', 'membres.etudiant'])->get();

        if ($groupes->isEmpty()) {
            throw new DomainException("Aucun groupe virtuel d'examen ne correspond aux filtres pour générer le pack d'émargements.");
        }

        $sessionNom = 'examens';
        if ($f['session_id'] !== null) {
            $sess = SessionExamen::find($f['session_id']);
            if ($sess !== null) {
                $sessionNom = Str::slug($sess->code ?: $sess->nom);
            }
        }

        $nomArchive = 'pack_emargements_'.$sessionNom.'_'.now()->format('Ymd_His').'.zip';
        $cheminTemp = (string) tempnam(sys_get_temp_dir(), 'zip_emargements_');
        $zip = new ZipArchive;

        if ($zip->open($cheminTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DomainException("Impossible de créer l'archive ZIP temporaire.");
        }

        $bordereau = [
            '==================================================================',
            "UNIREGISTRE — BORDEREAU DE LIVRAISON DU PACK D'ÉMARGEMENTS EXAMENS",
            'Généré le : '.now()->format('d/m/Y à H:i:s'),
            'Session   : '.$sessionNom,
            'Total     : '.$groupes->count().' groupe(s) virtuel(s)',
            '==================================================================',
            '',
            sprintf('%-4s | %-12s | %-10s | %-6s | %s', 'N°', 'CODE MATIÈRE', 'GROUPE', 'CAND.', 'LIBELLÉ'),
            str_repeat('-', 70),
        ];

        $totalCandidats = 0;
        $fichiersAjoutes = 0;
        $index = 1;

        foreach ($groupes as $gv) {
            try {
                $fiche = $this->construireFicheDetaillee($gv, $user);
                $html = $this->rendreDocumentGroupe($gv, 'emargement', $user, true, $fiche);
                $pdf = $this->pdfGenerator->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);

                $codeMatiere = Str::slug((string) ($gv->matiere?->code ?? 'MAT'), '_');
                $nomFichierPdf = sprintf('emargement_%02d_%s_GV%d.pdf', $index, $codeMatiere, $gv->id);
                $zip->addFromString($nomFichierPdf, $pdf);

                $nbMembres = count($fiche['etudiants_eligibles']);
                $totalCandidats += $nbMembres;
                $bordereau[] = sprintf(
                    '%-4s | %-12s | GV #%-6d | %-6d | %s',
                    str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    substr((string) ($gv->matiere?->code ?? '—'), 0, 12),
                    $gv->id,
                    $nbMembres,
                    $gv->libelle
                );

                $fichiersAjoutes++;
                $index++;
            } catch (\Throwable $e) {
                $bordereau[] = sprintf('[NON INCLUS] GV #%d (%s) : %s', $gv->id, $gv->libelle, $e->getMessage());
            }
        }

        $bordereau[] = str_repeat('-', 70);
        $bordereau[] = "Total émargements inclus : {$fichiersAjoutes} document(s) PDF · {$totalCandidats} candidat(s) éligibles.";
        $bordereau[] = 'Conformité stricte Matière ↔ Groupes ↔ Étudiants · UniRegistre.';

        $zip->addFromString('00_BORDEREAU_CENTRE_EXAMEN.txt', implode("\n", $bordereau));
        $zip->close();

        return [
            'chemin' => $cheminTemp,
            'nom_fichier' => $nomArchive,
            'total_groupes' => $fichiersAjoutes,
            'total_etudiants' => $totalCandidats,
        ];
    }

    /**
     * Génère le rendu HTML imprimable (ou PDF) d'une synthèse de groupes virtuels (Phase E) :
     * - synthese_matiere : Synthèse des groupes par matière
     * - synthese_session : Synthèse globale de la session
     *
     * @param  array<string, mixed>  $filtres
     * @param  array<int, array<string, mixed>>|null  $resumesPrecalcules
     */
    public function rendreDocumentSelection(
        array $filtres,
        string $document,
        User $user,
        bool $estPdf = false,
        ?array $resumesPrecalcules = null,
    ): string {
        $document = in_array($document, self::DOCUMENTS_SELECTION, true) ? $document : 'synthese_session';
        $f = $this->normaliserFiltres($filtres, $user);

        if ($resumesPrecalcules === null) {
            $query = $this->construireRequete($f, $user);
            $resumesPrecalcules = $query->limit(500)->get()
                ->map(fn (GroupeVirtuelExamen $gv) => $this->resumerGroupeVirtuel($gv))
                ->values()
                ->all();
        }

        foreach ($resumesPrecalcules as $r) {
            if (! $r['export_officiel_autorise']) {
                $motif = implode(' ', $r['bloquants']) ?: CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE;
                throw new DomainException("Impression officielle bloquée pour « {$r['libelle']} » : {$motif}");
            }
        }

        $syntheseParMatiere = collect($resumesPrecalcules)
            ->groupBy(fn (array $r) => ($r['matiere_code'] ?? '—').' — '.($r['matiere_nom'] ?? 'Sans matière'))
            ->map(function (Collection $items, string $matiereLibelle) {
                return [
                    'matiere' => $matiereLibelle,
                    'nb_groupes_virtuels' => $items->count(),
                    'groupes_sources' => $items->pluck('groupes_sources_codes')->unique()->implode(', '),
                    'effectif_attendu' => (int) $items->sum('effectif_attendu'),
                    'effectif_eligible' => (int) $items->sum('effectif_eligible'),
                    'effectif_exclu' => (int) $items->sum('effectif_exclu'),
                    'groupes' => $items->values()->all(),
                ];
            })
            ->values()
            ->all();

        $session = $f['session_id'] !== null ? SessionExamen::with('anneeUniversitaire')->find($f['session_id']) : null;
        $matiere = $f['matiere_id'] !== null ? Matiere::find($f['matiere_id']) : null;
        $faculte = $f['faculte_id'] !== null ? Faculte::find($f['faculte_id']) : null;
        $annee = $f['annee_id'] !== null ? AnneeUniversitaire::find($f['annee_id']) : $session?->anneeUniversitaire;

        $tousValidesEtPublies = $resumesPrecalcules !== [] && collect($resumesPrecalcules)->every(
            fn (array $r) => $r['statut_validation'] === 'valide' && $r['statut_publication'] === 'publie'
        );

        $statutBandeau = $tousValidesEtPublies
            ? 'DOCUMENT OFFICIEL PUBLIÉ'
            : 'ÉTAT DE TRAVAIL / PROPOSITIONS NON PUBLIÉES';

        $titreDocument = $document === 'synthese_matiere'
            ? 'Synthèse des groupes virtuels d\'examen par matière'
            : 'Synthèse globale des groupes virtuels de la session d\'examens';

        return view('modules.evaluation.groupes-virtuels.impression', [
            'modeDocument' => $document,
            'titreDocument' => $titreDocument,
            'statutBandeau' => $statutBandeau,
            'estPropositionNonValidee' => ! $tousValidesEtPublies,
            'institution' => config('app.name', 'UniRegistre'),
            'dateEdition' => now()->format('d/m/Y à H:i'),
            'reference' => 'GV-SYN-'.now()->format('Ymd-His'),
            'orientation' => 'landscape',
            'estPdf' => $estPdf,
            'fiche' => null,
            'resume' => [
                'annee_libelle' => $annee?->libelle ?? ($resumesPrecalcules[0]['annee_libelle'] ?? 'Toutes années'),
                'session_code' => $session?->code ?? ($resumesPrecalcules[0]['session_code'] ?? '—'),
                'session_nom' => $session?->nom ?? ($resumesPrecalcules[0]['session_nom'] ?? 'Toutes sessions'),
                'faculte_nom' => $faculte?->nom ?? ($resumesPrecalcules[0]['faculte_nom'] ?? 'Toutes facultés'),
                'matiere_code' => $matiere?->code ?? 'Toutes',
                'matiere_nom' => $matiere?->nom ?? 'Toutes matières',
                'libelle' => 'Sélection de '.count($resumesPrecalcules).' groupe(s) virtuel(s)',
            ],
            'resumesSelection' => $resumesPrecalcules,
            'syntheseParMatiere' => $syntheseParMatiere,
            'effectifTotal' => (int) collect($resumesPrecalcules)->sum('effectif_eligible'),
        ])->render();
    }

    /**
     * Sérialise les données dans le format cible (XLSX, CSV, PDF, HTML) et trace l'opération sans PII.
     *
     * @param  array<int, string>  $entetes
     * @param  array<int, array<string, string>>  $lignes
     * @return array{contenu: string, mime: string, nom_fichier: string, effectif: int}
     */
    private function serialiserExport(
        string $format,
        array $entetes,
        array $lignes,
        string $nomBase,
        callable $generateurHtml,
        string $actionAudit,
        string $referenceAudit,
    ): array {
        if ($format === 'csv') {
            $contenu = $this->csvSerializer->serialize($entetes, $lignes);
            $mime = $this->csvSerializer->mimeType();
            $nomFichier = $nomBase.'.csv';
        } elseif ($format === 'xlsx') {
            $contenu = $this->xlsxSerializer->serialize($entetes, $lignes);
            $mime = $this->xlsxSerializer->mimeType();
            $nomFichier = $nomBase.'.xlsx';
        } elseif ($format === 'pdf') {
            $html = $generateurHtml();
            $contenu = $this->pdfGenerator->generer($html, [
                'paper' => 'A4',
                'orientation' => 'portrait',
            ]);
            $mime = 'application/pdf';
            $nomFichier = $nomBase.'.pdf';
        } else {
            $contenu = $generateurHtml();
            $mime = 'text/html; charset=UTF-8';
            $nomFichier = $nomBase.'.html';
        }

        IntegrationLog::tracer(
            'evaluation',
            'internal',
            mb_substr($actionAudit.'.'.$format, 0, 50),
            'completed',
            (string) json_encode([
                'format' => $format,
                'lignes' => count($lignes),
                'reference' => $referenceAudit,
            ], JSON_UNESCAPED_UNICODE),
            200,
            null,
            null,
            (string) Str::uuid(),
            mb_substr($referenceAudit, 0, 50),
        );

        return [
            'contenu' => $contenu,
            'mime' => $mime,
            'nom_fichier' => $nomFichier,
            'effectif' => count($lignes),
        ];
    }

    /**
     * Charge les listes de référence pour les menus déroulants de filtres.
     *
     * @return array<string, mixed>
     */
    private function chargerReferentielsFiltres(?int $faculteId, ?int $anneeId): array
    {
        return [
            'annees' => AnneeUniversitaire::query()->orderByDesc('date_debut')->get(['id', 'libelle', 'active']),
            'sessions' => SessionExamen::query()
                ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
                ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
                ->orderByDesc('date_debut')
                ->get(['id', 'code', 'nom', 'annee_universitaire_id', 'statut']),
            'facultes' => Faculte::query()
                ->when($faculteId !== null, fn ($q) => $q->whereKey($faculteId))
                ->orderBy('nom')
                ->get(['id', 'code', 'nom']),
            'matieres' => Matiere::query()
                ->when($faculteId !== null, fn ($q) => $q->whereHas('ue.programme', fn ($p) => $p->where('faculte_id', $faculteId)))
                ->orderBy('code')
                ->limit(150)
                ->get(['id', 'code', 'nom', 'niveau']),
            'niveaux' => Niveau::query()
                ->when($faculteId !== null, fn ($q) => $q->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId)))
                ->orderBy('ordre')
                ->orderBy('code')
                ->get(['id', 'code', 'nom', 'programme_id']),
            'programmes' => Programme::query()
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->orderBy('code')
                ->get(['id', 'code', 'nom', 'faculte_id']),
        ];
    }
}
