<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\NotificationService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service centralisé pour la surveillance et garde des DS et Examens (§7-13).
 * Architecture unifiée pour Contrôles Continus / DS et Examens (zéro duplication).
 */
class SurveillanceService
{
    public function __construct(
        private ConflitEpreuveService $conflits,
        private ?NotificationService $notifications = null
    ) {}

    /**
     * Affecter un surveillant à une épreuve (§7).
     *
     * @param  array{teacher_id?: ?int, user_id?: ?int, role?: string, salle_id?: ?int}  $donnees
     */
    public function assigner(Epreuve $epreuve, array $donnees, ?int $userId = null): Surveillance
    {
        return DB::transaction(function () use ($epreuve, $donnees) {
            // Verrou pessimiste : deux affectations concurrentes de la
            // même épreuve sont sérialisées (MySQL réel ; no-op SQLite).
            $epreuve = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($epreuve->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée.');
            }

            if (empty($donnees['teacher_id']) && empty($donnees['user_id'])) {
                throw new DomainException('Surveillant requis : enseignant ou utilisateur.');
            }

            $nomSurveillant = 'Ce surveillant';
            if (! empty($donnees['teacher_id'])) {
                $teacher = Teacher::find((int) $donnees['teacher_id']);
                if ($teacher !== null) {
                    $nomSurveillant = $teacher->nomComplet();
                    if (! $teacher->actif) {
                        throw new DomainException("Affectation impossible : l'enseignant {$nomSurveillant} est inactif.");
                    }
                }
            }

            // Règle 2 : aucun doublon sur la même épreuve (§7)
            $dejaAffecte = $epreuve->surveillants()
                ->whereNotIn('statut', ['annule', 'remplace'])
                ->where(function ($q) use ($donnees) {
                    if (! empty($donnees['teacher_id'])) {
                        $q->where('teacher_id', (int) $donnees['teacher_id']);
                    }
                    if (! empty($donnees['user_id'])) {
                        $q->orWhere('user_id', (int) $donnees['user_id']);
                    }
                })->exists();

            if ($dejaAffecte) {
                throw new DomainException('Ce surveillant est déjà affecté à cette épreuve.');
            }

            // Règle d'unicité du rôle Principal : un principal remplacé
            // (historique) ne bloque pas son remplaçant — seules les
            // gardes actives (assigne/confirme) occupent le rôle.
            $roleVoulu = $donnees['role'] ?? 'surveillant';
            if ($roleVoulu === 'principal') {
                $principalExiste = $epreuve->surveillants()
                    ->whereIn('statut', ['assigne', 'confirme'])
                    ->where('role', 'principal')
                    ->exists();

                if ($principalExiste) {
                    throw new DomainException('Un surveillant Principal est déjà affecté à cette épreuve.');
                }
            }

            // Règle 1 : aucun conflit horaire avec d'autres épreuves (§7)
            $conflits = $this->conflits->detecter([
                'date' => $epreuve->date->format('Y-m-d'),
                'heure_debut' => substr((string) $epreuve->heure_debut, 0, 5),
                'heure_fin' => substr((string) $epreuve->heure_fin, 0, 5),
                'surveillants' => [$donnees],
            ], $epreuve->id);

            $bloquants = array_values(array_filter($conflits, fn (array $c) => $c['type'] === 'surveillant' && $c['critique']));

            if ($bloquants !== []) {
                throw new DomainException("Affectation impossible pour {$nomSurveillant} : ".$bloquants[0]['message']);
            }

            // Règle 4 : vérification des créneaux d'enseignement réguliers (ScheduleSlot)
            if (! empty($donnees['teacher_id'])) {
                $jourSemaine = strtolower($epreuve->date->format('l'));
                $slotConflit = ScheduleSlot::where('teacher_id', (int) $donnees['teacher_id'])
                    ->where('day_of_week', $jourSemaine)
                    ->where('status', '!=', 'cancelled')
                    ->with('matiere')
                    ->get()
                    ->first(fn ($slot) => $slot->chevauche((string) $epreuve->heure_debut, (string) $epreuve->heure_fin));

                if ($slotConflit !== null) {
                    throw new DomainException("Affectation impossible pour {$nomSurveillant} : L'enseignant a déjà un cours régulier à ce créneau (« {$slotConflit->matiere?->nom} »).");
                }
            }

            $surveillance = Surveillance::create([
                'epreuve_id' => $epreuve->id,
                'teacher_id' => $donnees['teacher_id'] ?? null,
                'user_id' => $donnees['user_id'] ?? null,
                'role' => $donnees['role'] ?? 'surveillant',
                'salle_id' => $donnees['salle_id'] ?? $epreuve->salle_id,
                'statut' => 'assigne',
                'presence' => null,
            ]);

            IntegrationLog::tracer(
                'surveillance',
                'internal',
                'surveillance.assigned',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'SURV-'.$surveillance->id
            );

            EvaluationChanged::dispatch('surveillance.assignee', $epreuve->id);

            $this->notifierSurveillant($surveillance, 'Nouvelle affectation de garde', "Vous êtes affecté(e) à la surveillance de l'épreuve {$epreuve->code} le {$epreuve->date->format('d/m/Y')}.");

            return $surveillance;
        });
    }

