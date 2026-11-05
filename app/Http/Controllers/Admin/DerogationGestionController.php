<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Services\EcheanceDerogationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gestion administrative et gouvernance des dérogations et échéances (LOOP 146, Q132 à Q146) :
 * - Q135 / Q136 : Constat de dépassement d'échéance sans forclusion ni conséquence sauvage (HIST-007, DELAI-002).
 * - Q137 / Q138 : Instruction fail-closed subordonnée aux arrêtés officiels de compétence (AUTH-007).
 * - Q139 / Q140 : Relances institutionnelles sans création de délai fictif (BUS-006, STAT-005).
 * - Q141 : Information transparente de l'étudiant sans promesse de délai ni préjugement (BUS-007).
 * - Q143 : Transmission formelle pour réexamen sans décision déduite du silence (RECOURS-004).
 * - Q144 : Conservation verbatim des décisions insuffisamment motivées sans extrapolation (HIST-008).
 * - Q145 : Figeage neutre des décisions ambiguës sans présomption d'issue (STAT-007).
 * - Q146 : Enregistrement de clarification officielle sans sélection d'option A/B/C non confirmée.
 */
class DerogationGestionController extends Controller
{
    public function __construct(
        private readonly EcheanceDerogationService $echeanceService,
    ) {}

    public function index(Request $request): View
    {
        $actions = [
            'constat_depassement_echeance',
            'depot_demande_derogation',
            'contestation_attente_prolongee',
        ];

        $etudiantIds = HistoriqueAcademique::query()
            ->whereIn('action', $actions)
            ->distinct()
            ->pluck('etudiant_id');

        $query = Etudiant::with('faculte')->whereIn('id', $etudiantIds);

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('nom', 'like', "%{$q}%")
                    ->orWhere('prenom', 'like', "%{$q}%")
                    ->orWhere('cin', 'like', "%{$q}%");
            });
        }

        if ($request->filled('faculte_id')) {
            $query->where('faculte_id', (int) $request->input('faculte_id'));
        }

        $etudiants = $query->orderBy('nom')->paginate(15)->withQueryString();
        $facultes = Faculte::where('active', true)->orderBy('nom')->get();

        return view('admin.derogations.index', [
            'etudiants' => $etudiants,
            'facultes' => $facultes,
            'totalDossiers' => $etudiantIds->count(),
            'recherche' => $request->input('q'),
            'faculteId' => $request->input('faculte_id'),
        ]);
    }

    public function show(Etudiant $etudiant): View
    {
        $etudiant->load('faculte');
        $dossier = $this->echeanceService->consulterDossierDerogations($etudiant);

        return view('admin.derogations.show', [
            'etudiant' => $etudiant,
            'depassements' => $dossier['depassements'],
            'demandes' => $dossier['demandes'],
            'voiesRecours' => $dossier['voies_recours'],
            'autoritesCompetentes' => config('loop146.derogations.autorites_competentes', []),
            'canauxRelance' => config('loop146.derogations.canaux_relance', []),
            'voiesReexamen' => config('loop146.derogations.voies_reexamen', []),
        ]);
    }

    /**
     * Q135, Q136 / DELAI-002, HIST-007 :
     * Enregistre un constat de dépassement d'échéance.
     */
    public function constaterDepassement(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'type_echeance' => ['required', 'string', 'max:100'],
            'echeance_initiale' => ['required', 'date'],
            'reference_reglementaire' => ['nullable', 'string', 'max:255'],
        ]);

        $resultat = $this->echeanceService->constaterDepassementEcheance(
            $etudiant,
            $donnees['type_echeance'],
            $donnees['echeance_initiale'],
            $donnees['reference_reglementaire'] ?? null,
            agentId: (int) $request->user()->id
        );

        $msg = $resultat['depassement_constate']
            ? "Constat de dépassement enregistré pour {$donnees['type_echeance']}. Échéance initiale scellée."
            : 'Échéance enregistrée : aucun dépassement à ce jour.';

        return redirect()->route('admin.derogations.show', $etudiant)->with('success', $msg);
    }

    /**
     * Q138 / AUTH-007 : Statuer officiellement sur une demande de dérogation.
     */
    public function statuer(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'decision' => ['required', 'string', 'in:accordee,refusee'],
            'autorite_qualite' => ['required', 'string', 'max:100'],
            'reference_acte_officiel' => ['required', 'string', 'max:255'],
            'motif_refus' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->echeanceService->statuerDerogation(
                $etudiant,
                $donnees['demande_id'],
                $donnees['decision'],
                $donnees['autorite_qualite'],
                $donnees['reference_acte_officiel'],
                $donnees['motif_refus'] ?? null
            );
        } catch (DomainException $e) {
            return redirect()
                ->route('admin.derogations.show', $etudiant)
                ->withErrors(['decision' => $e->getMessage()]);
        }

        $decisionLibelle = $donnees['decision'] === 'accordee' ? 'accordée' : 'refusée';

        return redirect()
            ->route('admin.derogations.show', $etudiant)
            ->with('success', "La décision officielle ({$decisionLibelle}) a été consignée sous la référence {$donnees['reference_acte_officiel']}.");
    }

    /**
     * Q139 / BUS-006 : Relancer via un canal institutionnel officiel.
     */
    public function relancer(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'canal_relance' => ['required', 'string', 'max:100'],
            'reference_preuve_relance' => ['nullable', 'string', 'max:255'],
            'cle_idempotence' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $resultat = $this->echeanceService->relancerDemandeDerogation(
                $etudiant,
                $donnees['demande_id'],
                $donnees['canal_relance'],
                $donnees['reference_preuve_relance'] ?? null,
                $donnees['cle_idempotence'] ?? null
            );
        } catch (DomainException $e) {
            return redirect()
                ->route('admin.derogations.show', $etudiant)
                ->withErrors(['canal_relance' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.derogations.show', $etudiant)
            ->with('success', $resultat['deja_enregistree']
                ? 'Cette relance est déjà enregistrée (opération idempotente).'
                : 'La relance institutionnelle a été enregistrée sans création de délai arbitraire.');
    }

    /**
     * Q141 / BUS-007 : Informer l'étudiant d'une attente prolongée.
     */
    public function informerAttente(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'demarches' => ['required', 'string', 'min:5', 'max:2000'],
            'reponse_recue' => ['nullable', 'boolean'],
        ]);

        $demarches = array_filter(array_map('trim', explode("\n", $donnees['demarches'])));

        $resultat = $this->echeanceService->informerAttenteProlongee(
            $etudiant,
            $donnees['demande_id'],
            $demarches,
            (bool) ($donnees['reponse_recue'] ?? false)
        );

        return redirect()
            ->route('admin.derogations.show', $etudiant)
            ->with('success', 'L’information factuelle d’attente a été enregistrée sans préjugement d’issue ni promesse de délai.');
    }

    /**
     * Q143 / RECOURS-004 : Transmettre pour réexamen officiel.
     */
    public function transmettreReexamen(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'voie_transmission' => ['required', 'string', 'max:100'],
            'reference_transmission' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->echeanceService->transmettrePourReexamen(
                $etudiant,
                $donnees['demande_id'],
                $donnees['voie_transmission'],
                $donnees['reference_transmission']
            );
        } catch (DomainException $e) {
            return redirect()
                ->route('admin.derogations.show', $etudiant)
                ->withErrors(['voie_transmission' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.derogations.show', $etudiant)
            ->with('success', "La demande a été transmise via la voie officielle {$donnees['voie_transmission']}. Aucun délai garanti n'est inventé.");
    }

    /**
     * Q144 / HIST-008 : Enregistrer une décision insuffisamment motivée (verbatim).
     */
    public function enregistrerDecisionInsuffisammentMotivee(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'decision_texte' => ['required', 'string', 'min:5', 'max:4000'],
            'motifs_fournis' => ['nullable', 'string', 'max:2000'],
            'reference_decision' => ['nullable', 'string', 'max:255'],
        ]);

        $this->echeanceService->enregistrerDecisionInsuffisammentMotivee(
            $etudiant,
            $donnees['demande_id'],
            $donnees['decision_texte'],
            $donnees['motifs_fournis'] ?? null,
            $donnees['reference_decision'] ?? null
        );

        return redirect()
            ->route('admin.derogations.show', $etudiant)
            ->with('success', 'La décision a été consignée verbatim sans extrapolation ni ajout de motif manquant.');
    }

    /**
     * Q145 / STAT-007 : Enregistrer une décision ambiguë.
     */
    public function enregistrerDecisionAmbigue(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'contenu_decision' => ['required', 'string', 'min:5', 'max:4000'],
            'reference_acte' => ['nullable', 'string', 'max:255'],
        ]);

        $this->echeanceService->enregistrerDecisionAmbiguë(
            $etudiant,
            $donnees['demande_id'],
            $donnees['contenu_decision'],
            $donnees['reference_acte'] ?? null
        );

        return redirect()
            ->route('admin.derogations.show', $etudiant)
            ->with('success', 'La décision ambiguë a été enregistrée : le dossier est figé sans présumer d’accord ni de refus.');
    }

    /**
     * Q146 / STAT-008 : Appliquer une clarification officielle confirmant le refus.
     */
    public function appliquerClarification(Request $request, Etudiant $etudiant): RedirectResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'issue_clarifiee' => ['required', 'string', 'in:accord,refus'],
            'reference_clarification' => ['required', 'string', 'max:255'],
            'auteur_clarification' => ['nullable', 'string', 'max:100'],
            'date_clarification' => ['nullable', 'date'],
        ]);

        $resultat = $this->echeanceService->appliquerClarificationDecision(
            $etudiant,
            $donnees['demande_id'],
            $donnees['issue_clarifiee'],
            $donnees['reference_clarification'],
            $donnees['auteur_clarification'] ?? null,
            $donnees['date_clarification'] ?? null
        );

        return redirect()
            ->route('admin.derogations.show', $etudiant)
            ->with('success', 'La clarification officielle a été scellée. La décision initiale reste préservée sans mutation automatique de dossier.');
    }
}
