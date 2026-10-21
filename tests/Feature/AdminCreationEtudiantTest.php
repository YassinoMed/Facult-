<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Création administrative d'un étudiant complet : identité, rattachement
 * LMD, affectation, validation motivée et statut de paiement oui/non
 * (paiement externe, sans facture ni transaction locale).
 */
class AdminCreationEtudiantTest extends TestCase
{
    use ActAsScolarite;
    use RefreshDatabase;

    private Faculte $faculte;

    private Programme $programme;

    private Niveau $niveau;

    private Group $groupe;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.finances.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GI',
            'nom' => 'Génie Informatique',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-08-31',
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'GI-L1-A',
            'nom' => 'GI L1 A',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'filiere' => 'GI',
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->scolarite('super_admin');
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function donnees(array $surcharges = []): array
    {
        return array_merge([
            'nom' => 'El Amrani',
            'prenom' => 'Yassine',
            'cin' => 'AB123456',
            'identifiant_national' => 'MENS-001',
            'email' => 'yassine.elamrani@example.test',
            'telephone' => '+212 600 000 000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'groupe_id' => $this->groupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_inscription' => 'premiere',
            'regime' => 'initiale',
            'adresse' => '12 rue des Écoles',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'motif_validation' => 'Dossier vérifié au guichet avec pièces originales.',
            'paye' => '0',
        ], $surcharges);
    }

    public function test_la_page_de_creation_est_accessible_aux_agents_habilités(): void
    {
        $this->get(route('admin.etudiants.creer'))
            ->assertOk()
            ->assertSee('Créer un étudiant')
            ->assertSee('Identité de l’étudiant')
            ->assertSee('Rattachement académique')
            ->assertSee('Paiement effectué ?')
            ->assertSee('name="paye"', false)
            ->assertDontSee('Frais et facturation')
            ->assertSee('Motif de validation immédiate')
            ->assertSee('data-annee="'.$this->annee->id.'"', false)
            ->assertDontSee('&lt;strong&gt;', false);
    }

    public function test_le_bouton_de_creation_est_visible_sur_le_registre(): void
    {
        $this->get(route('admin.etudiants.index'))
            ->assertOk()
            ->assertSee('Créer un étudiant')
            ->assertSee(route('admin.etudiants.creer'), false);
    }

    public function test_les_erreurs_de_validation_sont_affichees_sur_la_page(): void
    {
        $this->from(route('admin.etudiants.creer'))
            ->followingRedirects()
            ->post(route('admin.etudiants.store'), $this->donnees([
                'paye' => 'peut-être',
            ]))
            ->assertOk()
            ->assertSee('Veuillez corriger les champs suivants.')
            ->assertSee('Le statut de paiement est invalide (oui ou non).')
            ->assertDontSee('&lt;ul&gt;', false);
    }

