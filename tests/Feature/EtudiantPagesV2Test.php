<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\FactureLigne;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pages secondaires du portail étudiant en V2.
 *
 * Les neuf routes historiquement non vérifiées dépendent de modules
 * désactivés par défaut. Ce test les active explicitement, construit des
 * données réelles (annonce, agenda, dossier, planning, facture, paiement,
 * simulation) puis vérifie que chaque page HTML expose la coque V2 et ses
 * classes de page. Le point d'actualisation du planning reste un endpoint
 * JSON : son contrat est vérifié séparément.
 */
class EtudiantPagesV2Test extends TestCase
{
    use RefreshDatabase;

    private Etudiant $etudiant;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.announcements.active' => true,
            'modules.calendar.active' => true,
            'modules.finances.active' => true,
            'modules.parcours-academique.active' => true,
            'modules.schedule.active' => true,
            'inscriptions-paiements.actif' => true,
        ]);

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FSTV2',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'L1-V2-A',
            'nom' => 'L1 Informatique A',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'actif' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'V2PAGES01',
            'nom' => 'Tazi',
            'prenom' => 'Nour',
            'email' => 'v2.pages@example.test',
            'telephone' => '+212600000099',
            'date_naissance' => '2004-06-12',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '10 Rue des Écoles',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'Etudiant@123',
        ]);

        $this->etudiant->definirStatut('valide');

        DossierAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'matricule' => 'ARCH-V2-001',
            'date_archivage' => now()->toDateString(),
            'statut' => 'actif',
            'observation' => 'Dossier de démonstration V2.',
        ]);

        $this->inscription = $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => 'en_cours',
        ]);

        Annonce::create([
            'titre' => 'Rentrée académique 2026',
            'contenu' => 'La rentrée est fixée au 14 septembre. Les emplois du temps sont publiés.',
            'type' => 'information',
            'statut' => 'published',
            'audience' => 'etudiants',
            'faculte_id' => $faculte->id,
            'publiee_a' => now()->subHour(),
        ]);

        EvenementCalendrier::create([
            'annee_universitaire_id' => $this->annee->id,
            'titre' => 'Journée portes ouvertes',
            'type' => 'evenement',
            'date_debut' => now()->addDays(3)->toDateString(),
            'date_fin' => now()->addDays(3)->toDateString(),
            'audience' => 'etudiants',
            'faculte_id' => $faculte->id,
            'statut' => 'publie',
        ]);

        $matiere = Matiere::create([
            'code' => 'V2INFO01',
            'nom' => 'Algorithmique avancée',
            'coefficient' => 3,
            'credits' => 6,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'semestre' => 1,
        ]);

        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'L1 Informatique A — S1',
            'starts_at' => '2026-09-14',
            'ends_at' => '2027-01-17',
            'actif' => true,
            'statut' => 'publie',
            'publie_a' => now()->subDay(),
        ]);

        ScheduleSlot::create([
            'schedule_id' => $planning->id,
            'matiere_id' => $matiere->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $facture = Facture::create([
            'reference' => 'FAC-V2-0001',
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'inscription_academique_id' => $this->inscription->id,
            'statut' => 'issued',
            'total_centimes' => 1200000,
            'date_echeance' => now()->addMonth()->toDateString(),
        ]);

        FactureLigne::create([
            'facture_id' => $facture->id,
            'libelle' => 'Frais de scolarité annuels',
            'montant_centimes' => 1200000,
        ]);

        ParcoursSimulation::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_source_id' => $this->inscription->id,
            'annee_universitaire_id' => $this->annee->id,
            'scenario' => 'passage',
            'hypotheses' => [],
            'resultat' => [
                'resultat_final' => 'passage',
                'credits_valides' => 60,
                'message' => 'Simulation de démonstration.',
            ],
            'regle_reference' => 'V2-DEMO',
            'auteur_id' => null,
        ]);
    }

    private function connecter(): static
    {
        return $this->withSession(['etudiant_auth_id' => $this->etudiant->id]);
    }

    private function assertCoqueV2(string $html, string $classePage): void
    {
        $this->assertStringContainsString('etudiant-shell', $html);
        $this->assertStringContainsString('data-etudiant-theme="v2"', $html);
        $this->assertStringContainsString('data-etudiant-page-theme="v2"', $html);
        $this->assertStringContainsString('data-etudiant-theme-switch', $html);
        $this->assertStringContainsString($classePage, $html);
    }

    public function test_les_annonces_affichent_la_v2_et_les_donnees_publiees(): void
    {
        $response = $this->connecter()->get(route('etudiant.annonces.index'));

        $response->assertOk();
        $response->assertSee('Rentrée académique 2026');

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'annonces-page');
        $this->assertStringContainsString('annonce-card', $html);
    }

    public function test_les_archives_affichent_la_v2_et_la_feuille_institutionnelle(): void
    {
        $response = $this->connecter()->get(route('etudiant.archives.show'));

        $response->assertOk();
        $response->assertSee('ARCH-V2-001');

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'archives-page');
        $this->assertStringContainsString('archives-sheet', $html);
        $this->assertStringContainsString('archives-stamp', $html);
        $this->assertStringContainsString('archives-section-head', $html);
    }

    public function test_l_agenda_affiche_la_v2_et_les_evenements_de_la_periode(): void
    {
        $response = $this->connecter()->get(route('etudiant.calendrier.agenda'));

        $response->assertOk();
        $response->assertSee('Journée portes ouvertes');

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'agenda-page');
        $this->assertStringContainsString('agenda-item', $html);
    }

    public function test_le_planning_affiche_la_v2_et_les_creneaux_publies(): void
    {
        $response = $this->connecter()->get(route('etudiant.emploi-du-temps.semaine'));

        $response->assertOk();
        $response->assertSee('Algorithmique avancée');

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'planning-page');
        $this->assertStringContainsString('planning-grid', $html);
        $this->assertStringContainsString('planning-slot', $html);
        $this->assertStringContainsString('planning-slot--normal', $html);
    }

    public function test_les_finances_affichent_la_v2_et_la_facture_reelle(): void
    {
        $response = $this->connecter()->get(route('etudiant.finances.index'));

        $response->assertOk();
        $response->assertSee('FAC-V2-0001');

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'finances-page');
        $this->assertStringContainsString('finances-kpis', $html);
        $this->assertStringContainsString('facture-card', $html);
    }

    public function test_les_paiements_affichent_la_v2_et_le_dossier_annuel(): void
    {
        $response = $this->connecter()->get(route('etudiant.paiements.index'));

        $response->assertOk();
        $response->assertSee('2026-2027');

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'paiements-page');
        $this->assertStringContainsString('paiement-card', $html);
        $this->assertStringContainsString('paiement-metrics', $html);
        $this->assertStringContainsString('paiement-metric', $html);
    }

    public function test_les_simulations_affichent_la_v2_et_l_historique_self_service(): void
    {
        $response = $this->connecter()->get(route('etudiant.parcours.simulations.index'));

        $response->assertOk();
        $response->assertSee('passage');

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'simulations-page');
        $this->assertStringContainsString('simulation-row', $html);
        $this->assertStringContainsString('simulation-result', $html);
        $this->assertStringContainsString('btn-primary', $html);
    }

    public function test_le_formulaire_de_simulation_affiche_la_v2(): void
    {
        $response = $this->connecter()->get(route('etudiant.parcours.simulations.create'));

        $response->assertOk();

        $html = $response->getContent();
        $this->assertCoqueV2($html, 'simulations-page');
        $this->assertStringContainsString('simulation-form', $html);
        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString('btn-secondary', $html);
    }

    public function test_l_endpoint_d_actualisation_reste_un_json_sans_markup_de_theme(): void
    {
        $response = $this->connecter()->getJson(route('etudiant.emploi-du-temps.actualisations'));

        $response->assertOk();
        $response->assertJsonStructure(['version', 'updated', 'slots']);

        $version = $response->json('version');

        $this->connecter()
            ->getJson(route('etudiant.emploi-du-temps.actualisations', ['depuis' => $version]))
            ->assertOk()
            ->assertJson([
                'version' => $version,
                'updated' => false,
                'slots' => [],
            ]);
    }

    public function test_l_endpoint_d_actualisation_reste_protege_par_l_authentification(): void
    {
        $this->get(route('etudiant.emploi-du-temps.actualisations'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_les_pages_secondaires_conservent_la_v1_a_la_demande(): void
    {
        $response = $this->connecter()->get(route('etudiant.annonces.index', ['theme' => 'v1']));

        $response->assertOk();
        $response->assertSee('Rentrée académique 2026');
        $response->assertSee('data-etudiant-theme="v1"', false);
        $response->assertSee('data-etudiant-page-theme="v1"', false);
    }
}
