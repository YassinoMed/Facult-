<?php

namespace Database\Seeders;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Fonction;
use App\Models\GradeEnseignant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Seeder;

/**
 * Population de démonstration (§57) : idempotent (firstOrCreate),
 * rattachée au référentiel LMD (chaîne DEMO-*).
 */
class PopulationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'PR', 'nom' => 'Professeur', 'ordre' => 1],
            ['code' => 'MCF', 'nom' => 'Maître de conférences', 'ordre' => 2],
            ['code' => 'MA', 'nom' => 'Maître-assistant', 'ordre' => 3],
            ['code' => 'ASS', 'nom' => 'Assistant', 'ordre' => 4],
            ['code' => 'VAC', 'nom' => 'Vacataire', 'ordre' => 5],
        ] as $grade) {
            GradeEnseignant::firstOrCreate(['code' => $grade['code']], $grade + ['actif' => true]);
        }

        foreach ([
            ['code' => 'SCO', 'nom' => 'Agent de scolarité'],
            ['code' => 'RESP', 'nom' => 'Responsable pédagogique'],
            ['code' => 'CHEF', 'nom' => 'Chef de département'],
            ['code' => 'SEC', 'nom' => 'Secrétariat'],
        ] as $fonction) {
            Fonction::firstOrCreate(['code' => $fonction['code']], $fonction + ['actif' => true]);
        }

        $faculte = Faculte::where('code', 'FST')->first() ?? Faculte::firstOrFail();
        $programme = Programme::where('code', 'DEMO-LGL')->first();
        $niveau = $programme ? Niveau::where('programme_id', $programme->id)->where('code', 'L2')->first() : null;
        $annee = AnneeUniversitaire::where('libelle', '2026-2027')->first();

        if ($programme && $niveau && $annee) {
            $groupe = Group::firstOrCreate(
                ['code' => 'DEMO-GL2-A'],
                ['nom' => 'GL2-A (démo)', 'faculte_id' => $faculte->id,
                    'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
                    'annee_universitaire_id' => $annee->id,
                    'niveau' => 'L2', 'filiere' => 'Génie Logiciel', 'capacite' => 30, 'actif' => true]
            );

            Teacher::firstOrCreate(
                ['email' => 'demo.enseignant@universite.test'],
                ['nom' => 'Démo', 'prenom' => 'Enseignant', 'matricule' => 'ENS-DEMO-001',
                    'grade_id' => GradeEnseignant::where('code', 'MA')->first()?->id,
                    'actif' => true, 'statut' => 'actif']
            );

            $etudiant = Etudiant::where('cin', 'DEMO000001')->first();

            if ($etudiant && AffectationGroupe::where('etudiant_id', $etudiant->id)->where('statut', 'active')->doesntExist()) {
                AffectationGroupe::create([
                    'etudiant_id' => $etudiant->id, 'groupe_id' => $groupe->id,
                    'annee_universitaire_id' => $annee->id,
                    'date_debut' => now()->toDateString(), 'motif' => 'seed démo',
                ]);
                $etudiant->update(['groupe_id' => $groupe->id, 'programme_id' => $programme->id, 'niveau_id' => $niveau->id]);
            }

            if ($etudiant) {
                InscriptionAcademique::firstOrCreate(
                    ['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id],
                    ['programme_id' => $programme->id, 'niveau_id' => $niveau->id,
                        'niveau' => 'L2', 'statut' => 'en_cours', 'type' => 'reinscription', 'regime' => 'initiale']
                );
            }
        }
    }
}
