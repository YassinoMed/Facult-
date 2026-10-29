<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\DataExchange\ImportResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * LOT 0 — rattachement niveau / programme.
 *
 * `niveaux.code` n'est unique que PAR programme : deux programmes peuvent
 * porter un « L1 » avec des identifiants différents. Ces tests reproduisent
 * le cas réel et prouvent qu'aucun niveau d'un autre programme n'est
 * sélectionné silencieusement.
 */
class ImportNiveauProgrammeTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $gest;

    private Programme $demo;

    private Niveau $l1Gest;

    private Niveau $l1Demo;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.referentiel.active' => true,
            'modules.enseignement.active' => true,
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
        ]);

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'sigle' => 'FST',
            'description' => 'Test LOT 0',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité LOT 0',
            'email' => 'admin.lot0@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->gest = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'PAR-GEST',
            'nom' => 'Gestion',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->demo = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'DEMO-LGL',
            'nom' => 'Licence Génie Logiciel (démo)',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        // Ordre volontaire : DEMO-LGL est créé en premier. Un `first()`
        // global sur le code « L1 » retournerait donc son niveau — c'est
        // exactement le défaut reproduit.
        $this->l1Demo = Niveau::create([
            'programme_id' => $this->demo->id,
            'code' => 'L1',
            'nom' => 'Première année',
            'ordre' => 1,
        ]);

        $this->l1Gest = Niveau::create([
            'programme_id' => $this->gest->id,
            'code' => 'L1',
            'nom' => 'Première année',
            'ordre' => 1,
        ]);

        // L2 n'existe QUE dans DEMO-LGL : sert à prouver le refus d'un
        // niveau qui appartient à un autre programme.
        Niveau::create([
            'programme_id' => $this->demo->id,
            'code' => 'L2',
            'nom' => 'Deuxième année',
            'ordre' => 2,
        ]);
    }

    /* ────────────────────────────────────────────────────────────
     * Résolveur de base
     * ──────────────────────────────────────────────────────────── */

    public function test_le_resolveur_ne_confond_jamais_deux_programmes(): void
    {
        $this->assertNotSame($this->l1Demo->id, $this->l1Gest->id);

        $this->assertSame($this->l1Gest->id, Niveau::resoudreDansProgramme('L1', $this->gest->id)?->id);
        $this->assertSame($this->l1Demo->id, Niveau::resoudreDansProgramme('L1', $this->demo->id)?->id);

        // Casse et espaces : normalisation identique partout.
        $this->assertSame($this->l1Gest->id, Niveau::resoudreDansProgramme(' l1 ', $this->gest->id)?->id);

        // Niveau d'un autre programme : jamais retourné.
        $this->assertNull(Niveau::resoudreDansProgramme('L2', $this->gest->id));
        $this->assertNull(Niveau::resoudreDansProgramme('L1', 999999));

        // Sans programme : uniquement si le code est globalement unique.
        $this->assertNull(Niveau::resoudreCodeUnique('L1'));
        $this->assertNotNull(Niveau::resoudreCodeUnique('L2'));
    }

    /* ────────────────────────────────────────────────────────────
     * Import réel : le niveau résolu est celui du programme visé
     * ──────────────────────────────────────────────────────────── */

    public function test_import_inscription_resout_le_niveau_dans_le_programme(): void
    {
        $etudiant = $this->etudiant('08999888');

        [$token, $resultat] = $this->previewer(
            "cin;annee_universitaire;niveau;filiere\n"
            ."08999888;2026-2027;L1;PAR-GEST\n"
        );

        $this->assertCount(0, $resultat->erreurs);
        $this->assertCount(1, $resultat->valides);

        $this->confirmer($token);

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();

        $this->assertSame($this->gest->id, $inscription->programme_id);
        $this->assertSame($this->l1Gest->id, $inscription->niveau_id);
        $this->assertNotSame($this->l1Demo->id, $inscription->niveau_id);
        $this->assertSame('L1', $inscription->niveau);
        $this->assertSame(2, Semestre::where('inscription_academique_id', $inscription->id)->count());
    }

    public function test_import_inscription_refuse_un_niveau_d_un_autre_programme(): void
    {
        $etudiant = $this->etudiant('08999889');

        [$token, $resultat] = $this->previewer(
            "cin;annee_universitaire;niveau;filiere\n"
            ."08999889;2026-2027;L2;PAR-GEST\n"
        );

        $this->assertCount(0, $resultat->valides);
        $this->assertTrue(
            collect($resultat->erreurs)->contains(
                fn (array $erreur) => ($erreur['code'] ?? null) === 'NIVEAU_HORS_PROGRAMME'
            ),
            'Le niveau L2 de DEMO-LGL doit être refusé pour PAR-GEST.'
        );

        // Le preview est en lecture seule.
        $this->assertDatabaseMissing('inscriptions_academiques', ['etudiant_id' => $etudiant->id]);

        // Confirmation : aucune ligne valide → refus explicite, aucune écriture.
        $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.confirmer', 'inscriptions-academiques'), [
                'token' => $token,
                'mode' => 'upsert',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('fichier');

        $this->assertDatabaseMissing('inscriptions_academiques', ['etudiant_id' => $etudiant->id]);
    }

    public function test_import_inscription_sans_programme_ne_devine_jamais_le_niveau(): void
    {
        $etudiant = $this->etudiant('08999890');

        [$token, $resultat] = $this->previewer(
            "cin;annee_universitaire;niveau;filiere\n"
            ."08999890;2026-2027;L1;\n"
        );

        $this->assertCount(0, $resultat->erreurs);
        $this->assertCount(1, $resultat->valides);

        $this->confirmer($token);

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();

        // Deux « L1 » existent : aucun choix arbitraire n'est autorisé.
        $this->assertNull($inscription->programme_id);
        $this->assertNull($inscription->niveau_id);
        $this->assertSame('L1', $inscription->niveau);
    }

    public function test_import_inscription_est_idempotent_et_conserve_le_bon_niveau(): void
    {
        $etudiant = $this->etudiant('08999891');
        $csv = "cin;annee_universitaire;niveau;filiere\n"
            ."08999891;2026-2027;L1;PAR-GEST\n";

        [$token1] = $this->previewer($csv);
        $this->confirmer($token1, 'upsert');

        [$token2] = $this->previewer($csv);
        $this->confirmer($token2, 'upsert');

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertSame($this->gest->id, $inscription->programme_id);
        $this->assertSame($this->l1Gest->id, $inscription->niveau_id);
    }

    public function test_lot_mixte_ne_cree_aucun_rattachement_incoherent(): void
    {
        $valide = $this->etudiant('08999892');
        $refuse = $this->etudiant('08999893');

        [$token, $resultat] = $this->previewer(
            "cin;annee_universitaire;niveau;filiere\n"
            ."08999892;2026-2027;L1;PAR-GEST\n"
            ."08999893;2026-2027;L2;PAR-GEST\n"
        );

        $this->assertCount(1, $resultat->valides);
        $this->assertCount(1, $resultat->erreurs);

        $this->confirmer($token);

        $this->assertDatabaseHas('inscriptions_academiques', [
            'etudiant_id' => $valide->id,
            'programme_id' => $this->gest->id,
            'niveau_id' => $this->l1Gest->id,
        ]);
        $this->assertDatabaseMissing('inscriptions_academiques', ['etudiant_id' => $refuse->id]);
    }

    /* ────────────────────────────────────────────────────────────
     * Autres chemins corrigés : étudiants et groupes
     * ──────────────────────────────────────────────────────────── */

    public function test_import_etudiant_resout_le_niveau_dans_le_programme(): void
    {
        [$token, $resultat] = $this->previewer(
            "cin;nom;prenom;email;sexe;faculte;programme;niveau_ref;niveau;annee_academique\n"
            ."08999894;Test;Étudiant;08999894@etudiant.test;M;FST;PAR-GEST;L1;L1;2026-2027\n",
            'etudiants'
        );

        $this->assertCount(0, $resultat->erreurs);
        $this->assertCount(1, $resultat->valides);

        $this->confirmer($token, 'upsert', 'etudiants');

        $etudiant = Etudiant::where('cin', '08999894')->firstOrFail();
        $this->assertSame($this->gest->id, $etudiant->programme_id);
        $this->assertSame($this->l1Gest->id, $etudiant->niveau_id);
        $this->assertNotSame($this->l1Demo->id, $etudiant->niveau_id);
    }

    public function test_import_etudiant_refuse_un_niveau_d_un_autre_programme(): void
    {
        [, $resultat] = $this->previewer(
            "cin;nom;prenom;email;sexe;faculte;programme;niveau_ref;niveau;annee_academique\n"
            ."08999895;Test;Refusé;08999895@etudiant.test;M;FST;PAR-GEST;L2;L2;2026-2027\n",
            'etudiants'
        );

        $this->assertCount(0, $resultat->valides);
        $this->assertTrue(
            collect($resultat->erreurs)->contains(
                fn (array $erreur) => ($erreur['code'] ?? null) === 'NIVEAU_HORS_PROGRAMME'
            )
        );
        $this->assertDatabaseMissing('etudiants', ['cin' => '08999895']);
    }

    public function test_import_groupe_resout_le_niveau_dans_le_programme(): void
    {
        [$token, $resultat] = $this->previewer(
            "code;nom;faculte;programme;niveau_ref;niveau;capacite\n"
            ."GRP-LOT0;Groupe LOT 0;FST;PAR-GEST;L1;L1;30\n",
            'groupes'
        );

        $this->assertCount(0, $resultat->erreurs);
        $this->assertCount(1, $resultat->valides);

        $this->confirmer($token, 'upsert', 'groupes');

        $groupe = Group::where('code', 'GRP-LOT0')->firstOrFail();
        $this->assertSame($this->gest->id, $groupe->programme_id);
        $this->assertSame($this->l1Gest->id, $groupe->niveau_id);
        $this->assertNotSame($this->l1Demo->id, $groupe->niveau_id);
    }

    public function test_import_groupe_refuse_un_niveau_d_un_autre_programme(): void
    {
        [$token, $resultat] = $this->previewer(
            "code;nom;faculte;programme;niveau_ref;niveau;capacite\n"
            ."GRP-BAD;Groupe invalide;FST;PAR-GEST;L2;L2;30\n",
            'groupes'
        );

        // Le preview de GroupDefinition ne valide pas le niveau : le refus
        // est explicite à la confirmation (IoException → erreur de session).
        $this->assertCount(1, $resultat->valides);

        $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.confirmer', 'groupes'), [
                'token' => $token,
                'mode' => 'upsert',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('fichier');

        $this->assertDatabaseMissing('groups', ['code' => 'GRP-BAD']);
    }

    /* ────────────────────────────────────────────────────────────
     * Outils
     * ──────────────────────────────────────────────────────────── */

    private function etudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Étudiant',
            'email' => strtolower($cin).'@etudiant.test',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2025',
            'password' => 'Etudiant@123',
        ]);

        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    /**
     * @return array{0: string, 1: ImportResult}
     */
    private function previewer(string $csv, string $entite = 'inscriptions-academiques'): array
    {
        $fichier = UploadedFile::fake()->createWithContent('import.csv', $csv, 'text/csv');

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', $entite), ['fichier' => $fichier])
            ->assertOk();

        $token = $preview->viewData('token');
        $this->assertNotEmpty($token);

        return [$token, $preview->viewData('resultat')];
    }

    private function confirmer(string $token, string $mode = 'upsert', string $entite = 'inscriptions-academiques'): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.confirmer', $entite), [
                'token' => $token,
                'mode' => $mode,
            ])
            ->assertOk();
    }
}
