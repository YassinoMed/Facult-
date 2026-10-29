<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Competence;
use App\Modules\Referentiel\Models\CompetenceObjectif;
use App\Modules\Referentiel\Models\CompetenceRapportSnapshot;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lot 20 — Programmes et compétences : tests du cahier.
 *
 * Couverture : catalogue SAISI jamais seedé, code dupliqué refusé,
 * révisions versionnées du brouillon, figage à l'approbation par
 * l'AUTORITÉ des maquettes (`maquettes.approve`), associations
 * canoniques (cible inexistante refusée, cohérence de portée,
 * idempotence), rapport de couverture DISTINGUANT absence de données
 * et absence de couverture, import par codes avec AMBIGUÏTÉS détectées
 * (jamais de choix arbitraire), non-régression du versionnage des
 * maquettes, isolation facultaire, non-écriture des tables officielles,
 * cohérence (liens morts) et performance synthétique.
 *
 * Données 100 % synthétiques — aucune donnée réelle d'étudiant.
 */
class ReferentielCompetencesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $admin;

    private Programme $programmeA;

    private UniteEnseignement $ueA;

    private Matiere $matiereA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Sciences Test', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit Test', 'code' => 'FDEG', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.comp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        [$this->programmeA, $this->ueA, $this->matiereA] = $this->programmeAvecUe(
            $this->faculteA, 'LGL', 'UE-GL1', 'MAT-GL1'
        );
    }

    /* ── Scaffolds ──────────────────────────────────────────────── */

    /** Programme complet (niveau, semestre, UE, matière/ECUE de l'UE). */
    private function programmeAvecUe(Faculte $faculte, string $codeProgramme, string $codeUe, string $codeMatiere): array
    {
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => $codeProgramme, 'nom' => 'Programme '.$codeProgramme,
            'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => $codeUe, 'nom' => 'UE '.$codeUe, 'credits' => 6, 'coefficient' => 2, 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => $codeMatiere, 'nom' => 'Matière '.$codeMatiere, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'coefficient' => 1, 'actif' => true,
        ]);

        return [$programme, $ue, $matiere];
    }

    /** Maquette avec plan : 1 UE + son ECUE (contenu saisi en brouillon, puis statut). */
    private function maquetteAvecPlan(Programme $programme, UniteEnseignement $ue, Matiere $matiere, string $statut = 'approved'): Maquette
    {
        $maquette = Maquette::create([
            'programme_id' => $programme->id, 'annee_universitaire_id' => $this->annee->id,
            'version' => 1, 'statut' => 'draft', 'auteur_id' => $this->admin->id,
        ]);

        app(MaquetteService::class)->definirContenu($maquette, [
            ['ue_id' => $ue->id, 'semestre_id' => $ue->semestre_id],
        ], $this->admin->id);

        $ligne = MaquetteUe::where('maquette_id', $maquette->id)->firstOrFail();
        MaquetteUeEcue::create([
            'maquette_ue_id' => $ligne->id, 'matiere_id' => $matiere->id,
            'coefficient' => 2, 'credits' => 4,
        ]);

        if ($statut !== 'draft') {
            // Statut direct du fixture (le workflow complet est couvert
            // par MaquetteVersioningTest ; le verrouillage est respecté :
            // le contenu est saisi AVANT le figage).
            $maquette->forceFill(['statut' => $statut, 'validee_par' => $this->admin->id])->save();
        }

        return $maquette;
    }

    /** Compétence directe (statut contourné pour le scaffold uniquement). */
    private function competence(string $code, string $statut = 'approuvee', ?int $faculteId = null): Competence
    {
        return Competence::create([
            'code' => $code, 'libelle' => 'Compétence '.$code, 'faculte_id' => $faculteId,
            'statut' => $statut, 'cree_par' => $this->admin->id,
        ]);
    }

    private function adminFaculte(Faculte $faculte): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Faculté', 'email' => 'af-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    private function consultateur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'c-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
    }

    private function appelPost(User $u, string $route, array $params, array $data): TestResponse
    {
        return $this->actingAs($u)->postJson(route($route, $params), $data);
    }

    private function appelGet(User $u, string $route, array $params = []): TestResponse
    {
        return $this->actingAs($u)->getJson(route($route, $params));
    }

    private function appelDelete(User $u, string $route, array $params, array $data = []): TestResponse
    {
        return $this->actingAs($u)->deleteJson(route($route, $params), $data);
    }

    /* ── Catalogue : saisi, versionné, approuvé par l'autorité ─── */

    public function test_catalogue_vide_par_defaut_aucune_competence_inventee(): void
    {
        $this->appelGet($this->admin, 'admin.referentiel.competences.index')
            ->assertOk()->assertJson(['competences' => []]);
        $this->assertSame(0, Competence::count()); // jamais seedé
    }

    public function test_creation_refusee_sans_referentiel_manage(): void
    {
        $this->appelPost($this->consultateur(), 'admin.referentiel.competences.store', [], [
            'code' => 'C-X', 'libelle' => 'Compétence X',
        ])->assertStatus(403);
        $this->assertSame(0, Competence::count());
    }

    public function test_code_duplique_refuse(): void
    {
        $this->appelPost($this->admin, 'admin.referentiel.competences.store', [], [
            'code' => 'C-1', 'libelle' => 'Première',
        ])->assertStatus(201);

        $this->appelPost($this->admin, 'admin.referentiel.competences.store', [], [
            'code' => 'C-1', 'libelle' => 'Doublon',
        ])->assertStatus(422); // jamais d'écrasement silencieux
    }

    public function test_revision_brouillon_versionnee_puis_figee_refusee(): void
    {
        $competence = $this->appelPost($this->admin, 'admin.referentiel.competences.store', [], [
            'code' => 'C-R', 'libelle' => 'Révisable',
        ])->assertStatus(201)->json('competence');
        $this->assertSame(1, $competence['version']);

        $revisee = $this->appelPost($this->admin, 'admin.referentiel.competences.reviser', ['id' => $competence['id']], [
            'libelle' => 'Révisée une fois',
        ])->assertOk()->json('competence');
        $this->assertSame(2, $revisee['version']);

        // Approbation = FIGAGE : plus aucune révision possible.
        $this->appelPost($this->admin, 'admin.referentiel.competences.approuver', ['id' => $competence['id']], [])
            ->assertOk();

        $this->appelPost($this->admin, 'admin.referentiel.competences.reviser', ['id' => $competence['id']], [
            'libelle' => 'Interdit après figage',
        ])->assertStatus(422);
        $this->assertSame(2, Competence::findOrFail($competence['id'])->version);
    }

    public function test_approbation_exige_l_autorite_des_maquettes(): void
    {
        $competence = $this->competence('C-AUT', 'brouillon');

        // admin_faculte : referentiel.view SEUL → 403 (pas maquettes.approve).
        $this->appelPost($this->adminFaculte($this->faculteA), 'admin.referentiel.competences.approuver', ['id' => $competence->id], [])
            ->assertStatus(403);
        $this->assertSame('brouillon', $competence->refresh()->statut);

        // admin_scolarite (maquettes.approve) → approbation tracée.
        $this->appelPost($this->admin, 'admin.referentiel.competences.approuver', ['id' => $competence->id], [])
            ->assertOk();
        $model = $competence->refresh();
        $this->assertSame('approuvee', $model->statut);
        $this->assertSame($this->admin->id, (int) $model->approuve_par);
        $this->assertNotNull($model->approuve_le);
    }

    public function test_archivage_puis_plus_associable(): void
    {
        $competence = $this->competence('C-ARCH');
        $this->appelPost($this->admin, 'admin.referentiel.competences.archiver', ['id' => $competence->id], [])
            ->assertOk();

        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ue', 'cible_id' => $this->ueA->id,
        ])->assertStatus(422);
        $this->assertSame(0, CompetenceObjectif::count());
    }

    /* ── Associations canoniques ───────────────────────────────── */

    public function test_association_refuse_competence_non_approuvee_et_cible_inexistante(): void
    {
        $brouillon = $this->competence('C-BR', 'brouillon');
        $approuvee = $this->competence('C-OK');

        // Compétence non approuvée : refusée.
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $brouillon->id], [
            'cible_type' => 'ue', 'cible_id' => $this->ueA->id,
        ])->assertStatus(422);

        // Cible inexistante : lien mort JAMAIS accepté.
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $approuvee->id], [
            'cible_type' => 'ue', 'cible_id' => 999999,
        ])->assertStatus(422);

        $this->assertSame(0, CompetenceObjectif::count());
    }

    public function test_association_cohrence_de_portee_faculte(): void
    {
        [, $ueB] = $this->programmeAvecUe($this->faculteB, 'FDRO', 'UE-DR1', 'MAT-DR1');
        $localeA = $this->competence('C-LOCA', 'approuvee', $this->faculteA->id);
        $globale = $this->competence('C-GLOB');

        // Compétence LOCALE A vers cible faculté B : incohérence métier (422).
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $localeA->id], [
            'cible_type' => 'ue', 'cible_id' => $ueB->id,
        ])->assertStatus(422);

        // Compétence GLOBALE vers cible B : acceptée.
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $globale->id], [
            'cible_type' => 'ue', 'cible_id' => $ueB->id,
        ])->assertStatus(201);
    }

    public function test_association_idempotente_et_dissociable(): void
    {
        $competence = $this->competence('C-IDEM');

        $id1 = $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ecue', 'cible_id' => $this->matiereA->id, 'niveau_attendu' => 'maitrise',
        ])->assertStatus(201)->json('objectif.id');

        $id2 = $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ecue', 'cible_id' => $this->matiereA->id,
        ])->assertStatus(200)->json('objectif.id');

        $this->assertSame($id1, $id2); // UNIQUE : re-lier = l'existant
        $this->assertSame(1, CompetenceObjectif::count());

        $this->appelDelete($this->admin, 'admin.referentiel.competences.objectifs.dissocier', ['id' => $competence->id], [
            'cible_type' => 'ecue', 'cible_id' => $this->matiereA->id,
        ])->assertOk()->assertJson(['supprime' => true]);
        $this->assertSame(0, CompetenceObjectif::count());
    }

    /* ── Rapport de couverture : les DEUX absences distinguées ─── */

    public function test_rapport_distingue_absence_de_donnees_et_absence_de_couverture(): void
    {
        $maquette = $this->maquetteAvecPlan($this->programmeA, $this->ueA, $this->matiereA);
        $couverte = $this->competence('C-COV');   // sera reliée à l'UE du plan
        $ignoree = $this->competence('C-LAC');    // jamais reliée

        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $couverte->id], [
            'cible_type' => 'ue', 'cible_id' => $this->ueA->id, 'niveau_attendu' => 'initiation',
        ])->assertStatus(201);

        $rapport = $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', ['maquette' => $maquette->id])
            ->assertOk()->json();

        // Axe 1 — UE reliée : renseignée ; ECUE jamais reliée : ABSENCE DE DONNÉE.
        $this->assertSame('renseigne', $rapport['ues'][0]['etat']);
        $this->assertSame('C-COV', $rapport['ues'][0]['competences'][0]['code']);
        $this->assertSame('non_renseigne', $rapport['ecues'][0]['etat']);

        // Axe 2 — compétence jamais couverte : ABSENCE DE COUVERTURE (lacune).
        $etats = collect($rapport['competences'])->keyBy('code');
        $this->assertSame('couverte', $etats['C-COV']['etat']);
        $this->assertSame('non_couverte', $etats['C-LAC']['etat']);

        // Totaux et légende explicites (jamais confondus).
        $this->assertSame(1, $rapport['totaux']['ues']);
        $this->assertSame(1, $rapport['totaux']['ues_renseignees']);
        $this->assertSame(1, $rapport['totaux']['ecues']);
        $this->assertSame(0, $rapport['totaux']['ecues_renseignes']);
        $this->assertSame(2, $rapport['totaux']['competences_perimetre']);
        $this->assertSame(1, $rapport['totaux']['competences_couvertes']);
        $this->assertStringContainsString('ABSENCE DE DONNÉE', $rapport['legende']['non_renseigne']);
        $this->assertStringContainsString('ABSENCE DE COUVERTURE', $rapport['legende']['non_couverte']);
    }

    public function test_couverture_refusee_hors_perimetre_faculte(): void
    {
        $maquette = $this->maquetteAvecPlan($this->programmeA, $this->ueA, $this->matiereA);

        // admin_faculte B sur une maquette du programme A : 403 (IDOR).
        $this->appelGet($this->adminFaculte($this->faculteB), 'admin.referentiel.maquettes.couverture', ['maquette' => $maquette->id])
            ->assertStatus(403);

        // Association sur une cible de la faculté A par admin_faculte B : 403.
        $competence = $this->competence('C-IDOR');
        $this->appelPost($this->adminFaculte($this->faculteB), 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ue', 'cible_id' => $this->ueA->id,
        ])->assertStatus(403);
    }

    public function test_nouvelle_version_ne_modifie_ni_associations_ni_rapport(): void
    {
        $maquette = $this->maquetteAvecPlan($this->programmeA, $this->ueA, $this->matiereA);
        $competence = $this->competence('C-V1');
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ecue', 'cible_id' => $this->matiereA->id,
        ])->assertStatus(201);

        $rapportAvant = $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', ['maquette' => $maquette->id])
            ->assertOk()->json();

        // Nouvelle version de maquette (snapshot) : les associations
        // canoniques et le rapport de la version d'origine restent identiques.
        $v2 = app(MaquetteService::class)->nouvelleVersion($maquette, $this->annee->id, $this->admin->id, 'v2');

        $this->assertSame(1, CompetenceObjectif::count());
        $rapportApres = $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', ['maquette' => $maquette->id])
            ->assertOk()->json();
        $this->assertSame($rapportAvant, $rapportApres); // aucune écriture rétroactive

        // La v2 pointe le même plan canonique : même couverture.
        $rapportV2 = $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', ['maquette' => $v2->id])
            ->assertOk()->json();
        $this->assertSame($rapportAvant['totaux'], $rapportV2['totaux']);
    }

    /* ── Import par codes : ambiguïtés DÉTECTÉES ───────────────── */

    public function test_import_detecte_ambiguite_et_ne_choisit_pas_arbitrairement(): void
    {
        // DEUX UE portant le MÊME code, dans deux programmes différents
        // (unique = (semestre, code) : la collision par code est réelle).
        [, $ueAmb1] = $this->programmeAvecUe($this->faculteA, 'LP1', 'UE-AMB', 'MAT-P1');
        [, $ueAmb2] = $this->programmeAvecUe($this->faculteA, 'LP2', 'UE-AMB', 'MAT-P2');
        $competence = $this->competence('C-IMP');

        $resultat = $this->appelPost($this->admin, 'admin.referentiel.competences.import', [], [
            'lignes' => [
                ['competence_code' => 'C-IMP', 'cible_type' => 'ue', 'cible_code' => 'UE-AMB'],
                ['competence_code' => 'C-IMP', 'cible_type' => 'ue', 'cible_code' => 'UE-GL1'],
                ['competence_code' => 'INCONNU', 'cible_type' => 'ue', 'cible_code' => 'UE-GL1'],
            ],
        ])->assertOk()->json();

        // La ligne ambiguë est rejetée AVEC ses candidats — aucune
        // association arbitraire ; la ligne valide est créée.
        $this->assertSame(1, $resultat['creees']);
        $this->assertSame(0, $resultat['ignorees']);
        $this->assertCount(2, $resultat['rejets']);
        $motifAmbigu = collect($resultat['rejets'])->first(fn ($r) => str_contains($r['motif'], 'AMBIGU'));
        $this->assertNotNull($motifAmbigu);
        $this->assertStringContainsString((string) $ueAmb1->id, $motifAmbigu['motif']);
        $this->assertStringContainsString((string) $ueAmb2->id, $motifAmbigu['motif']);

        // Exactement UNE association (la ligne non ambiguë) — jamais UE-AMB.
        $this->assertSame(1, CompetenceObjectif::count());
        $this->assertSame($this->ueA->id, (int) CompetenceObjectif::sole()->cible_id);
    }

    public function test_import_valide_idempotent(): void
    {
        $competence = $this->competence('C-IMP2');
        $lignes = [
            ['competence_code' => 'C-IMP2', 'cible_type' => 'ue', 'cible_code' => 'UE-GL1', 'niveau_attendu' => 'avance'],
            ['competence_code' => 'C-IMP2', 'cible_type' => 'ecue', 'cible_code' => 'MAT-GL1'],
        ];

        $premier = $this->appelPost($this->admin, 'admin.referentiel.competences.import', [], ['lignes' => $lignes])
            ->assertOk()->json();
        $this->assertSame(2, $premier['creees']);
        $this->assertSame(0, $premier['ignorees']);

        // Re-import : doublons ignorés, aucune nouvelle ligne.
        $second = $this->appelPost($this->admin, 'admin.referentiel.competences.import', [], ['lignes' => $lignes])
            ->assertOk()->json();
        $this->assertSame(0, $second['creees']);
        $this->assertSame(2, $second['ignorees']);
        $this->assertSame(2, CompetenceObjectif::count());
    }

    /* ── Cohérence, non-écriture, module, performance ──────────── */

    public function test_coherence_signale_les_liens_morts(): void
    {
        $competence = $this->competence('C-MORT');
        $matiere = Matiere::create([
            'code' => 'MAT-TEMP', 'nom' => 'Temporaire', 'ue_id' => $this->ueA->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 2, 'coefficient' => 1, 'actif' => true,
        ]);
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ecue', 'cible_id' => $matiere->id,
        ])->assertStatus(201);

        // La cible disparaît (aucune FK polymorphe) : le contrôle de
        // cohérence l'expose au lieu de laisser un lien mort silencieux.
        $matiere->delete();
        $anomalies = $this->appelGet($this->admin, 'admin.referentiel.competences.coherence')
            ->assertOk()->json('anomalies');

        $this->assertNotEmpty($anomalies);
        $this->assertStringContainsString('introuvable (lien mort)', $anomalies[0]['anomalie']);
    }

    public function test_donnees_officielles_non_modifiees_par_le_flux(): void
    {
        $maquette = $this->maquetteAvecPlan($this->programmeA, $this->ueA, $this->matiereA);

        // Snapshot APRÈS le scaffold : seules les écritures du FLUX comptent.
        $tables = ['maquettes', 'maquette_ue', 'maquette_ue_ecue', 'matieres', 'unites_enseignement', 'programmes'];
        $avant = collect($tables)->mapWithKeys(fn ($t) => [$t => \DB::table($t)->count()])->all();

        $competence = $this->competence('C-FLOW');
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'programme', 'cible_id' => $this->programmeA->id,
        ])->assertStatus(201);
        $this->appelPost($this->admin, 'admin.referentiel.competences.import', [], [
            'lignes' => [['competence_code' => 'C-FLOW', 'cible_type' => 'ue', 'cible_code' => 'UE-GL1']],
        ])->assertOk();
        $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', ['maquette' => $maquette->id])->assertOk();

        // Les écritures du lot ne touchent QUE competences/objectifs.
        $apres = collect($tables)->mapWithKeys(fn ($t) => [$t => \DB::table($t)->count()])->all();
        $this->assertSame($avant, $apres);
    }

    public function test_module_referentiel_desactive_renvoie_404(): void
    {
        config(['modules.referentiel.active' => false]);

        $this->appelGet($this->admin, 'admin.referentiel.competences.index')->assertStatus(404);
        $this->appelPost($this->admin, 'admin.referentiel.competences.store', [], [
            'code' => 'C-404', 'libelle' => 'Interdit module off',
        ])->assertStatus(404);
    }

    public function test_performance_rapport_volumetrie_synthetic(): void
    {
        // 10 UE × 5 ECUE : rapport complet sous 3 secondes.
        $ues = [];
        $matieres = [];
        for ($i = 1; $i <= 10; $i++) {
            [, $ue, $matiere] = $this->programmeAvecUe(
                $this->faculteA, 'LPERF'.$i, 'UE-P'.$i, 'MAT-P'.$i.'-0'
            );
            $ues[] = $ue;
            $matieres[] = $matiere;
        }

        $maquette = Maquette::create([
            'programme_id' => $this->programmeA->id, 'annee_universitaire_id' => $this->annee->id,
            'version' => 1, 'statut' => 'draft', 'auteur_id' => $this->admin->id,
        ]);
        app(MaquetteService::class)->definirContenu($maquette, array_map(
            fn ($ue) => ['ue_id' => $ue->id, 'semestre_id' => $ue->semestre_id], $ues
        ), $this->admin->id);
        $maquette->forceFill(['statut' => 'approved', 'validee_par' => $this->admin->id])->save();

        $lignes = MaquetteUe::where('maquette_id', $maquette->id)->get();
        foreach ($lignes as $index => $ligne) {
            MaquetteUeEcue::create([
                'maquette_ue_id' => $ligne->id, 'matiere_id' => $matieres[$index]->id,
                'coefficient' => 2, 'credits' => 4,
            ]);
        }

        $competence = $this->competence('C-PERF');
        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ue', 'cible_id' => $ues[0]->id,
        ])->assertStatus(201);

        $debut = microtime(true);
        $rapport = $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', ['maquette' => $maquette->id])
            ->assertOk()->json();
        $duree = microtime(true) - $debut;

        $this->assertSame(10, $rapport['totaux']['ues']);
        $this->assertSame(1, $rapport['totaux']['ues_renseignees']);
        $this->assertLessThan(3.0, $duree, 'Rapport trop lent : '.$duree.' s');
    }

    /* ── Import : contexte explicite, jamais de choix arbitraire ── */

    public function test_import_cible_contexte_leve_une_ambiguite_ue_sans_choix_arbitraire(): void
    {
        [, $ueAmb1] = $this->programmeAvecUe($this->faculteA, 'LP1', 'UE-AMB', 'MAT-P1');
        [, $ueAmb2] = $this->programmeAvecUe($this->faculteA, 'LP2', 'UE-AMB', 'MAT-P2');
        $this->competence('C-CTX');

        $resultat = $this->appelPost($this->admin, 'admin.referentiel.competences.import', [], [
            'lignes' => [
                // Sans contexte : ambiguïté détectée, rien n'est créé.
                ['competence_code' => 'C-CTX', 'cible_type' => 'ue', 'cible_code' => 'UE-AMB'],
                // Contexte = code programme : résolution unique vers LP1.
                ['competence_code' => 'C-CTX', 'cible_type' => 'ue', 'cible_code' => 'UE-AMB', 'cible_contexte' => 'LP1'],
                // Contexte inconnu : rejet explicite, jamais de repli arbitraire.
                ['competence_code' => 'C-CTX', 'cible_type' => 'ue', 'cible_code' => 'UE-AMB', 'cible_contexte' => 'INCONNU'],
            ],
        ])->assertOk()->json();

        $this->assertSame(1, $resultat['creees']);
        $this->assertSame(2, count($resultat['rejets']));
        $this->assertStringContainsString('AMBIGU', $resultat['rejets'][0]['motif']);
        $this->assertStringContainsString('cible_contexte', $resultat['rejets'][0]['motif']);
        $this->assertStringContainsString('contexte « INCONNU »', $resultat['rejets'][1]['motif']);

        $this->assertDatabaseHas('competence_objectifs', ['cible_type' => 'ue', 'cible_id' => $ueAmb1->id]);
        $this->assertDatabaseMissing('competence_objectifs', ['cible_type' => 'ue', 'cible_id' => $ueAmb2->id]);
    }

    public function test_import_cible_contexte_programme_par_code_faculte(): void
    {
        [$programmeB] = $this->programmeAvecUe($this->faculteB, 'LGL', 'UE-FB', 'MAT-FB');
        $this->competence('C-PRG');

        $resultat = $this->appelPost($this->admin, 'admin.referentiel.competences.import', [], [
            'lignes' => [
                ['competence_code' => 'C-PRG', 'cible_type' => 'programme', 'cible_code' => 'LGL'],
                ['competence_code' => 'C-PRG', 'cible_type' => 'programme', 'cible_code' => 'LGL', 'cible_contexte' => 'FST'],
                ['competence_code' => 'C-PRG', 'cible_type' => 'programme', 'cible_code' => 'LGL', 'cible_contexte' => 'FDEG'],
            ],
        ])->assertOk()->json();

        $this->assertSame(2, $resultat['creees']);
        $this->assertSame(1, count($resultat['rejets']));
        $this->assertStringContainsString('AMBIGU', $resultat['rejets'][0]['motif']);

        $this->assertDatabaseHas('competence_objectifs', ['cible_type' => 'programme', 'cible_id' => $this->programmeA->id]);
        $this->assertDatabaseHas('competence_objectifs', ['cible_type' => 'programme', 'cible_id' => $programmeB->id]);
    }

    public function test_import_cible_contexte_ecue_verifie_l_appartenance_a_l_ue(): void
    {
        $this->competence('C-ECUE');

        $resultat = $this->appelPost($this->admin, 'admin.referentiel.competences.import', [], [
            'lignes' => [
                ['competence_code' => 'C-ECUE', 'cible_type' => 'ecue', 'cible_code' => $this->matiereA->code, 'cible_contexte' => 'UE-INCONNUE'],
                ['competence_code' => 'C-ECUE', 'cible_type' => 'ecue', 'cible_code' => $this->matiereA->code, 'cible_contexte' => $this->ueA->code],
            ],
        ])->assertOk()->json();

        $this->assertSame(1, $resultat['creees']);
        $this->assertSame(1, count($resultat['rejets']));
        $this->assertDatabaseHas('competence_objectifs', ['cible_type' => 'ecue', 'cible_id' => $this->matiereA->id]);
    }

    /* ── Snapshots : trace figée, jamais réinterprétée ─────────── */

    public function test_le_snapshot_de_rapport_est_immuable_apres_dissociation(): void
    {
        $maquette = $this->maquetteAvecPlan($this->programmeA, $this->ueA, $this->matiereA);
        $competence = $this->competence('C-SNAP');

        $this->appelPost($this->admin, 'admin.referentiel.competences.objectifs.associer', ['id' => $competence->id], [
            'cible_type' => 'ue', 'cible_id' => $this->ueA->id,
        ])->assertStatus(201);

        $premier = $this->appelPost($this->admin, 'admin.referentiel.maquettes.couverture.figer', ['maquette' => $maquette->id], [])
            ->assertStatus(201)->json('snapshot');
        $second = $this->appelPost($this->admin, 'admin.referentiel.maquettes.couverture.figer', ['maquette' => $maquette->id], [])
            ->assertStatus(201)->json('snapshot');

        // Même état ⇒ même empreinte (sérialisation canonique).
        $this->assertSame($premier['empreinte'], $second['empreinte']);
        $this->assertSame(2, CompetenceRapportSnapshot::count());

        $this->appelDelete($this->admin, 'admin.referentiel.competences.objectifs.dissocier', ['id' => $competence->id], [
            'cible_type' => 'ue', 'cible_id' => $this->ueA->id,
        ])->assertOk();

        // Le rapport courant perd la couverture…
        $courant = $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', ['maquette' => $maquette->id])
            ->assertOk()->json();
        $this->assertSame(0, $courant['totaux']['ues_renseignees']);

        // …mais le snapshot publié reste STRICTEMENT inchangé.
        $fige = CompetenceRapportSnapshot::findOrFail($premier['id']);
        $this->assertSame(1, $fige->donnees['totaux']['ues_renseignees']);
        $this->assertSame(1, $fige->donnees['totaux']['competences_couvertes']);

        $avecHistorique = $this->appelGet($this->admin, 'admin.referentiel.maquettes.couverture', [
            'maquette' => $maquette->id, 'snapshots' => 1,
        ])->assertOk()->json();
        $this->assertCount(2, $avecHistorique['snapshots']);
    }

    /* ── UI minimale : catalogue, couverture, cohérence ────────── */

    public function test_l_ui_minimale_permet_creation_approbation_et_couverture(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.referentiel.competences.index'))
            ->assertOk()
            ->assertSee('Compétences pédagogiques');

        $this->actingAs($this->admin)
            ->post(route('admin.referentiel.competences.store'), [
                'code' => 'C-UI', 'libelle' => 'Compétence interface',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $competence = Competence::where('code', 'C-UI')->firstOrFail();
        $this->assertSame('brouillon', $competence->statut);

        $this->actingAs($this->admin)
            ->post(route('admin.referentiel.competences.approuver', $competence->id))
            ->assertRedirect();
        $this->assertSame('approuvee', $competence->fresh()->statut);

        $maquette = $this->maquetteAvecPlan($this->programmeA, $this->ueA, $this->matiereA);

        $this->actingAs($this->admin)
            ->get(route('admin.referentiel.maquettes.couverture', $maquette))
            ->assertOk()
            ->assertSee('Couverture pédagogique')
            ->assertSee('ABSENCE DE DONNÉE')
            ->assertSee('Rapports figés');

        $this->actingAs($this->admin)
            ->get(route('admin.referentiel.competences.coherence'))
            ->assertOk()
            ->assertSee('Cohérence des associations');
    }

    /* ── Commande planifiée : lecture seule ────────────────────── */

    public function test_la_commande_de_coherence_signale_sans_corriger(): void
    {
        $competence = $this->competence('C-CMD');
        $objectif = CompetenceObjectif::create([
            'competence_id' => $competence->id,
            'cible_type' => 'ue',
            'cible_id' => 999999, // lien mort volontaire
            'cree_par' => $this->admin->id,
        ]);

        $this->artisan('competences:coherence')
            ->expectsOutput('Associations compétence ↔ cible : 1 anomalie(s).')
            ->assertExitCode(0);

        // Aucune correction automatique : la ligne reste intacte.
        $this->assertSame(1, CompetenceObjectif::count());
        $this->assertDatabaseHas('competence_objectifs', ['id' => $objectif->id]);
    }
}
