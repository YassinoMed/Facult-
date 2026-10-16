<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courriel;
use App\Services\CourrielService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Cockpit de messagerie (LOOP-MAIL-ENTERPRISE-01) : supervision du journal
 * technique, catalogue et aperçu des modèles, reprise encadrée des échecs,
 * envoi manuel restreint (modèle unique, double confirmation, débit
 * limité, acteur audité). Aucun secret, OTP, corps ou adresse en clair
 * n'est exposé — ni dans les vues, ni dans les réponses, ni dans les logs.
 */
class CourrielController extends Controller
{
    public function __construct(
        private readonly CourrielService $courriels,
    ) {}

    /**
     * Tableau de bord + historique paginé (filtres : statut, modèle).
     */
    public function index(Request $request): View
    {
        $statuts = Courriel::STATUTS;
        $modeles = array_keys((array) config('courriels.modeles', []));

        $requete = Courriel::query()->latest('id');

        $filtreStatut = (string) $request->string('statut');
        if (in_array($filtreStatut, $statuts, true)) {
            $requete->where('statut', $filtreStatut);
        }

        $filtreModele = (string) $request->string('modele');
        if (in_array($filtreModele, $modeles, true)) {
            $requete->where('modele', $filtreModele);
        }

        $compteurs = [];
        foreach ($statuts as $statut) {
            $compteurs[$statut] = Courriel::where('statut', $statut)->count();
        }

        return view('admin.courriels.index', [
            'compteurs' => $compteurs,
            'courriels' => $requete->paginate(20)->withQueryString(),
            'statuts' => $statuts,
            'modeles' => $modeles,
            'filtreStatut' => $filtreStatut,
            'filtreModele' => $filtreModele,
            'tauxEchec' => $this->tauxEchec(),
        ]);
    }

    /**
     * Détail expurgé d'un envoi.
     */
    public function voir(Courriel $courriel): View
    {
        return view('admin.courriels.voir', [
            'courriel' => $courriel,
            'catalogue' => config('courriels.modeles.'.$courriel->modele),
        ]);
    }

    /**
     * Catalogue des modèles + aperçu sans envoi.
     */
    public function catalogue(Request $request): View
    {
        $modeles = (array) config('courriels.modeles', []);
        $choisi = (string) $request->string('modele', 'message_manuel');

        if (! array_key_exists($choisi, $modeles)) {
            $choisi = 'message_manuel';
        }

        $apercu = $this->courriels->previsualiser($choisi, ['message' => 'Exemple de message administratif.']);

        return view('admin.courriels.catalogue', [
            'modeles' => $modeles,
            'choisi' => $choisi,
            'apercu' => $apercu,
        ]);
    }

    /**
     * Reprise encadrée d'un échec (gardes serveur : statut, plafond,
     * cooldown, refus OTP — voir `CourrielService::reessayer`).
     */
    public function reessayer(Request $request, Courriel $courriel): RedirectResponse
    {
        $donnees = $request->validate([
            'destinataire' => ['required', 'string', 'max:254'],
            'confirmation' => ['required', 'accepted'],
        ]);

        try {
            $courriel = $this->courriels->reessayer($courriel, $donnees['destinataire'], $request->user()?->id);
        } catch (DomainException|InvalidArgumentException $e) {
            return redirect()->route('admin.courriels.voir', $courriel)
                ->with('error', $e->getMessage());
        }

        if ($courriel->statut === Courriel::STATUT_ECHEC) {
            return redirect()->route('admin.courriels.voir', $courriel)
                ->with('error', 'Nouvel échec ('.$courriel->categorie_erreur.') : diagnostic expurgé disponible.');
        }

        return redirect()->route('admin.courriels.voir', $courriel)
            ->with('success', 'Message réémis vers l’adresse d’origine.');
    }

    /**
     * Formulaire d'envoi manuel (modèle unique sûr).
     */
    public function creer(): View
    {
        return view('admin.courriels.creer');
    }

    /**
     * Envoi manuel en deux temps : `apercu` (validation + prévisualisation,
     * aucun envoi) puis `confirmer` (confirmation explicite exigée).
     */
    public function envoyer(Request $request): View|RedirectResponse
    {
        $donnees = $request->validate([
            'destinataire' => ['required', 'string', 'max:254'],
            'message' => ['required', 'string', 'max:500'],
            'etape' => ['nullable', 'string', 'in:apercu,confirmer'],
            'confirmation' => ['sometimes', 'accepted'],
        ]);

        $cleDebit = 'courriel-manuel:'.$request->ip().':'.$request->user()?->id;
        if (RateLimiter::tooManyAttempts($cleDebit, (int) config('courriels.debit_manuel_par_minute', 5))) {
            return redirect()->route('admin.courriels.creer')
                ->with('error', 'Débit maximal atteint : patientez une minute.')
                ->withInput();
        }

        $etape = $donnees['etape'] ?? 'apercu';

        try {
            $apercu = $this->courriels->valider('message_manuel', $donnees['destinataire'], ['message' => $donnees['message']]);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('admin.courriels.creer')
                ->with('error', $e->getMessage())
                ->withInput();
        }

        if ($etape !== 'confirmer' || ! isset($donnees['confirmation'])) {
            return view('admin.courriels.creer', [
                'apercu' => $apercu,
                'destinataire' => $donnees['destinataire'],
                'message' => $donnees['message'],
            ]);
        }

        RateLimiter::hit($cleDebit);

        try {
            $courriel = $this->courriels->envoyer('message_manuel', $donnees['destinataire'], [
                'message' => $donnees['message'],
            ], [
                'acteur_admin_id' => $request->user()?->id,
                'correlation' => 'manuel-'.now()->format('YmdHis'),
            ]);
        } catch (InvalidArgumentException|DomainException $e) {
            return redirect()->route('admin.courriels.creer')
                ->with('error', $e->getMessage())
                ->withInput();
        }

        if ($courriel->statut === Courriel::STATUT_ECHEC) {
            return redirect()->route('admin.courriels.voir', $courriel)
                ->with('error', 'Échec d’envoi ('.$courriel->categorie_erreur.') : diagnostic expurgé disponible.');
        }

        return redirect()->route('admin.courriels.voir', $courriel)
            ->with('success', 'Message transmis au transport SMTP.');
    }

    /**
     * Taux d'échec sur les dernières 24 h (alerte technique, sans PII).
     */
    private function tauxEchec(): ?float
    {
        $total = Courriel::where('created_at', '>=', now()->subDay())->count();

        if ($total === 0) {
            return null;
        }

        $echecs = Courriel::where('created_at', '>=', now()->subDay())
            ->where('statut', Courriel::STATUT_ECHEC)
            ->count();

        return round($echecs * 100 / $total, 1);
    }
}
