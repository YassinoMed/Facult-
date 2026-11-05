<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * LOOP 146 / Phase Q137 — Dépôt de demande de dérogation administrative par l'étudiant.
 * Règle EXC-001 : Dépôt session-scopé, anti-IDOR, zéro décision tacite ni délai présumé.
 */
class Q137DerogationDepotHttpTest extends TestCase
{
    use RefreshDatabase;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'loop146.derogations.mecanisme_identifie' => false,
            'loop146.derogations.autorites_competentes' => [],
        ]);

        $faculte = Faculte::create([
            'code' => 'FS',
            'nom' => 'Faculté des Sciences',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '81460011',
            'nom' => 'Alami',
            'prenom' => 'Hassan',
            'email' => 'hassan.alami@universite.ac.ma',
            'date_naissance' => '2004-01-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
    }

    public function test_q137_etudiant_connecte_peut_deposer_demande_avec_piece(): void
    {
        $fichier = UploadedFile::fake()->create('certificat.pdf', 300, 'application/pdf');

        $reponse = $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $this->etudiant->mot_de_passe_version,
        ])->post(route('etudiant.derogations.deposer'), [
            'objet' => 'Demande d’inscription tardive pour motif médical',
            'motif' => 'Hospitalisation documentée par certificat officiel durant la période réglementaire.',
            'piece_justificative' => $fichier,
        ]);

        $reponse->assertRedirect(route('etudiant.derogations.index'));
        $reponse->assertSessionHas('success');

        $audit = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'depot_demande_derogation')
            ->firstOrFail();

        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertSame('Demande d’inscription tardive pour motif médical', $valeurs['objet']);
        $this->assertFalse($valeurs['competence_etablie']);
        $this->assertFalse($valeurs['delai_modifie']);
        $this->assertFalse($valeurs['consequences_appliquees']);
        $this->assertNotNull($valeurs['piece_hash']);
    }

    public function test_q137_invite_redirige_vers_connexion(): void
    {
        $reponse = $this->post(route('etudiant.derogations.deposer'), [
            'objet' => 'Demande',
            'motif' => 'Motif circonstancié suffisant pour le test.',
        ]);

        $reponse->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_q137_validation_objet_trop_court_rejete(): void
    {
        $reponse = $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $this->etudiant->mot_de_passe_version,
        ])->post(route('etudiant.derogations.deposer'), [
            'objet' => 'ab',
            'motif' => 'Motif circonstancié suffisant pour le test.',
        ]);

        $reponse->assertSessionHasErrors(['objet']);
        $this->assertSame(0, HistoriqueAcademique::where('action', 'depot_demande_derogation')->count());
    }

    public function test_q137_validation_motif_trop_court_rejete(): void
    {
        $reponse = $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $this->etudiant->mot_de_passe_version,
        ])->post(route('etudiant.derogations.deposer'), [
            'objet' => 'Demande dérogation',
            'motif' => 'Court',
        ]);

        $reponse->assertSessionHasErrors(['motif']);
        $this->assertSame(0, HistoriqueAcademique::where('action', 'depot_demande_derogation')->count());
    }
}
