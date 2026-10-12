<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\ParcoursEtudiantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parcours académique en lecture seule (LOOP 13) : programme et niveau
 * réels, version applicable démontrée (active programme×année, jamais
 * la courante par défaut), 2087 sans programme jamais devinés,
 * session-scopé côté portail.
 */
class ParcoursEtudiantTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private SemestreLmd $semestre;

    private UniteEnseignement $ue;

    private Matiere $ecue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.parc@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'PARC', 'nom' => 'Programme parcours',
            'statut' => 'actif', 'actif' => true,
        ]);
        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1',
            'ordre' => 1, 'actif' => true,
        ]);
        $this->semestre = SemestreLmd::create([
            'niveau_id' => $this->niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $this->semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE-PARC', 'nom' => 'UE parcours', 'credits' => 6, 'actif' => true,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'EC-PARC', 'nom' => 'ECUE parcours', 'credits' => 6, 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Parc', 'prenom' => 'Our',
            'email' => strtolower($cin).'@parc.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    private function inscrire(Etudiant $etudiant, ?int $programmeId = null): InscriptionAcademique
    {
        return $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programmeId,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);
    }

    private function activerMaquette(?int $anneeId = null): Maquette
    {
        $maquette = Maquette::create([
            'programme_id' => $this->programme->id,
            'annee_universitaire_id' => $anneeId ?? $this->annee->id,
            'version' => 1, 'statut' => 'active',
        ]);
        $ligne = MaquetteUe::create([
            'maquette_id' => $maquette->id, 'ue_id' => $this->ue->id, 'semestre_id' => $this->semestre->id,
        ]);
        MaquetteUeEcue::create([
            'maquette_ue_id' => $ligne->id, 'matiere_id' => $this->ecue->id,
            'ordre' => 0, 'coefficient' => 1, 'credits' => 6,
            'volume_cm' => 20, 'volume_td' => 0, 'volume_tp' => 0,
            'regime' => 'obligatoire', 'obligatoire' => true,
        ]);

        return $maquette;
    }

    public function test_parcours_complet_plan_actif(): void
    {
        $etudiant = $this->creerEtudiant('PARC0001');
        $this->inscrire($etudiant, $this->programme->id);
        $this->activerMaquette();

        $parcours = app(ParcoursEtudiantService::class)->consulter($etudiant);

        $this->assertSame('OK', $parcours['etat']);
        $this->assertTrue($parcours['source_plan']);
        $this->assertSame('PARC', $parcours['programme']);
        $this->assertSame('L1', $parcours['niveau']);
        $this->assertSame(1, $parcours['maquette']['version']);
        $this->assertSame('EC-PARC', $parcours['semestres'][0]['ecues'][0]['code']);
    }

    public function test_sans_inscription(): void
    {
        $parcours = app(ParcoursEtudiantService::class)->consulter($this->creerEtudiant('PARC0002'));

        $this->assertSame('SANS_INSCRIPTION', $parcours['etat']);
    }

    public function test_sans_programme_jamais_devine(): void
    {
        // Cas des 2087 : inscription ET dossier sans programme.
        $etudiant = $this->creerEtudiant('PARC0003');
        $this->inscrire($etudiant, null);
        $this->activerMaquette();

        $parcours = app(ParcoursEtudiantService::class)->consulter($etudiant);

        $this->assertSame('SANS_PROGRAMME', $parcours['etat']);
        $this->assertNull($parcours['programme']);
    }

    public function test_maquette_autre_annee_non_applicable(): void
    {
        $etudiant = $this->creerEtudiant('PARC0004');
        $this->inscrire($etudiant, $this->programme->id);

        $autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $this->activerMaquette($autreAnnee->id);

        // La maquette active COURANTE (autre année) ne s'applique pas
        // implicitement : état explicite + chaîne canonique hors plan.
        $parcours = app(ParcoursEtudiantService::class)->consulter($etudiant);

        $this->assertSame('SANS_MAQUETTE_APPLICABLE', $parcours['etat']);
        $this->assertFalse($parcours['source_plan']);
        $this->assertSame('EC-PARC', $parcours['semestres'][0]['ecues'][0]['code']);
    }

    public function test_admin_voit_idor_bloque(): void
    {
        $etudiant = $this->creerEtudiant('PARC0005');
        $this->inscrire($etudiant, $this->programme->id);
        $this->activerMaquette();

        $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.parcours', $etudiant))
            ->assertOk()->assertSee('PARC')->assertSee('EC-PARC');

        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $restreintB = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.parc@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteB->id,
        ], 'admin_faculte');

        $this->actingAs($restreintB)
            ->get(route('admin.etudiants.parcours', $etudiant))
            ->assertForbidden();
    }

    public function test_portail_session_scope(): void
    {
        $alice = $this->creerEtudiant('PARC0006');
        $this->inscrire($alice, $this->programme->id);
        $this->activerMaquette();

        $bob = $this->creerEtudiant('PARC0007');
        $this->inscrire($bob, null);

        // Invité : redirection.
        $this->get(route('etudiant.parcours'))->assertRedirect();

        // Bob ne voit que SON parcours (sans programme), jamais celui d'Alice.
        $this->withSession(['etudiant_auth_id' => $bob->id])
            ->get(route('etudiant.parcours'))
            ->assertOk()->assertSee('non renseigné')->assertDontSee('UE-PARC');

        $this->withSession(['etudiant_auth_id' => $alice->id])
            ->get(route('etudiant.parcours'))
            ->assertOk()->assertSee('PARC')->assertSee('EC-PARC');
    }

    public function test_consultation_necrit_rien(): void
    {
        $etudiant = $this->creerEtudiant('PARC0008');
        $this->inscrire($etudiant, $this->programme->id);
        $this->activerMaquette();

        $avant = [InscriptionAcademique::count(), Maquette::count(), Matiere::count()];

        app(ParcoursEtudiantService::class)->consulter($etudiant);
        $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.parcours', $etudiant))->assertOk();

        $this->assertSame($avant, [InscriptionAcademique::count(), Maquette::count(), Matiere::count()]);
    }

    public function test_pdf_informatif_sans_cin(): void
    {
        $etudiant = $this->creerEtudiant('PARC0009');
        $this->inscrire($etudiant, $this->programme->id);
        $this->activerMaquette();

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.parcours.pdf'));

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'application/pdf');
        $contenu = $reponse->getContent();
        $this->assertStringStartsWith('%PDF', (string) $contenu);
        $this->assertStringNotContainsString($etudiant->cin, (string) $contenu);
    }

    public function test_pdf_echec_sans_500(): void
    {
        $etudiant = $this->creerEtudiant('PARC0010');

        $panne = $this->createMock(PdfGeneratorInterface::class);
        $panne->method('generer')->willThrowException(new \RuntimeException('moteur HS'));
        app()->instance(PdfGeneratorInterface::class, $panne);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.parcours.pdf'))
            ->assertRedirect(route('etudiant.parcours'))
            ->assertSessionHasErrors('parcours');
    }
}
