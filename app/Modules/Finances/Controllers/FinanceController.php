<?php

namespace App\Modules\Finances\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\Finances\Models\Remboursement;
use App\Modules\Finances\Models\Tarif;
use App\Modules\Finances\Services\FinanceService;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FinanceController extends Controller
{
    public function dashboard(Request $request, FinanceService $finances): View
    {
        Gate::authorize('viewAny', Facture::class);

        $faculteRestreinte = $request->user()->faculteRestreinteId();
        $agregats = $finances->agregatsFactures($faculteRestreinte);

        $base = Facture::query()
            ->when($faculteRestreinte !== null, fn ($q) => $q->whereHas(
                'etudiant',
                fn ($e) => $e->where('faculte_id', $faculteRestreinte)
            ));

        return view('modules.finances.tableau-bord', [
            'total' => $agregats['total'],
            'encaisse' => $agregats['encaisse'],
            'reste' => $agregats['reste'],
            'en_retard' => $agregats['en_retard'],
            'impayees' => (clone $base)->whereIn('statut', ['issued', 'partially_paid', 'overdue'])->count(),
            'remboursements' => Remboursement::query()
                ->where('statut', 'requested')
                ->when($faculteRestreinte !== null, fn ($q) => $q->whereHas(
                    'paiement.facture.etudiant',
                    fn ($e) => $e->where('faculte_id', $faculteRestreinte)
                ))
                ->count(),
            'recus_a_verifier' => config('inscriptions-paiements.actif', true)
                ? DossierPaiement::query()
                    ->whereIn('statut', DossierPaiement::STATUTS_A_TRAITER)
                    ->when($faculteRestreinte !== null, fn ($q) => $q->whereHas(
                        'inscription.etudiant',
                        fn ($e) => $e->where('faculte_id', $faculteRestreinte)
                    ))
                    ->count()
                : 0,
        ]);
    }

    public function factures(Request $request): View
    {
        Gate::authorize('viewAny', Facture::class);

        $query = Facture::with(['etudiant', 'anneeUniversitaire'])->latest()
            ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->whereHas(
                'etudiant',
                fn ($e) => $e->where('faculte_id', $request->user()->faculteRestreinteId())
            ));

        return view('modules.finances.factures', ['factures' => $query->paginate(15)->withQueryString()]);
    }

    public function show(Request $request, Facture $facture): View
    {
        $facture->loadMissing('etudiant.faculte');
        abort_unless($facture->etudiant === null || $request->user()->peutAccederFaculte((int) $facture->etudiant->faculte_id), 404);
        Gate::authorize('view', $facture);

        $facture->load(['lignes', 'paiements.remboursements']);

        return view('modules.finances.facture-show', ['facture' => $facture]);
    }

    public function emettre(Request $request, InscriptionAcademique $inscription, FinanceService $finances): RedirectResponse
    {
        abort_unless($request->user()->peutAccederFaculte((int) $inscription->etudiant->faculte_id), 404);
        Gate::authorize('manage', Facture::class);

        try {
            $facture = $finances->emettreFacture($inscription, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['facture' => $e->getMessage()]);
        }

        return redirect()->route('admin.finances.factures.show', $facture)->with('success', "Facture {$facture->reference} émise.");
    }

    public function payer(Request $request, Facture $facture, FinanceService $finances): RedirectResponse
    {
        Gate::authorize('manage', Facture::class);

        // Défense en profondeur : le périmètre facultaire ne dépend pas
        // uniquement de la matrice de permissions. Un compte restreint à une
        // faculté ne peut jamais encaisser la facture d'une autre.
        $faculteFacture = $facture->etudiant?->faculte_id;
        abort_unless(
            $faculteFacture === null || $request->user()->peutAccederFaculte((int) $faculteFacture),
            404
        );

        $donnees = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'methode' => ['required', 'in:'.implode(',', Paiement::METHODES)],
        ]);

        try {
            $finances->enregistrerPaiement(
                $facture,
                (int) round((float) $donnees['montant'] * 100),
                $donnees['methode'],
                (int) $request->user()->id,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['paiement' => $e->getMessage()]);
        }

        return back()->with('success', 'Paiement enregistré, en attente de confirmation.');
    }

    public function confirmer(Request $request, Paiement $paiement, FinanceService $finances): RedirectResponse
    {
        Gate::authorize('confirm', $paiement);

        try {
            $finances->confirmerPaiement($paiement, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['paiement' => $e->getMessage()]);
        }

        return back()->with('success', 'Paiement confirmé, reçu disponible.');
    }

    public function recu(Request $request, Paiement $paiement, PdfGeneratorInterface $pdf): Response
    {
        Gate::authorize('confirm', $paiement);

        if (! $paiement->estConfirme()) {
            abort(422, 'Reçu disponible uniquement après confirmation.');
        }

        $paiement->load('facture.etudiant');
        $octets = $pdf->generer(
            '<h1>Reçu de paiement</h1>'
            .'<p>Référence : '.e($paiement->reference).'</p>'
            .'<p>Étudiant : '.e($paiement->facture->etudiant->nom_complet).'</p>'
            .'<p>Montant : '.Facture::formater($paiement->montant_centimes).'</p>'
            .'<p>Confirmé le : '.e($paiement->confirme_a?->format('d/m/Y H:i') ?? '').'</p>'
        );

        return new Response($octets, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recu-'.$paiement->reference.'.pdf"',
        ]);
    }

    public function remboursements(Request $request): View
    {
        Gate::authorize('viewAny', Facture::class);

        $faculteRestreinte = $request->user()->faculteRestreinteId();

        $remboursements = Remboursement::query()
            ->with(['paiement.facture.etudiant'])
            ->when($faculteRestreinte !== null, fn ($q) => $q->whereHas(
                'paiement.facture.etudiant',
                fn ($e) => $e->where('faculte_id', $faculteRestreinte)
            ))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('modules.finances.remboursements', [
            'remboursements' => $remboursements,
            'transitions' => Remboursement::TRANSITIONS,
        ]);
    }

    public function demanderRemboursement(Request $request, Paiement $paiement, FinanceService $finances): RedirectResponse
    {
        Gate::authorize('manage', Facture::class);

        $paiement->loadMissing('facture.etudiant');
        $facultePaiement = $paiement->facture?->etudiant?->faculte_id;
        abort_unless(
            $facultePaiement === null || $request->user()->peutAccederFaculte((int) $facultePaiement),
            404
        );

        $donnees = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'motif' => ['required', 'string', 'max:255'],
        ]);

        try {
            $finances->demanderRemboursement(
                $paiement,
                (int) round((float) $donnees['montant'] * 100),
                $donnees['motif'],
                (int) $request->user()->id,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['remboursement' => $e->getMessage()]);
        }

        return back()->with('success', 'Demande de remboursement enregistrée.');
    }

    public function traiterRemboursement(Request $request, Remboursement $remboursement, FinanceService $finances): RedirectResponse
    {
        Gate::authorize('manage', Facture::class);

        $remboursement->loadMissing('paiement.facture.etudiant');
        $faculteRemboursement = $remboursement->paiement?->facture?->etudiant?->faculte_id;
        abort_unless(
            $faculteRemboursement === null || $request->user()->peutAccederFaculte((int) $faculteRemboursement),
            404
        );

        $donnees = $request->validate([
            'statut' => ['required', 'in:approved,rejected,processed,completed'],
        ]);

        try {
            $finances->traiterRemboursement(
                $remboursement,
                $donnees['statut'],
                (int) $request->user()->id,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['remboursement' => $e->getMessage()]);
        }

        return back()->with('success', 'Remboursement mis à jour.');
    }

    public function tarifs(Request $request): View
    {
        Gate::authorize('manage', Tarif::class);

        return view('modules.finances.tarifs', [
            'tarifs' => Tarif::with(['anneeUniversitaire', 'programme'])->orderByDesc('id')->paginate(15),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
        ]);
    }

    public function storeTarif(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Tarif::class);

        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'libelle' => ['required', 'string', 'max:150'],
            'montant' => ['required', 'numeric', 'min:0'],
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'type_frais' => ['required', 'in:'.implode(',', Tarif::TYPES)],
        ]);

        $montant = (int) round((float) $donnees['montant'] * 100);
        unset($donnees['montant']);

        Tarif::create($donnees + ['montant_centimes' => $montant]);

        return back()->with('success', 'Tarif créé.');
    }

    public function mesFinances(Request $request): View
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        $factures = Facture::where('etudiant_id', $etudiant->id)
            ->with(['lignes', 'paiements'])
            ->orderByDesc('id')->paginate(10);

        return view('modules.finances.mes-finances', [
            'factures' => $factures,
            'solde' => app(FinanceService::class)->soldeEtudiant($etudiant),
        ]);
    }
}
