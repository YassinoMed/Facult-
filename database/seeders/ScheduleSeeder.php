<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Support\DemoData;
use Illuminate\Database\Seeder;

/**
 * Module Schedule : données réalistes de démonstration (local/testing
 * uniquement) — enseignants, salles, groupes, plannings avec cas
 * normal/annulé/modifié/exception. Idempotent (firstOrCreate).
 */
class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        if (! DemoData::autorisee()) {
            $this->command?->warn('ScheduleSeeder ignoré (SEED_DEMO_DATA non activé).');

            return;
        }

        $fst = Faculte::where('code', 'FST')->firstOrFail();
        $annee = AnneeUniversitaire::where('active', true)->first()
            ?? AnneeUniversitaire::orderByDesc('id')->first();

        if ($annee === null) {
            $this->command?->warn('ScheduleSeeder : aucune année universitaire, abandon.');

            return;
        }

        foreach ([
            ['nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => 'a.bensalah@universite.test'],
            ['nom' => 'Trabelsi', 'prenom' => 'Leila', 'email' => 'l.trabelsi@universite.test'],
            ['nom' => 'Haddad', 'prenom' => 'Karim', 'email' => 'k.haddad@universite.test'],
            ['nom' => 'Mansour', 'prenom' => 'Nadia', 'email' => null],
            ['nom' => 'Guesmi', 'prenom' => 'Sami', 'email' => null],
        ] as $enseignant) {
            Teacher::firstOrCreate(['nom' => $enseignant['nom'], 'prenom' => $enseignant['prenom']], $enseignant + ['actif' => true]);
        }

        foreach ([
            ['code' => 'A01', 'capacite' => 60],
            ['code' => 'A12', 'capacite' => 40],
            ['code' => 'B04', 'capacite' => 35],
            ['code' => 'B12', 'capacite' => 50],
            ['code' => 'C02', 'capacite' => 25],
        ] as $salle) {
            Room::firstOrCreate(['code' => $salle['code']], $salle + ['actif' => true]);
        }

        $groupes = [];
        foreach ([
            ['code' => 'L1-INFO-A', 'nom' => 'L1 Informatique — Groupe A', 'niveau' => 'L1', 'filiere' => 'Informatique'],
            ['code' => 'L1-INFO-B', 'nom' => 'L1 Informatique — Groupe B', 'niveau' => 'L1', 'filiere' => 'Informatique'],
            ['code' => 'L2-MIAGE', 'nom' => 'L2 MIAGE', 'niveau' => 'L2', 'filiere' => 'MIAGE'],
        ] as $groupe) {
            $groupes[] = Group::firstOrCreate(['code' => $groupe['code']], $groupe + ['faculte_id' => $fst->id, 'actif' => true]);
        }

        // Rattachement de quelques étudiants existants (même faculté/niveau).
        foreach (Etudiant::where('faculte_id', $fst->id)->where('niveau', 'L1')->limit(4)->get() as $index => $etudiant) {
            $etudiant->forceFill(['groupe_id' => $groupes[$index % 2]->id])->save();
        }

        $matieres = Matiere::where('niveau', 'L1')->orderBy('code')->limit(5)->get();

        if ($matieres->isEmpty()) {
            $this->command?->warn('ScheduleSeeder : aucune matière L1, abandon.');

            return;
        }

        $enseignants = Teacher::orderBy('id')->get();
        $salles = Room::orderBy('id')->get();

        $planning = Schedule::firstOrCreate(
            ['groupe_id' => $groupes[0]->id, 'annee_universitaire_id' => $annee->id, 'semestre' => 1],
            ['nom' => 'L1 Informatique A — S1', 'actif' => true, 'starts_at' => '2026-09-15', 'ends_at' => '2027-01-31']
        );

        $creneaux = [
            ['jour' => 'monday', 'debut' => '08:00', 'fin' => '09:30', 'type' => 'course', 'statut' => 'scheduled'],
            ['jour' => 'monday', 'debut' => '10:00', 'fin' => '11:30', 'type' => 'td', 'statut' => 'scheduled'],
            ['jour' => 'tuesday', 'debut' => '10:00', 'fin' => '11:30', 'type' => 'course', 'statut' => 'modified'],
            ['jour' => 'wednesday', 'debut' => '14:00', 'fin' => '15:30', 'type' => 'tp', 'statut' => 'cancelled'],
            ['jour' => 'thursday', 'debut' => '09:00', 'fin' => '10:30', 'type' => 'course', 'statut' => 'scheduled'],
        ];

        foreach ($creneaux as $index => $creneau) {
            ScheduleSlot::firstOrCreate(
                [
                    'schedule_id' => $planning->id,
                    'day_of_week' => $creneau['jour'],
                    'start_time' => $creneau['debut'].':00',
                ],
                [
                    'matiere_id' => $matieres[$index % $matieres->count()]->id,
                    'teacher_id' => $enseignants[$index % $enseignants->count()]->id,
                    'room_id' => $salles[$index % $salles->count()]->id,
                    'end_time' => $creneau['fin'].':00',
                    'type' => $creneau['type'],
                    'status' => $creneau['statut'],
                ]
            );
        }

        // Exception ponctuelle sur le premier créneau du mardi.
        $mardi = ScheduleSlot::where('schedule_id', $planning->id)->where('day_of_week', 'tuesday')->first();

        if ($mardi !== null) {
            $mardi->exceptions()->firstOrCreate(
                ['exception_date' => now()->next('tuesday')->toDateString()],
                [
                    'new_start_time' => '11:00:00',
                    'new_end_time' => '12:30:00',
                    'new_room_id' => $salles->last()->id,
                    'status' => 'modified',
                    'reason' => 'Maintenance de la salle.',
                ]
            );
        }
    }
}
