<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Services\PreferenceNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LOT 02 — préférences de notification de l'étudiant : exposition HTTP de
 * PreferenceNotificationService::definir() (jamais accessible avant).
 * Opt-out par catégorie uniquement ; les catégories institutionnelles
 * (résultats, documents officiels) restent FORCÉES à l'envoi et à l'affichage
 * — aucune écriture n'est tentée pour elles. Anti-IDOR : l'étudiant cible
 * est résolu par session, jamais par paramètre client ; un étudiant ne
 * modifie jamais les préférences d'un autre.
 */
class NotificationPreferencesController extends Controller
{
    public function __construct(private readonly PreferenceNotificationService $preferences) {}

    /**
     * Libellés manuels des catégories (portail étudiant).
     *
     * @return array<string, string>
     */
    private const LIBELLES = [
        'resultats' => 'Résultats et délibérations',
        'documents_officiels' => 'Documents officiels',
        'demandes' => 'Demandes',
        'annonces' => 'Annonces',
        'inscriptions' => 'Inscriptions',
    ];

    public function afficher(Request $request): View
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $etats = NotificationPreference::where('etudiant_id', $etudiantId)
            ->whereIn('categorie', PreferenceNotificationService::CATEGORIES)
            ->pluck('actif', 'categorie');

        $categories = [];
        foreach (PreferenceNotificationService::CATEGORIES as $categorie) {
            $categories[] = [
                'cle' => $categorie,
                'libelle' => self::LIBELLES[$categorie] ?? $categorie,
                'obligatoire' => in_array($categorie, PreferenceNotificationService::OBLIGATOIRES, true),
                'actif' => in_array($categorie, PreferenceNotificationService::OBLIGATOIRES, true)
                    ? true
                    : (bool) ($etats[$categorie] ?? true),
            ];
        }

        return view('notifications.preferences', [
            'titre' => 'Préférences de notification',
            'retour' => route('etudiant.notifications.index'),
            'categories' => $categories,
        ]);
    }

    public function mettreAJour(Request $request): RedirectResponse
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $choisies = (array) ($request->input('preferences') ?? []);

        // Catégories autorisées whitelistées ; les obligatoires ne sont
        // jamais écrites (culture : pas d'opt-out institutionnel possible).
        foreach (PreferenceNotificationService::CATEGORIES as $categorie) {
            if (in_array($categorie, PreferenceNotificationService::OBLIGATOIRES, true)) {
                continue;
            }

            $this->preferences->definir($etudiantId, null, $categorie, in_array($categorie, $choisies, true));
        }

        return redirect()->route('etudiant.notifications.preferences')
            ->with('success', 'Préférences de notification enregistrées.');
    }
}
