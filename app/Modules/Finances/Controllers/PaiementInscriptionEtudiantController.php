<?php

namespace App\Modules\Finances\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\Finances\Requests\DeposerRecuPaiementRequest;
use App\Modules\Finances\Services\DossierPaiementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : espace étudiant.
 *
 * L'étudiant est TOUJOURS résolu depuis la session `etudiant_auth_id` ;
 * aucun identifiant client ne permet de consulter le dossier d'un autre
 * étudiant. Chaque action vérifie en plus que l'inscription lui appartient.
 */
class PaiementInscriptionEtudiantController extends Controller
{
    public function __construct(
        private readonly DossierPaiementService $dossiers,
    ) {}

    public function index(Request $request): View
    {
        $this->guardActif();

        $etudiant = $this->etudiantConnecte($request);

        $inscriptions = $etudiant->inscriptionsAcademiques()
            ->with([
                'etudiant',
                'anneeUniversitaire',
                'programmeRef',
                'dossierPaiement.recus' => fn ($q) => $q->orderByDesc('version'),
            ])
            ->orderByDesc('annee_universitaire_id')
            ->get();

        $montants = $this->dossiers->montantsAttendus($inscriptions);

        $lignes = $inscriptions->map(function (InscriptionAcademique $inscription) use ($montants): array {
            return [
                'inscription' => $inscription,
                'montant' => $montants[$inscription->id],
                'dernier' => $inscription->dossierPaiement?->recus->first(),
            ];
        });

        return view('modules.finances.paiements-inscriptions.etudiant-index', [
            'etudiant' => $etudiant,
            'lignes' => $lignes,
        ]);
    }

    public function show(Request $request, InscriptionAcademique $inscription): View
    {
        $this->guardActif();

        $etudiant = $this->etudiantConnecte($request);
        $this->verifierProprietaire($inscription, $etudiant);

        $inscription->load([
            'etudiant',
            'anneeUniversitaire',
            'programmeRef',
            'dossierPaiement.recus' => fn ($q) => $q->orderByDesc('version'),
        ]);

        $dossier = $inscription->dossierPaiement;
        $recus = $dossier?->recus ?? collect();

        return view('modules.finances.paiements-inscriptions.etudiant-show', [
            'etudiant' => $etudiant,
            'inscription' => $inscription,
            'dossier' => $dossier,
            'recus' => $recus,
            'dernier' => $recus->first(),
            'montant' => $this->dossiers->montantAttendu($inscription),
        ]);
    }

    public function store(
        DeposerRecuPaiementRequest $request,
        InscriptionAcademique $inscription,
    ): RedirectResponse {
        $this->guardActif();

        $etudiant = $this->etudiantConnecte($request);
        $this->verifierProprietaire($inscription, $etudiant);

        try {
            $this->dossiers->deposerRecu(
                $inscription,
                $etudiant,
                $request->file('recu'),
                $request->validated(),
            );
        } catch (DomainException|RuntimeException $e) {
            return back()
                ->withErrors(['recu' => $e->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('etudiant.paiements.inscription', $inscription)
            ->with('success', 'Reçu déposé. Il sera vérifié par la scolarité : aucun paiement n’est confirmé avant validation.');
    }

    public function apercu(
        Request $request,
        InscriptionAcademique $inscription,
        RecuPaiement $recu,
    ): StreamedResponse {
        return $this->reponseRecu($request, $inscription, $recu, true);
    }

    public function telecharger(
        Request $request,
        InscriptionAcademique $inscription,
        RecuPaiement $recu,
    ): StreamedResponse {
        return $this->reponseRecu($request, $inscription, $recu, false);
    }

    private function reponseRecu(
        Request $request,
        InscriptionAcademique $inscription,
        RecuPaiement $recu,
        bool $inline,
    ): StreamedResponse {
        $this->guardActif();

        $etudiant = $this->etudiantConnecte($request);
        $this->verifierProprietaire($inscription, $etudiant);

        abort_unless((int) $recu->inscription_academique_id === (int) $inscription->id, 404);

        $this->dossiers->auditerConsultation(
            $recu,
            $etudiant,
            $inline ? 'paiement.recu_consulte' : 'paiement.recu_telecharge',
        );

        return $this->dossiers->reponseRecu($recu, $inline);
    }

    private function etudiantConnecte(Request $request): Etudiant
    {
        return Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));
    }

    private function verifierProprietaire(InscriptionAcademique $inscription, Etudiant $etudiant): void
    {
        abort_unless(
            (int) $inscription->etudiant_id === (int) $etudiant->id,
            404,
            'Inscription introuvable.'
        );
    }

    private function guardActif(): void
    {
        abort_unless((bool) config('inscriptions-paiements.actif', true), 404);
    }
}
