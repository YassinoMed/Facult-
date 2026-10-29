<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Calendar\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-UNIREGISTRE-PORTAILS-ENTERPRISE-01 — LOT 2.
 *
 * Agenda étudiant : un événement chevauche réellement la période
 * demandée (y compris multi-jours commençant avant ou finissant dedans),
 * et les bornes client sont validées avant la requête SQL.
 */
class AgendaCalendrierTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.calendar.active' => true, 'modules.evaluation.active' => false]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => '60000001', 'nom' => 'Agenda', 'prenom' => 'Etudiant',
            'email' => 'agenda@etudiant.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
    }

    private function evenement(string $titre, string $debut, ?string $fin = null, array $surcharge = []): EvenementCalendrier
    {
        return EvenementCalendrier::create(array_merge([
            'annee_universitaire_id' => $this->annee->id,
            'titre' => $titre,
            'type' => 'evenement',
            'date_debut' => $debut,
            'date_fin' => $fin,
            'audience' => 'tous',
            'statut' => 'publie',
        ], $surcharge));
    }

    public function test_evenement_multi_jours_commencant_avant_la_periode_est_visible(): void
    {
        $this->evenement('Chevauchement', '2026-10-01', '2026-10-10');
        $this->evenement('Dans', '2026-10-05');

        $titres = array_column(
            app(AgendaService::class)->agendaPourEtudiant($this->etudiant, '2026-10-05', '2026-10-06'),
            'titre'
        );

        $this->assertContains('Chevauchement', $titres);
        $this->assertContains('Dans', $titres);
    }

    public function test_evenement_finissant_dans_la_periode_est_visible(): void
    {
        $this->evenement('Fin dedans', '2026-09-28', '2026-10-05');

        $titres = array_column(
            app(AgendaService::class)->agendaPourEtudiant($this->etudiant, '2026-10-05', '2026-10-06'),
            'titre'
        );

        $this->assertContains('Fin dedans', $titres);
    }

    public function test_evenement_hors_periode_ou_non_publie_reste_invisible(): void
    {
        $this->evenement('Avant', '2026-09-20', '2026-09-25');
        $this->evenement('Apres', '2026-10-20', '2026-10-22');
        $this->evenement('Brouillon', '2026-10-05', null, ['statut' => 'brouillon']);

        $titres = array_column(
            app(AgendaService::class)->agendaPourEtudiant($this->etudiant, '2026-10-05', '2026-10-06'),
            'titre'
        );

        $this->assertNotContains('Avant', $titres);
        $this->assertNotContains('Apres', $titres);
        $this->assertNotContains('Brouillon', $titres);
    }

    public function test_evenement_sans_date_fin_est_visible_s_il_commence_dans_la_periode(): void
    {
        $this->evenement('Sans fin', '2026-10-05');

        $titres = array_column(
            app(AgendaService::class)->agendaPourEtudiant($this->etudiant, '2026-10-05', '2026-10-06'),
            'titre'
        );

        $this->assertContains('Sans fin', $titres);
    }

    public function test_bornes_de_date_invalides_sont_rejetees_avant_sql(): void
    {
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->getJson(route('etudiant.calendrier.agenda', ['debut' => '2026-13-99']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('debut');

        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->getJson(route('etudiant.calendrier.agenda', [
                'debut' => '2026-10-10', 'fin' => '2026-10-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fin');
    }

    public function test_agenda_http_valide_affiche_l_evenement(): void
    {
        $this->evenement('Rentrée', '2026-10-05');

        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.calendrier.agenda', [
                'debut' => '2026-10-01', 'fin' => '2026-10-10',
            ]))
            ->assertOk()
            ->assertSee('Rentrée');
    }
}
