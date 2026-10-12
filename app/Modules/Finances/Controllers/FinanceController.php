<?php

namespace App\Modules\Finances\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
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
    public function dashboard(Request $request): View
    {
        Gate::authorize('viewAny', Facture::class);

        $base = Facture::query()
            ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->whereHas(
                'etudiant',
                fn ($e) => $e->where('faculte_id', $request->user()->faculteRestreinteId())
            ));

        $factures = (clone $base)->with('paiements')->get();

        $total = 0;
        $encaisse = 0;
        $enRetard = 0;
        foreach ($factures as $facture) {
            $total += $facture->netCentimes();
            $encaisse += $facture->payeCentimes();
            if ($facture->resteCentimes() > 0 && $facture->date_echeance !== null && $facture->date_echeance->isPast()) {
                $enRetard += $facture->resteCentimes();
            }
        }

        return view('modules.finances.tableau-bord', [
            'total' => $total,
            'encaisse' => $encaisse,
            'reste' => max(0, $total - $encaisse),
            'en_retard' => $enRetard,
            'impayees' => (clone $base)->whereIn('statut', ['issued', 'partially_paid', 'overdue'])->count(),
            'remboursements' => Remboursement::where('statut', 'requested')->count(),
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
