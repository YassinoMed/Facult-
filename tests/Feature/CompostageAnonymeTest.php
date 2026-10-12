<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * LOOP NOTES ANONYMISÉES (§1-§23) : numéro de compostage par épreuve,
 * saisie anonyme sans PII, résolution serveur, permissions, scope,
 * IDOR, concurrence, import/export, verrouillage.
 */
class CompostageAnonymeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.compostage@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true]);
    }

    private int $compteurEpreuve = 0;

    private function creerEpreuve(): Epreuve
    {
        $this->compteurEpreuve++;
        // Créneaux disjoints par compteur : pas de conflit salle/groupe.
        $debut = sprintf('%02d:00', 8 + $this->compteurEpreuve);
        $fin = sprintf('%02d:00', 10 + $this->compteurEpreuve);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-PRINC-'.random_int(1000, 9999),
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20', 'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $this->groupe->id, 'date' => '2027-01-'.sprintf('%02d', 5 + $this->compteurEpreuve), 'heure_debut' => $debut, 'heure_fin' => $fin,
        ]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sara.'.mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $cin)).'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $etudiant;
    }

    public function test_generation_numero_format_non_sequentiel(): void
    {
        $epreuve = $this->creerEpreuve();
        $a = $this->creerEtudiant('CMPGEN001');
        $b = $this->creerEtudiant('CMPGEN002');

        $service = app(CompostageService::class);
        $na = $service->getOrCreate($epreuve, $a)->numero;
        $nb = $service->getOrCreate($epreuve, $b)->numero;

        $this->assertMatchesRegularExpression('/^CMP-\d{6}$/', $na);
        $this->assertNotSame($na, $nb);
    }

    public function test_get_or_create_idempotent(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPIDEM01');

        $service = app(CompostageService::class);
        $premier = $service->getOrCreate($epreuve, $etudiant);
        $second = $service->getOrCreate($epreuve, $etudiant);

        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, EvaluationCompostage::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_numero_different_par_epreuve(): void
    {
        $epreuveA = $this->creerEpreuve();
        $epreuveB = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPDIFF01');

        $service = app(CompostageService::class);

        $this->assertNotSame(
            $service->getOrCreate($epreuveA, $etudiant)->numero,
            $service->getOrCreate($epreuveB, $etudiant)->numero
        );
    }

    public function test_saisie_par_numero_sans_etudiant_id(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPSAIS01');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
            ['numero' => $numero, 'valeur' => 14.5],
        ], $this->admin->id);

        $this->assertSame(1, $resultat['saisies']);
        $this->assertSame([], $resultat['erreurs']);
        $this->assertDatabaseHas('evaluation_notes', ['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 14.50]);
    }

    public function test_validation_bornes_par_numero(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPBORN01');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;

        foreach ([-1, 99] as $horsBornes) {
            $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
                ['numero' => $numero, 'valeur' => $horsBornes],
            ], $this->admin->id);
            $this->assertNotEmpty($resultat['erreurs']);
        }

        foreach ([0, 20] as $borne) {
            $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
                ['numero' => $numero, 'valeur' => $borne],
            ], $this->admin->id);
            $this->assertSame([], $resultat['erreurs']);
        }
    }

    public function test_page_saisie_sans_pii(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPPII001');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        app(CompostageService::class)->getOrCreate($epreuve, $etudiant);

        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluations.notes.saisie', $epreuve));

        $reponse->assertOk();
        $reponse->assertSee('CMP-');
        $reponse->assertDontSee($etudiant->nom);
        $reponse->assertDontSee($etudiant->prenom);
        $reponse->assertDontSee($etudiant->cin);
        $reponse->assertDontSee($etudiant->email);
    }

    public function test_compostage_autre_epreuve_refuse(): void
    {
        $epreuveA = $this->creerEpreuve();
        $epreuveB = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPIDOR01');
        Candidat::create(['epreuve_id' => $epreuveB->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $numeroA = app(CompostageService::class)->getOrCreate($epreuveA, $etudiant)->numero;

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuveB, [
            ['numero' => $numeroA, 'valeur' => 12],
        ], $this->admin->id);

        $this->assertNotEmpty($resultat['erreurs']);
        $this->assertSame(0, $resultat['saisies']);
    }

    public function test_compostage_inexistant_refuse(): void
    {
        $epreuve = $this->creerEpreuve();

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
            ['numero' => 'CMP-000000', 'valeur' => 12],
        ], $this->admin->id);

        $this->assertNotEmpty($resultat['erreurs']);
    }

    public function test_scope_faculte_404(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FSJES', 'code' => 'FSJES', 'description' => 'T', 'active' => true]);
        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.compostage@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $epreuve = $this->creerEpreuve();

        $this->actingAs($adminRestreint)->get(route('admin.evaluations.notes.saisie', $epreuve))->assertNotFound();
    }

    public function test_correspondance_reservee_permission(): void
    {
        $epreuve = $this->creerEpreuve();
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.compostage@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->get(route('admin.evaluations.epreuves.correspondance', $epreuve))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.evaluations.epreuves.correspondance', $epreuve))->assertOk();
    }

    public function test_generation_refusee_epreuve_verrouillee(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPVER001');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $epreuve->update(['statut' => 'locked']);

        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.compostages.generer', $epreuve))
            ->assertSessionHasErrors('epreuve');
    }

    public function test_import_compostage_note(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPIMP001');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;

        $this->actingAs($this->admin)->post(route('admin.evaluations.notes.import-anonyme', $epreuve), [
            'lignes' => [['numero' => $numero, 'valeur' => 11]],
        ])->assertRedirect();

        $this->assertDatabaseHas('evaluation_notes', ['epreuve_id' => $epreuve->id, 'valeur' => 11.00]);
    }

    public function test_export_anonyme_sans_pii(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPEXP001');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        app(CompostageService::class)->getOrCreate($epreuve, $etudiant);
        app(SaisieNoteService::class)->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 13],
        ], $this->admin->id);

        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluations.notes.export-anonyme', $epreuve));

        $reponse->assertOk();
        $contenu = (string) $reponse->streamedContent();
        $this->assertStringContainsString('CMP-', $contenu);
        $this->assertStringNotContainsString($etudiant->nom, $contenu);
        $this->assertStringNotContainsString($etudiant->cin, $contenu);
    }

    public function test_audit_sans_pii(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPAUD001');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;
        app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
            ['numero' => $numero, 'valeur' => 12],
        ], $this->admin->id);

        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail();
        $historique = $note->historique()->latest('id')->firstOrFail();

        $this->assertStringNotContainsString($etudiant->cin, (string) $historique->motif);
        $this->assertStringNotContainsString($etudiant->email, (string) $historique->motif);
    }

    public function test_concurrence_double_generation_unique(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPCONC01');

        $service = app(CompostageService::class);
        $premier = $service->getOrCreate($epreuve, $etudiant);
        $second = $service->getOrCreate($epreuve, $etudiant);

        $this->assertSame($premier->numero, $second->numero);
        $this->assertSame(1, EvaluationCompostage::where('epreuve_id', $epreuve->id)
            ->where('etudiant_id', $etudiant->id)->count());

        // Contrainte UNIQUE epreuve×numero : doublon refusé en base.
        $autre = $this->creerEtudiant('CMPCONC02');
        $this->expectException(QueryException::class);
        EvaluationCompostage::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $autre->id, 'numero' => $premier->numero,
        ]);
    }

    public function test_saisie_sans_n_plus_un(): void
    {
        $epreuve = $this->creerEpreuve();

        for ($i = 0; $i < 10; $i++) {
            $etudiant = $this->creerEtudiant('CMPN1-'.$i.random_int(100, 999));
            Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
            app(CompostageService::class)->getOrCreate($epreuve, $etudiant);
        }

        \DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.evaluations.notes.saisie', $epreuve))->assertOk();
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(25, $requetes, "Saisie 10 candidats : {$requetes} requêtes (N+1 interdit).");
    }

    public function test_correspondance_hors_perimetre_404(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac2', 'email' => 'fac2.compostage@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $epreuve = $this->creerEpreuve();

        $this->actingAs($adminRestreint)->get(route('admin.evaluations.epreuves.correspondance', $epreuve))
            ->assertNotFound();
    }

    public function test_saisie_verrouillee_refusee(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPVER002');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;
        $epreuve->update(['statut' => 'locked']);

        $this->expectException(\DomainException::class);
        app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
            ['numero' => $numero, 'valeur' => 10],
        ], $this->admin->id);
    }

    public function test_store_anonyme_verrouillee_erreur_propre_sans_500(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPVERHTTP1');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;
        $epreuve->update(['statut' => 'locked']);

        $this->actingAs($this->admin)->post(route('admin.evaluations.notes.store-anonyme', $epreuve), [
            'lignes' => [['numero' => $numero, 'valeur' => 10]],
        ])->assertRedirect()->assertSessionHasErrors();
    }

    public function test_store_lot_verrouillee_erreur_propre_sans_500(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPVERHTTP2');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        app(CompostageService::class)->getOrCreate($epreuve, $etudiant);
        $epreuve->update(['statut' => 'locked']);

        $this->actingAs($this->admin)->post(route('admin.evaluations.notes.store', $epreuve), [
            'lignes' => [['etudiant_id' => $etudiant->id, 'valeur' => 10]],
        ])->assertRedirect()->assertSessionHasErrors();
    }

    public function test_import_anonyme_verrouillee_erreur_propre_sans_500(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant('CMPVERHTTP3');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;
        $epreuve->update(['statut' => 'locked']);

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},12\n"
        );

        $this->actingAs($this->admin)->post(route('admin.evaluations.notes.import-anonyme', $epreuve), [
            'fichier' => $fichier,
        ])->assertRedirect()->assertSessionHasErrors();
    }
}
