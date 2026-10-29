<?php

namespace App\Modules\CentreServicesIt\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CentreServicesIt\Models\TicketIt;
use App\Modules\CentreServicesIt\Services\ServiceTicketsIt;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API du centre de services IT.
 *
 * Surface volontairement bornée : création idempotente, consultation,
 * transition, affectation, escalade et statistiques. Aucune pièce jointe
 * ni règle de rétention n'est implémentée : ces décisions (D11-5/D11-7)
 * n'ont pas été arbitrées et restent hors périmètre.
 */
final class TicketItController extends Controller
{
    public function __construct(private readonly ServiceTicketsIt $service) {}

    public function index(Request $requete): JsonResponse
    {
        $valides = $requete->validate([
            'statut' => ['nullable', Rule::in([
                'pending', 'in_progress', 'waiting', 'completed', 'cancelled', 'expired', 'indisponible',
            ])],
            'priorite' => ['nullable', Rule::in(TicketIt::PRIORITES)],
            'categorie_id' => ['nullable', 'integer'],
            'en_retard' => ['nullable', 'boolean'],
            'recherche' => ['nullable', 'string', 'max:80'],
        ]);

        $faculteId = $requete->user()->faculteRestreinteId();

        $query = TicketIt::query()
            ->portee($faculteId)
            ->with(['categorie', 'tache', 'demandeur'])
            ->latest('id');

        if (! empty($valides['statut'])) {
            $statut = $valides['statut'];

            if ($statut === 'indisponible') {
                $query->whereNull('task_id');
            } else {
                $query->whereHas('tache', fn ($q) => $q->where('statut', $statut));
            }
        }

        if (! empty($valides['priorite'])) {
            $query->where('priorite', $valides['priorite']);
        }

        if (! empty($valides['categorie_id'])) {
            $query->where('categorie_id', (int) $valides['categorie_id']);
        }

        if (! empty($valides['en_retard'])) {
            $query->where('echeance_sla', '<', now())
                ->whereHas('tache', fn ($q) => $q->whereIn('statut', TicketIt::STATUTS_ACTIFS));
        }

        if (! empty($valides['recherche'])) {
            $recherche = '%'.$valides['recherche'].'%';
            $query->where(fn ($q) => $q
                ->where('reference', 'like', $recherche)
                ->orWhere('sujet', 'like', $recherche));
        }

        return response()->json($query->paginate(50)->withQueryString());
    }

    public function store(Request $requete): JsonResponse
    {
        $valides = $requete->validate([
            'cle_idempotence' => ['required', 'string', 'max:80'],
            'categorie_id' => ['required', 'integer'],
            'sujet' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priorite' => ['nullable', Rule::in(TicketIt::PRIORITES)],
            'faculte_id' => ['nullable', 'integer'],
        ]);

        $existait = TicketIt::where('cle_unique', $valides['cle_idempotence'])->exists();

        try {
            $ticket = $this->service->creer([
                'cle_unique' => $valides['cle_idempotence'],
                'categorie_id' => (int) $valides['categorie_id'],
                'sujet' => $valides['sujet'],
                'description' => $valides['description'] ?? null,
                'priorite' => $valides['priorite'] ?? null,
                'faculte_id' => isset($valides['faculte_id']) ? (int) $valides['faculte_id'] : null,
            ], $requete->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 409);
        }

        return response()->json($ticket, $existait ? 200 : 201);
    }

    public function show(Request $requete, TicketIt $ticket): JsonResponse
    {
        $this->verifierPortee($ticket, $requete->user());

        $ticket->load([
            'categorie', 'tache', 'demandeur', 'evenements.auteur',
        ]);

        return response()->json($ticket);
    }

    public function transition(Request $requete, TicketIt $ticket): JsonResponse
    {
        $this->verifierPortee($ticket, $requete->user());

        $valides = $requete->validate([
            'statut' => ['required', Rule::in(['in_progress', 'waiting', 'completed', 'cancelled'])],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $ticket = $this->service->transiter(
                $ticket,
                $valides['statut'],
                $requete->user(),
                $valides['commentaire'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 409);
        }

        return response()->json($ticket);
    }

    public function assigner(Request $requete, TicketIt $ticket): JsonResponse
    {
        $this->verifierPortee($ticket, $requete->user());

        $valides = $requete->validate([
            'assigne_a' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $ticket = $this->service->assigner($ticket, (int) $valides['assigne_a'], $requete->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 409);
        }

        return response()->json($ticket);
    }

    public function escalader(Request $requete, TicketIt $ticket): JsonResponse
    {
        $this->verifierPortee($ticket, $requete->user());

        $valides = $requete->validate([
            'cle_idempotence' => ['required', 'uuid'],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $ticket = $this->service->escalader(
                $ticket,
                $requete->user(),
                $valides['cle_idempotence'],
                $valides['motif'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 409);
        }

        return response()->json($ticket);
    }

    public function statistiques(Request $requete): JsonResponse
    {
        return response()->json($this->service->statistiques($requete->user()));
    }

    private function verifierPortee(TicketIt $ticket, User $acteur): void
    {
        abort_unless($this->service->peutAcceder($ticket, $acteur), 404);
    }
}