    /**
     * Désaffecter / annuler une surveillance (§7).
     */
    public function desaffecter(Surveillance $surveillance, ?string $motif = null, ?int $userId = null): Surveillance
    {
        return DB::transaction(function () use ($surveillance, $motif) {
            if ($surveillance->epreuve->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée.');
            }

            $surveillance->update([
                'statut' => 'annule',
                'observations' => trim(($surveillance->observations ?? '').($motif ? " [Annulé : {$motif}]" : ' [Annulé]')),
            ]);

            IntegrationLog::tracer(
                'surveillance',
                'internal',
                'surveillance.removed',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'SURV-'.$surveillance->id
            );

            EvaluationChanged::dispatch('surveillance.annulee', $surveillance->epreuve_id);

            return $surveillance;
        });
    }

    /**
     * Remplacement d'un surveillant avec conservation de l'historique (§11).
     *
     * @param  array{teacher_id?: ?int, user_id?: ?int, role?: string, salle_id?: ?int}  $donnees
     */
    public function remplacer(Surveillance $surveillance, array $donnees, ?string $motif = null, ?int $userId = null): Surveillance
    {
        return DB::transaction(function () use ($surveillance, $donnees, $motif, $userId) {
            if ($surveillance->epreuve->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée.');
            }

            // Seule une garde active est remplaçable : pas de chaîne
            // remplace→remplace, pas de résurrection d'annulée.
            if (! in_array($surveillance->statut, ['assigne', 'confirme'], true)) {
                throw new DomainException("Remplacement interdit depuis « {$surveillance->statut} ».");
            }

            // Verrouillage de concurrence pessimiste
            $surveillanceActive = Surveillance::where('id', $surveillance->id)->lockForUpdate()->firstOrFail();

            $surveillanceActive->update([
                'statut' => 'remplace',
                'motif_absence' => $motif ?? $surveillanceActive->motif_absence,
            ]);

            // Si le remplaçant était la garde de réserve sur cette même épreuve,
            // on le promeut directement pour qu'il devienne surveillant titulaire
            // sans violer la contrainte d'unicité d'enseignant actif sur l'épreuve.
            $reserveExistante = null;
            if (! empty($donnees['teacher_id']) || ! empty($donnees['user_id'])) {
                $reserveExistante = $surveillanceActive->epreuve->surveillants()
                    ->where('role', 'reserve')
                    ->whereIn('statut', ['assigne', 'confirme'])
                    ->where(function ($q) use ($donnees) {
                        if (! empty($donnees['teacher_id'])) {
                            $q->where('teacher_id', (int) $donnees['teacher_id']);
                        }
                        if (! empty($donnees['user_id'])) {
                            $q->orWhere('user_id', (int) $donnees['user_id']);
                        }
                    })
                    ->first();
            }

            if ($reserveExistante !== null) {
                $nouveauRole = $donnees['role'] ?? $surveillanceActive->role;
                $reserveExistante->update([
                    'role' => $nouveauRole,
                    'salle_id' => $donnees['salle_id'] ?? $surveillanceActive->salle_id,
                    'remplace_surveillance_id' => $surveillanceActive->id,
                    'observations' => $motif ?? "Mobilisé depuis la réserve pour remplacer le titulaire #{$surveillanceActive->id}",
                ]);
                $remplacant = $reserveExistante;
            } else {
                $remplacant = $this->assigner($surveillanceActive->epreuve, $donnees, $userId);
                $remplacant->update([
                    'remplace_surveillance_id' => $surveillanceActive->id,
                    'observations' => $motif ?? "Remplacement de la garde #{$surveillanceActive->id}",
                ]);
            }

            IntegrationLog::tracer(
                'surveillance',
                'internal',
                'surveillance.replaced',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'SURV-'.$remplacant->id
            );

            EvaluationChanged::dispatch('surveillance.remplacee', $surveillanceActive->epreuve_id);

            $this->notifierSurveillant($remplacant, 'Remplacement de surveillance', "Vous remplacez un surveillant sur l'épreuve {$surveillanceActive->epreuve->code} le {$surveillanceActive->epreuve->date->format('d/m/Y')}.");

            return $remplacant;
        });
    }

