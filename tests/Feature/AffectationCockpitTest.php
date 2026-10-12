<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\MassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cockpit intelligent /admin/affectations/masse (MASTER LOOP) :
 * explicabilité centralisée (score/raisons/alternatives), centre
 * d'exceptions par catégories, recherche serveur, export CSV,
 * rapport post-opération, cas sûrs. Zéro second moteur.
 */
class AffectationCockpitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Group $groupe1;

    private Group $groupe2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
            'description' => 'Médecine',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.cockpit@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->groupe1 = Group::create([
            'code' => 'L1-INFO-G1',
            'nom' => 'Groupe 1 Informatique L1',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->groupe2 = Group::create([
            'code' => 'L1-INFO-G2',
            'nom' => 'Groupe 2 Informatique L1',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin, string $nom = 'Ben Ali', string $prenom = 'Mohamed', array $extra = []): Etudiant
    {
        static $compteur = 500;
        $compteur++;

        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin,
            'num_inscription' => 'MAT-'.$compteur,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => "cockpit{$compteur}@universite.test",
            'date_naissance' => '2004-01-15',
            'sexe' => 'M',
            'telephone' => '98000'.$compteur,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => $this->annee->libelle,
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ], $extra));

        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        return $etudiant;
    }

    private function analyser(array $criteres = []): array
    {
        return app(MassAssignmentService::class)->analyser(array_merge([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
        ], $criteres), $this->admin);
    }

    public function test_analyse_enrichie_score_certitude_raisons_alternatives(): void
    {
        // Groupe unique : décision sans ambiguïté → cas sûr.
        $this->groupe2->delete();
        $etudiant = $this->creerEtudiant('11111111');

        $analyse = $this->analyser(['etudiant_ids' => [$etudiant->id]]);

        $this->assertSame(1, $analyse['assigned_count']);
        $proposition = $analyse['propositions'][0];

        $this->assertArrayHasKey('score', $proposition);
        $this->assertArrayHasKey('certitude', $proposition);
        $this->assertArrayHasKey('reasons', $proposition);
        $this->assertArrayHasKey('scoring_breakdown', $proposition);
        $this->assertArrayHasKey('alternatives', $proposition);
        $this->assertGreaterThanOrEqual(85, $proposition['score']);
        $this->assertSame('elevee', $proposition['certitude']);
        $this->assertNotEmpty($proposition['reasons']);
        $this->assertSame(30, $proposition['scoring_breakdown']['niveau']);
        // Groupe unique : aucune alternative, choix sans ambiguïté.
        $this->assertSame([], $proposition['alternatives']);
    }

    public function test_analyse_classe_les_alternatives_par_score(): void
    {
        $etudiant = $this->creerEtudiant('22222222');

        // Sature le groupe 1 pour forcer le choix du groupe 2.
        for ($i = 0; $i < 20; $i++) {
            $occupant = $this->creerEtudiant('99'.str_pad((string) $i, 6, '0', STR_PAD_LEFT));
            AffectationGroupe::create([
                'etudiant_id' => $occupant->id,
                'groupe_id' => $this->groupe1->id,
                'annee_universitaire_id' => $this->annee->id,
                'statut' => 'active',
                'date_debut' => now(),
            ]);
        }

        $analyse = $this->analyser(['etudiant_ids' => [$etudiant->id]]);
        $proposition = $analyse['propositions'][0];

        $this->assertSame($this->groupe2->id, $proposition['proposed_group_id']);
        $this->assertNotEmpty($proposition['alternatives']);
    }

    public function test_alternatives_compactes_sans_raisons_detaillees(): void
    {
        $etudiant = $this->creerEtudiant('44444444');

        $analyse = $this->analyser(['etudiant_ids' => [$etudiant->id]]);
        $proposition = $analyse['propositions'][0];

        // Contrat de compaction D-005 : décision identique (choix +
        // classement par score), payload minimal (group_id/code/score).
        $this->assertSame($this->groupe1->id, $proposition['proposed_group_id']);
        $this->assertNotEmpty($proposition['alternatives']);
        foreach ($proposition['alternatives'] as $alternative) {
            $this->assertSame(['group_id', 'code', 'score'], array_keys($alternative));
        }
        $scores = array_column($proposition['alternatives'], 'score');
        $tries = $scores;
        rsort($tries);
        $this->assertSame($tries, $scores);
        $this->assertNotEmpty($proposition['reasons']);
    }

    public function test_anomalies_categorisees_avec_synthese(): void
    {
        $sansInscription = $this->creerEtudiant('33333333');
        $sansInscription->inscriptionsAcademiques()->delete();

        $analyse = $this->analyser(['etudiant_ids' => [$sansInscription->id]]);

        $this->assertSame(0, $analyse['assigned_count']);
        $this->assertSame('INSCRIPTION_ABSENTE', $analyse['anomalies'][0]['code']);
        $this->assertSame('Inscription', $analyse['anomalies'][0]['categorie']);
        $this->assertSame(['Inscription' => 1], $analyse['categories']);
    }

    public function test_anomalies_portent_donnees_regularisation_et_action(): void
    {
        $sansInscription = $this->creerEtudiant('33333334');
        $sansInscription->inscriptionsAcademiques()->delete();

        $analyse = $this->analyser(['etudiant_ids' => [$sansInscription->id]]);
        $anomalie = $analyse['anomalies'][0];

        $this->assertSame('33333334', $anomalie['student_cin']);
        $this->assertNotEmpty($anomalie['student_numero']);
        $this->assertSame('L1', $anomalie['niveau']);
        $this->assertArrayHasKey('url_action', $anomalie);
        $this->assertStringContainsString('admin/etudiants/'.$sansInscription->id, $anomalie['url_action']);
    }

    public function test_centre_exceptions_rendu_dans_vue_simulation(): void
    {
        $bloque = $this->creerEtudiant('33333335');
        $bloque->inscriptionsAcademiques()->delete();

        $reponse = $this->actingAs($this->admin)->post(route('admin.affectations.masse.analyser'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'etudiant_ids_text' => (string) $bloque->id,
        ]);

        $reponse->assertOk();
        $reponse->assertSee("Centre d'exceptions académiques", false);
        $reponse->assertSee('Inscription (1)');
        $reponse->assertSee('Régulariser');
    }

    public function test_recherche_serveur_retourne_les_homonymes_sans_choisir(): void
    {
        $this->creerEtudiant('44444444', 'Ben Ali', 'Mohamed');
        $this->creerEtudiant('55555555', 'Ben Ali', 'Mohamed');

        $reponse = $this->actingAs($this->admin)
            ->getJson(route('admin.affectations.masse.recherche', ['q' => 'Ben Ali']));

        $reponse->assertOk();
        $ids = array_column($reponse->json('resultats'), 'id');

        $this->assertSame(2, $reponse->json('total'));
        $this->assertCount(2, $ids);
        // CIN masquée : jamais la CIN complète dans la recherche.
        $this->assertStringNotContainsString('44444444', $reponse->getContent());
    }

    public function test_recherche_serveur_restreinte_a_la_faculte(): void
    {
        $this->creerEtudiant('66666666', 'Secret', 'FaculteB', ['faculte_id' => $this->faculteB->id]);

        $restreint = User::creerCompteBackOffice([
            'name' => 'Admin Faculte',
            'email' => 'admin.faculte@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $reponse = $this->actingAs($restreint)
            ->getJson(route('admin.affectations.masse.recherche', ['q' => 'Secret']));

        $reponse->assertOk();
        $this->assertSame(0, $reponse->json('total'));
    }

    public function test_recherche_serveur_exige_deux_caracteres(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.affectations.masse.recherche', ['q' => 'A']))
            ->assertStatus(422);
    }

    public function test_recherche_serveur_exige_une_authentification(): void
    {
        $this->getJson(route('admin.affectations.masse.recherche', ['q' => 'Ben Ali']))
            ->assertUnauthorized();
    }

    public function test_export_csv_propositions_et_blocages(): void
    {
        $this->creerEtudiant('77777777');
        $bloque = $this->creerEtudiant('88888888');
        $bloque->inscriptionsAcademiques()->delete();

        $reponse = $this->actingAs($this->admin)->post(route('admin.affectations.masse.exporter'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
        ]);

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $contenu = $reponse->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu);
        $this->assertStringContainsString('groupe_propose;score;certitude;statut', $contenu);
        $this->assertStringContainsString(';propose;', $contenu);
        $this->assertStringContainsString(';bloque;', $contenu);
    }

    public function test_export_csv_refuse_sans_permission(): void
    {
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consultation',
            'email' => 'consultation@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($consultation)->post(route('admin.affectations.masse.exporter'), [
            'annee_universitaire_id' => $this->annee->id,
        ])->assertForbidden();
    }

    public function test_confirmation_flashe_le_rapport_post_operation(): void
    {
        $etudiant = $this->creerEtudiant('99999999');

        $reponse = $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Test rapport post-opération',
            'confirmer' => true,
            'selections' => [
                ['student_id' => $etudiant->id, 'groupe_id' => $this->groupe1->id],
            ],
        ]);

        $reponse->assertRedirect(route('admin.affectations.masse'));
        $reponse->assertSessionHas('rapport_masse', fn ($rapport) => $rapport['affectes'] === 1 && $rapport['total'] === 1);
    }

    public function test_propositions_persistees_portent_alternatives_et_compatibles(): void
    {
        $etudiant = $this->creerEtudiant('10101010');

        $rapport = app(MassAssignmentService::class)->creerPropositions([
            [
                'student_id' => $etudiant->id,
                'groupe_id' => $this->groupe1->id,
                'score' => 92,
                'certitude' => 'elevee',
                'reasons' => ['Niveau académique strict conforme (+30 pts)'],
                'alternatives' => [
                    ['group_id' => $this->groupe2->id, 'code' => 'L1-INFO-G2', 'score' => 88, 'reasons' => []],
                ],
                'groupes_compatibles' => [$this->groupe1->id => 'L1-INFO-G1 (0/20)', $this->groupe2->id => 'L1-INFO-G2 (0/20)'],
            ],
        ], $this->annee->id, $this->admin);

        $this->assertSame(1, $rapport['creees']);

        $proposition = AffectationProposal::where('student_id', $etudiant->id)->firstOrFail();
        $this->assertSame(92, $proposition->score);
        $this->assertSame($this->groupe2->id, $proposition->metadata['alternatives'][0]['group_id']);
        $this->assertArrayHasKey((string) $this->groupe1->id, $proposition->metadata['groupes_compatibles']);
    }

    public function test_pages_cockpit_et_simulation_rendent_200(): void
    {
        $this->creerEtudiant('12121212');

        $this->actingAs($this->admin)->get(route('admin.affectations.masse'))
            ->assertOk();

        $this->actingAs($this->admin)->post(route('admin.affectations.masse.analyser'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
        ])->assertOk()
            ->assertViewIs('admin.affectations.masse-simulation');
    }

    public function test_tableau_propositions_affiche_alternatives_scorees(): void
    {
        $etudiant = $this->creerEtudiant('13131313');

        app(MassAssignmentService::class)->creerPropositions([
            [
                'student_id' => $etudiant->id,
                'groupe_id' => $this->groupe1->id,
                'score' => 92,
                'certitude' => 'elevee',
                'reasons' => ['Niveau académique strict conforme (+30 pts)'],
                'alternatives' => [
                    ['group_id' => $this->groupe2->id, 'code' => 'L1-INFO-G2', 'score' => 88, 'reasons' => []],
                ],
                'groupes_compatibles' => [$this->groupe1->id => 'L1-INFO-G1 (0/20)', $this->groupe2->id => 'L1-INFO-G2 (0/20)'],
            ],
        ], $this->annee->id, $this->admin);

        $this->actingAs($this->admin)->get(route('admin.affectations.propositions.index', [
            'annee_universitaire_id' => $this->annee->id,
        ]))->assertOk();
    }
}
