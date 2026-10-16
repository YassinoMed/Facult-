<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerrouillagePin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Verrouillage secondaire après inactivité (LOOP N) : code PIN personnel
 * (bcrypt, temporisation progressive), invalidation sur événements de
 * sécurité. Le PIN n'est jamais un facteur fort indépendant.
 */
final class VerrouillageService
{
    public function __construct(private readonly ParametreService $parametres) {}

    public function estEligible(User $utilisateur): bool
    {
        return (bool) $this->parametres->get('verrouillage.actif', true)
            && VerrouillagePin::where('user_id', $utilisateur->id)->exists();
    }

    public function delaiSecondes(): int
    {
        return max(300, (int) $this->parametres->get('verrouillage.delai_minutes', 15) * 60);
    }

    public function preavisSecondes(): int
    {
        return max(0, min(300, (int) $this->parametres->get('verrouillage.preavis_secondes', 60)));
    }

    public function longueurPin(): int
    {
        return max(6, min(8, (int) $this->parametres->get('verrouillage.pin_longueur', 6)));
    }

    /**
     * @return array{ok: bool, attente_secondes: int}
     */
    public function verifier(User $utilisateur, string $code): array
    {
        $pin = VerrouillagePin::where('user_id', $utilisateur->id)->first();

        if ($pin === null) {
            return ['ok' => false, 'attente_secondes' => 0];
        }

        if ($pin->bloque_until !== null && $pin->bloque_until->isFuture()) {
            return ['ok' => false, 'attente_secondes' => (int) now()->diffInSeconds($pin->bloque_until)];
        }

        if (! preg_match('/^\d+$/', $code) || ! Hash::check($code, $pin->pin_hash)) {
            $this->enregistrerEchec($utilisateur, $pin);

            return ['ok' => false, 'attente_secondes' => 0];
        }

        $pin->update(['echecs' => 0, 'bloque_until' => null]);

        Log::channel(config('logging.default'))->notice('verrouillage.deverrouille', [
            'acteur_id' => $utilisateur->id,
        ]);

        return ['ok' => true, 'attente_secondes' => 0];
    }

    public function definir(User $utilisateur, string $code): void
    {
        VerrouillagePin::updateOrCreate(
            ['user_id' => $utilisateur->id],
            ['pin_hash' => Hash::make($code), 'echecs' => 0, 'bloque_until' => null]
        );

        Log::channel(config('logging.default'))->notice('verrouillage.pin_defini', [
            'acteur_id' => $utilisateur->id,
        ]);
    }

    public function purger(User $utilisateur, string $motif): void
    {
        VerrouillagePin::where('user_id', $utilisateur->id)->delete();

        Log::channel(config('logging.default'))->notice('verrouillage.pin_purge', [
            'acteur_id' => $utilisateur->id,
            'motif' => $motif,
        ]);
    }

    /**
     * Initialise le contexte en session après authentification réussie :
     * activité datée + éligibilité (PIN défini). Appelé par les flux
     * de connexion (jamais ailleurs pour éviter toute réactivation).
     */
    public function initialiserSession(Request $request, ?User $utilisateur): void
    {
        $request->session()->put('derniere_activite', now()->timestamp);
        $request->session()->put(
            'secondaire_eligible',
            $utilisateur !== null && VerrouillagePin::where('user_id', $utilisateur->id)->exists()
        );
        $request->session()->forget('secondaire_verrouille');
    }

    /**
     * Prolongation explicite (action utilisateur réelle, jamais
     * automatique) : ne touche le minuteur QUE si le verrou n'est pas
     * déjà acquis — impossible de réanimer un délai échu.
     */
    public function prolonger(Request $request): bool
    {
        if ((bool) $request->session()->get('secondaire_verrouille', false)) {
            return false;
        }

        $derniere = $request->session()->get('derniere_activite');

        if ($derniere !== null && now()->timestamp - (int) $derniere > $this->delaiSecondes()) {
            return false;
        }

        $request->session()->put('derniere_activite', now()->timestamp);

        return true;
    }

    private function enregistrerEchec(User $utilisateur, VerrouillagePin $pin): void
    {
        $max = max(3, (int) $this->parametres->get('verrouillage.tentatives_max', 5));
        $base = max(10, (int) $this->parametres->get('verrouillage.temporisation_secondes', 30));

        // Incrément atomique en base : deux onglets soumettant en
        // parallèle ne peuvent ni sous-compter ni sur-compter (§4).
        VerrouillagePin::where('user_id', $utilisateur->id)->increment('echecs');
        $pin->refresh();
        $echecs = (int) $pin->echecs;

        if ($echecs >= $max && ($pin->bloque_until === null || $pin->bloque_until->isPast())) {
            // Temporisation progressive : base × dépassement, plafond 15 min.
            $pin->update(['bloque_until' => now()->addSeconds(min(900, $base * ($echecs - $max + 1)))]);
        }

        Log::channel(config('logging.default'))->notice('verrouillage.echec', [
            'acteur_id' => $utilisateur->id,
            'tentative' => $echecs,
        ]);
    }
}
