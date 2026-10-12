<?php

namespace App\Modules\Schedule\Import;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleConflictService;

/**
 * Validation métier (§12-16) : entités existantes (code d'abord, nom
 * ensuite — sans créer de doublons), doublons intra-fichier, conflits
 * groupe/enseignant/salle intra-fichier ET contre la base (hors
 * planning remplacé). Ne touche jamais la base.
 */
class ScheduleImportValidator
{
    public function __construct(
        private readonly ScheduleConflictService $conflits,
    ) {}

    /**
     * @param  array{entete: array<string, mixed>, lignes: array<int, array<string, mixed>>}  $donnees
     * @param  array{annee_universitaire_id?: ?int, semestre?: ?string, groupe_id?: ?int}  $attendus
     */
    public function valider(array $donnees, array $attendus = [], ?int $planningRemplaceId = null): ImportResult
    {
        $erreurs = [];
        $avertissements = [];
        $valides = [];
        $vus = [];

        $entete = $donnees['entete'];

        $annee = AnneeUniversitaire::where('libelle', $entete['academic_year'])->first();
        if ($annee === null) {
            $erreurs[] = $this->erreur('structure', 'academic_year', "Année « {$entete['academic_year']} » introuvable.");
        }

        if (! in_array($entete['semester'], ['1', '2'], true)) {
            $erreurs[] = $this->erreur('structure', 'semester', 'Semestre invalide (S1/S2 ou 1/2).');
        }

        $groupe = Group::where('code', $entete['group'])->first();
        if ($groupe === null) {
            $erreurs[] = $this->erreur('structure', 'group', "Groupe « {$entete['group']} » introuvable (le groupe doit exister).");
        }

        // Cohérence avec les sélecteurs du formulaire (§10) s'ils sont renseignés.
        if ($annee !== null && ($attendus['annee_universitaire_id'] ?? null) !== null
            && (int) $attendus['annee_universitaire_id'] !== $annee->id) {
            $erreurs[] = $this->erreur('structure', 'academic_year', 'Année du fichier différente de la sélection.');
        }
        if (($attendus['semestre'] ?? null) !== null && (string) $attendus['semestre'] !== (string) $entete['semester']) {
            $erreurs[] = $this->erreur('structure', 'semester', 'Semestre du fichier différent de la sélection.');
        }
        if ($groupe !== null && ($attendus['groupe_id'] ?? null) !== null
            && (int) $attendus['groupe_id'] !== $groupe->id) {
            $erreurs[] = $this->erreur('structure', 'group', 'Groupe du fichier différent de la sélection.');
        }

        foreach (['starts_at', 'ends_at'] as $cle) {
            if (($entete[$cle] ?? null) !== null && ! $this->dateValide($entete[$cle])) {
                $erreurs[] = $this->erreur('structure', $cle, "Date « {$entete[$cle]} » invalide (AAAA-MM-JJ).");
            }
        }
        if (($entete['starts_at'] ?? null) !== null && ($entete['ends_at'] ?? null) !== null
            && $entete['ends_at'] < $entete['starts_at']) {
            $erreurs[] = $this->erreur('structure', 'ends_at', 'Fin antérieure au début de période.');
        }

        foreach ($donnees['lignes'] as $ligne) {
            $this->validerLigne($ligne, $vus, $erreurs, $avertissements, $valides);
        }

        // Conflits intra-fichier (pairwise sur les lignes valides).
        $this->conflitsIntraFichier($valides, $erreurs);

        // Conflits contre la base (hors planning remplacé).
        if ($groupe !== null) {
            $this->conflitsBase($valides, $groupe->id, (int) $entete['semester'], $planningRemplaceId, $erreurs);
            $this->conflitsCapaciteImport($valides, $groupe->etudiants()->count(), $erreurs);
        }

        // Entités distinctes reconnues (§16 : résumé de validation).
        $resolues = [
            'matieres' => collect($valides)->unique('matiere_id')->count(),
            'enseignants' => collect($valides)->whereNotNull('teacher_id')->unique('teacher_id')->count(),
            'salles' => collect($valides)->whereNotNull('room_id')->unique('room_id')->count(),
        ];

        $existant = null;
        if ($groupe !== null && $annee !== null && in_array($entete['semester'], ['1', '2'], true)) {
            $planning = Schedule::where('groupe_id', $groupe->id)
                ->where('annee_universitaire_id', $annee->id)
                ->where('semestre', (int) $entete['semester'])
                ->withCount('slots')
                ->first();

            if ($planning !== null) {
                $existant = [
                    'id' => $planning->id,
                    'nom' => $planning->nom,
                    'creneaux' => $planning->slots_count,
                ];
                $avertissements[] = "Un emploi du temps existe déjà ({$planning->nom}, {$planning->slots_count} créneaux) : le remplacement exige une confirmation explicite.";
            }
        }

        return new ImportResult($valides, $erreurs, $avertissements, array_slice($valides, 0, 10), $existant, $resolues);
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @param  array<int, string>  $vus
     * @param  array<int, array{ligne: int|string, champ: string, message: string}>  $erreurs
     * @param  array<int, string>  $avertissements
     * @param  array<int, array<string, mixed>>  $valides
     */
    private function validerLigne(array $ligne, array &$vus, array &$erreurs, array &$avertissements, array &$valides): void
    {
        $no = $ligne['ligne'];
        $ko = function (string $champ, string $message) use ($no, &$erreurs): void {
            $erreurs[] = $this->erreur($no, $champ, $message);
        };

        if (! in_array($ligne['day'], ScheduleSlot::JOURS, true)) {
            $ko('day', "Jour « {$ligne['day']} » invalide (".ScheduleNormalizer::joursValides().').');

            return;
        }

        if (! $this->heureValide($ligne['start_time']) || ! $this->heureValide($ligne['end_time'])) {
            $ko('horaire', "Horaire « {$ligne['start_time']}–{$ligne['end_time']} » invalide (HH:MM).");

            return;
        }

        if ($ligne['start_time'] >= $ligne['end_time']) {
            $ko('horaire', "Fin antérieure au début ({$ligne['start_time']}–{$ligne['end_time']}).");

            return;
        }

        $matiere = Matiere::where('code', $ligne['course'])->first()
            ?? Matiere::where('nom', $ligne['course'])->first();
        if ($matiere === null) {
            $ko('course', "Matière « {$ligne['course']} » introuvable (code ou nom).");

            return;
        }

        $enseignant = $this->resoudreEnseignant($ligne['teacher']);
        if (($ligne['teacher'] ?? null) !== null && $enseignant === null) {
            $ko('teacher', "Enseignant « {$ligne['teacher']} » introuvable (email ou « Nom Prénom »).");

            return;
        }

        $salle = $this->resoudreSalle($ligne['room']);
        if (($ligne['room'] ?? null) !== null && $salle === null) {
            $ko('room', "Salle « {$ligne['room']} » introuvable (code).");

            return;
        }

        if (! in_array($ligne['type'], ScheduleSlot::TYPES, true)) {
            $ko('type', "Type « {$ligne['type']} » invalide.");

            return;
        }

        // Doublon intra-fichier (§15).
        $cle = implode('|', [$ligne['day'], $ligne['start_time'], $ligne['end_time'], $matiere->id]);
        if (isset($vus[$cle])) {
            $ko('doublon', "Doublon détecté ligne {$no} (déjà présent ligne {$vus[$cle]}).");

            return;
        }
        $vus[$cle] = $no;

        $valides[] = array_merge($ligne, [
            'matiere_id' => $matiere->id,
            'teacher_id' => $enseignant?->id,
            'room_id' => $salle?->id,
        ]);
    }

    private function resoudreEnseignant(?string $valeur): ?Teacher
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }
        $valeur = trim($valeur);