    /**
     * Émargement et pointage d'un surveillant (§13).
     */
    public function enregistrerPresence(
        Surveillance $surveillance,
        string $presence,
        ?string $heureArrivee = null,
        ?string $heureDepart = null,
        ?string $motif = null,
        ?int $userId = null
    ): Surveillance {
        return DB::transaction(function () use ($surveillance, $presence, $heureArrivee, $heureDepart, $motif) {
            if (! in_array($presence, Surveillance::PRESENCES, true)) {
                throw new DomainException("Statut de présence invalide : {$presence}.");
            }

            // L'historique (remplacée, annulée) est immuable : seule une
            // garde active s'émarge. L'émargement tardif reste possible
            // épreuve verrouillée (constat post-hoc, comme les incidents).
            if (! in_array($surveillance->statut, ['assigne', 'confirme'], true)) {
                throw new DomainException("Émargement interdit depuis « {$surveillance->statut} ».");
            }

            // Normalisation H:i:s à l'écriture : MySQL TIME normalise,
            // SQLite conserve la chaîne telle quelle — sans ceci, la
            // lecture diverge selon le driver (prouvé par test).
            $normaliserHeure = fn (?string $h): ?string => $h === null
                ? null
                : Carbon::createFromFormat('H:i', substr($h, 0, 5))->format('H:i:s');

            $champs = [
                'presence' => $presence,
                'heure_arrivee' => $normaliserHeure($heureArrivee) ?? $surveillance->heure_arrivee,
                'heure_depart' => $normaliserHeure($heureDepart) ?? $surveillance->heure_depart,
                'motif_absence' => $motif ?? $surveillance->motif_absence,
            ];

            if (in_array($presence, ['present', 'retard'], true)) {
                $champs['statut'] = 'confirme';
            } elseif ($presence === 'absent' || $presence === 'absent_justifie') {
                // Reste assigné mais flaggé absent
            }

            $surveillance->update($champs);

            IntegrationLog::tracer(
                'surveillance',
                'internal',
                "surveillance.{$presence}",
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'SURV-'.$surveillance->id
            );

            EvaluationChanged::dispatch('surveillance.emargement', $surveillance->epreuve_id);

            return $surveillance;
        });
    }

