<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Evaluation\Services\SessionExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-EXAM-PREPARATION-01 — assistant de préparation des examens :
 * surface agrégée (/admin/examens/preparation), validation de
 * population (snapshot + numéros anonymes), contrôles de cohérence
 * étendus (capacité, dérive), émargement par salle avec aperçu et
 * historique, autorisations et isolation faculté.
 */
class ExamPreparationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private Room $salle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.prep'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
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
        $this->salle = Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true]);
    }

    /* ── Helpers ── */

    private function creerSession(): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-PRINC', 'nom' => 'Principale 2026-2027', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20', 'statut' => 'draft',
        ]);
    }

    private function creerEpreuve(SessionExamen $session, array $surcharge = []): Epreuve
    {
        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1',
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        return Epreuve::create(array_merge([
            'code' => 'EP-001', 'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->ecue->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-06', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'statut' => 'draft',
        ], $surcharge));
    }

    private function creerEtudiant(string $nom = 'Benali', string $prenom = 'Sara'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'EE'.random_int(100000, 999999),
            'nom' => $nom, 'prenom' => $prenom,
            'email' => strtolower($nom).'.'.random_int(10000, 99999).'@test.ma',
            'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Tests', 'ville' => 'Casablanca',
            'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.random_int(10000, 99999)])->save();

        return $etudiant;
    }

    private function peuplerCandidats(Epreuve $epreuve, int $nombre, ?int $salleId = null): array
    {
        $etudiants = [];
        for ($i = 0; $i < $nombre; $i++) {
            $etudiant = $this->creerEtudiant('Étudiant'.$i, 'RéÉçï'.$i);
            $etudiants[] = $etudiant;
            Candidat::create([
                'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
                'salle_id' => $salleId ?? $epreuve->salle_id, 'statut' => 'present',
            ]);
        }

        return $etudiants;
    }

    /* ── 1. Accès, module, autorisations ── */

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.evaluation.active' => false]);
        $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.index'))
            ->assertNotFound();
    }

    public function test_acces_invite_redirige(): void
    {
        $this->get(route('admin.examens.preparation.index'))->assertRedirect();
    }

    public function test_agent_sans_permission_update_refuse_validation(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.prep'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $epreuve = $this->creerEpreuve($this->creerSession());
        $this->peuplerCandidats($epreuve, 1);

        $this->actingAs($agent)
            ->post(route('admin.examens.preparation.epreuves.population', $epreuve))
            ->assertForbidden();
    }

    public function test_tableau_de_bord_et_assistant_accessibles(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $this->peuplerCandidats($epreuve, 2);

        $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.index'))
            ->assertOk()
            ->assertSee('Préparation des examens');

        $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.session', $session))
            ->assertOk()
            ->assertSee('Algorithmique')
            ->assertSee('Valider population');
    }

    public function test_epreuve_hors_perimetre_faculte_404(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDroit', 'code' => 'FD', 'description' => 'T', 'active' => true]);
        $groupeB = Group::create(['code' => 'GD1', 'nom' => 'Droit 1', 'faculte_id' => $faculteB->id, 'niveau' => 'L1', 'actif' => true]);

        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac A', 'email' => 'facA.prep'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $epreuveB = $this->creerEpreuve($this->creerSession(), ['groupe_id' => $groupeB->id, 'salle_id' => null]);
        $this->peuplerCandidats($epreuveB, 1);

        $this->actingAs($adminRestreint)
            ->get(route('admin.examens.preparation.epreuves.emargement', $epreuveB))
            ->assertNotFound();

        $this->actingAs($adminRestreint)
            ->post(route('admin.examens.preparation.epreuves.population', $epreuveB))
            ->assertNotFound();
    }

    /* ── 2. Validation de population (G4 + G5) ── */

    public function test_valider_population_fige_snapshot_et_numeros_anonymes(): void
    {
        $epreuve = $this->creerEpreuve($this->creerSession());
        $etudiants = $this->peuplerCandidats($epreuve, 3);

        $this->actingAs($this->admin)
            ->post(route('admin.examens.preparation.epreuves.population', $epreuve))
            ->assertRedirect()
            ->assertSessionHas('succes');

        $epreuve = $epreuve->fresh();
        $this->assertNotNull($epreuve->population_validated_at);
        $this->assertSame(3, $epreuve->population_effectif);
        $this->assertSame(64, strlen((string) $epreuve->population_hash));

        $numeros = $epreuve->candidats()->orderBy('etudiant_id')->pluck('numero_anonyme');
        $this->assertSame(3, $numeros->unique()->count());
        $this->assertNotContains(null, $numeros->all());

        // Aucun numéro n'est la CIN d'un étudiant.
        $cins = array_map(fn (Etudiant $e) => $e->cin, $etudiants);
        foreach ($numeros as $numero) {
            $this->assertNotContains($numero, $cins);
        }
    }

    public function test_validation_idempotente_numeros_stables(): void
    {
        $epreuve = $this->creerEpreuve($this->creerSession());
        $this->peuplerCandidats($epreuve, 2);

        $service = app(PreparationExamenService::class);
        $service->validerPopulation($epreuve, (int) $this->admin->id);
        $avant = $epreuve->candidats()->orderBy('etudiant_id')->pluck('numero_anonyme')->all();
        $hashAvant = $epreuve->fresh()->population_hash;

        // Re-validation : zéro doublon créé, numéros et hash stables.
        $service->validerPopulation($epreuve, (int) $this->admin->id);

        $this->assertSame(2, $epreuve->candidats()->count());
        $this->assertSame($avant, $epreuve->candidats()->orderBy('etudiant_id')->pluck('numero_anonyme')->all());
        $this->assertSame($hashAvant, $epreuve->fresh()->population_hash);
    }

    public function test_numero_anonyme_et_candidat_uniques_niveau_base(): void
    {
        $epreuve = $this->creerEpreuve($this->creerSession());
        $etudiantA = $this->creerEtudiant('Anonyme', 'Un');
        $etudiantB = $this->creerEtudiant('Anonyme', 'Deux');

        Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiantA->id,
            'numero_anonyme' => 'AN-0001', 'statut' => 'present',
        ]);

        // Même numéro anonyme pour un autre candidat de la même épreuve : refus DB.
        try {
            Candidat::create([
                'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiantB->id,
                'numero_anonyme' => 'AN-0001', 'statut' => 'present',
            ]);
            $this->fail('Le numéro anonyme en double aurait dû être refusé par la base.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1); // refus attendu
        }

        // Doublon de candidat (même étudiant, même épreuve) : refus DB.
        $this->expectException(UniqueConstraintViolationException::class);
        Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiantA->id,
            'statut' => 'present',
        ]);
    }

    /* ── 3. Dérive de population et capacité (G6) ── */

    public function test_population_modifiee_signalée_et_blocage_ouverture(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $this->peuplerCandidats($epreuve, 2);

        $service = app(PreparationExamenService::class);
        $service->validerPopulation($epreuve, (int) $this->admin->id);

        // Dérive : un candidat ajouté après validation.
        Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $this->creerEtudiant('Nouveau', 'Etudiant')->id,
            'salle_id' => $this->salle->id, 'statut' => 'present',
        ]);

        $controles = $service->verifierEpreuve($epreuve->fresh());
        $this->assertTrue($controles['population_modifiee']);
        $this->assertNotEmpty(array_filter(
            $controles['avertissements'],
            fn (string $message) => str_contains($message, 'Population modifiée')
        ));

        // Readiness : la dérive bloque l'ouverture de la session.
        $readiness = app(SessionExamenService::class)->readiness($session);
        $this->assertSame(1, $readiness['population_modifiee']);
        $this->assertNotEmpty(array_filter(
            $readiness['bloquants'],
            fn (string $message) => str_contains($message, 'revalider la population')
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('revalider la population');
        app(SessionExamenService::class)->transiter($session, 'open');
    }

    public function test_revalidation_efface_le_signal_de_dérive(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $this->peuplerCandidats($epreuve, 2);

        $service = app(PreparationExamenService::class);
        $service->validerPopulation($epreuve, (int) $this->admin->id);

        Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $this->creerEtudiant('Tardif', 'Candidat')->id,
            'salle_id' => $this->salle->id, 'statut' => 'present',
        ]);

        $service->validerPopulation($epreuve->fresh(), (int) $this->admin->id);

        $readiness = app(SessionExamenService::class)->readiness($session);
        $this->assertSame(0, $readiness['population_modifiee']);
        $this->assertSame(3, $epreuve->fresh()->population_effectif);
    }

    public function test_capacite_insuffisante_bloque_ouverture(): void
    {
        $petiteSalle = Room::create(['code' => 'B2', 'nom' => 'B2', 'capacite' => 2, 'actif' => true]);
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session, ['salle_id' => $petiteSalle->id]);
        $this->peuplerCandidats($epreuve, 3, $petiteSalle->id);

        $controles = app(PreparationExamenService::class)->verifierEpreuve($epreuve);
        $this->assertNotEmpty(array_filter(
            $controles['bloquants'],
            fn (string $message) => str_contains($message, 'capacité de 2')
        ));

        $readiness = app(SessionExamenService::class)->readiness($session);
        $this->assertSame(1, $readiness['capacite_insuffisante']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sur-capacité');
        app(SessionExamenService::class)->transiter($session, 'open');
    }

    public function test_salle_sans_capacite_renseignee_avertit_sans_bloquer(): void
    {
        $salleInconnue = Room::create(['code' => 'B3', 'nom' => 'B3', 'capacite' => null, 'actif' => true]);
        $epreuve = $this->creerEpreuve($this->creerSession(), ['salle_id' => $salleInconnue->id]);
        $this->peuplerCandidats($epreuve, 2, $salleInconnue->id);

        $controles = app(PreparationExamenService::class)->verifierEpreuve($epreuve);

        $this->assertNotEmpty(array_filter(
            $controles['avertissements'],
            fn (string $message) => str_contains($message, 'capacité non renseignée')
        ));
        $this->assertEmpty(array_filter(
            $controles['bloquants'],
            fn (string $message) => str_contains($message, 'capacité')
        ));
    }

    /* ── 4. Émargement PDF (G2 + G3) ── */

    public function test_emargement_pdf_par_epreuve_telechargement_et_trace(): void
    {
        $epreuve = $this->creerEpreuve($this->creerSession());
        $this->peuplerCandidats($epreuve, 2);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.epreuves.emargement', $epreuve))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment; filename="emargement-'.$epreuve->id.'.pdf"', (string) $reponse->baseResponse->headers->get('Content-Disposition'));

        $trace = DocumentExamenGenere::where('epreuve_id', $epreuve->id)->get();
        $this->assertCount(1, $trace);
        $this->assertSame(2, $trace->first()->candidats_count);
        $this->assertSame((int) $this->admin->id, (int) $trace->first()->genere_par);
        $this->assertNull($trace->first()->salle_id);
    }

    public function test_emargement_apercu_inline(): void
    {
        $epreuve = $this->creerEpreuve($this->creerSession());
        $this->peuplerCandidats($epreuve, 1);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.epreuves.emargement', $epreuve).'?apercu=1')
            ->assertOk();

        $this->assertStringContainsString('inline', (string) $reponse->baseResponse->headers->get('Content-Disposition'));
    }

    public function test_emargement_par_salle_filtree_et_tracee(): void
    {
        $salleB = Room::create(['code' => 'C07', 'nom' => 'C07', 'capacite' => 30, 'actif' => true]);
        $epreuve = $this->creerEpreuve($this->creerSession());
        $this->peuplerCandidats($epreuve, 2, $this->salle->id);
        $this->peuplerCandidats($epreuve, 3, $salleB->id);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.epreuves.emargement', $epreuve).'?salle_id='.$salleB->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment; filename="emargement-'.$epreuve->id.'-'.$salleB->id.'.pdf"', (string) $reponse->baseResponse->headers->get('Content-Disposition'));

        $trace = DocumentExamenGenere::where('epreuve_id', $epreuve->id)->where('salle_id', $salleB->id)->firstOrFail();
        $this->assertSame(3, $trace->candidats_count);
    }

    public function test_emargement_liste_vide_pdf_vide_trace(): void
    {
        $epreuve = $this->creerEpreuve($this->creerSession());

        $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.epreuves.emargement', $epreuve))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(0, (int) DocumentExamenGenere::where('epreuve_id', $epreuve->id)->firstOrFail()->candidats_count);
    }

    public function test_historique_de_generation_affiche_et_cumule(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $this->peuplerCandidats($epreuve, 1);

        $route = route('admin.examens.preparation.epreuves.emargement', $epreuve);
        $this->actingAs($this->admin)->get($route)->assertOk();
        $this->actingAs($this->admin)->get($route)->assertOk();

        // Réimpression : chaque génération laisse une trace distincte.
        $this->assertSame(2, DocumentExamenGenere::where('epreuve_id', $epreuve->id)->count());

        $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.session', $session))
            ->assertOk()
            ->assertSee('emargement-'.$epreuve->id.'.pdf')
            ->assertSee('Historique de génération');
    }

    public function test_gros_effectif_pdf_genere_sans_erreur(): void
    {
        $epreuve = $this->creerEpreuve($this->creerSession(), ['salle_id' => null]);
        $salles = collect([Room::create(['code' => 'G01', 'nom' => 'G01', 'capacite' => 15, 'actif' => true]),
            Room::create(['code' => 'G02', 'nom' => 'G02', 'capacite' => 15, 'actif' => true])]);

        // 25 candidats répartis sur deux salles (pagination multi-pages).
        foreach (range(1, 25) as $i) {
            $etudiant = $this->creerEtudiant('Étudiant'.$i, 'RéÉçï'.$i);
            Candidat::create([
                'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
                'salle_id' => $salles[$i % 2]->id, 'statut' => 'present',
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.examens.preparation.epreuves.emargement', $epreuve))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(25, (int) DocumentExamenGenere::where('epreuve_id', $epreuve->id)->firstOrFail()->candidats_count);
    }
}
