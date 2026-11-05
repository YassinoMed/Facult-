<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\EcheanceDerogationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Q142ContestationAttenteProlongeeHttpTest extends TestCase
{
    use RefreshDatabase;

    private EcheanceDerogationService $service;

    private Etudiant $etudiant;

    private string $demandeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EcheanceDerogationService::class);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '71460021',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'karim.benali@universite.ac.ma',
            'date_naissance' => '2003-05-14',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Casablanca Maarif',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        // Créer un dépassement d'échéance et une demande en attente
        $this->service->constaterDepassementEcheance(
            $this->etudiant,
            'ECH-INSCRIPTION-2026',
            Carbon::now()->subDays(5),
            'Reglement-Art-12'
        );

        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Doyen', 'Commission_Pedagogique'],
            'loop146.derogations.canaux_officiels' => ['Courrier_Officiel_Rectorat', 'Portail_Numerique_Gouvernance'],
            'loop146.derogations.voies_recours' => ['Commission_Recours_Gracieux_Universite', 'Mediation_Academique'],
        ]);

        $demande = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            'Demande d’inscription tardive',
            'Hospitalisation justifiée par certificat médical légalisé.'
        );
        $this->demandeId = $demande['demande_id'];

        $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $this->etudiant->mot_de_passe_version,
        ]);
    }

    public function test_etudiant_connecte_peut_consulter_la_page_derogations(): void
    {
        $reponse = $this->get(route('etudiant.derogations.index'));

        $reponse->assertOk();
        $reponse->assertSee($this->demandeId);
        $reponse->assertSee('En cours d’instruction');
        $reponse->assertSee('ECH-INSCRIPTION-2026');
        $reponse->assertSee('Commission Recours Gracieux Universite');
    }

    public function test_invite_redirige_vers_connexion(): void
    {
        $this->flushSession();

        $reponse = $this->get(route('etudiant.derogations.index'));

        $reponse->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_etudiant_peut_contester_attente_prolongee_sans_mutation_dossier(): void
    {
        $statutInitial = $this->etudiant->statut;

        $reponse = $this->post(route('etudiant.derogations.contester-attente'), [
            'demande_id' => $this->demandeId,
            'motif_contestation' => 'Délai d’attente supérieur à trois semaines sans information complémentaire.',
        ]);

        $reponse->assertRedirect(route('etudiant.derogations.index'));
        $reponse->assertSessionHas('success');

        // Vérifier que le dossier initial n'a subi AUCUNE mutation
        $actualise = $this->etudiant->fresh();
        $this->assertSame($statutInitial, $actualise->statut);

        // Vérifier le rendu sur la page (statut mis à jour à Attente contestée)
        $vue = $this->get(route('etudiant.derogations.index'));
        $vue->assertOk();
        $vue->assertSee('Attente contestée');
    }

    public function test_contestation_attente_rejetee_si_motif_trop_court(): void
    {
        $reponse = $this->from(route('etudiant.derogations.index'))
            ->post(route('etudiant.derogations.contester-attente'), [
                'demande_id' => $this->demandeId,
                'motif_contestation' => 'Court',
            ]);

        $reponse->assertRedirect(route('etudiant.derogations.index'));
        $reponse->assertSessionHasErrors('motif_contestation');
    }

    public function test_contestation_attente_idempotente(): void
    {
        $donnees = [
            'demande_id' => $this->demandeId,
            'motif_contestation' => 'Attente prolongée anormale constatée sans réponse rectorale.',
        ];

        $this->post(route('etudiant.derogations.contester-attente'), $donnees);
        $this->post(route('etudiant.derogations.contester-attente'), $donnees);

        $dossier = $this->service->consulterDossierDerogations($this->etudiant);
        $this->assertCount(1, $dossier['demandes']);
        $this->assertTrue($dossier['demandes'][0]['conteste']);
    }

    public function test_anti_idor_autre_etudiant_ne_voit_pas_les_demandes_d_autrui(): void
    {
        $autre = Etudiant::create([
            'cin' => '71460022',
            'nom' => 'Mansouri',
            'prenom' => 'Hassan',
            'email' => 'hassan.mansouri@universite.ac.ma',
            'date_naissance' => '2003-08-20',
            'sexe' => 'M',
            'faculte_id' => $this->etudiant->faculte_id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Casablanca',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $autre->definirStatut('valide');

        $reponse = $this->withSession([
            'etudiant_auth_id' => $autre->id,
            'etudiant_mdp_version' => $autre->mot_de_passe_version,
        ])->get(route('etudiant.derogations.index'));

        $reponse->assertOk();
        $reponse->assertDontSee($this->demandeId);
        $reponse->assertDontSee('ECH-INSCRIPTION-2026');
        $reponse->assertSee('Aucune demande de dérogation', false);
    }
}
