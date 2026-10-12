<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariants structurels du référentiel LMD (LOOP correction) :
 * double chemin UE, cohérence faculté des programmes, garde-fous
 * de nommage. Aucune migration destructive, uniquement des refus
 * serveur + resynchronisations documentées.
 */
class ReferentielIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFacB;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Programme $programmeA;

    private Programme $programmeB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.integrite@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin Droit', 'email' => 'droit.integrite@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->programmeA = $this->creerProgramme($this->faculteA, 'L-INFO', 'Licence Informatique');
        $this->programmeB = $this->creerProgramme($this->faculteB, 'L-DROIT', 'Licence Droit');
    }

    private function creerProgramme(Faculte $faculte, string $code, string $nom): Programme
    {
        $domaine = Domaine::create(['faculte_id' => $faculte->id, 'code' => 'DOM-'.$code, 'nom' => 'Domaine '.$code, 'actif' => true]);
        $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => 'MEN-'.$code, 'nom' => 'Mention '.$code, 'actif' => true]);
        $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => 'SPE-'.$code, 'nom' => 'Spécialité '.$code, 'actif' => true]);

        return Programme::create([
            'faculte_id' => $faculte->id, 'specialite_id' => $specialite->id,
            'code' => $code, 'nom' => $nom, 'statut' => 'actif', 'actif' => true,
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    private function creerChaine(Programme $programme, string $niveauCode = 'L1', string $semestreCode = 'S1', ?string $ueCode = null): array
    {
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => $niveauCode, 'nom' => $niveauCode, 'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => $semestreCode, 'nom' => $semestreCode, 'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => $ueCode ?? 'UE-'.$niveauCode, 'nom' => 'UE '.$niveauCode, 'credits' => 30, 'actif' => true,
        ]);

        return [$niveau, $semestre, $ue];
    }

    private function payloadUe(UniteEnseignement $ue, array $surcharge = []): array
    {
        return array_merge([
            'semestre_id' => $ue->semestre_id,
            'code' => $ue->code,
            'nom' => $ue->nom,
            'credits' => $ue->credits,
            'type' => 'fondamentale',
            'actif' => true,
        ], $surcharge);
    }

    public function test_ue_cannot_reference_semestre_of_another_programme(): void
    {
        [, $semestreA, $ue] = $this->creerChaine($this->programmeA);
        [, $semestreB] = $this->creerChaine($this->programmeB, 'L1', 'S1', 'UE-B');

        // Déplacement vers le semestre d'un autre programme : REFUSÉ
        // (invariant §8 — la règle de validation verrouille le chemin,
        // un transfert exigerait recréation + analyse d'impact).
        $reponse = $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['ues', $ue->id]),
            $this->payloadUe($ue, ['semestre_id' => $semestreB->id])
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('semestre_id');

        $ue->refresh();
        $this->assertSame($semestreA->id, $ue->semestre_id);
        $this->assertSame(
            $this->programmeA->id, $ue->programme_id,
            'UE.programme_id == UE.semestre.niveau.programme_id (invariant §8).'
        );
    }

    public function test_ue_programme_matches_semestre_programme(): void
    {
        [, , $ue] = $this->creerChaine($this->programmeA);

        $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['ues', $ue->id]),
            $this->payloadUe($ue, ['nom' => 'UE renommée'])
        )->assertRedirect();

        $ue->refresh();
        $this->assertSame('UE renommée', $ue->nom);
        $this->assertSame($this->programmeA->id, $ue->programme_id);
        $this->assertSame($ue->semestre->niveau->programme_id, $ue->programme_id);
    }

    public function test_ue_semestre_autre_faculte_refuse(): void
    {
        [, , $ue] = $this->creerChaine($this->programmeB);
        [, $semestreA] = $this->creerChaine($this->programmeA);

        // Compte restreint FDL : semestre FST inaccessible.
        $this->actingAs($this->adminFacB)->put(
            route('admin.referentiel.entites.update', ['ues', $ue->id]),
            $this->payloadUe($ue, ['semestre_id' => $semestreA->id])
        )->assertForbidden();

        $this->assertSame($this->programmeB->id, $ue->fresh()->programme_id);
    }

    public function test_programme_specialite_autre_faculte_refusee(): void
    {
        $specialiteB = Specialite::whereHas('mention.domaine', fn ($q) => $q->where('faculte_id', $this->faculteB->id))->firstOrFail();

        $reponse = $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['programmes', $this->programmeA->id]),
            [
                'faculte_id' => $this->faculteA->id,
                'specialite_id' => $specialiteB->id,
                'code' => $this->programmeA->code,
                'nom' => $this->programmeA->nom,
                'statut' => 'actif',
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('specialite_id');
        $this->assertNotSame($specialiteB->id, $this->programmeA->fresh()->specialite_id);
    }

    public function test_programme_nom_trop_court_refuse(): void
    {
        $reponse = $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['programmes', $this->programmeA->id]),
            [
                'faculte_id' => $this->faculteA->id,
                'code' => $this->programmeA->code,
                'nom' => 'P',
                'statut' => 'actif',
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('nom');
        $this->assertNotSame('P', $this->programmeA->fresh()->nom);
    }

    // ── LOOP 2 — non-régression des invariants implémentés ──────

    public function test_programme_departement_autre_faculte_refuse(): void
    {
        // INV-2 : le département doit appartenir à la faculté du programme
        // (miroir de la règle spécialité déjà couverte).
        $departementB = Departement::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'DRO', 'nom' => 'Droit', 'actif' => true,
        ]);

        $reponse = $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['programmes', $this->programmeA->id]),
            [
                'faculte_id' => $this->faculteA->id,
                'departement_id' => $departementB->id,
                'code' => $this->programmeA->code,
                'nom' => $this->programmeA->nom,
                'statut' => 'actif',
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('departement_id');
        $this->assertNull($this->programmeA->fresh()->departement_id);
    }

    public function test_niveau_hors_duree_diplome_refuse(): void
    {
        // INV-10 (chemin web CRUD, l'import étant déjà couvert) : le
        // nombre de niveaux ne dépasse pas la durée du type de diplôme.
        $licence2 = TypeDiplome::create([
            'code' => 'LIC2', 'nom' => 'Licence 2 ans', 'cycle' => 'licence',
            'duree_annees' => 2, 'actif' => true,
        ]);
        $programme = $this->creerProgramme($this->faculteA, 'L-BIO', 'Licence Biologie');
        $programme->update(['type_diplome_id' => $licence2->id]);

        foreach (['L1', 'L2'] as $i => $code) {
            Niveau::create([
                'programme_id' => $programme->id, 'code' => $code, 'nom' => $code,
                'ordre' => $i + 1, 'actif' => true,
            ]);
        }

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.referentiel.entites.store', 'niveaux'),
            ['programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3, 'actif' => true]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('programme_id');
        $this->assertSame(2, Niveau::where('programme_id', $programme->id)->count());
    }

    public function test_niveau_dans_duree_diplome_accepte(): void
    {
        // Contrôle positif : la garde n'est pas sur-stricte — dans la
        // durée prévue (3), le 3e niveau passe.
        $licence = TypeDiplome::create([
            'code' => 'LIC', 'nom' => 'Licence', 'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $programme = $this->creerProgramme($this->faculteA, 'L-CHI', 'Licence Chimie');
        $programme->update(['type_diplome_id' => $licence->id]);

        foreach (['L1', 'L2'] as $i => $code) {
            Niveau::create([
                'programme_id' => $programme->id, 'code' => $code, 'nom' => $code,
                'ordre' => $i + 1, 'actif' => true,
            ]);
        }

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.referentiel.entites.store', 'niveaux'),
            ['programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3, 'actif' => true]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionDoesntHaveErrors();
        $this->assertSame(3, Niveau::where('programme_id', $programme->id)->count());
    }

    public function test_ue_utilisee_par_maquette_non_brouillon_verrouillee(): void
    {
        // Garde « UE verrouillée » : toute maquette non-brouillon fige
        // l'UE (le contenu ne se modifie que par nouvelle version).
        [, $semestre, $ue] = $this->creerChaine($this->programmeA);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $this->programmeA->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'review', 'auteur_id' => $this->admin->id,
        ]);
        $maquette->unitesEnseignement()->attach($ue->id, ['semestre_id' => $semestre->id]);

        $reponse = $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['ues', $ue->id]),
            $this->payloadUe($ue, ['nom' => 'UE sabotée'])
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('ue');
        $this->assertSame('UE L1', $ue->fresh()->nom);

        // Contrôle : repassée en brouillon, la maquette ne verrouille plus.
        $maquette->update(['statut' => 'draft']);

        $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['ues', $ue->id]),
            $this->payloadUe($ue, ['nom' => 'UE corrigée'])
        )->assertRedirect();

        $this->assertSame('UE corrigée', $ue->fresh()->nom);
    }

    public function test_maquette_contenu_semestre_pivot_divergent_refuse(): void
    {
        // INV-5 : le semestre du pivot maquette_ue doit être celui de
        // l'UE — sinon deux sources de vérité divergentes dans le plan.
        [, $semestreS1, $ue] = $this->creerChaine($this->programmeA, 'L1', 'S1');
        $niveau2 = Niveau::create([
            'programme_id' => $this->programmeA->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2, 'actif' => true,
        ]);
        $semestreS3 = SemestreLmd::create([
            'niveau_id' => $niveau2->id, 'code' => 'S3', 'nom' => 'S3', 'numero' => 3, 'ordre' => 1, 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $this->programmeA->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'draft', 'auteur_id' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)->put(
            route('admin.referentiel.maquettes.contenu', $maquette),
            ['ues' => [['ue_id' => $ue->id, 'semestre_id' => $semestreS3->id]]]
        );

        $reponse->assertUnprocessable();
        $this->assertSame(0, $maquette->fresh()->unitesEnseignement()->count());

        // Contrôle : semestre aligné sur l'UE → accepté.
        $this->actingAs($this->admin)->put(
            route('admin.referentiel.maquettes.contenu', $maquette),
            ['ues' => [['ue_id' => $ue->id, 'semestre_id' => $semestreS1->id]]]
        )->assertRedirect();
        $this->assertSame(1, $maquette->fresh()->unitesEnseignement()->count());
    }
}
