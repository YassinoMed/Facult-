<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration du référentiel LMD (§34) : idempotent
 * (firstOrCreate partout), clairement DEV (codes DEMO-*).
 */
class ReferentielSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'LIC', 'nom' => 'Licence', 'ordre' => 1],
            ['code' => 'MAS', 'nom' => 'Mastère', 'ordre' => 2],
            ['code' => 'ING', 'nom' => 'Ingénieur', 'ordre' => 3],
            ['code' => 'DOC', 'nom' => 'Doctorat', 'ordre' => 4],
        ] as $type) {
            TypeDiplome::firstOrCreate(['code' => $type['code']], $type + ['actif' => true]);
        }

        $faculte = Faculte::where('code', 'FST')->first() ?? Faculte::firstOrFail();

        $departement = Departement::firstOrCreate(
            ['faculte_id' => $faculte->id, 'code' => 'DEMO-INFO'],
            ['nom' => 'Département Informatique (démo)', 'responsable' => ' UNITÉ DÉMO ', 'actif' => true]
        );

        $domaine = Domaine::firstOrCreate(
            ['faculte_id' => $faculte->id, 'code' => 'DEMO-ST'],
            ['sigle' => 'ST', 'nom' => 'Sciences et Technologies (démo)', 'actif' => true]
        );

        $mention = Mention::firstOrCreate(
            ['domaine_id' => $domaine->id, 'code' => 'DEMO-INF'],
            ['nom' => 'Informatique (démo)', 'actif' => true]
        );

        $specialite = Specialite::firstOrCreate(
            ['mention_id' => $mention->id, 'code' => 'DEMO-GL'],
            ['nom' => 'Génie Logiciel (démo)', 'actif' => true]
        );

        $programme = Programme::firstOrCreate(
            ['faculte_id' => $faculte->id, 'code' => 'DEMO-LGL'],
            [
                'departement_id' => $departement->id,
                'specialite_id' => $specialite->id,
                'type_diplome_id' => TypeDiplome::where('code', 'LIC')->first()?->id,
                'nom' => 'Licence Génie Logiciel (démo)',
                'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
                'mode' => 'initiale', 'actif' => true,
            ]
        );

        foreach ([['L1', 'Licence 1', 1], ['L2', 'Licence 2', 2], ['L3', 'Licence 3', 3]] as [$code, $nom, $ordre]) {
            $niveau = Niveau::firstOrCreate(
                ['programme_id' => $programme->id, 'code' => $code],
                ['nom' => $nom.' (démo)', 'ordre' => $ordre, 'semestres_nombre' => 2, 'credits_attendus' => 60, 'actif' => true]
            );

            foreach ([1, 2] as $k) {
                $numero = ($ordre - 1) * 2 + $k;
                $semestre = SemestreLmd::firstOrCreate(
                    ['niveau_id' => $niveau->id, 'code' => 'S'.$numero],
                    ['nom' => 'Semestre '.$numero.' (démo)', 'numero' => $numero, 'ordre' => $numero, 'credits_attendus' => 30, 'actif' => true]
                );

                if ($code === 'L1' && $k === 1) {
                    $ue = UniteEnseignement::firstOrCreate(
                        ['semestre_id' => $semestre->id, 'code' => 'DEMO-UE01'],
                        ['programme_id' => $programme->id, 'nom' => 'Programmation (démo)',
                            'credits' => 12, 'coefficient' => 3, 'type' => 'fondamentale', 'actif' => true]
                    );

                    $algo = Matiere::firstOrCreate(
                        ['code' => 'DEMO-ALG'],
                        ['nom' => 'Algorithmique (démo)', 'coefficient' => 2, 'credits' => 6,
                            'niveau' => 'L1', 'semestre' => 1, 'ue_id' => $ue->id,
                            'volume_cm' => 20, 'volume_td' => 20, 'volume_tp' => 10,
                            'type_evaluation' => 'CC+Examen', 'actif' => true]
                    );
                    $avancee = Matiere::firstOrCreate(
                        ['code' => 'DEMO-ALG2'],
                        ['nom' => 'Algorithmique avancée (démo)', 'coefficient' => 2, 'credits' => 6,
                            'niveau' => 'L2', 'semestre' => 1, 'ue_id' => $ue->id,
                            'volume_cm' => 20, 'volume_td' => 20, 'volume_tp' => 10,
                            'type_evaluation' => 'CC+Examen', 'actif' => true]
                    );
                    $avancee->prerequis()->syncWithoutDetaching([$algo->id]);
                }
            }
        }

        $annee = AnneeUniversitaire::firstOrCreate(
            ['libelle' => '2026-2027'],
            ['date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => false]
        );

        $maquette = Maquette::firstOrCreate(
            ['programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id, 'version' => 1],
            ['statut' => 'draft', 'commentaire' => 'Maquette de démonstration.']
        );

        foreach (UniteEnseignement::where('programme_id', $programme->id)->get() as $ue) {
            $maquette->unitesEnseignement()->syncWithoutDetaching([$ue->id => ['semestre_id' => $ue->semestre_id]]);
        }
    }
}
