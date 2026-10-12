<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Seeder;

/**
 * Enseignement démo (§57) : idempotent, cohérent avec Phases 1-2.
 */
class EnseignementSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'CM', 'nom' => 'Cours magistral'],
            ['code' => 'TD', 'nom' => 'Travaux dirigés'],
            ['code' => 'TP', 'nom' => 'Travaux pratiques'],
            ['code' => 'PROJET', 'nom' => 'Projet'],
            ['code' => 'SEMINAIRE', 'nom' => 'Séminaire'],
            ['code' => 'STAGE', 'nom' => 'Stage'],
        ] as $type) {
            TypeEnseignement::firstOrCreate(['code' => $type['code']], $type + ['actif' => true]);
        }

        $programme = Programme::where('code', 'DEMO-LGL')->first();
        $annee = AnneeUniversitaire::where('libelle', '2026-2027')->first();
        $groupe = Group::where('code', 'DEMO-GL2-A')->first();
        $teacher = Teacher::where('email', 'demo.enseignant@universite.test')->first();
        $matiere = Matiere::where('code', 'DEMO-ALG')->first();

        if ($programme && $annee && $groupe && $teacher && $matiere && $matiere->ue) {
            $ens = Enseignement::firstOrCreate(
                ['matiere_id' => $matiere->id, 'type_id' => TypeEnseignement::where('code', 'TD')->first()?->id, 'annee_universitaire_id' => $annee->id],
                [
                    'semestre_id' => $matiere->ue->semestre_id,
                    'programme_id' => $programme->id,
                    'volume_td' => 12, 'statut' => 'active', 'cree_par' => null,
                ]
            );

            $ens->groupes()->syncWithoutDetaching([$groupe->id]);
            $ens->affectations()->firstOrCreate(
                ['teacher_id' => $teacher->id, 'role' => 'enseignant'],
                ['volume_heures' => 12, 'statut' => 'active']
            );
        }

        if (! $programme || ! $annee || ! $groupe || ! $teacher || ! $matiere) {
            return;
        }

        $td = Enseignement::firstOrCreate(
            ['matiere_id' => $matiere->id, 'type_id' => TypeEnseignement::where('code', 'CM')->first()->id, 'annee_universitaire_id' => $annee->id],
            [
                'programme_id' => $programme->id,
                'semestre_id' => $matiere->ue?->semestre_id,
                'volume_cm' => 18, 'statut' => 'planned',
            ]
        );

        $groupe->enseignementLiens()->firstOrCreate([
            'enseignement_id' => $td->id, 'groupe_id' => $groupe->id, 'sous_groupe_id' => null,
        ]);

        if ($td->affectations()->where('teacher_id', $teacher->id)->where('statut', 'active')->doesntExist()) {
            $td->affectations()->create([
                'teacher_id' => $teacher->id, 'role' => 'responsable', 'volume_heures' => 12,
            ]);
        }
    }
}
