<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\ExamenEvenement;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamenService;
use App\Modules\Referentiel\Models\Programme;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP EX-LIFECYCLE — campagne dédiée au cycle de vie des examens.
 *
 * Matrice cible du cahier des charges (EX-LC-xxx) adaptée à la
 * politique réellement démontrée dans le code (voir
 * docs/loop/examens-cycle-vie/01-decisions-metier.md) : aucune règle
 * métier artificielle n'est créée pour rendre un scénario passant.
 */
class ExamenCycleVieTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté Sciences A',
            'code' => 'FSA-'.substr(md5(uniqid('', true)), 0, 4),
            'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté Lettres B',
            'code' => 'FLB-'.substr(md5(uniqid('', true)), 0, 4),
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $admin = $this->scolarite('super_admin');

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-LC-'.substr(md5(uniqid('', true)), 0, 5),
            'nom' => 'Session Cycle de Vie',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $admin->id,
        ]);
    }

    /* ── H1 · Création ─────────────────────────────────────────── */

    public function test_ex_lc_001_creation_valide_etat_initial_draft_historise(): void
    {
        $this->scolarite('admin_scolarite');

        $reponse = $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Cycle Vie 001',
            'type' => 'examen',
        ]);

        $reponse->assertRedirect();

        $examen = Examen::where('libelle', 'Examen Cycle Vie 001')->firstOrFail();
        $this->assertSame('draft', $examen->statut);

        $evenement = ExamenEvenement::where('examen_id', $examen->id)->firstOrFail();
        $this->assertSame(ExamenEvenement::ACTION_CREATION, $evenement->action);
        $this->assertSame('draft', $evenement->statut_apres);
    }

    public function test_ex_lc_002_donnees_obligatoires_absentes_refuse(): void
    {
        $this->scolarite('admin_scolarite');

        $reponse = $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
        ]);

        $reponse->assertSessionHasErrors(['libelle', 'type']);
        $this->assertSame(0, Examen::count());
    }

    public function test_ex_lc_005_etat_privilégie_injectable_par_le_client_est_ignoré(): void
    {
        $this->scolarite('admin_scolarite');

        $reponse = $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Injection',
            'type' => 'examen',
            'statut' => 'published', // tentative d'imposition client
            'published_at' => '2026-10-31 10:00:00',
        ]);

        $reponse->assertRedirect();

        $examen = Examen::where('libelle', 'Examen Injection')->firstOrFail();
        $this->assertSame('draft', $examen->statut, 'Le client ne doit jamais imposer un état privilégié.');
        $this->assertNull($examen->published_at);
    }

    /* ── H2 · Transitions valides et invalides ─────────────────── */

    public function test_ex_lc_008_transition_normale_autorisée_est_appliquée_et_historisée(): void
    {
        $examen = $this->creerExamen('draft');

        $reponse = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'planned']));

        $reponse->assertRedirect();
        $reponse->assertSessionHasNoErrors();

        $examen->refresh();
        $this->assertSame('planned', $examen->statut);

        $evenement = ExamenEvenement::where('examen_id', $examen->id)
            ->where('action', ExamenEvenement::ACTION_TRANSITION)
            ->firstOrFail();
        $this->assertSame('draft', $evenement->statut_avant);
        $this->assertSame('planned', $evenement->statut_apres);
        $this->assertNotNull($evenement->acteur_id);
    }

    public function test_ex_lc_009_transition_inconnue_refusée(): void
    {
        $examen = $this->creerExamen('draft');

        // 'archived' est admis par la route (whereIn), mais la matrice
        // draft → archived n'existe pas : refus métier via erreur de session.
        $reponse = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'archived']));

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('examen');
        $examen->refresh();
        $this->assertSame('draft', $examen->statut);
    }

    public function test_ex_lc_010_transition_sautant_un_etat_refusée(): void
    {
        $examen = $this->creerExamen('draft');

        $reponse = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'scheduled']));

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('examen');
        $examen->refresh();
        $this->assertSame('draft', $examen->statut);
        $this->assertSame(1, ExamenEvenement::where('examen_id', $examen->id)->count(), 'Aucun événement de transition ne doit être écrit pour un refus.');
    }

    public function test_ex_lc_011_transition_répétée_produit_aucun_double_effet(): void
    {
        $examen = $this->creerExamen('planned');

        $this->post(route('admin.evaluations.examens.transiter', [$examen, 'scheduled']))->assertRedirect();
        $repetition = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'scheduled']));

        $repetition->assertRedirect();
        $repetition->assertSessionHasErrors('examen');

        $examen->refresh();
        $this->assertSame('scheduled', $examen->statut);
        $this->assertSame(1, ExamenEvenement::where('examen_id', $examen->id)->count(), 'Une seule transition enregistrée : la répétition est refusée, sans double effet ni événement additionnel.');
    }

    public function test_ex_lc_012_transition_directe_vers_published_interdite_en_un_clic(): void
    {
        $examen = $this->creerExamen('scheduled');

        $reponse = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'published']));

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('examen');

        $examen->refresh();
        $this->assertSame('scheduled', $examen->statut, 'La publication officielle exige le circuit de gouvernance.');
    }

    public function test_ex_lc_013_depuis_etat_terminal_archived_aucune_transition_possible(): void
    {
        $examen = $this->creerExamen('archived');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('verrouill');

        app(ExamenService::class)->transiter($examen, 'draft');
    }

    public function test_ex_lc_018_appel_direct_du_service_applique_les_mêmes_règles(): void
    {
        $examen = $this->creerExamen('draft');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('interdite');

        app(ExamenService::class)->transiter($examen, 'published');
    }

    /* ── Sécurité : autorisation et périmètre ──────────────────── */

    public function test_ex_lc_016_transition_sans_permission_refusée(): void
    {
        $examen = $this->creerExamen('draft');

        // consultation : lecture seule, pas evaluations.update
        $this->scolarite('consultation');

        $reponse = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'planned']));
        $reponse->assertForbidden();

        $examen->refresh();
        $this->assertSame('draft', $examen->statut);
    }

    public function test_ex_lc_017_transition_avec_permission_mais_hors_périmètre_refusée(): void
    {
        $examen = $this->creerExamen('draft', $this->faculteB);

        $utilisateurA = User::creerCompteBackOffice([
            'name' => 'Admin Fac A',
            'email' => 'fac.a.'.uniqid('', true).'@scolarite.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($utilisateurA);

        // IDOR : examen de la faculté B ciblé par un acteur de la faculté A.
        $reponse = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'planned']));
        $reponse->assertForbidden();

        $examen->refresh();
        $this->assertSame('draft', $examen->statut);
    }

    public function test_ex_lc_007_consultation_hors_périmètre_refusée_sur_prévisualisation(): void
    {
        $examen = $this->creerExamen('draft', $this->faculteB);

        $utilisateurA = User::creerCompteBackOffice([
            'name' => 'Admin Fac A 2',
            'email' => 'fac.a2.'.uniqid('', true).'@scolarite.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($utilisateurA);

        // La Policy `view` refuse d'abord (permission + périmètre) : 403.
        $this->get(route('admin.evaluations.examens.transitions', $examen))
            ->assertForbidden();
    }

    /* ── H7 · Concurrence, idempotence ─────────────────────────── */

    public function test_ex_lc_045_deux_transitions_succesives_depuis_le_même_etat_une_seule_est_appliquée(): void
    {
        $examen = $this->creerExamen('draft');
        $service = app(ExamenService::class);
        $acteur = User::where('role', 'super_admin')->firstOrFail();

        $service->transiter($examen, 'planned', $acteur->id);

        // Rejeu strict de la même demande : l'état a changé, la décision
        // est recalculée dans la transaction → refus, aucun double effet.
        $this->expectException(DomainException::class);
        $service->transiter($examen->refresh(), 'planned', $acteur->id);
    }

    public function test_ex_lc_047_rejeu_d_une_ancienne_requête_refusé_sans_historique_additionnel(): void
    {
        $examen = $this->creerExamen('draft');
        $acteur = User::where('role', 'super_admin')->firstOrFail();
        $service = app(ExamenService::class);

        $service->transiter($examen, 'cancelled', $acteur->id, 'Annulation de test');
        $evenementsAvantRejeu = ExamenEvenement::where('examen_id', $examen->id)->count();

        try {
            $service->transiter($examen->refresh(), 'cancelled', $acteur->id, 'Rejeu');
            $this->fail('Le rejeu devait être refusé.');
        } catch (DomainException) {
            // Refus attendu : la matrice n'admet pas cancelled → cancelled.
        }

        $this->assertSame($evenementsAvantRejeu, ExamenEvenement::where('examen_id', $examen->id)->count(), 'Aucun événement ne doit être écrit lors d\'un rejeu refusé.');
    }

    /* ── H8 · Audit et historique ──────────────────────────────── */

    public function test_ex_lc_051_plusieurs_transitions_laissent_un_historique_ordonné_et_complet(): void
    {
        $examen = $this->creerExamen('draft');
        $acteur = User::where('role', 'super_admin')->firstOrFail();
        $service = app(ExamenService::class);

        $service->transiter($examen, 'planned', $acteur->id);
        $service->transiter($examen, 'cancelled', $acteur->id, 'Réorganisation');
        $service->transiter($examen->refresh(), 'archived', $acteur->id);

        $evenements = ExamenEvenement::where('examen_id', $examen->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(['creation', 'transition_effectuee', 'transition_effectuee', 'transition_effectuee'], $evenements->pluck('action')->all());
        $this->assertSame(['draft', 'planned', 'cancelled', 'archived'], $evenements->pluck('statut_apres')->all());
        $this->assertSame('Réorganisation', $evenements[2]->motif);
    }

    public function test_ex_lc_055_le_journal_est_append_only_modification_et_suppression_refusées(): void
    {
        $examen = $this->creerExamen('draft');
        app(ExamenService::class)->transiter($examen, 'planned');

        $evenement = ExamenEvenement::where('examen_id', $examen->id)
            ->where('action', ExamenEvenement::ACTION_TRANSITION)
            ->firstOrFail();

        $this->expectException(DomainException::class);
        $evenement->update(['statut_apres' => 'archived']);
    }

    /* ── D7/G3 · Prévisualisation et explicabilité ─────────────── */

    public function test_ex_lc_prev_endpoint_liste_les_transitions_disponibles_et_explique_les_blocages(): void
    {
        $this->scolarite('admin_scolarite');
        $examen = $this->creerExamen('scheduled', $this->faculteA);

        $reponse = $this->getJson(route('admin.evaluations.examens.transitions', [
            'examen' => $examen->id,
            'cible' => 'published',
        ]));

        $reponse->assertOk();
        $corps = $reponse->json();

        $this->assertSame('scheduled', $corps['statut_courant']);
        $this->assertSame('published', $corps['previsualisation']['cible']);

        // La publication directe n'est jamais présentée comme possible :
        // elle exige le circuit de gouvernance.
        $this->assertStringContainsString(
            'circuit de gouvernance',
            implode(' ', $corps['previsualisation']['conditions_satisfaites'])
        );

        $cibles = array_column($corps['disponibles'], 'cible');
        $this->assertContains('published', $cibles);
        $this->assertContains('cancelled', $cibles);
    }

    public function test_ex_lc_prev_etat_critique_n_expose_que_l_archivage_comme_possible(): void
    {
        $this->scolarite('admin_scolarite');
        $examen = $this->creerExamen('completed', $this->faculteA);

        $reponse = $this->getJson(route('admin.evaluations.examens.transitions', $examen));

        $reponse->assertOk();
        $corps = $reponse->json();

        $acceptables = array_column(array_filter($corps['disponibles'], fn ($t) => $t['acceptable']), 'cible');
        $this->assertSame(['archived'], $acceptables, 'Un examen critique ne peut atteindre que archived (garde estCritique).');

        $locked = array_column(array_filter($corps['disponibles'], fn ($t) => $t['cible'] === 'locked'), 'bloquages');
        $this->assertNotEmpty($locked[0], 'La prévisualisation doit expliquer le blocage.');
    }

    /* ── Helpers ───────────────────────────────────────────────── */

    private function creerExamen(string $statut, ?Faculte $faculte = null): Examen
    {
        $this->scolarite('super_admin');

        $programme = null;

        if ($faculte !== null) {
            $programme = Programme::create([
                'faculte_id' => $faculte->id,
                'code' => 'PRG-'.substr(md5(uniqid('', true)), 0, 6),
                'nom' => 'Programme '.$faculte->code,
                'cycle' => 'licence',
                'active' => true,
            ]);
        }

        $examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme?->id,
            'libelle' => 'Examen LC '.substr(md5(uniqid('', true)), 0, 6),
            'type' => 'examen',
            'statut' => $statut,
        ]);

        if ($statut === 'draft') {
            // Le journal de création n'est écrit que par le contrôleur ;
            // pour un fixture direct on aligne l'historique.
            ExamenEvenement::query()->create([
                'examen_id' => $examen->id,
                'action' => ExamenEvenement::ACTION_CREATION,
                'acteur_id' => auth()->id(),
                'statut_avant' => 'draft',
                'statut_apres' => 'draft',
                'created_at' => now(),
            ]);
        }

        return $examen;
    }
}
