<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\Recherche\Providers\MatieresProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Catalogue matières — Vague 1 de la correction référentiel
 * (audit 08/10/2026) : code canonique normalisé INV-4/INV-9, périmètre
 * INV-6 (IDOR lecture ECUE, provider, exports), import sans colonnes
 * mortes, saturation des niveaux INV-10 côté import.
 */
class MatiereCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFacA;

    private User $adminFacB;

    private Matiere $ecueFacA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.referentiel.active' => true]);

        $faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.catalogue@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.catalogue@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteA->id,
        ], 'admin_faculte');
        $this->adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.catalogue@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteB->id,
        ], 'admin_faculte');

        // Chaîne LMD dans la faculté A + ECUE rattachée.
        $programme = Programme::create([
            'faculte_id' => $faculteA->id, 'code' => 'L-CAT', 'nom' => 'Licence Catalogue',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-CAT', 'nom' => 'UE', 'credits' => 6, 'actif' => true,
        ]);
        $this->ecueFacA = Matiere::create([
            'code' => 'CAT-01', 'nom' => 'ECUE de la faculté A', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);
    }

    /* ── INV-4/INV-9 : code canonique normalisé ── */

    public function test_code_normalise_a_l_ecriture_toutes_voies(): void
    {
        $matiere = Matiere::create([
            'code' => '  inf301 ', 'nom' => 'Analyse',
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);

        $this->assertSame('INF301', $matiere->fresh()->code);
        $this->assertSame('INF301', Matiere::normaliserCode(' inf301 '));

        // Résolution canonique insensible à la casse/espaces.
        $this->assertSame($matiere->id, Matiere::resoudreParCode(' inf301 ')?->id);
        $this->assertNull(Matiere::resoudreParCode(''));
        $this->assertNull(Matiere::resoudreParCode(null));
    }

    public function test_import_code_minuscule_idempotent_sans_doublon(): void
    {
        $this->importer('matieres', "code;nom\nalg-99;Algorithmique\n");
        $this->importer('matieres', "code;nom\nALG-99;Algorithmique\n");

        // Deux imports (casses différentes du code) → UNE matière normalisée.
        $this->assertSame(1, Matiere::where('code', 'ALG-99')->count());
        $this->assertSame('Algorithmique', Matiere::where('code', 'ALG-99')->firstOrFail()->nom);
    }

    public function test_import_refuse_niveau_libre(): void
    {
        $definition = app(DefinitionRegistry::class)->import('matieres');

        $erreurs = $definition->validerLigneMetier(['code' => 'X', 'niveau' => 'XYZ'], $this->admin->id);

        $this->assertNotEmpty($erreurs);
        $this->assertSame('NIVEAU_INCONNU', $erreurs[0]['code']);
    }

    public function test_definition_matieres_sans_colonnes_mortes(): void
    {
        $definition = app(DefinitionRegistry::class)->import('matieres');

        $this->assertNotContains('ecue_code', $definition->colonnes());
        $this->assertNotContains('departement_code', $definition->colonnes());

        // Les colonnes inconnues sont ignorées sans effet (alias disparus).
        $this->importer('matieres', "code;nom;ecue_code\nDEAD-01;Sans effet;UE-ABSENTE\n");
        $this->assertDatabaseHas('matieres', ['code' => 'DEAD-01']);
        $this->assertNull(Matiere::where('code', 'DEAD-01')->first()->ue_id);
    }

    /* ── INV-10 : saturation des niveaux côté import ── */

    public function test_import_niveau_refuse_programme_inconnu_sans_repli_global(): void
    {
        $definition = app(DefinitionRegistry::class)->import('niveaux');

        $erreurs = $definition->validerLigneMetier(['code' => 'L1', 'programme' => 'INEXISTANT'], $this->admin->id);

        $this->assertNotEmpty($erreurs);
        $this->assertSame('PROGRAMME_NOT_FOUND', $erreurs[0]['code']);
    }

    public function test_import_niveau_refuse_sous_diplome_sature(): void
    {
        $diplome = TypeDiplome::create(['code' => 'LIC', 'nom' => 'Licence', 'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true]);
        $programme = Programme::where('code', 'L-CAT')->firstOrFail();
        $programme->update(['type_diplome_id' => $diplome->id]);

        // L1 existe déjà (setUp) : completer L2/L3 pour saturer la licence.
        foreach (['L2', 'L3'] as $i => $code) {
            Niveau::create(['programme_id' => $programme->id, 'code' => $code, 'nom' => $code, 'ordre' => $i + 2, 'actif' => true]);
        }

        $definition = app(DefinitionRegistry::class)->import('niveaux');

        $erreurs = $definition->validerLigneMetier(['code' => 'M1', 'programme' => 'L-CAT'], $this->admin->id);

        $this->assertNotEmpty($erreurs, 'Un 4e niveau sous une licence de 3 ans doit être refusé.');
        $this->assertSame('NIVEAU_PROGRAMME_SATURE', $erreurs[0]['code']);

        // Un niveau EXISTANT reste idempotent (re-import = update, pas de refus).
        $this->assertSame([], $definition->validerLigneMetier(['code' => 'L3', 'programme' => 'L-CAT'], $this->admin->id));
    }

    /* ── INV-6 : périmètre par instance et lecteurs scopés ── */

    public function test_idor_lecture_ecue_par_faculte(): void
    {
        // Restreint de la faculté B : l'ECUE de la faculté A est
        // INtrouvable (404), pas « interdite » (pas de fuite d'existence).
        $this->actingAs($this->adminFacB)
            ->get(route('admin.referentiel.ecues.show', $this->ecueFacA))
            ->assertNotFound();

        // Même faculté : lecture permise.
        $this->actingAs($this->adminFacA)
            ->get(route('admin.referentiel.ecues.show', $this->ecueFacA))
            ->assertOk();

        // Global : lecture permise.
        $this->actingAs($this->admin)
            ->get(route('admin.referentiel.ecues.show', $this->ecueFacA))
            ->assertOk();
    }

    public function test_idor_ecriture_ecue_refusee_et_sans_effet(): void
    {
        // admin_faculte n'a pas referentiel.manage : refus permission.
        $this->actingAs($this->adminFacB)
            ->patch(route('admin.referentiel.ecues.update', $this->ecueFacA), ['volume_cm' => 42])
            ->assertForbidden();

        $this->assertDatabaseMissing('matieres', ['id' => $this->ecueFacA->id, 'volume_cm' => 42]);
    }

    public function test_provider_recherche_scope_faculte(): void
    {
        $transversale = Matiere::create([
            'code' => 'TRANS-CAT', 'nom' => 'Transversale unique',
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);

        // Restreint B : voit la transversale, PAS l'ECUE de la faculté A.
        $resultats = app(MatieresProvider::class)->rechercher($this->adminFacB, '%CAT%', 10);
        $codes = $resultats->pluck('titre')->all();
        $this->assertContains('TRANS-CAT — Transversale unique', $codes);
        $this->assertNotContains('CAT-01 — ECUE de la faculté A', $codes);

        // Même faculté : l'ECUE est trouvée.
        $codesA = app(MatieresProvider::class)->rechercher($this->adminFacA, '%CAT%', 10)->pluck('titre')->all();
        $this->assertContains('CAT-01 — ECUE de la faculté A', $codesA);
    }

    public function test_options_export_matieres_scopes(): void
    {
        $reponse = $this->actingAs($this->adminFacB)->get(route('admin.exports.options'));
        $reponse->assertOk();

        $codes = collect($reponse->json('matieres'))->pluck('code')->all();
        $this->assertNotContains('CAT-01', $codes, 'Un restreint ne voit pas les ECUE d\'une autre faculté.');
    }

    public function test_lectures_derivees_corrigent_les_divergences_legacy(): void
    {
        // Divergence prouvée en prod (audit) : UE en L2/S3 (numero 3)
        // mais matière avec legacy niveau='L1'/semestre=1.
        $faculte = Faculte::where('code', 'FST')->firstOrFail();
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'L-DERIV', 'nom' => 'Licence Dérivée',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveauL2 = Niveau::create(['programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2, 'actif' => true]);
        $semestre3 = SemestreLmd::create(['niveau_id' => $niveauL2->id, 'code' => 'S3', 'nom' => 'S3', 'numero' => 3, 'ordre' => 3, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre3->id, 'programme_id' => $programme->id,
            'code' => 'UE-DERIV', 'nom' => 'UE', 'credits' => 6, 'actif' => true,
        ]);
        $attachee = Matiere::create([
            'code' => 'DER-01', 'nom' => 'Attachée divergente', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);
        $standalone = Matiere::create([
            'code' => 'DER-02', 'nom' => 'Standalone', 'ue_id' => null,
            'niveau' => 'M2', 'semestre' => 2, 'coefficient' => 1, 'credits' => 2,
        ]);

        // Chaîne LMD fait foi pour la rattachée : L2, semestre relatif 1 (S3 impair).
        $this->assertSame('L2', $attachee->niveauAffiche());
        $this->assertSame(1, $attachee->semestreRelatif());

        // Repli legacy pour la standalone.
        $this->assertSame('M2', $standalone->niveauAffiche());
        $this->assertSame(2, $standalone->semestreRelatif());
    }

    public function test_filtre_planning_niveau_derive(): void
    {
        // Épreuve sur une ECUE DIVERGENTE : UE en L1 (chaîne) mais
        // legacy niveau='M2' — le filtre dérivé la classe en L1.
        $programme = Programme::where('code', 'L-CAT')->firstOrFail();
        $niveauL1 = Niveau::where('programme_id', $programme->id)->firstOrFail();
        $semestre = SemestreLmd::where('niveau_id', $niveauL1->id)->firstOrFail();
        $ue = UniteEnseignement::where('programme_id', $programme->id)->firstOrFail();
        $divergente = Matiere::create([
            'code' => 'DIV-01', 'nom' => 'Divergente', 'ue_id' => $ue->id,
            'niveau' => 'M2', 'semestre' => 2, 'coefficient' => 1, 'credits' => 2,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => false,
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'SES-DIV', 'nom' => 'Session',
            'type' => 'principale', 'date_debut' => '2027-01-05', 'date_fin' => '2027-01-30',
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'libelle' => 'Ex', 'type' => 'examen', 'statut' => 'scheduled',
        ]);
        Epreuve::create([
            'examen_id' => $examen->id,
            'annee_universitaire_id' => $annee->id, 'session_id' => $session->id,
            'matiere_id' => $divergente->id, 'ue_id' => $ue->id,
            'code' => 'EPR-DIV', 'date' => '2027-01-12',
            'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00', 'duree_minutes' => 120,
            'type_evaluation' => 'examen', 'coefficient' => 1, 'bareme' => 20, 'note_max' => 20,
            'statut' => 'scheduled',
        ]);

        // Filtre dérivé L1 (la chaîne) : l'épreuve divergente apparaît.
        $reponseL1 = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.planning', ['niveau' => 'L1']))
            ->assertOk();
        $this->assertMatchesRegularExpression(
            '/<strong>1<\/strong>\s*épreuve\(s\) trouvée\(s\)/u',
            $reponseL1->getContent(),
            'L\'épreuve de l\'ECUE divergente doit apparaître au niveau dérivé L1.'
        );

        // Filtre M2 (le legacy mensonger) : elle n'apparaît plus.
        $reponseM2 = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.planning', ['niveau' => 'M2']))
            ->assertOk();
        $this->assertMatchesRegularExpression(
            '/<strong>0<\/strong>\s*épreuve\(s\) trouvée\(s\)/u',
            $reponseM2->getContent(),
            'Le legacy mensonger M2 ne doit plus classer l\'épreuve.'
        );
    }

    private function importer(string $entite, string $contenu, string $mode = 'upsert'): void
    {
        $fichier = UploadedFile::fake()->createWithContent("{$entite}.csv", $contenu, 'text/csv');

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', $entite), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', $entite),
            ['token' => $preview->viewData('token'), 'mode' => $mode]
        )->assertOk();
    }
}
