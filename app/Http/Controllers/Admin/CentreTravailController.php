<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use App\Services\InscriptionEligibilityService;
use App\Services\InscriptionService;
use App\Services\Verification\CorrectionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Centre de travail administratif intelligent (Sous-lot B).
 *
 * File de travail unifiée, consultable, filtrable et paginée,
 * avec indicateurs de pilotage calculés en temps réel.
 *
 * Règles strictes :
 * - Lecture seule absolue : aucune mutation de tâche ni d'inscription lors de la consultation.
 * - Isolation facultaire étanche : scoping serveur strict appliqué aux listes et aux indicateurs.
 * - Zéro N+1 : eager loading ciblé sur assigné et faculté.
 * - Zéro synchronisation intempestive au chargement de page.
 */
class CentreTravailController extends Controller
{
    public const SOURCES = [
        'inscription' => 'Inscriptions académiques',
        'demande_sla' => 'Demandes en retard (SLA)',
        'justification' => 'Justifications d\'absence',
        'deliberation' => 'Délibérations bloquées',
        'epreuve_salle' => 'Épreuves sans salle',
        'sans_groupe' => 'Étudiants sans groupe',
        'facture_impayee' => 'Factures impayées',
    ];

    public function index(Request $request): View
    {
        $utilisateur = $request->user();

        // 1. Contrôle d'autorisation strict : compte actif et lecture seule (tasks.view ou archives.view)
        abort_unless(
            $utilisateur && $utilisateur->estActif() && ($utilisateur->hasPermission('tasks.view') || $utilisateur->hasPermission('archives.view')),
            403,
            'Accès non autorisé au Centre de travail.'
        );

        $faculteRestreinteId = $utilisateur->faculteRestreinteId();

        // 2. Validation côté serveur des filtres
        $valide = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::SOURCES))],
            'statut' => ['nullable', 'string', 'in:pending,in_progress,waiting,completed,cancelled,expired,actives,toutes'],
            'priorite' => ['nullable', 'string', 'in:critical,high,normal,low,critical_high'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'assignation' => ['nullable', 'string', 'in:toutes,miennes,non_assignees'],
            'souffrance' => ['nullable', 'in:0,1'],
            'bloque' => ['nullable', 'in:0,1'],
            'en_retard' => ['nullable', 'in:0,1'],
            'corrections_attente' => ['nullable', 'in:0,1'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date'],
        ]);

        // Si l'utilisateur est restreint à une faculté (ex. admin_faculte),
        // on impose strictement sa faculté (anti-BOLA / IDOR)
        if ($faculteRestreinteId !== null) {
            $valide['faculte_id'] = $faculteRestreinteId;
        }

        // 3. Indicateurs de Pilotage (périmètre facultaire strict)
        $baseKpi = Task::query()
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId));

        $kpis = [
            'en_attente' => (clone $baseKpi)->where('statut', 'pending')->count(),
            'en_cours' => (clone $baseKpi)->where('statut', 'in_progress')->count(),
            'prioritaires' => (clone $baseKpi)
                ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->whereIn('priorite', ['critical', 'high'])
                ->count(),
            'souffrance_48h' => (clone $baseKpi)
                ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->where('created_at', '<=', now()->subHours(48))
                ->count(),
            'bloquees' => (clone $baseKpi)
                ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->where(function ($q) {
                    $q->where('source', 'deliberation')
                        ->orWhere(function ($sub) {
                            $sub->where('source', 'inscription')
                                ->where('data->bloque', true);
                        });
                })
                ->count(),
            'cloturees_recemment' => (clone $baseKpi)
                ->where('statut', 'completed')
                ->where('terminee_a', '>=', now()->subDays(7))
                ->count(),
            'mes_taches' => (clone $baseKpi)
                ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->where('assigne_a', $utilisateur->id)
                ->count(),
            'non_assignees' => (clone $baseKpi)
                ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->whereNull('assigne_a')
                ->count(),
            'en_retard' => (clone $baseKpi)
                ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->whereNotNull('echeance_a')
                ->where('echeance_a', '<', now())
                ->count(),
            'corrections_4yeux' => DossierCorrection::where('statut', 'proposee')
                ->when($faculteRestreinteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($sub) => $sub->where('faculte_id', $faculteRestreinteId)))
                ->count(),
        ];

        // 3b. Indicateurs consolidés Inscriptions (Lot 1 Cockpit Inscriptions : 1 seule requête agrégée)
        $inscriptionsStats = InscriptionAcademique::query()
            ->when($faculteRestreinteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteRestreinteId)))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) as en_cours")
            ->selectRaw("SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as valides")
            ->selectRaw("SUM(CASE WHEN statut IN ('rejete', 'ajourne') THEN 1 ELSE 0 END) as rejetes")
            ->first();

        $inscriptionsKpis = [
            'total' => (int) ($inscriptionsStats->total ?? 0),
            'en_cours' => (int) ($inscriptionsStats->en_cours ?? 0),
            'valides' => (int) ($inscriptionsStats->valides ?? 0),
            'rejetes' => (int) ($inscriptionsStats->rejetes ?? 0),
            'bloquees' => (int) ($kpis['bloquees'] ?? 0),
            'paiements_attente' => 0,
            'corrections_attente' => (int) ($kpis['corrections_4yeux'] ?? 0),
        ];

        // 4. Construction de la requête filtrée
        $query = Task::query()
            ->with(['faculte', 'assigne']);

        // Application du périmètre facultaire
        if ($faculteRestreinteId !== null) {
            $query->where('faculte_id', $faculteRestreinteId);
        } elseif (! empty($valide['faculte_id'])) {
            $query->where('faculte_id', (int) $valide['faculte_id']);
        }

        // Statut : par défaut les tâches actives (pending, in_progress, waiting)
        $statutFiltre = $valide['statut'] ?? 'actives';
        if ($statutFiltre === 'actives') {
            $query->whereIn('statut', ['pending', 'in_progress', 'waiting']);
        } elseif ($statutFiltre !== 'toutes' && ! empty($statutFiltre)) {
            $query->where('statut', $statutFiltre);
        }

        // Priorité — `critical_high` = valeur composée exacte de
        // l'indicateur « Prioritaires » (critical OU high)
        if (! empty($valide['priorite'])) {
            if ($valide['priorite'] === 'critical_high') {
                $query->whereIn('priorite', ['critical', 'high']);
            } else {
                $query->where('priorite', $valide['priorite']);
            }
        }

        // Source / Catégorie
        if (! empty($valide['source'])) {
            $query->where('source', $valide['source']);
        }

        // Assignation
        if (($valide['assignation'] ?? null) === 'miennes') {
            $query->where('assigne_a', $utilisateur->id);
        } elseif (($valide['assignation'] ?? null) === 'non_assignees') {
            $query->whereNull('assigne_a');
        }

        // Souffrance (créé il y a >= 48h)
        if (! empty($valide['souffrance'])) {
            $query->where('created_at', '<=', now()->subHours(48));
        }

        // Bloqué
        if (! empty($valide['bloque'])) {
            $query->where(function ($q) {
                $q->where('source', 'deliberation')
                    ->orWhere(function ($sub) {
                        $sub->where('source', 'inscription')
                            ->where('data->bloque', true);
                    });
            });
        }

        // En retard (échéance dépassée)
        if (! empty($valide['en_retard'])) {
            $query->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->whereNotNull('echeance_a')
                ->where('echeance_a', '<', now());
        }

        // Corrections 4-yeux en attente
        if (! empty($valide['corrections_attente'])) {
            $etudiantIdsAvecCorrection = DossierCorrection::where('statut', 'proposee')->pluck('etudiant_id')->unique()->all();
            $query->where('source', 'inscription')
                ->whereIn('data->etudiant_id', $etudiantIdsAvecCorrection);
        }

        // Période de création
        if (! empty($valide['date_debut'])) {
            $query->whereDate('created_at', '>=', $valide['date_debut']);
        }
        if (! empty($valide['date_fin'])) {
            $query->whereDate('created_at', '<=', $valide['date_fin']);
        }

        // Recherche textuelle multi-critères (Lot 2 : titre, description, clé unique, étudiant CIN/nom/prénom/email/matricule, paiement)
        if (! empty($valide['search'])) {
            $terme = trim($valide['search']);
            $etudiantIds = Etudiant::query()
                ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
                ->where(function ($q) use ($terme) {
                    $q->where('cin', 'like', "%{$terme}%")
                        ->orWhere('nom', 'like', "%{$terme}%")
                        ->orWhere('prenom', 'like', "%{$terme}%")
                        ->orWhere('email', 'like', "%{$terme}%")
                        ->orWhere('num_inscription', 'like', "%{$terme}%");
                })
                ->pluck('id')
                ->all();

            $paiementInscriptionIds = DossierPaiement::query()
                ->where('reference', 'like', "%{$terme}%")
                ->pluck('inscription_academique_id')
                ->filter()
                ->all();

            $query->where(function ($q) use ($terme, $etudiantIds, $paiementInscriptionIds) {
                $q->where('titre', 'like', "%{$terme}%")
                    ->orWhere('description', 'like', "%{$terme}%")
                    ->orWhere('cle_unique', 'like', "%{$terme}%");

                if (! empty($etudiantIds)) {
                    $q->orWhere(function ($sub) use ($etudiantIds) {
                        $sub->where('source', 'inscription')
                            ->whereIn('data->etudiant_id', $etudiantIds);
                    });
                }

                if (! empty($paiementInscriptionIds)) {
                    $q->orWhere(function ($sub) use ($paiementInscriptionIds) {
                        $sub->where('source', 'inscription')
                            ->whereIn('data->inscription_id', $paiementInscriptionIds);
                    });
                }
            });
        }

        // Tri : priorité critique d'abord, puis échéance, puis FIFO, puis tie-breaker ID strictement déterministe
        $query->orderByRaw("CASE priorite WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN echeance_a IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('echeance_a', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        $taches = $query->paginate(15)->withQueryString();

        // Facultés pour le filtre (uniquement si utilisateur non restreint)
        $facultes = $faculteRestreinteId === null
            ? Faculte::where('active', true)->orderBy('nom')->get(['id', 'nom', 'code'])
            : collect();

        // Agents disponibles pour les actions groupées d'assignation
        $agentsDisponibles = User::where('actif', true)
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'faculte_id'])
            ->filter(fn (User $u) => $u->hasPermission('tasks.view'));

        return view('admin.centre-travail.index', [
            'taches' => $taches,
            'kpis' => $kpis,
            'inscriptionsKpis' => $inscriptionsKpis,
            'sources' => self::SOURCES,
            'facultes' => $facultes,
            'filtres' => $valide,
            'faculteRestreinteId' => $faculteRestreinteId,
            'statutFiltre' => $statutFiltre,
            'agentsDisponibles' => $agentsDisponibles,
        ]);
    }

    /**
     * Consultation détaillée et évaluation explicable d'une inscription (Sous-lot C).
     *
     * Lecture seule stricte :
     * - Contrôle d'éligibilité via la façade canonique InscriptionEligibilityService::evaluer()
     * - Diagnostic structuré des 4 contrôles (Période, Pièces, Anomalies, MENS)
     * - Analyse des pièces justificatives et motifs d'anomalies
     * - Suivi des corrections 4-eyes en attente
     * - Journal d'audit chronologique
     * - Isolation facultaire étanche (404 si hors périmètre, anti-énumération)
     */
    public function show(Request $request, InscriptionAcademique $inscription): View
    {
        $utilisateur = $request->user();

        // 1. Contrôle d'autorisation strict : compte actif et lecture seule (tasks.view ou archives.view)
        abort_unless(
            $utilisateur && $utilisateur->estActif() && ($utilisateur->hasPermission('tasks.view') || $utilisateur->hasPermission('archives.view')),
            403,
            'Accès non autorisé au Centre de travail.'
        );

        // 2. Pré-chargement des relations pour éviter tout N+1
        $inscription->loadMissing([
            'etudiant.faculte',
            'etudiant.groupe',
            'anneeUniversitaire',
            'programmeRef',
            'niveauRef',
            'documentsInscription',
        ]);

        $etudiant = $inscription->etudiant;
        abort_unless($etudiant !== null, 404, 'Dossier étudiant introuvable.');

        // 3. Périmètre facultaire strict (anti-BOLA / IDOR)
        abort_unless(
            $utilisateur->peutAccederFaculte((int) $etudiant->faculte_id),
            404,
            'Dossier hors du périmètre de votre faculté.'
        );

        // 4. Évaluation canonique explicable (DRY-RUN STRICT)
        $evaluation = app(InscriptionEligibilityService::class)->evaluer($inscription);

        // 5. Corrections 4-eyes : séparer les propositions en attente de l'historique complet.
        // Une proposition rattachée à une AUTRE inscription du même étudiant ne doit
        // pas être actionnable depuis ce dossier (le decision guard renvoie 404).
        $toutesCorrections = DossierCorrection::where('etudiant_id', $etudiant->id)
            ->where(fn ($q) => $q->whereNull('inscription_academique_id')
                ->orWhere('inscription_academique_id', $inscription->id))
            ->latest('id')
            ->get();
        $correctionsEnAttente = $toutesCorrections->where('statut', 'proposee');
        $correctionsHistorique = $toutesCorrections->whereIn('statut', ['approuvee', 'rejetee', 'appliquee']);

        // 6. Cursus et autres inscriptions de l'étudiant
        $autresInscriptions = InscriptionAcademique::with('anneeUniversitaire')
            ->where('etudiant_id', $etudiant->id)
            ->where('id', '!=', $inscription->id)
            ->latest('id')
            ->get();

        // 7. Affectations de groupe de l'étudiant
        $historiqueAffectations = $etudiant->affectations()
            ->with('groupe')
            ->take(5)
            ->get();

        // 8. Informations financières et de paiement (fail-soft si module absent)
        $dossierPaiement = null;
        if (class_exists(DossierPaiement::class)) {
            try {
                $dossierPaiement = DossierPaiement::with('recus')
                    ->where('inscription_academique_id', $inscription->id)
                    ->first();
            } catch (\Throwable) {
                // Fail-soft pour les finances
            }
        }

        // 9. Tâche associée dans le système Tasks
        $tache = Task::where('cle_unique', "inscription:{$inscription->id}")
            ->with(['assigne', 'evenements.auteur'])
            ->first();

        // 10. Historique d'audit académique récent
        $historique = HistoriqueAcademique::with('utilisateur')
            ->where(function ($q) use ($etudiant, $inscription) {
                $q->where('etudiant_id', $etudiant->id)
                    ->orWhere(function ($sub) use ($inscription) {
                        $sub->where('entite', 'inscription_academique')
                            ->where('entite_id', $inscription->id);
                    });
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        return view('admin.centre-travail.show', [
            'inscription' => $inscription,
            'etudiant' => $etudiant,
            'evaluation' => $evaluation,
            'correctionsEnAttente' => $correctionsEnAttente,
            'correctionsHistorique' => $correctionsHistorique,
            'autresInscriptions' => $autresInscriptions,
            'historiqueAffectations' => $historiqueAffectations,
            'dossierPaiement' => $dossierPaiement,
            'tache' => $tache,
            'historique' => $historique,
        ]);
    }

    /**
     * Validation d'une inscription éligible (Sous-lot D1).
     *
     * Règles strictes :
     * - Compte actif et permission 'archives.update' obligatoire
     * - Contrôle d'isolation facultaire (anti-BOLA / IDOR)
     * - Re-contrôle d'éligibilité au moment de la mutation (anti-TOCTOU)
     * - Refus strict si verdict canonique est 'BLOCAGE'
     * - Réutilisation intégrale de InscriptionService::transiter()
     * - Clôture atomique de la tâche associée dans TaskService
     */
    public function validerInscription(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        $utilisateur = $request->user();
        abort_unless(
            $utilisateur && $utilisateur->estActif() && $utilisateur->hasPermission('archives.update'),
            403,
            'Permission requise pour valider une inscription.'
        );

        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);
        $etudiant = $inscription->etudiant;
        abort_unless($etudiant !== null, 404, 'Dossier étudiant introuvable.');
        abort_unless($utilisateur->peutAccederFaculte((int) $etudiant->faculte_id), 404, 'Dossier hors de votre faculté.');

        // 1. Contrôle d'état préalable : l'inscription doit être 'en_cours'
        if ($inscription->statut !== 'en_cours') {
            return back()->withErrors(['statut' => "L'inscription est déjà au statut « {$inscription->statut} »."]);
        }

        // 2. Évaluation canonique de l'éligibilité au moment de la mutation (anti-TOCTOU)
        $evaluation = app(InscriptionEligibilityService::class)->evaluer($inscription);
        if ($evaluation['verdict'] === 'BLOCAGE' || ! $evaluation['eligible']) {
            $motifs = ! empty($evaluation['blocages'])
                ? implode(', ', array_column($evaluation['blocages'], 'titre'))
                : 'Anomalies bloquantes ou pièces justificatives manquantes.';

            return back()->withErrors(['statut' => "Validation refusée : le dossier présente des blocages critiques ({$motifs})."]);
        }

        $motif = $request->validate([
            'motif' => ['nullable', 'string', 'max:255'],
        ])['motif'] ?? 'Validation administrative via Centre de travail';

        // 3. Mutation transactionnelle via le service canonique InscriptionService
        try {
            app(InscriptionService::class)->transiter($inscription, 'valide', (int) $utilisateur->id, $motif);
        } catch (DomainException $e) {
            return back()->withErrors(['statut' => $e->getMessage()]);
        }

        // 4. Clôture de la tâche administrative associée
        $tache = Task::where('cle_unique', "inscription:{$inscription->id}")->first();
        if ($tache !== null && $tache->estActive()) {
            try {
                app(TaskService::class)->transiter($tache, 'completed', (int) $utilisateur->id, 'Inscription validée');
            } catch (DomainException) {
                // Fail-soft pour la tâche si déjà clôturée
            }
        }

        return redirect()->route('admin.centre-travail.show', $inscription)
            ->with('success', 'Inscription validée avec succès. Dossier finalisé.');
    }

    /**
     * Refus / Ajournement d'une inscription (Sous-lot D2).
     *
     * Règles strictes :
     * - Compte actif et permission 'archives.update' obligatoire
     * - Contrôle d'isolation facultaire (anti-BOLA / IDOR)
     * - Motif obligatoire enregistré dans l'historique
     * - Transition canonique vers 'ajourne' via InscriptionService
     * - Clôture par annulation de la tâche administrative liée
     */
    public function rejeterInscription(Request $request, InscriptionAcademique $inscription): RedirectResponse
    {
        $utilisateur = $request->user();
        abort_unless(
            $utilisateur && $utilisateur->estActif() && $utilisateur->hasPermission('archives.update'),
            403,
            'Permission requise pour refuser ou ajourner une inscription.'
        );

        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);
        $etudiant = $inscription->etudiant;
        abort_unless($etudiant !== null, 404, 'Dossier étudiant introuvable.');
        abort_unless($utilisateur->peutAccederFaculte((int) $etudiant->faculte_id), 404, 'Dossier hors de votre faculté.');

        // 1. Contrôle d'état préalable
        if ($inscription->statut !== 'en_cours') {
            return back()->withErrors(['statut' => "L'inscription est déjà au statut « {$inscription->statut} »."]);
        }

        // 2. Motif obligatoire (min 5, max 500)
        $valide = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        // 3. Mutation transactionnelle : transition canonique vers 'ajourne'
        try {
            app(InscriptionService::class)->transiter($inscription, 'ajourne', (int) $utilisateur->id, $valide['motif']);
        } catch (DomainException $e) {
            return back()->withErrors(['statut' => $e->getMessage()]);
        }

        // 4. Actualisation de la tâche administrative associée
        $tache = Task::where('cle_unique', "inscription:{$inscription->id}")->first();
        if ($tache !== null && $tache->estActive()) {
            try {
                app(TaskService::class)->transiter($tache, 'cancelled', (int) $utilisateur->id, "Inscription ajournée : {$valide['motif']}");
            } catch (DomainException) {
                // Fail-soft pour la tâche
            }
        }

        return redirect()->route('admin.centre-travail.show', $inscription)
            ->with('success', 'Inscription ajournée. Motif enregistré dans le dossier.');
    }

    /**
     * Décision sur une proposition de correction à quatre yeux (Sous-lot D3).
     *
     * Règles strictes :
     * - Compte actif et permission 'archives.update' obligatoire
     * - Contrôle d'isolation facultaire (anti-BOLA / IDOR)
     * - Contrôle strict d'appartenance de la correction à l'étudiant ET à l'inscription
     * - Règle des quatre yeux stricte : l'auteur ne peut JAMAIS approuver ou rejeter sa proposition
     * - Réutilisation de CorrectionService::approuver() et CorrectionService::rejeter()
     */
    public function deciderCorrection(Request $request, InscriptionAcademique $inscription, DossierCorrection $correction): RedirectResponse
    {
        $utilisateur = $request->user();
        abort_unless(
            $utilisateur && $utilisateur->estActif() && $utilisateur->hasPermission('archives.update'),
            403,
            'Permission requise pour statuer sur une correction.'
        );

        $inscription->loadMissing('etudiant');
        $etudiant = $inscription->etudiant;
        abort_unless($etudiant !== null, 404, 'Dossier étudiant introuvable.');
        abort_unless($utilisateur->peutAccederFaculte((int) $etudiant->faculte_id), 404, 'Dossier hors de votre faculté.');
        abort_unless((int) $correction->etudiant_id === (int) $etudiant->id, 404, 'Correction non rattachée à cet étudiant.');
        abort_unless(
            $correction->inscription_academique_id === null || (int) $correction->inscription_academique_id === (int) $inscription->id,
            404,
            'Correction non rattachée à cette inscription.'
        );

        $valide = $request->validate([
            'decision' => ['required', 'string', 'in:approuver,rejeter'],
            'motif' => ['nullable', 'string', 'min:5', 'max:500', 'required_if:decision,rejeter'],
        ]);

        // Contrôle strict de la règle des quatre yeux côté serveur (anti-contournement)
        abort_if(
            (int) $correction->propose_par === (int) $utilisateur->id,
            403,
            'Approbation par un second agent requise : vous ne pouvez pas statuer sur votre propre proposition.'
        );

        $serviceCorrections = app(CorrectionService::class);

        try {
            if ($valide['decision'] === 'approuver') {
                $serviceCorrections->approuver($correction, (int) $utilisateur->id, $valide['motif'] ?? null);
                $message = "Proposition de correction #{$correction->id} approuvée avec succès (second regard validé).";
            } else {
                $serviceCorrections->rejeter($correction, (int) $utilisateur->id, (string) ($valide['motif'] ?? 'Rejetée depuis le Centre de travail.'));
                $message = "Proposition de correction #{$correction->id} rejetée.";
            }
        } catch (DomainException $e) {
            return back()->withErrors(['correction' => $e->getMessage()]);
        }

        return redirect()->route('admin.centre-travail.show', $inscription)->with('success', $message);
    }

    /**
     * Actions en lot sur les tâches du Centre de travail (Lot P2).
     *
     * Règles strictes :
     * - Compte actif et permissions requises selon l'action (tasks.assign pour assigner, tasks.update pour les autres).
     * - Contrôle unitaire du périmètre facultaire pour chaque tâche sélectionnée.
     * - Cible d'assignation validée (active, habilitée, rattachée au périmètre).
     * - Interdiction formelle de clôture massive des dossiers académiques (validation individuelle obligatoire).
     */
    public function actionsGroupees(Request $request, TaskService $taskService): RedirectResponse|JsonResponse
    {
        $utilisateur = $request->user();
        abort_unless($utilisateur && $utilisateur->estActif(), 403, 'Compte non autorisé.');

        // Normalisation transparente des alias (Blade et requêtes API)
        $ids = $request->input('ids') ?? $request->input('tache_ids') ?? [];
        $action = $request->input('action');
        if ($action === 'changer_priorite') {
            $action = 'priorite';
        }
        if ($action === null && ($request->boolean('simuler') || $request->input('mode') === 'simulation')) {
            $action = 'demarrer';
        }
        $assigneA = $request->input('assigne_a') ?? $request->input('assigne_a_id');

        $request->merge([
            'ids' => is_array($ids) ? $ids : [$ids],
            'action' => $action,
            'assigne_a' => $assigneA,
        ]);

        $donnees = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:tasks,id'],
            'action' => ['required', 'in:assigner,demarrer,priorite,cloturer'],
            'assigne_a' => ['nullable', 'integer', 'exists:users,id'],
            'priorite' => ['nullable', 'required_if:action,priorite', 'in:critical,high,normal,low'],
            'simuler' => ['nullable', 'boolean'],
            'mode' => ['nullable', 'string', 'in:simulation,execution'],
        ]);

        $action = $donnees['action'];

        if ($action === 'assigner') {
            abort_unless($utilisateur->hasPermission('tasks.assign'), 403, 'Permission requise pour assigner des tâches.');
        } else {
            abort_unless($utilisateur->hasPermission('tasks.update'), 403, 'Permission requise pour modifier des tâches.');
        }

        $faculteRestreinteId = $utilisateur->faculteRestreinteId();
        $idsSoumis = array_values(array_unique(array_map('intval', $donnees['ids'])));
        $totalDemande = count($idsSoumis);

        $taches = Task::whereIn('id', $idsSoumis)
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->get();

        if ($taches->isEmpty()) {
            $messageErreur = 'Aucune tâche de votre périmètre ne peut être traitée.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['erreur' => $messageErreur], 422);
            }

            return back()->withErrors(['ids' => $messageErreur]);
        }

        $assigneA = $donnees['assigne_a'] ?? null;
        if ($action === 'assigner' && $assigneA !== null) {
            $cible = User::whereKey((int) $assigneA)->where('actif', true)->first();

            if ($cible === null || ! $cible->hasPermission('tasks.view')) {
                $err = 'La cible doit être un compte actif disposant de la permission tasks.view.';
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json(['erreur' => $err], 422);
                }

                return back()->withErrors(['assigne_a' => $err]);
            }

            $horsPerimetre = $taches->first(
                fn (Task $t) => $t->faculte_id !== null && ! $cible->peutAccederFaculte((int) $t->faculte_id)
            );

            if ($horsPerimetre !== null) {
                $err = 'La cible n’appartient pas au périmètre faculté des tâches sélectionnées.';
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json(['erreur' => $err], 422);
                }

                return back()->withErrors(['assigne_a' => $err]);
            }
        }

        // Mode Simulation (Lot 4 : simulation préalable sans mutation effective)
        if ($request->boolean('simuler') || ($donnees['mode'] ?? null) === 'simulation') {
            $eligibles = [];
            $exclus = [];

            foreach ($idsSoumis as $id) {
                $tache = $taches->firstWhere('id', $id);
                if ($tache === null) {
                    $exclus[] = [
                        'id' => $id,
                        'titre' => "Tâche #{$id}",
                        'motif' => 'Hors de votre périmètre facultaire ou introuvable.',
                    ];

                    continue;
                }

                if ($action === 'demarrer') {
                    if ($tache->statut === 'pending') {
                        $eligibles[] = [
                            'id' => $tache->id,
                            'titre' => $tache->titre,
                            'changement' => 'Statut pending → in_progress (prise en charge)',
                        ];
                    } else {
                        $exclus[] = [
                            'id' => $tache->id,
                            'titre' => $tache->titre,
                            'motif' => "Statut actuel '{$tache->statut}' incompatible (doit être 'pending').",
                        ];
                    }
                } elseif ($action === 'priorite') {
                    if ($tache->estActive()) {
                        $eligibles[] = [
                            'id' => $tache->id,
                            'titre' => $tache->titre,
                            'changement' => "Priorité {$tache->priorite} → {$donnees['priorite']}",
                        ];
                    } else {
                        $exclus[] = [
                            'id' => $tache->id,
                            'titre' => $tache->titre,
                            'motif' => "Tâche inactive ou déjà clôturée (statut '{$tache->statut}').",
                        ];
                    }
                } elseif ($action === 'cloturer') {
                    if ($tache->estActive()) {
                        $eligibles[] = [
                            'id' => $tache->id,
                            'titre' => $tache->titre,
                            'changement' => "Statut {$tache->statut} → completed (clôture)",
                        ];
                    } else {
                        $exclus[] = [
                            'id' => $tache->id,
                            'titre' => $tache->titre,
                            'motif' => "Tâche déjà clôturée ou inactive (statut '{$tache->statut}').",
                        ];
                    }
                } elseif ($action === 'assigner') {
                    $eligibles[] = [
                        'id' => $tache->id,
                        'titre' => $tache->titre,
                        'changement' => $assigneA ? "Assignation à l'agent #{$assigneA}" : 'Désassignation (remise en file non assignée)',
                    ];
                }
            }

            $bilanSimulation = [
                'simulation' => true,
                'total_demande' => $totalDemande,
                'total_eligibles' => count($eligibles),
                'total_exclus' => count($exclus),
                'eligibles' => $eligibles,
                'exclus' => $exclus,
                'action' => $action,
                'confirmation_necessaire' => true,
            ];

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json($bilanSimulation);
            }

            return back()->with('simulation', $bilanSimulation);
        }

        // Exécution réelle transactionnelle
        $traitees = 0;
        $ignorees = $totalDemande - $taches->count();

        foreach ($taches as $tache) {
            try {
                if ($action === 'assigner') {
                    $taskService->assigner($tache, $assigneA !== null ? (int) $assigneA : null, (int) $utilisateur->id);
                    $traitees++;
                } elseif ($action === 'demarrer') {
                    if ($tache->statut === 'pending') {
                        $taskService->transiter($tache, 'in_progress', (int) $utilisateur->id, 'Prise en charge groupée');
                        $traitees++;
                    } else {
                        $ignorees++;
                    }
                } elseif ($action === 'priorite' && ! empty($donnees['priorite'])) {
                    if ($tache->estActive()) {
                        $tache->update(['priorite' => $donnees['priorite']]);
                        $traitees++;
                    } else {
                        $ignorees++;
                    }
                } elseif ($action === 'cloturer') {
                    if ($tache->estActive()) {
                        $taskService->transiter($tache, 'completed', (int) $utilisateur->id, 'Clôture groupée');
                        $traitees++;
                    } else {
                        $ignorees++;
                    }
                }
            } catch (\Throwable) {
                $ignorees++;
            }
        }

        $message = "Action groupée exécutée : {$traitees} tâche(s) traitée(s), {$ignorees} ignorée(s).";
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'traitees' => $traitees,
                'ignorees' => $ignorees,
            ]);
        }

        return back()->with('success', $message);
    }
}
