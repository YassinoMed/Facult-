<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

class EmploiDuTempsDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    private const JOURS_MAP = [
        'lundi' => 'monday',
        'mardi' => 'tuesday',
        'mercredi' => 'wednesday',
        'jeudi' => 'thursday',
        'vendredi' => 'friday',
        'samedi' => 'saturday',
        'dimanche' => 'sunday',
    ];

    public function entite(): string
    {
        return 'emplois-du-temps';
    }

    public function libelle(): string
    {
        return 'Emplois du temps';
    }

    public function colonnes(): array
    {
        return [
            'code', 'annee_code', 'jour', 'heure_debut', 'heure_fin',
            'enseignement_code', 'groupe_code', 'enseignant_matricule', 'salle_code',
        ];
    }

    public function colonnesRequises(): array
    {
        return ['annee_code', 'jour', 'heure_debut', 'heure_fin', 'enseignement_code', 'groupe_code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'annee_code' => ['annee', 'annee_universitaire'],
            'enseignement_code' => ['enseignement', 'code_enseignement'],
            'groupe_code' => ['groupe', 'code_groupe'],
            'enseignant_matricule' => ['enseignant', 'matricule', 'enseignant_code'],
            'salle_code' => ['salle', 'code_salle', 'room_code'],
            'heure_debut' => ['debut', 'start_time'],
            'heure_fin' => ['fin', 'end_time'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50'],
            'annee_code' => ['required', 'string'],
            'jour' => ['required', 'string'],
            'heure_debut' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'heure_fin' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'enseignement_code' => ['required', 'string', 'max:50'],
            'groupe_code' => ['required', 'string', 'max:50'],
            'enseignant_matricule' => ['nullable', 'string', 'max:50'],
            'salle_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        $annee = AnneeUniversitaire::where('libelle', trim((string) $ligne['annee_code']))->first();
        if ($annee === null) {
            $erreurs[] = [
                'champ' => 'annee_code',
                'message' => "L'année universitaire « {$ligne['annee_code']} » est introuvable.",
                'code' => 'YEAR_NOT_FOUND',
            ];
        }

        $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();
        if ($ens === null) {
            $erreurs[] = [
                'champ' => 'enseignement_code',
                'message' => "L'enseignement « {$ligne['enseignement_code']} » est introuvable.",
                'code' => 'ENSEIGNEMENT_NOT_FOUND',
            ];
        }

        $grp = Group::where('code', trim((string) $ligne['groupe_code']))->first();
        if ($grp === null) {
            $erreurs[] = [
                'champ' => 'groupe_code',
                'message' => "Le groupe « {$ligne['groupe_code']} » est introuvable.",
                'code' => 'GROUP_NOT_FOUND',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        return implode('|', [
            trim((string) ($ligne['annee_code'] ?? '')),
            trim((string) ($ligne['groupe_code'] ?? '')),
            trim((string) ($ligne['jour'] ?? '')),
            trim((string) ($ligne['heure_debut'] ?? '')),
            trim((string) ($ligne['enseignement_code'] ?? '')),
        ]);
    }

    public function cleDoublon(): array
    {
        return ['annee_code', 'groupe_code', 'jour', 'heure_debut', 'enseignement_code'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $annee = AnneeUniversitaire::where('libelle', trim((string) $ligne['annee_code']))->first();
        $grp = Group::where('code', trim((string) $ligne['groupe_code']))->first();
        $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();

        if ($annee === null || $grp === null || $ens === null) {
            return null;
        }

        $jour = self::JOURS_MAP[mb_strtolower(trim((string) $ligne['jour']))] ?? mb_strtolower(trim((string) $ligne['jour']));
        $debut = substr(trim((string) $ligne['heure_debut']), 0, 5);

        return ScheduleSlot::whereHas('schedule', fn ($q) => $q->where('annee_universitaire_id', $annee->id)->where('groupe_id', $grp->id))
            ->where('matiere_id', $ens->matiere_id)
            ->where('day_of_week', $jour)
            ->where('start_time', 'like', $debut.'%')
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $annee = AnneeUniversitaire::where('libelle', trim((string) $ligne['annee_code']))->first();
        $grp = Group::where('code', trim((string) $ligne['groupe_code']))->first();
        $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();

        if ($annee === null || $grp === null || $ens === null) {
            throw new \DomainException('Année, groupe ou enseignement introuvable.');
        }

        $jour = self::JOURS_MAP[mb_strtolower(trim((string) $ligne['jour']))] ?? mb_strtolower(trim((string) $ligne['jour']));
        $debut = substr(trim((string) $ligne['heure_debut']), 0, 5);
        $fin = substr(trim((string) $ligne['heure_fin']), 0, 5);

        $teacher = ! empty($ligne['enseignant_matricule'])
            ? Teacher::where('matricule', trim((string) $ligne['enseignant_matricule']))->first()
            : null;

        $room = ! empty($ligne['salle_code'])
            ? Room::where('code', trim((string) $ligne['salle_code']))->first()
            : null;

        $schedule = Schedule::firstOrCreate([
            'annee_universitaire_id' => $annee->id,
            'groupe_id' => $grp->id,
        ], [
            'nom' => "EDT {$grp->nom} ({$annee->libelle})",
            'semestre' => 1,
            'statut' => 'draft',
            'actif' => true,
        ]);

        $slot = ScheduleSlot::updateOrCreate([
            'schedule_id' => $schedule->id,
            'day_of_week' => $jour,
            'start_time' => $debut.':00',
            'matiere_id' => $ens->matiere_id,
        ], [
            'end_time' => $fin.':00',
            'teacher_id' => $teacher?->id,
            'room_id' => $room?->id,
            'type' => 'course',
            'status' => 'scheduled',
            'notes' => ! empty($ligne['code']) ? "Code: {$ligne['code']}" : null,
        ]);

        return "créneau planifié : {$grp->code} - {$slot->day_of_week} {$slot->start_time}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void {}

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return ScheduleSlot::with(['schedule.anneeUniversitaire', 'schedule.groupe', 'matiere', 'teacher', 'room'])
            ->orderBy('day_of_week')
            ->orderBy('start_time');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('matiere', fn ($q) => $q->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'code' => 'SLOT-'.$modele->id,
            'annee_code' => $modele->schedule?->anneeUniversitaire?->libelle,
            'jour' => $modele->day_of_week,
            'heure_debut' => substr((string) $modele->start_time, 0, 5),
            'heure_fin' => substr((string) $modele->end_time, 0, 5),
            'enseignement_code' => $modele->matiere?->code,
            'groupe_code' => $modele->schedule?->groupe?->code,
            'enseignant_matricule' => $modele->teacher?->matricule,
            'salle_code' => $modele->room?->code,
        ];
    }
}
