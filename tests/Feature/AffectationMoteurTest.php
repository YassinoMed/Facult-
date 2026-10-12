<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\DataExchange\Definitions\AffectationGroupeDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Moteur d'affectation : contraintes unitaires, idempotence,
 * capacité anti-concurrence, bulk, import, sécurité, E2E.
 */
class AffectationMoteurTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.aff@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'GA', 'nom' => 'Groupe A', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    public function test_sans_inscription_bloque_avec_code(): void
    {
        $etudiant = $this->creerEtudiant(sansInscription: true);

        $contraintes = app(AffectationService::class)->controler($etudiant, $this->groupe, $this->annee);
        $codes = array_column($contraintes, 'code');

        $this->assertContains('REGISTRATION_NOT_FOUND', $codes);
        $this->assertTrue(collect($contraintes)->firstWhere('code', 'REGISTRATION_NOT_FOUND')['bloquant']);

        $this->expectException(\DomainException::class);
        app(AffectationService::class)->affecter($etudiant, $this->groupe, $this->annee, $this->admin->id, 'x');
    }

    public function test_programme_niveau_inactif_bloquants(): void
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LP1', 'nom' => 'L1',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $etudiant = $this->creerEtudiant('AFFP001', false, ['programme_id' => $programme->id, 'niveau' => 'L1']);

        // Programme différent via second programme.
        $programme2 = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LP2', 'nom' => 'L2',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->groupe->update(['programme_id' => $programme2->id, 'niveau' => 'L2']);

        $codes = array_column(app(AffectationService::class)->controler($etudiant, $this->groupe->fresh(), $this->annee), 'code');

        $this->assertContains('PROGRAM_MISMATCH', $codes);
        $this->assertContains('LEVEL_MISMATCH', $codes);

        $this->groupe->update(['actif' => false]);
        $codes = array_column(app(AffectationService::class)->controler($etudiant, $this->groupe->fresh(), $this->annee), 'code');
        $this->assertContains('GROUP_INACTIVE', $codes);
    }

    public function test_idempotence_meme_groupe(): void
    {
        $etudiant = $this->creerEtudiant();

        $service = app(AffectationService::class);
        $premiere = $service->affecter($etudiant, $this->groupe, $this->annee, $this->admin->id, 'initiale');
        $seconde = $service->affecter($etudiant, $this->groupe, $this->annee, $this->admin->id, 'bis');

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_capacite_limite_et_override_garde(): void
    {
        $this->groupe->update(['capacite' => 1]);
        $premier = $this->creerEtudiant('CAP00001');
        $second = $this->creerEtudiant('CAP00002');

        app(AffectationService::class)->affecter($premier, $this->groupe, $this->annee, $this->admin->id, 'a');

        // Second : bloqué, message explicite avec capacité + action.
        try {
            app(AffectationService::class)->affecter($second, $this->groupe, $this->annee, $this->admin->id, 'b');
            $this->fail('Capacité aurait dû bloquer.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('GROUP_FULL', $e->getMessage());
            $this->assertStringContainsString('1/1', $e->getMessage());
            $this->assertStringContainsString('groupes.manage', $e->getMessage());
        }

        // Override sans permission : 403.
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.aff@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->expectException(HttpException::class);
        app(AffectationService::class)->affecter($second, $this->groupe, $this->annee, $agent->id, 'force', true);
    }

    public function test_override_autorise_audite(): void
    {
        $this->groupe->update(['capacite' => 1]);
        $premier = $this->creerEtudiant('CAP00003');
        $second = $this->creerEtudiant('CAP00004');

        app(AffectationService::class)->affecter($premier, $this->groupe, $this->annee, $this->admin->id, 'a');
        $forcee = app(AffectationService::class)->affecter($second, $this->groupe, $this->annee, $this->admin->id, 'décision direction', true);

        $this->assertStringStartsWith('[FORCÉ]', $forcee->motif);
        $this->assertSame(2, $this->groupe->effectifActuel());
    }

    public function test_sous_groupe_rattache_ou_refuse(): void
    {
        $etudiant = $this->creerEtudiant();
        $sousGroupe = SousGroupe::create([
            'groupe_id' => $this->groupe->id, 'code' => 'GA-TD1', 'nom' => 'TD1', 'type' => 'td', 'actif' => true,
        ]);
        $autreGroupe = Group::create([
            'code' => 'GB', 'nom' => 'GB', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        $affectation = app(AffectationService::class)->affecter(
            $etudiant, $this->groupe, $this->annee, $this->admin->id, 'avec TD', false, $sousGroupe->id
        );
        $this->assertSame($sousGroupe->id, (int) $affectation->sous_groupe_id);

        $codes = array_column(
            app(AffectationService::class)->controler($etudiant, $autreGroupe, $this->annee, false, $sousGroupe->id),
            'code'
        );
        $this->assertContains('INVALID_SUBGROUP', $codes);
    }

    public function test_retrait_historise_sans_supprimer(): void
    {
        $etudiant = $this->creerEtudiant();
        $affectation = app(AffectationService::class)->affecter($etudiant, $this->groupe, $this->annee, $this->admin->id, 'a');

        app(AffectationService::class)->retirer($affectation, $this->admin->id, 'abandon');

        $this->assertSame('cloturee', $affectation->fresh()->statut);
        $this->assertNull($etudiant->fresh()->groupe_id);
        $this->assertDatabaseHas('affectations_groupe', ['id' => $affectation->id]);
        $this->assertDatabaseHas('situations_etudiant', ['etudiant_id' => $etudiant->id, 'type' => 'groupe']);
    }

    public function test_bulk_simulation_puis_application(): void
    {
        $ok = $this->creerEtudiant('BLK00001');
        $sansInscription = $this->creerEtudiant('BLK00002', sansInscription: true);

        $service = app(AffectationService::class);
        $simulation = $service->simuler([$ok->id, $sansInscription->id, 999999], $this->groupe, $this->annee);

        $this->assertSame([$ok->id], $simulation['proposees']);
        $this->assertArrayHasKey($sansInscription->id, $simulation['bloquees']);

        $resultat = $service->affecterLot([$ok->id, $sansInscription->id], $this->groupe, $this->annee, $this->admin->id, 'rentrée');

        $this->assertSame(1, $resultat['appliquees']);
        $this->assertSame($this->groupe->id, (int) $ok->fresh()->groupe_id);
        $this->assertDatabaseHas('notifications', ['etudiant_id' => $ok->id, 'type' => 'affectation.groupe']);
    }

    public function test_previsualiser_affiche_contraintes_et_impacts(): void
    {
        $etudiant = $this->creerEtudiant();

        $this->actingAs($this->admin)->get(route('admin.etudiants.affectations.previsualiser', [$etudiant, 'groupe_id' => $this->groupe->id, 'annee_universitaire_id' => $this->annee->id]))->assertOk();
    }

    public function test_simuler_affiche_pistes_correctives(): void
    {
        $sansInscription = $this->creerEtudiant('PST00001', sansInscription: true);

        $contenu = (string) $this->actingAs($this->admin)->post(route('admin.affectations.simuler'), [
            'groupe_id' => $this->groupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'etudiant_ids_text' => (string) $sansInscription->id,
        ])->assertOk()->getContent();

        // Piste : réinscrire d'abord + groupes compatibles, sans changer les règles.
        $this->assertStringContainsString('(ré)inscrire', $contenu);
        $this->assertStringContainsString($this->groupe->code, $contenu);
    }

    public function test_repartition_equilibree_groupes_capacite_illimitee(): void
    {
        // Non-régression : deux groupes sans capacité ne doivent pas voir
        // le premier absorber toute la répartition (tri multi-critères).
        $g1 = Group::create([
            'code' => 'UL1-A', 'nom' => 'UL1-A', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => null, 'actif' => true,
        ]);
        $g2 = Group::create([
            'code' => 'UL1-B', 'nom' => 'UL1-B', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => null, 'actif' => true,
        ]);
        $ids = [];

        for ($i = 1; $i <= 10; $i++) {
            $ids[] = $this->creerEtudiant('UL1'.str_pad((string) $i, 5, '0', STR_PAD_LEFT))->id;
        }

        $repartition = app(AffectationService::class)
            ->repartitionAutomatique($ids, [$g1->id, $g2->id], $this->annee)['repartition'];

        $this->assertSame([5, 5], [count($repartition[$g1->id]), count($repartition[$g2->id])]);
    }

    public function test_simuler_auto_accepte_plus_de_50_groupes(): void
    {        // Plafond 500 (moteur mesuré OK à 162 groupes) : 61 groupes OK.
        $groupes = [$this->groupe];

        for ($i = 1; $i <= 60; $i++) {
            $groupes[] = Group::create([
                'code' => 'GX'.$i, 'nom' => 'Groupe X'.$i, 'faculte_id' => $this->faculte->id,
                'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
            ]);
        }

        $etudiant = $this->creerEtudiant('PST00004');

        $this->actingAs($this->admin)->post(route('admin.affectations.auto.simuler'), [
            'groupe_ids' => array_map(fn (Group $g) => $g->id, $groupes),
            'annee_universitaire_id' => $this->annee->id,
            'etudiant_ids_text' => (string) $etudiant->id,
        ])->assertOk();
    }

    public function test_simuler_auto_affiche_pistes_correctives(): void
    {
        $sansInscription = $this->creerEtudiant('PST00002', sansInscription: true);

        // Zéro éligible : pas d'algorithme, retour explicite (§22).
        $this->actingAs($this->admin)->post(route('admin.affectations.auto.simuler'), [
            'groupe_ids' => [$this->groupe->id],
            'annee_universitaire_id' => $this->annee->id,
            'etudiant_ids_text' => (string) $sansInscription->id,
        ])->assertRedirect()
            ->assertSessionHasErrors('simulation');

        // Avec un éligible : page résultat + pistes.
        $ok = $this->creerEtudiant('PST00003');
        $contenu = (string) $this->actingAs($this->admin)->post(route('admin.affectations.auto.simuler'), [
            'groupe_ids' => [$this->groupe->id],
            'annee_universitaire_id' => $this->annee->id,
            'etudiant_ids_text' => "{$ok->id}\n{$sansInscription->id}",
        ])->assertOk()->getContent();

        $this->assertStringContainsString('Pré-check', $contenu);
        $this->assertStringContainsString('(ré)inscrire', $contenu);
    }

    public function test_idor_inter_facultes(): void
    {
        $etudiant = $this->creerEtudiant();
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.aff@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        // Groupe d'une autre faculté : refusé (convention existante : 403).
        $this->actingAs($adminFaculte)->post(route('admin.etudiants.affectations.store', $etudiant), [
            'groupe_id' => $this->groupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'x',
        ])->assertForbidden();

        // Étudiant d'une autre faculté : refusé même avec son propre groupe.
        $groupeFmp = Group::create([
            'code' => 'GFMP', 'nom' => 'GFMP', 'faculte_id' => $autreFaculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $this->actingAs($adminFaculte)->post(route('admin.etudiants.affectations.store', $etudiant), [
            'groupe_id' => $groupeFmp->id,
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'x',
        ])->assertForbidden();
        $this->assertNull($etudiant->fresh()->groupe_id);
    }

    public function test_e2e_transfert_conserve_tout(): void
    {
        $etudiant = $this->creerEtudiant();
        $groupeB = Group::create([
            'code' => 'GB2', 'nom' => 'GB2', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);

        $service = app(AffectationService::class);
        $service->affecter($etudiant, $this->groupe, $this->annee, $this->admin->id, 'initiale');

        // Note liée au contexte d'origine.
        $inscription = $etudiant->inscriptionsAcademiques()->first();
        $semestre = $inscription->semestres()->create(['numero' => 1]);
        $matiere = Matiere::create([
            'code' => 'E2E01', 'nom' => 'E2E', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
            'note' => 14, 'coefficient' => 1, 'credits' => 4, 'session' => 'normale',
        ]);

        $service->affecter($etudiant, $groupeB, $this->annee, $this->admin->id, 'rééquilibrage');

        // Historique : ancienne clôturée, nouvelle active, pointeur à jour.
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->where('statut', 'cloturee')->count());
        $this->assertSame($groupeB->id, (int) $etudiant->fresh()->groupe_id);
        // Notes conservées, audit présent, notification envoyée.
        $this->assertSame(1, Note::where('etudiant_id', $etudiant->id)->count());
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id, 'type' => 'groupe', 'nouvelle_valeur' => 'GB2',
        ]);
        $this->assertDatabaseHas('notifications', ['etudiant_id' => $etudiant->id, 'type' => 'affectation.groupe']);
    }

    public function test_import_definition_resout_et_persiste(): void
    {
        $etudiant = $this->creerEtudiant('IMP00001');
        $definition = app(AffectationGroupeDefinition::class);

        $this->assertSame('affectations-groupes', $definition->entite());
        $this->assertNull($definition->trouverExistant([
            'cin' => 'IMP00001', 'code_groupe' => 'GA', 'annee' => '2026-2027',
        ]));

        $message = $definition->persister([
            'cin' => 'IMP00001', 'code_groupe' => 'GA', 'annee' => '2026-2027',
        ], 'upsert', $this->admin->id);

        $this->assertStringContainsString('affecté', $message);
        $this->assertSame($this->groupe->id, (int) $etudiant->fresh()->groupe_id);

        // Idempotence fichier : seconde passe = déjà affecté.
        $message = $definition->persister([
            'cin' => 'IMP00001', 'code_groupe' => 'GA', 'annee' => '2026-2027',
        ], 'upsert', $this->admin->id);
        $this->assertStringContainsString('déjà affecté', $message);
    }

    private function creerEtudiant(string $cin = 'AFF00001', bool $sansInscription = false, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@aff.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $attributs));
        $etudiant->definirStatut('valide');

        if (! $sansInscription) {
            $etudiant->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
            ]);
        }

        return $etudiant;
    }

    public function test_affecter_lot_notifie_en_lot_sans_insert_par_etudiant(): void
    {
        $ids = [];
        for ($i = 1; $i <= 8; $i++) {
            $ids[] = $this->creerEtudiant('LOT'.str_pad((string) $i, 5, '0', STR_PAD_LEFT))->id;
        }

        $insertsNotifications = 0;
        DB::listen(function ($requete) use (&$insertsNotifications) {
            if (str_starts_with(strtolower($requete->sql), 'insert into') && str_contains($requete->sql, 'notifications')) {
                $insertsNotifications++;
            }
        });

        $resultat = app(AffectationService::class)->affecterLot(
            $ids, $this->groupe, $this->annee, $this->admin->id, 'rentrée'
        );

        $this->assertSame(8, $resultat['appliquees']);
        $this->assertSame(8, AppNotification::where('type', 'affectation.groupe')->count());
        $this->assertLessThanOrEqual(
            2, $insertsNotifications,
            "N+1 notifications : {$insertsNotifications} INSERT pour 8 étudiants (attendu : batch unique)."
        );
    }

    public function test_appliquer_json_echappe_max_input_vars(): void
    {
        // 1 500 ids via UN SEUL champ JSON (au-delà de max_input_vars).
        $ids = [];

        for ($i = 1; $i <= 60; $i++) {
            $ids[] = $this->creerEtudiant('JSN'.str_pad((string) $i, 5, '0', STR_PAD_LEFT))->id;
        }

        // Capacité 30 : 30 appliquées, 30 ignorées (capacité), fin → redirect.
        $this->actingAs($this->admin)->post(route('admin.affectations.auto.appliquer'), [
            'annee_universitaire_id' => $this->annee->id,
            'repartition_json' => json_encode([$this->groupe->id => $ids]),
            'motif' => 'Rentrée',
            'confirmer' => '1',
        ])->assertRedirect(route('admin.affectations.auto'));

        $this->assertSame(30, AffectationGroupe::where('groupe_id', $this->groupe->id)->where('statut', 'active')->count());
    }

    public function test_appliquer_json_malforme_422(): void
    {
        $this->actingAs($this->admin)->post(route('admin.affectations.auto.appliquer'), [
            'annee_universitaire_id' => $this->annee->id,
            'repartition_json' => '{invalide',
            'motif' => 'Rentrée',
            'confirmer' => '1',
        ])->assertStatus(422);
    }

    public function test_appliquer_tranche_reaffiche_reliquat(): void
    {
        // 510 ids, tranche 500 : 500 traités, reliquat réaffiché en vue.
        $this->groupe->update(['capacite' => 1000]);
        $ids = [];

        for ($i = 1; $i <= 510; $i++) {
            $ids[] = $this->creerEtudiant('TRN'.str_pad((string) $i, 5, '0', STR_PAD_LEFT))->id;
        }

        $contenu = (string) $this->actingAs($this->admin)->post(route('admin.affectations.auto.appliquer'), [
            'annee_universitaire_id' => $this->annee->id,
            'repartition_json' => json_encode([$this->groupe->id => $ids]),
            'motif' => 'Rentrée',
            'confirmer' => '1',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('Tranche appliquée', $contenu);
        $this->assertSame(500, AffectationGroupe::where('groupe_id', $this->groupe->id)->where('statut', 'active')->count());
    }
}
