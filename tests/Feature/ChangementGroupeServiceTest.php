<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\ChangementGroupeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP B (§9) — Changement de groupe : opération métier DISTINCTE de
 * l'affectation initiale, construite sur les primitives prouvées
 * (`AffectationService::affecter/retirer`, verrous ordonnés). Garanties :
 * idempotence (demande identique répétée = refus explicite, aucun doublon
 * d'historique), historique conservé (ancienne clôturée JAMAIS supprimée),
 * notification après commit, audit sans PII, simulation sans écriture.
 */
class ChangementGroupeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Group $source;

    private Group $cible;

    private Etudiant $etudiant;

    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentId = User::creerCompteBackOffice([
            'name' => 'Scolarité CG', 'email' => 'sco.cg@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite')->id;

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL-CG', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);

        $this->source = Group::create([
            'code' => 'CG-SRC', 'nom' => 'Source', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $niveau->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $this->cible = Group::create([
            'code' => 'CG-DST', 'nom' => 'Cible', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $niveau->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        $this->etudiant = $this->creerEtudiant('20000001', $niveau);
        app(AffectationService::class)->affecter($this->etudiant, $this->source, $this->annee, null, 'Fixture initiale');
        $this->etudiant = $this->etudiant->fresh();
    }

    public function test_changement_autorise_transfert_complet_et_historique(): void
    {
        $resultat = app(ChangementGroupeService::class)->changer(
            $this->etudiant->fresh(), $this->cible, $this->annee, $this->agentId, 'Rééquilibrage pédagogique demandé.'
        );

        $this->assertTrue($resultat['effectue']);

        // Nouvelle affectation active vers la cible, ancienne clôturée conservée.
        $this->assertSame($this->cible->id, $this->etudiant->fresh()->groupe_id);
        $active = $this->affectationActive();
        $this->assertSame($this->cible->id, $active->groupe_id);

        $anciennes = DB::table('affectations_groupe')
            ->where('etudiant_id', $this->etudiant->id)->where('statut', 'cloturee')->get();
        $this->assertCount(1, $anciennes, 'L’ancienne affectation est clôturée, jamais supprimée.');
        $this->assertSame($this->source->id, (int) $anciennes->first()->groupe_id);
    }

    public function test_demande_identique_repetee_refusee_sans_doublon(): void
    {
        app(ChangementGroupeService::class)->changer($this->etudiant->fresh(), $this->cible, $this->annee, $this->agentId, 'Premier changement.');

        // Double soumission : la cible est déjà le groupe courant.
        $resultat = app(ChangementGroupeService::class)->changer(
            $this->etudiant->fresh(), $this->cible, $this->annee, $this->agentId, 'Double soumission.'
        );

        $this->assertFalse($resultat['effectue']);
        $this->assertStringContainsString('déjà affecté', $resultat['raison']);
        // Aucun doublon : une seule affectation active, historique non dupliqué.
        $this->assertCount(1, DB::table('affectations_groupe')->where('etudiant_id', $this->etudiant->id)->where('statut', 'active')->get());
        $this->assertSame(2, DB::table('affectations_groupe')->where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_etudiant_sans_affectation_active_refuse(): void
    {
        $etudiant = $this->creerEtudiant('20000002');

        $resultat = app(ChangementGroupeService::class)->changer($etudiant, $this->cible, $this->annee, null, 'Sans affectation.');
        $this->assertFalse($resultat['effectue']);
        $this->assertStringContainsString('aucune affectation active', mb_strtolower($resultat['raison']));
    }

    public function test_destination_incompatible_niveau_refusee_sans_etat_modifie(): void
    {
        $niveauL2 = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2]);
        $groupeL2 = Group::create([
            'code' => 'CG-L2', 'nom' => 'Groupe L2', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $niveauL2->id, 'niveau' => 'L2', 'actif' => true,
        ]);

        $resultat = app(ChangementGroupeService::class)->changer($this->etudiant->fresh(), $groupeL2, $this->annee, $this->agentId, 'Niveau incompatible.');

        $this->assertFalse($resultat['effectue']);
        // Jamais de changement d'état sur refus (L1 dans un groupe L2).
        $this->assertSame($this->source->id, $this->etudiant->fresh()->groupe_id);
        $this->assertSame($this->source->id, $this->affectationActive()->groupe_id);
    }

    public function test_destination_inactive_refusee(): void
    {
        $this->cible->update(['actif' => false]);

        $resultat = app(ChangementGroupeService::class)->changer($this->etudiant->fresh(), $this->cible->fresh(), $this->annee, $this->agentId, 'Groupe inactif.');
        $this->assertFalse($resultat['effectue']);
        $this->assertSame($this->source->id, $this->etudiant->fresh()->groupe_id);
    }

    public function test_destination_sans_capacite_refusee_etat_intact(): void
    {
        $this->cible->update(['capacite' => 0]);

        $resultat = app(ChangementGroupeService::class)->changer($this->etudiant->fresh(), $this->cible->fresh(), $this->annee, $this->agentId, 'Capacité nulle.');
        $this->assertFalse($resultat['effectue']);
        $this->assertStringContainsString('GROUP_FULL', $resultat['raison']);
        $this->assertSame($this->source->id, $this->etudiant->fresh()->groupe_id);
        // Refus = état intact : l'affectation d'origine seule, aucune trace parasite.
        $this->assertSame(1, DB::table('affectations_groupe')->where('etudiant_id', $this->etudiant->id)->count(), 'Aucune écriture parasite (échec = état intact).');
    }

    public function test_simulation_sans_ecriture_et_impact_capacites(): void
    {
        $avant = DB::table('affectations_groupe')->where('etudiant_id', $this->etudiant->id)->count();

        $simulation = app(ChangementGroupeService::class)->simuler($this->etudiant->fresh(), $this->cible, $this->annee);

        $this->assertTrue($simulation['possible']);
        $this->assertSame($avant, DB::table('affectations_groupe')->where('etudiant_id', $this->etudiant->id)->count(), 'Simulation = zéro écriture.');
        $this->assertSame($this->source->id, $this->etudiant->fresh()->groupe_id);
        $this->assertArrayHasKey('impact_capacite', $simulation);
        $this->assertSame($this->source->id, $simulation['impact_capacite']['source']['groupe_id']);
        $this->assertSame($this->cible->id, $simulation['impact_capacite']['cible']['groupe_id']);
    }

    public function test_simulation_bloquee_rapporte_les_raisons(): void
    {
        $this->cible->update(['actif' => false]);

        $simulation = app(ChangementGroupeService::class)->simuler($this->etudiant->fresh(), $this->cible->fresh(), $this->annee);

        $this->assertFalse($simulation['possible']);
        $this->assertNotEmpty($simulation['raisons']);
        $this->assertSame($this->source->id, $this->etudiant->fresh()->groupe_id);
    }

    public function test_changement_http_autorise_par_admin_faculte(): void
    {
        $scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité HTTP', 'email' => 'sco.http.cg@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $reponse = $this->actingAs($scolarite)->post(
            route('admin.etudiants.affectations.changer', $this->etudiant),
            ['groupe_id' => $this->cible->id, 'annee_universitaire_id' => $this->annee->id, 'motif' => 'Décision administrative testée.']
        );
        $reponse->assertRedirect();

        $this->assertSame($this->cible->id, $this->etudiant->fresh()->groupe_id);
    }

    public function test_changement_http_cross_faculte_refuse_sans_mutation(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $adminEtranger = User::creerCompteBackOffice([
            'name' => 'Admin autre', 'email' => 'admin.autre.cg@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_faculte', $autreFaculte->id);

        $this->actingAs($adminEtranger)->post(
            route('admin.etudiants.affectations.changer', $this->etudiant),
            ['groupe_id' => $this->cible->id, 'annee_universitaire_id' => $this->annee->id, 'motif' => 'Tentative hors périmètre.']
        )->assertForbidden();

        // Rien n'a bougé : ni le groupe, ni l'historique.
        $this->assertSame($this->source->id, $this->etudiant->fresh()->groupe_id);
        $this->assertSame(1, DB::table('affectations_groupe')->where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_changement_http_sans_permission_refuse(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur', 'email' => 'lecteur.cg@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->post(
            route('admin.etudiants.affectations.changer', $this->etudiant),
            ['groupe_id' => $this->cible->id, 'annee_universitaire_id' => $this->annee->id, 'motif' => 'Sans permission.']
        )->assertForbidden();

        $this->assertSame($this->source->id, $this->etudiant->fresh()->groupe_id);
    }

    public function test_changement_notifie_apres_commit_et_trace_sans_pii(): void
    {
        $resultat = app(ChangementGroupeService::class)->changer(
            $this->etudiant->fresh(), $this->cible, $this->annee, $this->agentId, 'Motif officiel.'
        );

        $this->assertTrue($resultat['effectue']);

        // Notification après commit, dédupliquée par affectation, sans PII.
        $notifications = DB::table('notifications')
            ->where('etudiant_id', $this->etudiant->id)
            ->where('type', 'affectation.changee')->get();
        $this->assertNotEmpty($notifications, 'Un changement notifie l’étudiant (après commit).');
        $contenu = $notifications->implode('message', ' ');
        $this->assertStringNotContainsString((string) $this->etudiant->cin, $contenu);

        // Trace d'audit attribuable, sans PII.
        $trace = DB::table('historique_academique')
            ->where('etudiant_id', $this->etudiant->id)->where('action', 'affectation.changee')->first();
        $this->assertNotNull($trace, 'Le changement est tracé dans l’historique académique.');
        $detailsAudit = ((string) $trace->ancienne_valeur).' '.((string) $trace->nouvelle_valeur);
        $this->assertStringNotContainsString((string) $this->etudiant->cin, $detailsAudit);
        $this->assertSame($this->source->id, json_decode((string) $trace->ancienne_valeur, true)['groupe_source_id'] ?? null);
        $this->assertSame($this->cible->id, json_decode((string) $trace->nouvelle_valeur, true)['groupe_cible_id'] ?? null);
    }

    private function affectationActive(): object
    {
        $affectation = DB::table('affectations_groupe')
            ->where('etudiant_id', $this->etudiant->id)->where('statut', 'active')->first();
        $this->assertNotNull($affectation);

        return $affectation;
    }

    private function creerEtudiant(string $cin, ?Niveau $niveau = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Trabelsi', 'prenom' => 'Mounir', 'email' => mb_strtolower($cin).'@cg.test',
            'date_naissance' => '2004-05-05', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        if ($niveau !== null) {
            $etudiant->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $this->annee->id,
                'programme_id' => $this->programme->id, 'niveau_id' => $niveau->id,
                'niveau' => 'L1', 'type' => 'premiere', 'statut' => 'valide',
            ]);
        }

        return $etudiant->fresh();
    }
}
