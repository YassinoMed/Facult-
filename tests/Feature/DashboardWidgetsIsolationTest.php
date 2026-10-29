<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT 03 — isolation facultaire des widgets du dashboard admin
 * (annonces + évaluation). Le périmètre des compteurs suit
 * `faculteRestreinteId()` : un admin_faculte ne voit que le sien + les
 * données globales — jamais une autre faculté (anti-IDOR, même via un
 * widget).
 */
class DashboardWidgetsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $adminGlobal;

    private User $adminFSA;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté A Widgets',
            'code' => 'FAC-WA',
            'description' => 'Test A',
            'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté B Widgets',
            'code' => 'FAC-WB',
            'description' => 'Test B',
            'active' => true,
        ]);

        $this->adminGlobal = User::creerCompteBackOffice([
            'name' => 'Admin Global Widgets',
            'email' => 'admin.widgets@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFSA = User::creerCompteBackOffice([
            'name' => 'Admin Faculté A',
            'email' => 'admin.wa@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->semerAnnonces();
        $this->semerEpreuveB();
    }

    public function test_widget_annonces_respecte_le_perimetre_faculte(): void
    {
        $this->actingAs($this->adminGlobal);

        $renderGlobal = view('modules.announcements.widget-admin')->render();
        $this->assertStringContainsString('>3<', $renderGlobal);

        $this->actingAs($this->adminFSA);

        $renderRestreint = view('modules.announcements.widget-admin')->render();
        $this->assertStringContainsString('>2<', $renderRestreint);
        $this->assertStringNotContainsString('>3<', $renderRestreint);
    }

    public function test_widget_evaluation_isole_les_epreuves_hors_perimetre(): void
    {
        // L'unique épreuve en base appartient à la faculté B : le total
        // du widget est invisible pour la faculté A, visible en global.
        $this->actingAs($this->adminGlobal);

        $renderGlobal = view('modules.evaluation.widget-admin')->render();
        $this->assertStringContainsString('>1<', $renderGlobal);

        $this->actingAs($this->adminFSA);

        $renderRestreint = view('modules.evaluation.widget-admin')->render();
        $this->assertStringContainsString('>0<', $renderRestreint);
        $this->assertStringNotContainsString('>1<', $renderRestreint);
    }

    private function semerAnnonces(): void
    {
        Annonce::create([
            'titre' => 'Annonce globale widgets', 'contenu' => 'X.', 'type' => 'information',
            'statut' => 'published', 'audience' => 'tous',
        ]);
        Annonce::create([
            'titre' => 'Annonce A widgets', 'contenu' => 'X.', 'type' => 'information',
            'statut' => 'published', 'audience' => 'tous', 'faculte_id' => $this->faculteA->id,
        ]);
        Annonce::create([
            'titre' => 'Annonce B widgets', 'contenu' => 'X.', 'type' => 'information',
            'statut' => 'published', 'audience' => 'tous', 'faculte_id' => $this->faculteB->id,
        ]);
    }

    private function semerEpreuveB(): void
    {
        $this->compteur++;
        $programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'PRG-WB',
            'nom' => 'Licence Widgets B',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'type' => 'licence',
            'statut' => 'actif',
        ]);
        $niveauB = Niveau::create([
            'programme_id' => $programmeB->id,
            'code' => 'L1',
            'nom' => 'L1 Widgets B',
            'ordre' => 1,
        ]);
        $semestreB = SemestreLmd::create([
            'niveau_id' => $niveauB->id,
            'code' => 'S1',
            'nom' => 'S1 Widgets B',
            'numero' => 1,
            'ordre' => 1,
        ]);
        $ueB = UniteEnseignement::create([
            'semestre_id' => $semestreB->id,
            'programme_id' => $programmeB->id,
            'code' => 'UE-WB',
            'nom' => 'Algo Widgets B',
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $matiereB = Matiere::create([
            'code' => 'MAT-WB',
            'nom' => 'Algorithmique Widgets B',
            'ue_id' => $ueB->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $groupeB = Group::create([
            'code' => 'G-WB',
            'nom' => 'Groupe Widgets B',
            'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027 WB',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
        $sessionB = SessionExamen::create([
            'annee_universitaire_id' => $annee->id,
            'code' => 'S-WB-'.$this->compteur.'-'.random_int(1000, 9999),
            'nom' => 'Principale Widgets B',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->adminGlobal->id,
        ]);
        $examenB = Examen::create([
            'session_id' => $sessionB->id,
            'annee_universitaire_id' => $annee->id,
            'libelle' => 'Examen Widgets B',
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->adminGlobal->id,
        ]);

        // Salle absente : sera comptée par le widget (avec ses pairs).
        app(EpreuveService::class)->creer($examenB, [
            'session_id' => $sessionB->id,
            'matiere_id' => $matiereB->id,
            'groupe_id' => $groupeB->id,
            'date' => '2027-01-'.sprintf('%02d', 10 + $this->compteur),
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
    }
}