    /**
     * Simulation pure sans écriture (§8).
     *
     * @param  array<int, int>  $enseignantIds
     * @return array{epreuve_id: int, total_propositions: int, eligibles: int, conflits: int, propositions: array<int, mixed>}
     */
    public function simuler(Epreuve $epreuve, array $enseignantIds, ?int $salleId = null): array
    {
        $chargesActuelles = Surveillance::where('statut', '!=', 'annule')
            ->whereNotNull('teacher_id')
            ->selectRaw('teacher_id, count(*) as total')
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        $enseignants = Teacher::whereIn('id', $enseignantIds)->get()->keyBy('id');

        // Préchargement 1 : surveillants déjà sur cette épreuve (lookup O(1) en mémoire)
        $surveillantsSurEpreuve = $epreuve->surveillants()
            ->where('statut', '!=', 'annule')
            ->whereNotNull('teacher_id')
            ->pluck('teacher_id')
            ->flip()
            ->all();

        // Préchargement 2 : conflits sur autres épreuves chevauchantes de la même date (lookup O(1))
        $heureDebut = substr((string) $epreuve->heure_debut, 0, 5);
        $heureFin = substr((string) $epreuve->heure_fin, 0, 5);

        $epreuvesChevauchantes = Epreuve::whereDate('date', $epreuve->date->format('Y-m-d'))
            ->where('statut', '!=', 'cancelled')
            ->where('id', '!=', $epreuve->id)
            ->with(['matiere', 'surveillants' => fn ($q) => $q->where('statut', '!=', 'annule')->whereNotNull('teacher_id')])
            ->get()
            ->filter(fn (Epreuve $e) => $heureDebut < substr((string) $e->heure_fin, 0, 5)
                && substr((string) $e->heure_debut, 0, 5) < $heureFin);

        $conflitsEpreuvesParEnseignant = [];
        foreach ($epreuvesChevauchantes as $autreEpreuve) {
            $plage = substr((string) $autreEpreuve->heure_debut, 0, 5).' → '.substr((string) $autreEpreuve->heure_fin, 0, 5);
            $matiereNom = $autreEpreuve->matiere?->nom ?? '—';
            foreach ($autreEpreuve->surveillants as $s) {
                if ($s->teacher_id && ! isset($conflitsEpreuvesParEnseignant[$s->teacher_id])) {
                    $conflitsEpreuvesParEnseignant[$s->teacher_id] = "Surveillant déjà affecté sur « {$matiereNom} » ({$plage}).";
                }
            }
        }

        // Préchargement 3 : créneaux de cours réguliers groupés par enseignant (lookup mémoire)
        $jourSemaine = strtolower($epreuve->date->format('l'));
        $slotsGroupes = ScheduleSlot::whereIn('teacher_id', $enseignantIds)
            ->where('day_of_week', $jourSemaine)
            ->where('status', '!=', 'cancelled')
            ->with('matiere')
            ->get()
            ->groupBy('teacher_id');

        $propositions = [];
        $nbEligibles = 0;
        $nbConflits = 0;

        foreach ($enseignantIds as $ensId) {
            $enseignant = $enseignants->get($ensId);
            if (! $enseignant) {
                continue;
            }

            $charge = (int) ($chargesActuelles[$ensId] ?? 0);
            $erreurs = [];
            $avertissements = [];

            if (! $enseignant->actif) {
                $erreurs[] = 'Enseignant inactif.';
            }

            // Déjà sur l'épreuve ?
            if (isset($surveillantsSurEpreuve[$ensId])) {
                $erreurs[] = 'Déjà affecté à cette épreuve.';
            }

            // Conflit sur autre épreuve
            if (isset($conflitsEpreuvesParEnseignant[$ensId])) {
                $erreurs[] = $conflitsEpreuvesParEnseignant[$ensId];
            }

            // Conflit créneau cours régulier
            $slots = $slotsGroupes->get($ensId);
            if ($slots !== null) {
                $slotConflit = $slots->first(fn ($s) => $s->chevauche((string) $epreuve->heure_debut, (string) $epreuve->heure_fin));
                if ($slotConflit !== null) {
                    $erreurs[] = "Cours programmé (« {$slotConflit->matiere?->nom} »).";
                }
            }

            $eligible = ($erreurs === []);
            if ($eligible) {
                $nbEligibles++;
            } else {
                $nbConflits++;
            }

            $propositions[] = [
                'teacher_id' => $ensId,
                'nom' => $enseignant->nomComplet(),
                'matricule' => $enseignant->matricule ?? '—',
                'charge_actuelle' => $charge,
                'charge_previsionnelle' => $eligible ? $charge + 1 : $charge,
                'salle_id' => $salleId ?? $epreuve->salle_id,
                'eligible' => $eligible,
                'erreurs' => $erreurs,
                'avertissements' => $avertissements,
            ];
        }

        // Tri équitable : charge actuelle ASC puis éligibilité
        usort($propositions, function ($a, $b) {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }

            return $a['charge_actuelle'] <=> $b['charge_actuelle'];
        });