        if (str_contains($valeur, '@')) {
            return Teacher::where('email', $valeur)->first();
        }

        // « Nom Prénom » : comparaison insensible sur la concaténation.
        return Teacher::get()->first(
            fn (Teacher $t) => mb_strtolower(trim($t->nom.' '.$t->prenom)) === mb_strtolower($valeur)
                || mb_strtolower(trim($t->prenom.' '.$t->nom)) === mb_strtolower($valeur)
        );
    }

    private function resoudreSalle(?string $valeur): ?Room
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        return Room::where('code', trim($valeur))->first()
            ?? Room::where('nom', trim($valeur))->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $valides
     * @param  array<int, array{ligne: int|string, champ: string, message: string}>  $erreurs
     */
    private function conflitsIntraFichier(array $valides, array &$erreurs): void
    {
        foreach ($valides as $i => $a) {
            foreach ($valides as $j => $b) {
                if ($j <= $i || $a['day'] !== $b['day']) {
                    continue;
                }
                if (! $this->chevauche($a['start_time'], $a['end_time'], $b['start_time'], $b['end_time'])) {
                    continue;
                }
                if (($a['matiere_id'] ?? null) === null) {
                    continue;
                }
                // Groupe : toujours (même planning).
                $erreurs[] = $this->erreur(
                    $b['ligne'], 'conflit',
                    "CONFLIT groupe ligne {$b['ligne']} : chevauche la ligne {$a['ligne']} ({$a['start_time']}–{$a['end_time']})."
                );
                if (($a['teacher_id'] ?? null) !== null && $a['teacher_id'] === ($b['teacher_id'] ?? null)) {
                    $erreurs[] = $this->erreur(
                        $b['ligne'], 'conflit',
                        "CONFLIT enseignant ligne {$b['ligne']} : déjà occupé ligne {$a['ligne']}."
                    );
                }
                if (($a['room_id'] ?? null) !== null && $a['room_id'] === ($b['room_id'] ?? null)) {
                    $erreurs[] = $this->erreur(
                        $b['ligne'], 'conflit',
                        "CONFLIT salle ligne {$b['ligne']} : déjà occupée ligne {$a['ligne']}."
                    );
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $valides
     * @param  array<int, array{ligne: int|string, champ: string, message: string}>  $erreurs
     */
    private function conflitsBase(array $valides, int $groupeId, int $semestre, ?int $planningRemplaceId, array &$erreurs): void
    {
        $plannings = Schedule::where('groupe_id', $groupeId)
            ->when($planningRemplaceId !== null, fn ($q) => $q->where('id', '!=', $planningRemplaceId))
            ->with(['slots' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->get();

        foreach ($valides as $ligne) {
            foreach ($plannings as $planning) {
                foreach ($planning->slots as $slot) {
                    if ($slot->day_of_week !== $ligne['day']
                        || ! $this->chevauche($slot->start_time, $slot->end_time, $ligne['start_time'], $ligne['end_time'])) {
                        continue;
                    }
                    $erreurs[] = $this->erreur(
                        $ligne['ligne'], 'conflit',
                        "CONFLIT : « {$ligne['course']} » chevauche « {$slot->matiere->nom} » déjà planifié ({$planning->nom})."
                    );
                }
            }
        }

        // Enseignant/salle contre TOUS les plannings (hors remplacé).
        $slotsExternes = ScheduleSlot::where('status', '!=', 'cancelled')
            ->when($planningRemplaceId !== null, function ($q) use ($planningRemplaceId) {
                $q->whereNotIn('schedule_id', [$planningRemplaceId]);
            })
            ->with(['matiere', 'schedule.groupe'])
            ->get();

        foreach ($valides as $ligne) {
            foreach ($slotsExternes as $slot) {
                if ($slot->day_of_week !== $ligne['day']
                    || ! $this->chevauche($slot->start_time, $slot->end_time, $ligne['start_time'], $ligne['end_time'])) {
                    continue;
                }
                if (($ligne['teacher_id'] ?? null) !== null && $slot->teacher_id === $ligne['teacher_id']) {
                    $erreurs[] = $this->erreur($ligne['ligne'], 'conflit', "CONFLIT enseignant déjà occupé ({$slot->schedule->groupe->code}).");
                }
                if (($ligne['room_id'] ?? null) !== null && $slot->room_id === $ligne['room_id']) {
                    $erreurs[] = $this->erreur($ligne['ligne'], 'conflit', "CONFLIT salle déjà occupée ({$slot->schedule->groupe->code}).");
                }
            }
        }
    }

    private function chevauche(string $d1, string $f1, string $d2, string $f2): bool
    {
        return $d1 < $f2 && $d2 < $f1;
    }

    /**
     * Capacité (§12) : effectif du groupe vs capacité des salles.
     *
     * @param  array<int, array<string, mixed>>  $valides
     * @param  array<int, array{ligne: int|string, champ: string, message: string}>  $erreurs
     */
    private function conflitsCapaciteImport(array $valides, int $effectif, array &$erreurs): void
    {
        $salles = [];
        foreach ($valides as $ligne) {
            if (($ligne['room_id'] ?? null) === null) {
                continue;
            }
            $salles[$ligne['room_id']] ??= Room::find($ligne['room_id']);

            $salle = $salles[$ligne['room_id']];
            if ($salle !== null && $salle->capacite !== null && $effectif > (int) $salle->capacite) {
                $erreurs[] = $this->erreur(
                    $ligne['ligne'], 'capacite',
                    "Capacité dépassée ligne {$ligne['ligne']} : {$effectif} étudiants pour {$salle->capacite} places (salle {$salle->code})."
                );
            }
        }
    }

    private function heureValide(string $heure): bool
    {
        return preg_match('/^\d{2}:\d{2}$/', $heure) === 1;
    }

    private function dateValide(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false;
    }

    /**
     * @return array{ligne: int|string, champ: string, message: string}
     */
    private function erreur(int|string $ligne, string $champ, string $message): array
    {
        return ['ligne' => $ligne, 'champ' => $champ, 'message' => $message];
    }
}