    public function test_creation_complete_avec_inscription_validee_affectation_et_paiement_effectue(): void
    {
        $reponse = $this->post(route('admin.etudiants.store'), $this->donnees([
            'paye' => '1',
        ]));

        $etudiant = Etudiant::where('cin', 'AB123456')->firstOrFail();

        $reponse->assertRedirect(route('admin.etudiants.show', $etudiant));
        $reponse->assertSessionHas('success');
        $reponse->assertSessionHas('mot_de_passe_provisoire');

        $motDePasse = (string) session('mot_de_passe_provisoire');

        $this->assertSame('valide', $etudiant->statut);
        $this->assertSame($this->faculte->id, (int) $etudiant->faculte_id);
        $this->assertSame($this->programme->id, (int) $etudiant->programme_id);
        $this->assertSame($this->niveau->id, (int) $etudiant->niveau_id);
        $this->assertSame($this->groupe->id, (int) $etudiant->groupe_id);
        $this->assertSame('L1', $etudiant->niveau);
        $this->assertSame('2026-2027', $etudiant->annee_academique);
        $this->assertTrue(Hash::check($motDePasse, $etudiant->password));

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();

        $this->assertSame('valide', $inscription->statut);
        $this->assertSame('premiere', $inscription->type);
        $this->assertSame('initiale', $inscription->regime);
        $this->assertSame($this->programme->id, (int) $inscription->programme_id);
        $this->assertSame($this->niveau->id, (int) $inscription->niveau_id);
        $this->assertSame('L1', $inscription->niveau);
        $this->assertSame('Génie Informatique', $inscription->filiere);
        $this->assertSame('GI-L1-A', $inscription->groupe);
        $this->assertTrue($inscription->paye);

        $this->assertDatabaseHas('affectations_groupe', [
            'etudiant_id' => $etudiant->id,
            'groupe_id' => $this->groupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'active',
        ]);

        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('statut', 'active')
            ->count());

        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id,
            'type' => 'statut',
            'ancienne_valeur' => 'en_attente',
            'nouvelle_valeur' => 'valide',
        ]);

        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id,
            'type' => 'groupe',
            'nouvelle_valeur' => 'GI-L1-A',
        ]);

        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id,
            'type' => 'note',
            'ancienne_valeur' => 'inscription:en_cours',
            'nouvelle_valeur' => 'inscription:valide',
        ]);

        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'creation_dossier',
            'entite' => 'etudiant',
        ]);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'modification_inscription',
            'entite' => 'inscription',
            'entite_id' => $inscription->id,
        ]);

        $this->get(route('admin.etudiants.show', $etudiant))
            ->assertOk()
            ->assertSee('Paiement (site externe)')
            ->assertSee('Oui — effectué');
    }

    public function test_un_motif_long_est_tronque_pour_l_affectation_mais_conserve_ailleurs(): void
    {
        $motif = str_repeat('A', 255);

        $this->post(route('admin.etudiants.store'), $this->donnees([
            'motif_validation' => $motif,
        ]))->assertRedirect();

        $etudiant = Etudiant::where('cin', 'AB123456')->firstOrFail();
        $affectation = AffectationGroupe::where('etudiant_id', $etudiant->id)->firstOrFail();

        $this->assertLessThanOrEqual(255, mb_strlen($affectation->motif));
        $this->assertStringStartsWith('Création administrative — ', $affectation->motif);

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertSame($motif, $inscription->observation);
    }

    public function test_la_fiche_du_nouvel_etudiant_est_accessible(): void
    {
        $this->post(route('admin.etudiants.store'), $this->donnees())
            ->assertRedirect();

        $etudiant = Etudiant::where('cin', 'AB123456')->firstOrFail();

        $this->get(route('admin.etudiants.show', $etudiant))
            ->assertOk()
            ->assertSee($etudiant->nom_complet);
    }

    public function test_le_statut_de_paiement_est_independant_du_module_finances(): void
    {
        config(['modules.finances.active' => false]);

        $this->get(route('admin.etudiants.creer'))
            ->assertOk()
            ->assertSee('Paiement effectué ?')
            ->assertDontSee('Module Finances désactivé')
            ->assertDontSee('Frais et facturation');

        $reponse = $this->post(route('admin.etudiants.store'), $this->donnees([
            'paye' => '1',
        ]));

        $etudiant = Etudiant::where('cin', 'AB123456')->firstOrFail();

        $reponse->assertRedirect(route('admin.etudiants.show', $etudiant));

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();

        $this->assertTrue($inscription->paye);
        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);
    }

    public function test_une_periode_fermee_bloque_la_creation_sans_laisser_de_donnees(): void
    {
        RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'nom' => 'Période clôturée',
            'opened_at' => now()->subDays(10),
            'closed_at' => now()->subDay(),
            'statut' => 'active',
        ]);

        $this->post(route('admin.etudiants.store'), $this->donnees())
            ->assertSessionHasErrors('creation');

        $this->assertSame(0, Etudiant::where('cin', 'AB123456')->count());
        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);
    }

    public function test_creation_sans_paiement_effectue_enregistre_non_paye(): void
    {
        $reponse = $this->post(route('admin.etudiants.store'), $this->donnees());

        $etudiant = Etudiant::where('cin', 'AB123456')->firstOrFail();

        $reponse->assertRedirect(route('admin.etudiants.show', $etudiant));

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();

        $this->assertFalse($inscription->paye);
        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);

        $this->get(route('admin.etudiants.show', $etudiant))
            ->assertOk()
            ->assertSee('Paiement (site externe)')
            ->assertSee('Non — non effectué');
    }

    public function test_les_champs_optionnels_vides_sont_acceptes(): void
    {
        $this->post(route('admin.etudiants.store'), $this->donnees([
            'telephone' => '',
            'identifiant_national' => '',
            'mention_bac' => '',
            'groupe_id' => '',
        ]))->assertRedirect();

        $etudiant = Etudiant::where('cin', 'AB123456')->firstOrFail();

        $this->assertNull($etudiant->telephone);
        $this->assertNull($etudiant->identifiant_national);
        $this->assertNull($etudiant->mention_bac);
    }

    public function test_la_creation_ne_cree_ni_facture_ni_paiement(): void
    {
        $this->post(route('admin.etudiants.store'), $this->donnees())
            ->assertRedirect();

        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);
        $this->assertDatabaseCount('facture_lignes', 0);
    }

    public function test_les_entrees_malformees_sont_rejetees_sans_erreur_serveur(): void
    {
        $this->post(route('admin.etudiants.store'), $this->donnees([
            'cin' => ['AB123456'],
            'nom' => ['El Amrani'],
            'programme_id' => [$this->programme->id],
            'paye' => ['1'],
        ]))->assertSessionHasErrors(['cin', 'nom', 'programme_id', 'paye']);

        $this->assertSame(0, Etudiant::count());
    }

    public function test_un_statut_de_paiement_invalide_est_refuse_et_aucun_etudiant_n_est_cree(): void
    {
        $this->post(route('admin.etudiants.store'), $this->donnees([
            'paye' => 'peut-être',
        ]))->assertSessionHasErrors('paye');

        $this->assertSame(0, Etudiant::where('cin', 'AB123456')->count());
        $this->assertDatabaseCount('factures', 0);
    }

    public function test_un_groupe_d_un_autre_parcours_est_refuse(): void
    {
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GC',
            'nom' => 'Génie Civil',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $autreNiveau = Niveau::create([
            'programme_id' => $autreProgramme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $autreGroupe = Group::create([
            'code' => 'GC-L1-A',
            'nom' => 'GC L1 A',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'filiere' => 'GC',
            'programme_id' => $autreProgramme->id,
            'niveau_id' => $autreNiveau->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->post(route('admin.etudiants.store'), $this->donnees([
            'groupe_id' => $autreGroupe->id,
        ]))->assertSessionHasErrors('groupe_id');

        $this->assertSame(0, Etudiant::where('cin', 'AB123456')->count());
    }

    public function test_un_groupe_d_une_autre_annee_est_refuse(): void
    {
        $autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2027-2028',
            'date_debut' => '2027-09-01',
            'date_fin' => '2028-08-31',
            'active' => false,
        ]);

        $groupeAutreAnnee = Group::create([
            'code' => 'GI-L1-B',
            'nom' => 'GI L1 B',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'filiere' => 'GI',
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'annee_universitaire_id' => $autreAnnee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->post(route('admin.etudiants.store'), $this->donnees([
            'groupe_id' => $groupeAutreAnnee->id,
        ]))->assertSessionHasErrors('groupe_id');

        $this->assertSame(0, Etudiant::where('cin', 'AB123456')->count());
    }

    public function test_un_doublon_de_cin_est_refuse(): void
    {
        Etudiant::create([
            'cin' => 'AB123456',
            'nom' => 'Ancien',
            'prenom' => 'Étudiant',
            'email' => 'ancien@example.test',
            'date_naissance' => '2003-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '1 rue Ancienne',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Motdepasse@123',
        ]);

        $this->post(route('admin.etudiants.store'), $this->donnees([
            'email' => 'nouveau@example.test',
        ]))->assertSessionHasErrors('cin');

        $this->assertSame(1, Etudiant::whereRaw('UPPER(TRIM(cin)) = ?', ['AB123456'])->count());
    }

    public function test_un_agent_scolarite_peut_creer_avec_le_statut_de_paiement(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent.scolarite@example.test',
            'password' => Hash::make('Motdepasse@123'),
        ], 'agent_scolarite');

        $this->actingAs($agent);

        $this->get(route('admin.etudiants.creer'))
            ->assertOk()
            ->assertSee('Paiement effectué ?')
            ->assertDontSee('Frais et facturation');

        $this->post(route('admin.etudiants.store'), $this->donnees([
            'paye' => '1',
        ]))->assertRedirect();

        $etudiant = Etudiant::where('cin', 'AB123456')->firstOrFail();
        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();

        $this->assertTrue($inscription->paye);
        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);
    }

    public function test_un_admin_faculte_ne_peut_pas_creer_dans_une_autre_faculte(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FM',
            'active' => true,
        ]);

        $autreProgramme = Programme::create([
            'faculte_id' => $autreFaculte->id,
            'code' => 'MED',
            'nom' => 'Médecine',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Faculté',
            'email' => 'admin.faculte@example.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte);

        $this->get(route('admin.etudiants.creer'))
            ->assertOk()
            ->assertSee($this->faculte->nom)
            ->assertDontSee($autreFaculte->nom);

        $this->post(route('admin.etudiants.store'), $this->donnees([
            'faculte_id' => $autreFaculte->id,
            'programme_id' => $autreProgramme->id,
        ]))->assertSessionHasErrors('faculte_id');

        $this->assertSame(0, Etudiant::where('cin', 'AB123456')->count());
    }
}