        return [
            'epreuve_id' => $epreuve->id,
            'epreuve_code' => $epreuve->code,
            'matiere' => $epreuve->matiere->nom,
            'date' => $epreuve->date->format('Y-m-d'),
            'plage' => substr((string) $epreuve->heure_debut, 0, 5).' → '.substr((string) $epreuve->heure_fin, 0, 5),
            'total_propositions' => count($propositions),
            'eligibles' => $nbEligibles,
            'conflits' => $nbConflits,
            'propositions' => $propositions,
        ];
    }

    /**
     * Application transactionnelle d'un lot d'affectations (§9).
     *
     * @param  array<int, array{teacher_id?: ?int, user_id?: ?int, role?: string, salle_id?: ?int}>  $affectations
     * @return array{assignes: int, surveillance_ids: array<int, int>}
     */
    public function appliquer(Epreuve $epreuve, array $affectations, ?int $userId = null): array
    {
        return DB::transaction(function () use ($epreuve, $affectations, $userId) {
            // Verrouillage pessimiste de l'épreuve
            $epreuveVerrouillee = Epreuve::where('id', $epreuve->id)->lockForUpdate()->firstOrFail();

            if ($epreuveVerrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée.');
            }

            $crees = [];

            foreach ($affectations as $donnees) {
                $surveillance = $this->assigner($epreuveVerrouillee, $donnees, $userId);
                $crees[] = $surveillance->id;
            }

            return [
                'assignes' => count($crees),
                'surveillance_ids' => $crees,
            ];
        });
    }

    /**
     * Tableau de bord / Cockpit d'indicateurs de surveillance (§15).
     * Requête agrégée unique pour tous les compteurs (0 N+1).
     *
     * @return array<string, mixed>
     */
    public function obtenirCockpit(?int $faculteId = null, ?int $sessionId = null, ?string $typeEvaluation = null): array
    {
        $base = Surveillance::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId)))
            ->when($sessionId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->where('session_id', $sessionId)))
            ->when($typeEvaluation !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->where('type_evaluation', $typeEvaluation)));

        $stats = (clone $base)->selectRaw("
            COUNT(CASE WHEN statut <> 'annule' THEN 1 END) as total_gardes,
            COUNT(CASE WHEN statut = 'confirme' THEN 1 END) as gardes_confirmees,
            COUNT(CASE WHEN statut = 'assigne' THEN 1 END) as gardes_en_attente,
            COUNT(CASE WHEN presence = 'present' THEN 1 END) as surveillants_presents,
            COUNT(CASE WHEN presence = 'retard' THEN 1 END) as surveillants_retard,
            COUNT(CASE WHEN presence IN ('absent', 'absent_justifie') THEN 1 END) as surveillants_absents,
            COUNT(CASE WHEN statut = 'remplace' THEN 1 END) as remplacements
        ")->first();

        $totalGardes = (int) ($stats->total_gardes ?? 0);
        $gardesConfirmees = (int) ($stats->gardes_confirmees ?? 0);
        $gardesEnAttente = (int) ($stats->gardes_en_attente ?? 0);
        $surveillantsPresents = (int) ($stats->surveillants_presents ?? 0);
        $surveillantsRetard = (int) ($stats->surveillants_retard ?? 0);
        $surveillantsAbsents = (int) ($stats->surveillants_absents ?? 0);
        $remplacements = (int) ($stats->remplacements ?? 0);

        // Épreuves sans aucun surveillant actif (§15)
        $epreuvesSansSurveillant = Epreuve::query()
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($typeEvaluation !== null, fn ($q) => $q->where('type_evaluation', $typeEvaluation))
            ->whereDoesntHave('surveillants', fn ($s) => $s->where('statut', '!=', 'annule'))
            ->count();

        // Enseignants les plus sollicités
        $topCharges = Surveillance::where('statut', '!=', 'annule')
            ->whereNotNull('teacher_id')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId)))
            ->when($sessionId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->where('session_id', $sessionId)))
            ->selectRaw('teacher_id, count(*) as total')
            ->groupBy('teacher_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('enseignant')
            ->get();

        return [
            'total_gardes' => $totalGardes,
            'gardes_confirmees' => $gardesConfirmees,
            'gardes_en_attente' => $gardesEnAttente,
            'surveillants_presents' => $surveillantsPresents,
            'surveillants_retard' => $surveillantsRetard,
            'surveillants_absents' => $surveillantsAbsents,
            'remplacements' => $remplacements,
            'epreuves_sans_surveillant' => $epreuvesSansSurveillant,
            'top_charges' => $topCharges,
        ];
    }

    /**
     * Calcul fin de la charge par enseignant (§10).
     */
    public function calculerChargeEnseignants(?int $sessionId = null, ?int $faculteId = null): Collection
    {
        return Teacher::where('actif', true)
            ->with(['surveillances' => function ($q) use ($sessionId, $faculteId) {
                $q->where('statut', '!=', 'annule')
                    ->whereHas('epreuve', function ($e) use ($sessionId, $faculteId) {
                        $e->where('statut', '!=', 'cancelled')
                            ->when($sessionId !== null, fn ($sq) => $sq->where('session_id', $sessionId))
                            ->when($faculteId !== null, fn ($sq) => $sq->pourFaculteId($faculteId));
                    })->with('epreuve');
            }])
            ->get()
            ->map(function (Teacher $t) {
                $survs = $t->surveillances;
                $totalGardes = $survs->count();
                $gardesDs = $survs->filter(fn ($s) => in_array($s->epreuve?->type_evaluation, ['ds', 'controle_continu'], true))->count();
                $gardesExamen = $survs->filter(fn ($s) => $s->epreuve?->type_evaluation === 'examen')->count();

                $minutesTotales = $survs->sum(function ($s) {
                    return $s->epreuve?->duree_minutes ?? 120;
                });

                return [
                    'id' => $t->id,
                    'matricule' => $t->matricule,
                    'nom' => $t->nomComplet(),
                    'total_gardes' => $totalGardes,
                    'gardes_ds' => $gardesDs,
                    'gardes_examen' => $gardesExamen,
                    'heures_totales' => round($minutesTotales / 60, 1),
                ];
            })
            ->sortByDesc('total_gardes')
            ->values();
    }

    private function notifierSurveillant(Surveillance $surveillance, string $titre, string $message): void
    {
        // Injection paresseuse : le paramètre constructeur est optionnel
        // (contexte sans notifications) mais le conteneur ne le résout pas
        // systématiquement — sans cela, aucune notification ne partait.
        $this->notifications ??= app(NotificationService::class);

        if (! $this->notifications) {
            return;
        }

        try {
            $user = null;
            if ($surveillance->user_id) {
                $user = User::find($surveillance->user_id);
            } elseif ($surveillance->enseignant && $surveillance->enseignant->email) {
                $user = User::where('email', $surveillance->enseignant->email)->first();
            }

            if ($user) {
                $this->notifications->notifierUser(
                    $user,
                    'surveillance.affectation',
                    $titre,
                    $message,
                    route('admin.evaluations.surveillances.mes-gardes')
                );
            }

            // Canal enseignant (espace enseignant) : l'enseignant rattaché
            // reçoit aussi la notification sur son propre centre, avec un
            // lien vers son portail (jamais vers le back-office).
            $enseignant = $surveillance->teacher_id
                ? Teacher::find($surveillance->teacher_id)
                : null;

            if ($enseignant) {
                $this->notifications->notifierEnseignant(
                    $enseignant,
                    'surveillance.affectation',
                    $titre,
                    $message,
                    route('enseignant.surveillances.index')
                );
            }
        } catch (\Throwable) {
            // fail-soft : ne jamais bloquer l'affectation sur échec de notification
        }
    }
}
