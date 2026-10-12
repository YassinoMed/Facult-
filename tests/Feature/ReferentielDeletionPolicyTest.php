<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Politique de suppression du référentiel (LOOP §18/§24) : entité
 * référencée → refus (jamais de 500, jamais de suppression physique
 * de l'historique) ; entité vierge → suppression possible.
 */
class ReferentielDeletionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.suppression@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerProgramme(string $code): Programme
    {
        $domaine = Domaine::create(['faculte_id' => $this->faculte->id, 'code' => 'D-'.$code, 'nom' => 'D', 'actif' => true]);
        $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => 'M-'.$code, 'nom' => 'M', 'actif' => true]);
        $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => 'S-'.$code, 'nom' => 'S', 'actif' => true]);

        return Programme::create([
            'faculte_id' => $this->faculte->id, 'specialite_id' => $specialite->id,
            'code' => $code, 'nom' => 'Programme '.$code, 'statut' => 'actif', 'actif' => true,
        ]);
    }

    public function test_programme_avec_niveaux_non_supprimable(): void
    {
        $programme = $this->creerProgramme('P-SUP');
        Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);

        $this->actingAs($this->admin)
            ->delete(route('admin.referentiel.entites.destroy', ['programmes', $programme->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('programmes', ['id' => $programme->id]);
    }

    public function test_programme_vierge_supprimable(): void
    {
        $programme = $this->creerProgramme('P-VIERGE');

        $this->actingAs($this->admin)
            ->delete(route('admin.referentiel.entites.destroy', ['programmes', $programme->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('programmes', ['id' => $programme->id]);
    }

    public function test_ue_avec_ecue_non_supprimable(): void
    {
        $programme = $this->creerProgramme('P-UE');
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-SUP', 'nom' => 'UE', 'credits' => 30, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'SUP-01', 'nom' => 'Rattachée', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2, 'volume_cm' => 10,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.referentiel.entites.destroy', ['ues', $ue->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('unites_enseignement', ['id' => $ue->id]);
    }

    public function test_matiere_avec_epreuve_non_supprimable(): void
    {
        $matiere = Matiere::create([
            'code' => 'SUP-EP', 'nom' => 'Avec épreuve',
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2, 'volume_cm' => 10,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id,
            'code' => 'SES-SUP', 'nom' => 'Session', 'type' => 'principale', 'statut' => 'open',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-20',
        ]);
        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $this->creerProgramme('P-EP')->id,
            'libelle' => 'Ex', 'type' => 'examen', 'statut' => 'scheduled',
        ]);
        Epreuve::create([
            'annee_universitaire_id' => $annee->id,
            'session_id' => $session->id,
            'examen_id' => $examen->id,
            'matiere_id' => $matiere->id,
            'code' => 'EPR-SUP', 'date' => '2027-01-12',
            'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00', 'duree_minutes' => 120,
            'type_evaluation' => 'examen', 'coefficient' => 2, 'bareme' => 20, 'note_max' => 20,
            'statut' => 'scheduled',
        ]);

        $reponse = $this->actingAs($this->admin)
            ->delete(route('admin.archives.matieres.destroy', $matiere));

        $reponse->assertRedirect();
        $this->assertDatabaseHas('matieres', ['id' => $matiere->id]);
    }
}
