<?php

namespace App\Modules\Finances\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\Finances\Services\DossierPaiementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : file de vérification des
 * reçus d'inscription (back-office).
 *
 * Le dépôt d'un reçu n'est jamais un paiement confirmé : l'agent doit
 * d'abord prendre le reçu en vérification, puis valider ou refuser avec
 * motif. Chaque décision est tracée (reçu + journal d'audit).
 */
class PaiementInscriptionAdminController extends Controller
{
    public function __construct(
        private readonly DossierPaiementService $dossiers,
    ) {}

    public function index(Request $request): View
    {
        $this->guardActif();

        Gate::authorize('viewAny', DossierPaiement::class);

        $filtre = (string) $request->query('statut', 'a_traiter');
        $statuts = match ($filtre) {
            'a_traiter' => DossierPaiement::STATUTS_A_TRAITER,
            'NON_PAYE', 'VALIDE', 'REFUSE' => [$filtre],
            'tous' => DossierPaiement::STATUTS,
            default => DossierPaiement::STATUTS_A_TRAITER,
        };

        if (! in_array($filtre, ['a_traiter', 'NON_PAYE', 'VALIDE', 'REFUSE', 'tous'], true)) {
            $filtre = 'a_traiter';
        }

        $faculteId = $request->user()->faculteRestreinteId();

        $base = DossierPaiement::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas(
                'inscription.etudiant',
                fn ($e) => $e->where('faculte_id', $faculteId)
            ));

        $compteursBruts = (clone $base)
            ->select('statut')
            ->selectRaw('count(*) as total')
            ->groupBy('statut')
            ->pluck('total', 'statut');

        $compteurs = collect(DossierPaiement::STATUTS)
            ->mapWithKeys(fn (string $statut): array => [
                $statut => (int) ($compteursBruts[$statut] ?? 0),
            ])
            ->all();

        $compteurs['a_traiter'] = $compteurs[DossierPaiement::RECU_DEPOSE]
            + $compteurs[DossierPaiement::EN_VERIFICATION];

        $anneeId = $request->integer('annee');
        $recherche = trim((string) $request->query('q', ''));

        $dossiers = (clone $base)
            ->with([
                'inscription.etudiant.faculte',
                'inscription.anneeUniversitaire',
                'inscription.programmeRef',
                'recus' => fn ($q) => $q->orderByDesc('version'),
            ])
            ->whereIn('statut', $statuts)
            ->when($anneeId > 0, fn ($q) => $q->whereHas(
                'inscription',
                fn ($i) => $i->where('annee_universitaire_id', $anneeId)
            ))
            ->when($recherche !== '', fn ($q) => $q->whereHas(
                'inscription.etudiant',
                fn ($e) => $e->where(fn ($s) => $s
                    ->where('cin', 'like', '%'.$recherche.'%')
                    ->orWhere('nom', 'like', '%'.$recherche.'%')
                    ->orWhere('prenom', 'like', '%'.$recherche.'%'))
            ))
            // Priorité opérationnelle sans dépendre d'un SQL propriétaire.
            ->orderByRaw("CASE statut
                WHEN 'RECU_DEPOSE' THEN 0
                WHEN 'EN_VERIFICATION' THEN 1
                WHEN 'REFUSE' THEN 2
                WHEN 'NON_PAYE' THEN 3
                ELSE 4
            END")
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        $montants = $this->dossiers->montantsAttendus(
            $dossiers->getCollection()->pluck('inscription')->filter()
        );

        $lignes = $dossiers->getCollection()->map(function (DossierPaiement $dossier) use ($montants): array {
            return [
                'dossier' => $dossier,
                'montant' => $montants[$dossier->inscription_academique_id],
                'dernier' => $dossier->recus->first(),
            ];
        });

        return view('modules.finances.paiements-inscriptions.index', [
            'dossiers' => $dossiers,
            'lignes' => $lignes,
            'compteurs' => $compteurs,
            'filtre' => $filtre,
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
        ]);
    }

    public function show(DossierPaiement $dossier): View
    {
        $this->guardActif();

        Gate::authorize('view', $dossier);

        $dossier->load([
            'inscription.etudiant.faculte',
            'inscription.anneeUniversitaire',
            'inscription.programmeRef',
            'recus' => fn ($q) => $q->orderByDesc('version'),
        ]);

        return view('modules.finances.paiements-inscriptions.show', [
            'dossier' => $dossier,
            'montant' => $this->dossiers->montantAttendu($dossier->inscription),
            'dernier' => $dossier->recus->first(),
        ]);
    }

    public function prendre(Request $request, DossierPaiement $dossier): RedirectResponse
    {
        $this->guardActif();

        Gate::authorize('verifier', $dossier);

        try {
            $this->dossiers->prendreEnVerification($dossier, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['paiement' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.finances.paiements.show', $dossier)
            ->with('success', 'Reçu pris en vérification. Aucun paiement n’est encore confirmé.');
    }

    public function valider(Request $request, DossierPaiement $dossier): RedirectResponse
    {
        $this->guardActif();

        Gate::authorize('valider', $dossier);

        $donnees = $request->validate([
            'commentaire' => ['nullable', 'string', 'max:'.(int) config('inscriptions-paiements.commentaire_max', 1000)],
        ]);

        try {
            $this->dossiers->valider(
                $dossier,
                $request->user(),
                $donnees['commentaire'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['paiement' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.finances.paiements.show', $dossier)
            ->with('success', 'Reçu validé : le paiement de cette inscription est confirmé.');
    }

    public function refuser(Request $request, DossierPaiement $dossier): RedirectResponse
    {
        $this->guardActif();

        Gate::authorize('refuser', $dossier);

        $donnees = $request->validate([
            'motif' => [
                'required',
                'string',
                'min:'.(int) config('inscriptions-paiements.motif_refus_min', 10),
                'max:'.(int) config('inscriptions-paiements.motif_refus_max', 1000),
            ],
        ]);

        try {
            $this->dossiers->refuser($dossier, $request->user(), $donnees['motif']);
        } catch (DomainException $e) {
            return back()->withErrors(['paiement' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.finances.paiements.show', $dossier)
            ->with('success', 'Reçu refusé. L’étudiant peut déposer un nouveau reçu ; l’ancien reste dans l’historique.');
    }

    public function apercu(
        Request $request,
        DossierPaiement $dossier,
        RecuPaiement $recu,
    ): StreamedResponse {
        return $this->reponseRecu($dossier, $recu, true);
    }

    public function telecharger(
        Request $request,
        DossierPaiement $dossier,
        RecuPaiement $recu,
    ): StreamedResponse {
        return $this->reponseRecu($dossier, $recu, false);
    }

    private function reponseRecu(
        DossierPaiement $dossier,
        RecuPaiement $recu,
        bool $inline,
    ): StreamedResponse {
        $this->guardActif();

        Gate::authorize('view', $dossier);

        abort_unless((int) $recu->dossier_paiement_id === (int) $dossier->id, 404);

        $dossier->loadMissing('inscription.etudiant');

        /** @var Etudiant|null $etudiant */
        $etudiant = $dossier->inscription?->etudiant;
        abort_if($etudiant === null, 404);

        $this->dossiers->auditerConsultation(
            $recu,
            $etudiant,
            $inline ? 'paiement.recu_consulte' : 'paiement.recu_telecharge',
        );

        return $this->dossiers->reponseRecu($recu, $inline);
    }

    private function guardActif(): void
    {
        abort_unless((bool) config('inscriptions-paiements.actif', true), 404);
    }
}
