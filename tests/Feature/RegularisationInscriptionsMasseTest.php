<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use App\Models\User;
use App\Services\InscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegularisationInscriptionsMasseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite',
            'email' => 'admin.scol@test.tn',
            'password' => 'Secret@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiant(string $cin, string $niveau = 'L1'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'num_inscription' => 'INS-'.$cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => 'etudiant.'.$cin.'@universite.tn',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => $niveau,
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);

        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    public function test_service_regularise_les_inscriptions_manquantes_avec_semestres(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->creerEtudiant('TEST'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'L1');
        }

        $service = app(InscriptionService::class);
        $resultat = $service->regulariserInscriptionsManquantes($this->annee->id, $this->faculte->id);

        $this->assertSame(5, $resultat['total_candidats']);
        $this->assertSame(5, $resultat['crees']);

        $inscriptions = InscriptionAcademique::where('annee_universitaire_id', $this->annee->id)->get();
        $this->assertCount(5, $inscriptions);

        foreach ($inscriptions as $inscr) {
            $this->assertSame('valide', $inscr->statut);
            $this->assertSame('L1', $inscr->niveau);
            $semestres = Semestre::where('inscription_academique_id', $inscr->id)->pluck('numero')->all();
            $this->assertEqualsCanonicalizing([1, 2], $semestres);
        }

        // Idempotence : un second appel ne crée aucun doublon
        $rejoue = $service->regulariserInscriptionsManquantes($this->annee->id, $this->faculte->id);
        $this->assertSame(0, $rejoue['total_candidats']);
        $this->assertSame(0, $rejoue['crees']);
        $this->assertCount(5, InscriptionAcademique::where('annee_universitaire_id', $this->annee->id)->get());
    }

    public function test_route_cockpit_regularise_et_redirige_avec_succes(): void
    {
        $this->creerEtudiant('TEST000099', 'L2');

        $response = $this->actingAs($this->admin)->post(route('admin.affectations.masse.regulariser-inscriptions'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ]);

        $response->assertRedirect(route('admin.affectations.masse'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('inscriptions_academiques', [
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L2',
            'statut' => 'valide',
        ]);
    }

    public function test_commande_artisan_regularise_avec_succes(): void
    {
        $this->creerEtudiant('TEST000100', 'L3');

        $this->artisan('scolarite:regulariser-inscriptions', [
            '--annee' => $this->annee->libelle,
            '--faculte' => $this->faculte->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('inscriptions_academiques', [
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L3',
            'statut' => 'valide',
        ]);
    }
}
