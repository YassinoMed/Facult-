<?php

namespace App\Modules\CentreServicesIt\Services;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Calendar\Services\JoursOuvrables;
use App\Modules\CentreServicesIt\Models\CategorieIt;
use App\Modules\CentreServicesIt\Models\EvenementIt;
use App\Modules\CentreServicesIt\Models\TicketIt;
use App\Modules\Tasks\Services\TaskService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Centre de services IT — orchestration AU-DESSUS du pipeline Tasks.
 *
 * Invariants :
 * - aucune machine à états parallèle : les transitions et l'affectation
 *   passent par `TaskService` ;
 * - aucune donnée officielle (note, inscription, statut d'étudiant) n'est
 *   écrite par ce service ;
 * - la clé d'idempotence du ticket empêche tout doublon actif ;
 * - l'escalade porte sa propre clé d'idempotence : un rejeu ne saute
 *   jamais deux niveaux ;
 * - le périmètre facultaire est résolu côté serveur depuis l'utilisateur.
 */
final class ServiceTicketsIt
{
    public const SOURCE = 'centre_services_it';

    public function __construct(
        private readonly TaskService $taches,
        private readonly JoursOuvrables $joursOuvrables,
    ) {}

    public function tasksActif(): bool
    {
        return (bool) config('modules.tasks.active', false);
    }

    /**
     * Crée un ticket (ou retourne le ticket existant pour la même clé).
     *
     * @param  array{cle_unique: string, categorie_id: int, sujet: string, description?: ?string, priorite?: ?string, faculte_id?: ?int}  $donnees
     */
    public function creer(array $donnees, User $demandeur): TicketIt
    {
        $this->garantirTasks();

        return DB::transaction(function () use ($donnees, $demandeur): TicketIt {
            $existante = TicketIt::where('cle_unique', $donnees['cle_unique'])
                ->first();

            if ($existante !== null) {
                return $existante->load(['categorie', 'tache', 'demandeur']);
            }

            $categorie = CategorieIt::whereKey($donnees['categorie_id'])
                ->where('actif', true)
                ->first();

            if ($categorie === null) {
                throw new DomainException('Catégorie IT inconnue ou inactive.');
            }

            $priorite = $donnees['priorite'] ?? $categorie->priorite_defaut;

            if (! in_array($priorite, CategorieIt::PRIORITES, true)) {
                throw new DomainException('Priorité IT invalide.');
            }

            $faculteId = $this->resoudreFaculte($demandeur, $donnees['faculte_id'] ?? null);
            $echeance = $this->calculerEcheance($categorie, $priorite);

            try {
                $ticket = TicketIt::create([
                    // Référence provisoire courte (colonne 30) : remplacée par
                    // l'identifiant lisible dès que la ligne possède son id.
                    'reference' => 'TMP-'.Str::upper(Str::random(20)),
                    'cle_unique' => $donnees['cle_unique'],
                    'task_id' => null,
                    'demandeur_id' => $demandeur->id,
                    'faculte_id' => $faculteId,
                    'categorie_id' => $categorie->id,
                    'priorite' => $priorite,
                    'sujet' => $donnees['sujet'],
                    'description' => $donnees['description'] ?? null,
                    'echeance_sla' => $echeance,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Course entre deux requêtes portant la même clé : la seconde
                // relit le ticket créé par la première, sans doublon.
                return TicketIt::where('cle_unique', $donnees['cle_unique'])
                    ->firstOrFail()
                    ->load(['categorie', 'tache', 'demandeur']);
            }

            $ticket->update([
                'reference' => sprintf('IT-%s-%06d', now()->format('Y'), $ticket->id),
            ]);

            $tache = $this->taches->synchroniser($ticket->cle_unique, [
                'titre' => Str::limit("IT — {$categorie->libelle} : {$donnees['sujet']}", 150, '…'),
                'description' => $donnees['description'] ?? null,
                'source' => self::SOURCE,
                'priorite' => $this->prioriteTache($priorite),
                'echeance_a' => $echeance,
                'faculte_id' => $faculteId,
                'created_by' => $demandeur->id,
            ]);

            $ticket->update(['task_id' => $tache->id]);

            EvenementIt::create([
                'ticket_id' => $ticket->id,
                'type' => 'cree',
                'vers' => $tache->statut,
                'auteur_id' => $demandeur->id,
                'message' => "Ticket {$ticket->reference} créé.",
            ]);

            return $ticket->fresh(['categorie', 'tache', 'demandeur']);
        }, 5);
    }

    public function transiter(TicketIt $ticket, string $cible, User $auteur, ?string $commentaire = null): TicketIt
    {
        $this->garantirTasks();

        $tache = $ticket->tache;

        if ($tache === null) {
            throw new DomainException('Workflow indisponible : ce ticket n’est lié à aucune tâche.');
        }

        $avant = $tache->statut;
        $apres = $this->taches->transiter($tache, $cible, (int) $auteur->id, $commentaire);

        EvenementIt::create([
            'ticket_id' => $ticket->id,
            'type' => 'transition',
            'de' => $avant,
            'vers' => $apres->statut,
            'message' => $commentaire,
            'auteur_id' => $auteur->id,
        ]);

        return $ticket->fresh(['categorie', 'tache', 'demandeur', 'evenements']);
    }

    public function assigner(TicketIt $ticket, int $assigneId, User $auteur): TicketIt
    {
        $this->garantirTasks();

        $assigne = User::whereKey($assigneId)->where('actif', true)->first();

        if ($assigne === null) {
            throw new DomainException('Agent introuvable ou inactif.');
        }

        if (! $assigne->hasPermission('it.manage')) {
            throw new DomainException('L’agent ciblé ne possède pas la permission it.manage.');
        }

        if ($ticket->faculte_id !== null && ! $assigne->peutAccederFaculte((int) $ticket->faculte_id)) {
            throw new AuthorizationException('L’agent ciblé est hors du périmètre facultaire du ticket.');
        }

        $tache = $ticket->tache;

        if ($tache === null) {
            throw new DomainException('Workflow indisponible : ce ticket n’est lié à aucune tâche.');
        }

        $this->taches->assigner($tache, (int) $assigne->id, (int) $auteur->id);

        EvenementIt::create([
            'ticket_id' => $ticket->id,
            'type' => 'assigne',
            // Aucun nom d'agent recopié : l'identifiant technique suffit et
            // évite de dupliquer des données personnelles dans le journal.
            'message' => "Assigné à l’agent #{$assigne->id}.",
            'auteur_id' => $auteur->id,
        ]);

        return $ticket->fresh(['categorie', 'tache', 'demandeur', 'evenements']);
    }

    /**
     * Escalade d'un niveau (P3 → P2 → P1). La clé d'idempotence empêche
     * qu'un rejeu réseau fasse monter deux fois la priorité.
     */
    public function escalader(TicketIt $ticket, User $auteur, string $cleIdempotence, ?string $motif = null): TicketIt
    {
        $this->garantirTasks();

        try {
            return DB::transaction(function () use ($ticket, $auteur, $cleIdempotence, $motif): TicketIt {
                $verrouille = TicketIt::whereKey($ticket->id)->lockForUpdate()->firstOrFail();

                $deja = EvenementIt::where('cle_idempotence', $cleIdempotence)->exists();

                if ($deja) {
                    return $verrouille->fresh(['categorie', 'tache', 'demandeur', 'evenements']);
                }

                $tache = $verrouille->tache;

                if ($tache === null || ! $tache->estActive()) {
                    throw new DomainException('Ticket non actif : escalade impossible.');
                }

                $avant = $verrouille->priorite;
                $apres = match ($avant) {
                    'P3' => 'P2',
                    'P2' => 'P1',
                    default => 'P1',
                };

                // L'escalade ne repousse JAMAIS l'échéance : un retard déjà
                // constaté ne s'efface pas parce que le ticket monte en priorité.
                $verrouille->update([
                    'priorite' => $apres,
                    'escalade_le' => now(),
                ]);

                $this->taches->synchroniser($verrouille->cle_unique, [
                    'priorite' => $this->prioriteTache($apres),
                ]);

                EvenementIt::create([
                    'ticket_id' => $verrouille->id,
                    'type' => 'escalade',
                    'cle_idempotence' => $cleIdempotence,
                    'de' => $avant,
                    'vers' => $apres,
                    'message' => $motif,
                    'auteur_id' => $auteur->id,
                ]);

                return $verrouille->fresh(['categorie', 'tache', 'demandeur', 'evenements']);
            }, 5);
        } catch (UniqueConstraintViolationException) {
            return $ticket->fresh(['categorie', 'tache', 'demandeur', 'evenements']);
        }
    }

    /**
     * @return array{total: int, par_statut: array<string, int>, par_priorite: array<string, int>, en_retard: int, escalades: int}
     */
    public function statistiques(User $acteur): array
    {
        $faculteId = $acteur->faculteRestreinteId();
        $base = fn () => TicketIt::query()->portee($faculteId);

        $parStatut = [];

        foreach (['pending', 'in_progress', 'waiting', 'completed', 'cancelled', 'expired'] as $statut) {
            $parStatut[$statut] = $base()
                ->whereHas('tache', fn ($q) => $q->where('statut', $statut))
                ->count();
        }

        $parStatut['indisponible'] = $base()->whereNull('task_id')->count();

        $parPriorite = $base()
            ->selectRaw('priorite, count(*) as total')
            ->groupBy('priorite')
            ->pluck('total', 'priorite')
            ->all();

        return [
            'total' => $base()->count(),
            'par_statut' => $parStatut,
            'par_priorite' => $parPriorite,
            'en_retard' => $base()
                ->where('echeance_sla', '<', now())
                ->whereHas('tache', fn ($q) => $q->whereIn('statut', TicketIt::STATUTS_ACTIFS))
                ->count(),
            'escalades' => $base()->whereNotNull('escalade_le')->count(),
        ];
    }

    /**
     * @return Collection<int, CategorieIt>
     */
    public function categories(): Collection
    {
        return CategorieIt::where('actif', true)->orderBy('libelle')->get();
    }

    /**
     * @param  array{code: string, libelle: string, description?: ?string, sla_jours_p1: int, sla_jours_p2: int, sla_jours_p3: int, priorite_defaut: string}  $donnees
     */
    public function creerCategorie(array $donnees): CategorieIt
    {
        if (CategorieIt::where('code', $donnees['code'])->exists()) {
            throw new DomainException('Ce code de catégorie existe déjà.');
        }

        $this->validerCategorie($donnees);

        return CategorieIt::create([
            'code' => $donnees['code'],
            'libelle' => $donnees['libelle'],
            'description' => $donnees['description'] ?? null,
            'sla_jours_p1' => (int) $donnees['sla_jours_p1'],
            'sla_jours_p2' => (int) $donnees['sla_jours_p2'],
            'sla_jours_p3' => (int) $donnees['sla_jours_p3'],
            'priorite_defaut' => $donnees['priorite_defaut'],
            'actif' => true,
            'origine' => 'saisi',
        ]);
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function modifierCategorie(CategorieIt $categorie, array $donnees): CategorieIt
    {
        $fusion = [
            'sla_jours_p1' => $donnees['sla_jours_p1'] ?? $categorie->sla_jours_p1,
            'sla_jours_p2' => $donnees['sla_jours_p2'] ?? $categorie->sla_jours_p2,
            'sla_jours_p3' => $donnees['sla_jours_p3'] ?? $categorie->sla_jours_p3,
            'priorite_defaut' => $donnees['priorite_defaut'] ?? $categorie->priorite_defaut,
        ];

        $this->validerCategorie($fusion);

        $categorie->update($fusion + array_intersect_key($donnees, array_flip([
            'libelle', 'description', 'actif',
        ])));

        return $categorie->fresh();
    }

    public function peutAcceder(TicketIt $ticket, User $acteur): bool
    {
        $faculteId = $acteur->faculteRestreinteId();

        return $faculteId === null || (int) $ticket->faculte_id === $faculteId;
    }

    private function calculerEcheance(CategorieIt $categorie, string $priorite): Carbon
    {
        $date = $this->joursOuvrables->ajouterJoursOuvres(
            now()->format('Y-m-d'),
            $categorie->slaPour($priorite),
        );

        return Carbon::parse($date)->endOfDay();
    }

    private function prioriteTache(string $priorite): string
    {
        return match ($priorite) {
            'P1' => 'critical',
            'P2' => 'high',
            default => 'normal',
        };
    }

    /**
     * Un acteur restreint ne peut créer que pour SA faculté. Un acteur
     * global peut cibler une faculté existante (ou aucune = siège).
     */
    private function resoudreFaculte(User $demandeur, ?int $faculteId): ?int
    {
        $restreinte = $demandeur->faculteRestreinteId();

        if ($restreinte !== null) {
            if ($faculteId !== null && (int) $faculteId !== $restreinte) {
                throw new AuthorizationException('Création hors du périmètre facultaire.');
            }

            return $restreinte;
        }

        if ($faculteId !== null && ! Faculte::whereKey($faculteId)->exists()) {
            throw new DomainException('Faculté inconnue.');
        }

        return $faculteId;
    }

    private function garantirTasks(): void
    {
        if (! $this->tasksActif()) {
            throw new DomainException(
                'Le centre de services IT requiert le module Tâches (MODULE_TASKS=1). '
                .'Aucun ticket n’est créé sans pipeline de suivi.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function validerCategorie(array $donnees): void
    {
        foreach (['sla_jours_p1', 'sla_jours_p2', 'sla_jours_p3'] as $cle) {
            $valeur = (int) ($donnees[$cle] ?? 0);

            if ($valeur < 1 || $valeur > 30) {
                throw new DomainException('Les cibles de service doivent être comprises entre 1 et 30 jours ouvrés.');
            }
        }

        if (! in_array($donnees['priorite_defaut'] ?? null, CategorieIt::PRIORITES, true)) {
            throw new DomainException('Priorité par défaut invalide.');
        }
    }
}
