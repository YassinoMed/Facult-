<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Services\RendezVousScolariteService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contrôleur des rendez-vous et de l'aide à la scolarité (LOOP 16).
 * Accessible par l'étudiant authentifié.
 */
class RendezVousScolariteController extends Controller
{
    /**
     * Affiche le formulaire de prise de rendez-vous / demande d'aide.
     */
    public function nouveau(Request $request, RendezVousScolariteService $service): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $dateParDefaut = Carbon::tomorrow()->isWeekend()
            ? Carbon::next(Carbon::MONDAY)->format('Y-m-d')
            : Carbon::tomorrow()->format('Y-m-d');

        $creneauxInitiaux = $service->obtenirCreneauxDisponibles($dateParDefaut, $etudiant->faculte_id);

        return view('etudiant.rendez-vous.create', [
            'etudiant' => $etudiant,
            'motifs' => $service->motifs(),
            'dateParDefaut' => $dateParDefaut,
            'creneauxInitiaux' => $creneauxInitiaux,
            'contact' => config('inscriptions.contact_scolarite'),
        ]);
    }

    /**
     * Endpoint asynchrone retournant les créneaux réellement disponibles pour une date.
     */
    public function creneaux(Request $request, RendezVousScolariteService $service): JsonResponse
    {
        $etudiant = $this->etudiantConnecte($request);
        $date = $request->string('date')->toString();

        if (! $date || ! strtotime($date)) {
            return response()->json(['error' => 'Date invalide ou manquante.'], 422);
        }

        $creneaux = $service->obtenirCreneauxDisponibles($date, $etudiant->faculte_id);

        return response()->json([
            'date' => $date,
            'creneaux' => $creneaux,
        ]);
    }

    /**
     * Enregistre un rendez-vous côté serveur.
     * La confirmation n'est envoyée qu'après réservation réussie.
     */
    public function store(Request $request, RendezVousScolariteService $service): RedirectResponse|JsonResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        $donnees = $request->validate([
            'motif_categorie' => ['required', 'string'],
            'description' => ['required', 'string', 'max:2000'],
            'date_creneau' => ['required', 'date'],
            'heure_creneau' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ], [
            'motif_categorie.required' => 'Le motif de votre demande est obligatoire.',
            'description.required' => 'Veuillez préciser brièvement l\'objet de votre démarche.',
            'date_creneau.required' => 'La date du rendez-vous est obligatoire.',
            'heure_creneau.required' => 'L\'horaire du créneau est obligatoire.',
        ]);

        $demande = $service->reserver($etudiant, $donnees);

        if ($request->expectsJson()) {
            return response()->json([
                'statut' => 'confirme',
                'reference' => $demande->reference,
                'demande_id' => $demande->id,
                'message' => 'Rendez-vous réservé et confirmé avec succès.',
            ], 201);
        }

        return redirect()->route('etudiant.demandes.show', $demande)
            ->with('success', "Votre rendez-vous d'aide à la scolarité ({$demande->reference}) a été confirmé pour le {$donnees['date_creneau']} à {$donnees['heure_creneau']}.");
    }

    /**
     * Annule un rendez-vous et libère immédiatement le créneau horaire.
     */
    public function annuler(Request $request, OnlineRequest $demande, RendezVousScolariteService $service): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        $service->annuler($demande, $etudiant);

        return redirect()->route('etudiant.demandes.show', $demande)
            ->with('success', "Votre rendez-vous ({$demande->reference}) a été annulé avec succès. Le créneau est libéré.");
    }

    /**
     * Déplace un rendez-vous vers un nouveau créneau disponible.
     */
    public function deplacer(Request $request, OnlineRequest $demande, RendezVousScolariteService $service): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        $donnees = $request->validate([
            'nouvelle_date' => ['required', 'date'],
            'nouvelle_heure' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $service->deplacer($demande, $etudiant, $donnees['nouvelle_date'], $donnees['nouvelle_heure']);

        return redirect()->route('etudiant.demandes.show', $demande)
            ->with('success', "Votre rendez-vous a été déplacé au {$donnees['nouvelle_date']} à {$donnees['nouvelle_heure']}.");
    }

    /**
     * Résout l'étudiant connecté depuis la session sécurisée.
     */
    protected function etudiantConnecte(Request $request): Etudiant
    {
        $etudiantId = $request->session()->get('etudiant_auth_id');
        abort_if(! $etudiantId, 401);

        return Etudiant::findOrFail($etudiantId);
    }
}
