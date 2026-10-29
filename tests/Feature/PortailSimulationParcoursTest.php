<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Services\SimulationParcoursService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ADVANCED-01 LOT 01 — simulations de parcours self-service du portail
 * étudiant.
 *
 * Le portail RÉUTILISE le moteur unique (`SimulationParcoursService`,
 * 13 scénarios) : aucun second moteur, aucune formule dupliquée en
 * contrôleur ou en Blade. Ce fichier couvre :
 *
 * - l'authentification étudiante et l'anti-IDOR (étudiant résolu depuis
 *   la session, jamais depuis un identifiant client) ;
 * - la publication (D-01) : aucune moyenne, aucun crédit, aucune décision
 *   non publiés ne sortent par la simulation ;
 * - l'absence d'écriture officielle (notes, crédits, niveaux, décisions) ;
 * - le gate `module:parcours-academique` et la séparation back-office ;
 * - la validation serveur et les 13 scénarios.
 */
class PortailSimulationParcoursTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    private Programme $licence;

    private Niveau $l1;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();

        // Toutes les routes du module sont derrière ce flag : le portail
        // n'est pas exposé tant que l'institution ne l'a pas activé.
        config(['modules.parcours-academique.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $this->anneeSuivante = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->licence = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC', 'nom' => 'Licence Informatique',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->l1 = Niveau::create([
            'programme_id' => $this->licence->id, 'code' => 'L1', 'nom' => 'Première année', 'ordre' => 1,
        ]);

        $this->etudiant = $this->creerEtudiant('PORT-SIM-01', 'portail.simulation@universite.test');

        $this->inscription = $this->inscrire($this->etudiant, $this->annee);

        $this->matiere = Matiere::create([
            'code' => 'PORT-ALGO', 'nom' => 'Algorithmique', 'coefficient' => 2,
            'credits' => 5, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $this->noter($this->etudiant, $this->inscription, $this->matiere, 12.0);

        // Fixture « publiée » : la décision du jury est explicitement
        // PUBLIÉE. Sans elle, le portail refuse la simulation (D-01).
        $this->publierDecision($this->etudiant, $this->inscription, 'admis');
    }

    // -----------------------------------------------------------------
    // Authentification et séparation des espaces
    // -----------------------------------------------------------------

    public function test_invite_redirige_vers_la_connexion_etudiant(): void
    {
        $this->get(route('etudiant.parcours.simulations.index'))
            ->assertRedirect(route('etudiant.connexion.show'));
        $this->get(route('etudiant.parcours.simulations.create'))
            ->assertRedirect(route('etudiant.connexion.show'));
        $this->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage'])
            ->assertRedirect(route('etudiant.connexion.show'));

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    public function test_compte_back_office_ne_peut_pas_utiliser_le_portail_etudiant(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.portail@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        // `actingAs` ouvre la garde back-office, pas la session étudiante :
        // le middleware `etudiant.auth` doit refuser.
        $this->actingAs($admin)->get(route('etudiant.parcours.simulations.create'))
            ->assertRedirect(route('etudiant.connexion.show'));
        $this->actingAs($admin)->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage'])
            ->assertRedirect(route('etudiant.connexion.show'));

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    public function test_etudiant_ne_peut_pas_acceder_aux_routes_admin_du_parcours(): void
    {
        $this->connecterEtudiant($this->etudiant);

        $this->get(route('admin.parcours.index'))
            ->assertRedirect(route('admin.connexion.show'));
        $this->post(route('admin.parcours.simulations.store'), [
            'etudiant_id' => $this->etudiant->id, 'scenario' => 'passage',
        ])->assertRedirect(route('admin.connexion.show'));

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    public function test_module_desactive_repond_404_sur_toutes_les_routes_etudiantes(): void
    {
        $this->connecterEtudiant($this->etudiant);

        $simulation = app(SimulationParcoursService::class)->simuler($this->etudiant, 'passage');

        config(['modules.parcours-academique.active' => false]);

        $this->get(route('etudiant.parcours.simulations.index'))->assertNotFound();
        $this->get(route('etudiant.parcours.simulations.create'))->assertNotFound();
        $this->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage'])->assertNotFound();
        $this->get(route('etudiant.parcours.simulations.show', $simulation))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Publication (D-01)
    // -----------------------------------------------------------------

    public function test_resultats_publies_ouvrent_la_simulation_et_affichent_l_avertissement(): void
    {
        $this->connecterEtudiant($this->etudiant);

        $this->get(route('etudiant.parcours.simulations.create'))
            ->assertOk()
            ->assertSee('Calculer la simulation')
            ->assertSee('Résultat non officiel.')
            ->assertSee('Compensation');

        $reponse = $this->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage']);

        $simulation = ParcoursSimulation::where('etudiant_id', $this->etudiant->id)->firstOrFail();
        $reponse->assertRedirect(route('etudiant.parcours.simulations.show', $simulation));

        $this->assertSame('passage', $simulation->scenario);
        $this->assertSame($this->inscription->id, $simulation->inscription_source_id);
        $this->assertNull($simulation->auteur_id, 'Self-service : aucun auteur back-office.');
        $this->assertStringContainsString('sans effet officiel', $simulation->resultat['avertissement']);

        $this->get(route('etudiant.parcours.simulations.show', $simulation))
            ->assertOk()
            ->assertSee('sans effet officiel')
            ->assertSee('admis');
    }

    public function test_resultats_non_publies_bloquent_la_simulation_sans_divulgation(): void
    {
        $etudiant = $this->creerEtudiant('PORT-SIM-02', 'portail.nonpublie@universite.test');
        $inscription = $this->inscrire($etudiant, $this->annee);
        $matiere = Matiere::create([
            'code' => 'PORT-SECRET', 'nom' => 'Matière secrète', 'coefficient' => 1,
            'credits' => 5, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        // Aucune délibération publiée : ces notes ne doivent jamais
        // atteindre le portail, même indirectement via la simulation.
        $this->noter($etudiant, $inscription, $matiere, 17.75);

        $this->connecterEtudiant($etudiant);

        $this->get(route('etudiant.parcours.simulations.create'))
            ->assertOk()
            ->assertSee('pas encore')
            ->assertSee('indisponible')
            ->assertDontSee('Calculer la simulation');

        $this->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage'])
            ->assertRedirect(route('etudiant.parcours.simulations.create'))
            ->assertSessionHas('error', 'Vos résultats ne sont pas encore publiés : la simulation sera disponible après la délibération du jury.');

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    public function test_publication_partielle_par_inscription_bloque_aussi_la_simulation(): void
    {
        // Année N : décision publiée. Année N+1 : notes saisies, décision
        // non publiée. La publication est binaire PAR INSCRIPTION : la
        // présence d'une seule inscription non publiée portant des notes
        // suffit à fermer le portail.
        $inscriptionSuivante = $this->inscrire($this->etudiant, $this->anneeSuivante, false);
        $matiere = Matiere::create([
            'code' => 'PORT-N1', 'nom' => 'Matière N+1', 'coefficient' => 1,
            'credits' => 5, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $this->noter($this->etudiant, $inscriptionSuivante, $matiere, 15.5);

        $this->connecterEtudiant($this->etudiant);

        $this->get(route('etudiant.parcours.simulations.create'))
            ->assertOk()
            ->assertSee('pas encore')
            ->assertDontSee('Calculer la simulation');

        $this->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'compensation'])
            ->assertRedirect(route('etudiant.parcours.simulations.create'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    public function test_absence_de_deliberation_sans_note_reste_simulable_sans_exposer_de_decision(): void
    {
        // Aucune note saisie → rien de non publié à divulguer. La
        // simulation reste possible mais la décision officielle est
        // explicitement absente : aucun « admis » implicite.
        $etudiant = $this->creerEtudiant('PORT-SIM-03', 'portail.sansdecision@universite.test');
        $inscription = $this->inscrire($etudiant, $this->annee);

        $this->connecterEtudiant($etudiant);

        $this->get(route('etudiant.parcours.simulations.create'))
            ->assertOk()
            ->assertSee('Calculer la simulation');

        $reponse = $this->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage']);
        $simulation = ParcoursSimulation::where('etudiant_id', $etudiant->id)->firstOrFail();
        $reponse->assertRedirect(route('etudiant.parcours.simulations.show', $simulation));

        $this->assertNull($simulation->resultat['decision_officielle']);
        $this->assertContains('Aucune décision de jury publiée', $simulation->resultat['conditions_non_satisfaites']);

        $this->get(route('etudiant.parcours.simulations.show', $simulation))
            ->assertOk()
            ->assertSee('aucune décision publiée');
    }

    public function test_sans_inscription_la_simulation_est_indisponible_sans_substitution(): void
    {
        $etudiant = $this->creerEtudiant('PORT-SIM-04', 'portail.sansinscription@universite.test');
        $this->connecterEtudiant($etudiant);

        $this->get(route('etudiant.parcours.simulations.create'))
            ->assertOk()
            ->assertSee('Aucune inscription académique')
            ->assertDontSee('Calculer la simulation');

        $this->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage'])
            ->assertRedirect(route('etudiant.parcours.simulations.create'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    // -----------------------------------------------------------------
    // Anti-IDOR et historique
    // -----------------------------------------------------------------

    public function test_historique_ne_liste_que_les_simulations_de_l_etudiant_connecte(): void
    {
        $intrus = $this->creerEtudiant('PORT-SIM-05', 'portail.intrus@universite.test');
        $inscriptionIntrus = $this->inscrire($intrus, $this->annee);

        ParcoursSimulation::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_source_id' => $this->inscription->id,
            'annee_universitaire_id' => $this->annee->id,
            'scenario' => 'passage',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'progresser'],
        ]);
        ParcoursSimulation::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_source_id' => $this->inscription->id,
            'annee_universitaire_id' => $this->annee->id,
            'scenario' => 'dispense',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'dette_couverte_en_simulation'],
        ]);
        ParcoursSimulation::create([
            'etudiant_id' => $intrus->id,
            'inscription_source_id' => $inscriptionIntrus->id,
            'annee_universitaire_id' => $this->annee->id,
            'scenario' => 'compensation',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'admis'],
        ]);

        $this->connecterEtudiant($this->etudiant);

        $this->get(route('etudiant.parcours.simulations.index'))
            ->assertOk()
            ->assertSee('passage')
            ->assertSee('dispense')
            ->assertDontSee('compensation')
            ->assertSee('strictement non officielle');
    }

    public function test_show_dune_simulation_appartenant_a_un_autre_etudiant_repond_404(): void
    {
        $intrus = $this->creerEtudiant('PORT-SIM-06', 'portail.autre@universite.test');
        $inscriptionIntrus = $this->inscrire($intrus, $this->annee);

        $simulationIntrus = ParcoursSimulation::create([
            'etudiant_id' => $intrus->id,
            'inscription_source_id' => $inscriptionIntrus->id,
            'annee_universitaire_id' => $this->annee->id,
            'scenario' => 'passage',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'progresser'],
        ]);

        $this->connecterEtudiant($this->etudiant);

        $this->get(route('etudiant.parcours.simulations.show', $simulationIntrus))->assertNotFound();
    }

    public function test_simulation_creee_au_back_office_non_exposee_sur_le_portail(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.simulation@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $selfService = ParcoursSimulation::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_source_id' => $this->inscription->id,
            'annee_universitaire_id' => $this->annee->id,
            'scenario' => 'passage',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'progresser'],
        ]);
        // Simulation back-office : peut s'appuyer sur des notes non
        // publiées → document de travail interne, jamais exposé (D-01).
        $backOffice = ParcoursSimulation::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_source_id' => $this->inscription->id,
            'annee_universitaire_id' => $this->annee->id,
            'scenario' => 'dispense',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'dette_couverte_en_simulation'],
            'auteur_id' => $admin->id,
        ]);

        $this->connecterEtudiant($this->etudiant);

        $this->get(route('etudiant.parcours.simulations.index'))
            ->assertOk()
            ->assertSee('passage')
            ->assertDontSee('dispense');

        $this->get(route('etudiant.parcours.simulations.show', $backOffice))->assertNotFound();
        $this->get(route('etudiant.parcours.simulations.show', $selfService))->assertOk();
    }

    public function test_identifiants_envoyes_par_le_client_sont_ignores(): void
    {
        $autre = $this->creerEtudiant('PORT-SIM-07', 'portail.cible@universite.test');
        $inscriptionAutre = $this->inscrire($autre, $this->annee);

        $this->connecterEtudiant($this->etudiant);

        $this->post(route('etudiant.parcours.simulations.store'), [
            'etudiant_id' => $autre->id,
            'inscription_source_id' => $inscriptionAutre->id,
            'auteur_id' => 999,
            'scenario' => 'passage',
        ])->assertRedirect();

        $simulation = ParcoursSimulation::query()->sole();
        $this->assertSame($this->etudiant->id, $simulation->etudiant_id);
        $this->assertSame($this->inscription->id, $simulation->inscription_source_id);
        $this->assertNull($simulation->auteur_id);
        $this->assertSame(0, ParcoursSimulation::where('etudiant_id', $autre->id)->count());
    }

    // -----------------------------------------------------------------
    // Validation serveur
    // -----------------------------------------------------------------

    public function test_scenario_inconnu_est_rejete_sans_ecriture(): void
    {
        $this->connecterEtudiant($this->etudiant);

        $this->from(route('etudiant.parcours.simulations.create'))
            ->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'scenario_inexistant'])
            ->assertRedirect(route('etudiant.parcours.simulations.create'))
            ->assertSessionHasErrors('scenario');

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    public function test_note_hypothetique_hors_bornes_est_rejetee(): void
    {
        $this->connecterEtudiant($this->etudiant);

        $this->from(route('etudiant.parcours.simulations.create'))
            ->post(route('etudiant.parcours.simulations.store'), [
                'scenario' => 'validation_matiere',
                'hypotheses' => ['note' => 25, 'matiere_id' => $this->matiere->id],
            ])
            ->assertRedirect(route('etudiant.parcours.simulations.create'))
            ->assertSessionHasErrors('hypotheses.note');

        $this->assertDatabaseCount('parcours_simulations', 0);
    }

    // -----------------------------------------------------------------
    // Moteur unique : 13 scénarios, aucune écriture officielle
    // -----------------------------------------------------------------

    #[DataProvider('scenarios')]
    public function test_chaque_scenario_est_calcule_et_persiste_sans_effet_officiel(string $scenario): void
    {
        $this->connecterEtudiant($this->etudiant);

        $hypotheses = match ($scenario) {
            'validation_semestre' => ['semestre' => 1],
            'validation_matiere' => ['matiere_id' => $this->matiere->id, 'note' => 14.5],
            'equivalence', 'dispense' => ['cible' => 'UE Algorithmique'],
            default => [],
        };

        $reponse = $this->post(route('etudiant.parcours.simulations.store'), [
            'scenario' => $scenario,
            'hypotheses' => $hypotheses,
        ]);

        $simulation = ParcoursSimulation::where('etudiant_id', $this->etudiant->id)->firstOrFail();
        $reponse->assertRedirect(route('etudiant.parcours.simulations.show', $simulation));

        $this->assertSame($scenario, $simulation->scenario);
        $this->assertNotEmpty($simulation->resultat['resultat_final']);
        $this->assertStringContainsString('sans effet officiel', $simulation->resultat['avertissement']);
        $this->assertNotEmpty($simulation->resultat['regles'], 'Résultat explicable : règles citées.');

        $this->get(route('etudiant.parcours.simulations.show', $simulation))
            ->assertOk()
            ->assertSee('sans effet officiel');
    }

    public function test_aucune_ecriture_officielle_apres_une_simulation(): void
    {
        $this->connecterEtudiant($this->etudiant);

        $notesAvant = Note::count();
        $inscriptionsAvant = InscriptionAcademique::count();
        $semestresAvant = Semestre::count();
        $decisionsAvant = DecisionDeliberation::count();
        $etatAvant = $this->inscription->fresh()->only([
            'moyenne_annuelle', 'credits_obtenus', 'credits_total', 'decision', 'statut',
        ]);

        $this->post(route('etudiant.parcours.simulations.store'), [
            'scenario' => 'validation_matiere',
            'hypotheses' => ['matiere_id' => $this->matiere->id, 'note' => 15.75],
        ])->assertRedirect();

        $this->assertSame($notesAvant, Note::count(), 'Aucune note fictive persistée.');
        $this->assertDatabaseMissing('notes', ['note' => 15.75]);
        $this->assertSame($inscriptionsAvant, InscriptionAcademique::count());
        $this->assertSame($semestresAvant, Semestre::count());
        $this->assertSame($decisionsAvant, DecisionDeliberation::count());
        $this->assertSame($etatAvant, $this->inscription->fresh()->only([
            'moyenne_annuelle', 'credits_obtenus', 'credits_total', 'decision', 'statut',
        ]));

        // Seules traces : la simulation elle-même (append-only) et
        // l'audit académique.
        $this->assertDatabaseCount('parcours_simulations', 1);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'parcours.simulation',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function scenarios(): array
    {
        $cas = [];
        foreach (ParcoursSimulation::SCENARIOS as $scenario) {
            $cas[$scenario] = [$scenario];
        }

        return $cas;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function creerEtudiant(string $cin, string $email): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => $email,
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function inscrire(Etudiant $etudiant, AnneeUniversitaire $annee, bool $avecSemestreDeux = true): InscriptionAcademique
    {
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $this->licence->id,
            'niveau_id' => $this->l1->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        if ($avecSemestreDeux) {
            $inscription->semestres()->create(['numero' => 2]);
        }

        return $inscription;
    }

    private function noter(Etudiant $etudiant, InscriptionAcademique $inscription, Matiere $matiere, float $note): void
    {
        $semestre = Semestre::where('inscription_academique_id', $inscription->id)
            ->where('numero', 1)->firstOrFail();

        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => $note,
            'coefficient' => $matiere->coefficient,
            'credits' => $matiere->credits,
            'session' => 'normale',
        ]);
    }

    private function publierDecision(Etudiant $etudiant, InscriptionAcademique $inscription, string $decision): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $inscription->annee_universitaire_id,
            'statut' => 'published',
            'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.0,
            'credits_obtenus' => 5,
            'credits_total' => 5,
            'decision' => $decision,
            'publie' => true,
        ]);
    }

    private function connecterEtudiant(Etudiant $etudiant): void
    {
        // Session serveur uniquement : aucun identifiant client.
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ]);
    }
}
