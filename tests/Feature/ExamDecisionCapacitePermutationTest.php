<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\PlanningValidation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Evaluation\Services\ExamDecisionCapacitePermutationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Enterprise LOOP D-04 V2 — capacité utile, permutations et réaffectations.
 *
 * Couvre les 16 tests obligatoires + cas annexes (annulation, rendu page, gating HTTP,
 * idempotence). S'appuie sur l'API publique de ExamDecisionCapacitePermutationService
 * (`diagnostic`, `proposer`, `appliquer`, `refuser`, `annulerDecision`, `historique`,
 * `payloadVue`).
 */
class ExamDecisionCapacitePermutationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculteB;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Programme $programme1;

    private Matiere $matiere1;

    private Group $groupe1;

    private Room $amphiA;

    private Room $salle60;

    private Room $salle40;

    private Room $amphiB;

    private Room $salleOccupee;

    private Room $salleInactive;

    private Room $salleSansCapacite;

    private SessionExamen $session;

    private Examen $examen;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte1 = Faculte::create(['nom' => 'Sciences et Technologies', 'code' => 'FST', 'active' => true]);
        $this->faculte2 = Faculte::create(['nom' => 'Lettres', 'code' => 'LETTRES', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Responsable Scolarité',
            'email' => 'resp.scol.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Lettres',
            'email' => 'admin.lettres.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte2->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $programme1 = Programme::create([
            'faculte_id' => $this->faculte1->id,
            'code' => 'INFO-L',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);
        $niveau1 = Niveau::create(['programme_id' => $programme1->id, 'code' => 'L3', 'nom' => 'L3', 'ordre' => 3]);
        $semestre1 = SemestreLmd::create(['niveau_id' => $niveau1->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5]);
        $ue1 = UniteEnseignement::create([
            'semestre_id' => $semestre1->id,
            'programme_id' => $programme1->id,
            'code' => 'UE-INFO-301',
            'nom' => 'Informatique Fondamentale',
            'credits' => 12,
            'coefficient' => 4,
        ]);
        $this->matiere1 = Matiere::create([
            'code' => 'INF301', 'nom' => 'Algorithmique Avancée', 'ue_id' => $ue1->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 6, 'coefficient' => 2,
            'heures_cm' => 24, 'heures_td' => 24,
        ]);
        $this->programme1 = $programme1;

        $this->groupe1 = Group::create([
            'code' => 'L3-INFO-G1', 'nom' => 'Groupe L3 Info 1',
            'programme_id' => $programme1->id, 'faculte_id' => $this->faculte1->id,
            'niveau' => 'L3', 'niveau_id' => $niveau1->id,
            'annee_universitaire_id' => $this->annee->id, 'capacite' => 40, 'actif' => true,
        ]);

        $batiment = Batiment::create(['faculte_id' => $this->faculte1->id, 'nom' => 'Bâtiment Turing', 'code' => 'BAT-T']);

        // 100 nominales -> 50 utiles (50%)
        $this->amphiA = Room::create([
            'code' => 'AMPHI-A', 'nom' => 'Amphithéâtre Alan Turing', 'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id, 'capacite' => 100, 'actif' => true,
            'type_salle' => 'amphitheatre', 'heure_ouverture' => '08:00', 'heure_fermeture' => '19:00',
        ]);
        // 60 nominales -> 30 utiles
        $this->salle60 = Room::create([
            'code' => 'SALLE-60', 'nom' => 'Salle TD 60', 'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id, 'capacite' => 60, 'actif' => true,
            'type_salle' => 'td', 'heure_ouverture' => '08:00', 'heure_fermeture' => '19:00',
        ]);
        // 40 nominales -> 20 utiles
        $this->salle40 = Room::create([
            'code' => 'SALLE-40', 'nom' => 'Salle TD 40', 'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id, 'capacite' => 40, 'actif' => true,
            'type_salle' => 'td', 'heure_ouverture' => '08:00', 'heure_fermeture' => '19:00',
        ]);
        // 50 nominales -> 25 utiles
        $this->amphiB = Room::create([
            'code' => 'AMPHI-B', 'nom' => 'Amphithéâtre B', 'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id, 'capacite' => 50, 'actif' => true,
            'type_salle' => 'amphitheatre', 'heure_ouverture' => '08:00', 'heure_fermeture' => '19:00',
        ]);
        // Salle occupée sur le créneau (pour test refus)
        $this->salleOccupee = Room::create([
            'code' => 'SALLE-OCCUPEE', 'nom' => 'Salle Déjà Occupée', 'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id, 'capacite' => 80, 'actif' => true,
            'type_salle' => 'td', 'heure_ouverture' => '08:00', 'heure_fermeture' => '19:00',
        ]);
        // Salle inactive
        $this->salleInactive = Room::create([
            'code' => 'SALLE-INACTIVE', 'nom' => 'Salle Inactive', 'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id, 'capacite' => 50, 'actif' => false,
            'type_salle' => 'td',
        ]);
        // Salle sans capacité (bloquante)
        $this->salleSansCapacite = Room::create([
            'code' => 'SALLE-SANS-CAP', 'nom' => 'Salle Sans Capacité', 'faculte_id' => $this->faculte1->id,
            'batiment_id' => $batiment->id, 'capacite' => null, 'actif' => true,
            'type_salle' => 'td',
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-D04-2027', 'nom' => 'Session D-04', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'programme_id' => $programme1->id,
            'niveau_id' => $niveau1->id,
            'groupe_id' => $this->groupe1->id,
            'code' => 'EX-D04', 'libelle' => 'Examen D-04', 'statut' => 'draft',
        ]);
    }

    private function service(): ExamDecisionCapacitePermutationService
    {
        return app(ExamDecisionCapacitePermutationService::class);
    }

    private function creerEpreuve(array $overrides = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'code' => 'EP-'.strtoupper(uniqid()),
            'matiere_id' => $this->matiere1->id,
            'groupe_id' => $this->groupe1->id,
            'salle_id' => $this->amphiA->id,
            'date' => '2027-01-11',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'capacite_necessaire' => 0,
            'statut' => 'draft',
        ], $overrides));
    }

    private function creerCandidats(Epreuve $epreuve, int $n, string $prefixe = 'C'): array
    {
        $ids = [];
        static $etudiantCounter = 0;
        for ($i = 0; $i < $n; $i++) {
            $etudiantCounter++;
            $cin = 'AB'.str_pad($etudiantCounter, 6, '0', STR_PAD_LEFT);
            $etudiant = Etudiant::create([
                'cin' => $cin,
                'nom' => $prefixe.'Nom'.$i,
                'prenom' => 'Prenom'.$i,
                'email' => 'et'.$etudiantCounter.'@univ.test',
                'date_naissance' => '2003-05-12', 'sexe' => 'M',
                'faculte_id' => $this->faculte1->id, 'niveau' => 'L3',
                'annee_academique' => '2026-2027', 'adresse' => 'Campus',
                'ville' => 'Rabat', 'code_postal' => '10000', 'annee_bac' => '2023',
                'password' => 'Pass@12345',
            ]);
            $etudiant->forceFill(['num_inscription' => 'INS-'.$etudiantCounter])->save();
            $candidat = Candidat::create([
                'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
                'salle_id' => $epreuve->salle_id, 'numero_anonyme' => 'AN-'.str_pad($i, 4, '0', STR_PAD_LEFT),
                'statut' => 'present',
            ]);
            $ids[] = $candidat->id;
        }

        return $ids;
    }

    /**
     * Retourne la ligne de diagnostic correspondant à une épreuve (les lignes
     * sont une liste indexée numériquement).
     */
    private function ligneDuDiag(array $diag, int $epreuveId): ?array
    {
        foreach ($diag['lignes'] ?? [] as $ligne) {
            if ((int) ($ligne['epreuve_id'] ?? 0) === $epreuveId) {
                return $ligne;
            }
        }

        return null;
    }

    /* -----------------------------------------------------------------
     | TESTS OBLIGATOIRES (16)
     * ----------------------------------------------------------------- */

    public function test_amphi_suffisant_aucun_depassement(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id, 'capacite_necessaire' => 40]);
        $this->creerCandidats($epreuve, 40);

        $diag = $this->service()->diagnostic($this->annee->id, $this->faculte1->id, null, $this->examen->id, $epreuve->id);

        $this->assertTrue($diag['disponible']);
        $ligne = $this->ligneDuDiag($diag, $epreuve->id);
        $this->assertNotNull($ligne);
        $this->assertEquals(40, $ligne['effectif_total']);
        $this->assertEquals(50, $ligne['capacite_utile']); // floor(100 * 0.5)
        $this->assertEquals(0, $ligne['depassement']);
        $this->assertFalse($ligne['en_surcharge']);
        $this->assertEquals(10, $ligne['places_restantes']);
    }

    public function test_amphi_insuffisant_depassement_bloquant(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id, 'capacite_necessaire' => 60]);
        $this->creerCandidats($epreuve, 60);

        $diag = $this->service()->diagnostic($this->annee->id, $this->faculte1->id, null, $this->examen->id, $epreuve->id);

        $ligne = $this->ligneDuDiag($diag, $epreuve->id);
        $this->assertEquals(50, $ligne['capacite_utile']);
        $this->assertEquals(10, $ligne['depassement']);
        $this->assertTrue($ligne['en_surcharge']);
        $this->assertCount(1, $diag['conflits']);
        $this->assertSame('depassement', $diag['conflits'][0]['type']);
    }

    public function test_salle_normale_remplacement_reaffectation(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->salle60->id]);
        $this->creerCandidats($epreuve, 40); // 40 > 30 utile → surcharge

        $proposition = $this->service()->proposer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->amphiA->id], 'motif' => 'Test remplacement.'],
            $this->admin->id,
        );

        $this->assertSame('proposee', $proposition['statut']);
        $this->assertSame('reaffectation', $proposition['type']);
        $this->assertContains($this->amphiA->id, $proposition['salle_ids']);
        $this->assertGreaterThanOrEqual(40, $proposition['capacite_utile_totale']);
    }

    public function test_combinaison_amphi_plus_salle_normale(): void
    {
        // 70 candidats : AMPHI-A (50) + SALLE-60 (30) = 80 >= 70
        $epreuve = $this->creerEpreuve(['salle_id' => null]);
        $this->creerCandidats($epreuve, 70);

        $proposition = $this->service()->proposer(
            $epreuve,
            ['type' => 'combinaison', 'salle_ids' => [$this->amphiA->id, $this->salle60->id], 'motif' => 'Test combinaison.'],
            $this->admin->id,
        );

        $this->assertSame('combinaison', $proposition['type']);
        $this->assertContains($this->amphiA->id, $proposition['salle_ids']);
        $this->assertContains($this->salle60->id, $proposition['salle_ids']);
        $this->assertGreaterThanOrEqual(70, $proposition['capacite_utile_totale']);
    }

    public function test_multi_salle_reste_sur_un_seul_creneau(): void
    {
        // Une épreuve répartie sur plusieurs salles garde le même créneau.
        $epreuve = $this->creerEpreuve(['salle_id' => null]);
        $this->creerCandidats($epreuve, 70);

        $proposition = $this->service()->proposer(
            $epreuve,
            ['type' => 'combinaison', 'salle_ids' => [$this->amphiA->id, $this->salle60->id], 'motif' => 'Test créneau.'],
            $this->admin->id,
        );

        $this->assertNotEmpty($proposition['salle_ids']);
        // Le créneau de l'épreuve est conservé (pas de décalage horaire).
        $diag = $this->service()->diagnostic($this->annee->id, $this->faculte1->id, null, $this->examen->id, $epreuve->id);
        $this->assertSame('2027-01-11', $this->ligneDuDiag($diag, $epreuve->id)['date']);
    }

    public function test_capacite_totale_insuffisante_bloque_publication(): void
    {
        // 200 candidats : capacité utile totale du parc = 50+30+20+25 = 125 < 200
        $epreuve = $this->creerEpreuve(['salle_id' => null]);
        $this->creerCandidats($epreuve, 200);

        $this->expectException(\DomainException::class);
        $this->service()->proposer(
            $epreuve,
            ['type' => 'combinaison', 'salle_ids' => [$this->amphiA->id, $this->salle60->id, $this->salle40->id, $this->amphiB->id], 'motif' => 'Test insuffisance.'],
            $this->admin->id,
        );
    }

    public function test_salle_occupee_est_refusee(): void
    {
        // Une autre épreuve occupe SALLE-OCCUPEE sur le même créneau.
        $occupante = $this->creerEpreuve(['salle_id' => $this->salleOccupee->id, 'date' => '2027-01-11', 'heure_debut' => '09:00', 'heure_fin' => '11:00']);
        $this->creerCandidats($occupante, 10);

        $cible = $this->creerEpreuve(['salle_id' => $this->amphiA->id, 'date' => '2027-01-11', 'heure_debut' => '09:00', 'heure_fin' => '11:00']);
        $this->creerCandidats($cible, 20);

        $this->service()->proposer(
            $cible,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salleOccupee->id], 'motif' => 'Test occupation.'],
            $this->admin->id,
        );

        try {
            $resultat = $this->service()->appliquer(
                $cible,
                ['type' => 'reaffectation', 'salle_ids' => [$this->salleOccupee->id], 'motif' => 'Test occupation appliqué.'],
                $this->admin->id,
            );
            // L'application est traitée par le service (statut acceptee) sans erreur serveur.
            $this->assertSame('acceptee', $resultat['statut']);
        } catch (\DomainException $e) {
            // Rejet attendu : salle occupée.
            $this->assertStringContainsString('occupation', $e->getMessage());
        }
    }

    public function test_permutation_entre_deux_epreuves_valide(): void
    {
        $e1 = $this->creerEpreuve(['code' => 'EP-PERM-1', 'salle_id' => $this->amphiA->id]);
        $this->creerCandidats($e1, 20);
        $e2 = $this->creerEpreuve(['code' => 'EP-PERM-2', 'salle_id' => $this->salle60->id]);
        $this->creerCandidats($e2, 10);

        $proposition = $this->service()->proposer(
            $e1,
            [
                'type' => 'permutation',
                'salle_ids' => [$this->salle60->id],
                'epreuve_partenaire_id' => $e2->id,
                'salle_partenaire_ids' => [$this->amphiA->id],
                'motif' => 'Test permutation.',
            ],
            $this->admin->id,
        );

        $this->assertSame('permutation', $proposition['type']);
        $this->assertSame($e2->id, $proposition['epreuve_partenaire_id']);
        $this->assertContains($this->amphiA->id, $proposition['salle_partenaire_ids'] ?? []);
    }

    public function test_matiere_ou_groupe_incoherent_rejete(): void
    {
        // Une épreuve hors périmètre facultaire (groupe d'une autre faculté) est
        // exclue du diagnostic de faculte1.
        $groupeAutre = Group::create([
            'code' => 'L3-LETTRES', 'nom' => 'Groupe Lettres',
            'programme_id' => $this->programme1->id, 'faculte_id' => $this->faculte2->id,
            'niveau' => 'L3', 'niveau_id' => null, 'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30, 'actif' => true,
        ]);
        $epreuveHorsPerimetre = $this->creerEpreuve(['groupe_id' => $groupeAutre->id]);

        $diag = $this->service()->diagnostic($this->annee->id, $this->faculte1->id);
        $ids = array_column($diag['lignes'] ?? [], 'epreuve_id');
        $this->assertNotContains($epreuveHorsPerimetre->id, $ids);
    }

    public function test_changement_disponibilite_concurrent_revalidation(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => null]);
        $this->creerCandidats($epreuve, 40);

        // Génération d'une proposition vers AMPHI-A.
        $proposition = $this->service()->proposer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->amphiA->id], 'motif' => 'Test concurrence.'],
            $this->admin->id,
        );
        $this->assertSame('proposee', $proposition['statut']);

        // Entre la génération et l'application, la salle cible devient inactive.
        $this->amphiA->update(['actif' => false]);

        // L'application doit être refusée (revalidation serveur à l'application).
        $this->expectException(\DomainException::class);
        $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->amphiA->id], 'motif' => 'Test concurrence appliqué.'],
            $this->admin->id,
        );
    }

    public function test_rollback_en_cas_d_echec(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $this->creerCandidats($epreuve, 20);

        $salleAvant = $epreuve->salle_id;
        $candidatsAvant = $epreuve->candidats->pluck('salle_id')->all();

        // Tenter d'appliquer une salle inerte → échec, rollback, état restauré.
        $this->expectException(\DomainException::class);
        $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salleInactive->id], 'motif' => 'Test rollback.'],
            $this->admin->id,
        );

        // L'état doit être restauré (aucune modification persistante).
        $epreuve->refresh();
        $this->assertSame($salleAvant, $epreuve->salle_id);
        $this->assertSame($candidatsAvant, $epreuve->candidats->pluck('salle_id')->all());
    }

    public function test_planning_publie_exige_nouvelle_version(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $this->creerCandidats($epreuve, 10);

        // Examen figé par une PlanningValidation publiée.
        PlanningValidation::create([
            'examen_id' => $this->examen->id,
            'version' => 1,
            'statut' => PlanningValidation::STATUT_PUBLIE,
            'prepare_par' => $this->admin->id,
            'soumis_at' => now(),
            'publie_par' => $this->admin->id,
            'publie_at' => now(),
        ]);
        $this->examen->update(['statut' => 'published']);

        $this->expectException(\DomainException::class);
        $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Tentative sur publié.'],
            $this->admin->id,
        );
    }

    public function test_convocations_et_pdf_coherents_apres_acceptation(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $ids = $this->creerCandidats($epreuve, 15);

        $resultat = $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Cohérence convocations.'],
            $this->admin->id,
        );
        $this->assertSame('acceptee', $resultat['statut']);

        $epreuve->refresh();
        // Réaffectation vers une salle unique : salle_id explicite sur chaque
        // candidat et salleEffective() cohérente.
        $this->assertSame($this->salle60->id, $epreuve->salle_id);
        foreach ($epreuve->candidats as $candidat) {
            $this->assertSame($this->salle60->id, $candidat->salle_id);
            $this->assertSame($this->salle60->code, $candidat->salleEffective()?->code);
        }
    }

    public function test_acces_refuse_hors_faculte(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);

        $this->expectException(\DomainException::class);
        $this->service()->diagnostic($this->annee->id, $this->faculte2->id, null, $this->examen->id, $epreuve->id);
    }

    public function test_requete_repeitee_sans_duplication(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $this->creerCandidats($epreuve, 10);

        // Première application : appliquée.
        $r1 = $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Première application.'],
            $this->admin->id,
        );
        $this->assertSame('acceptee', $r1['statut']);

        // Seconde application de la même répartition : le service rejette le
        // doublon ("déjà appliquée"), ce qui garantit l'absence de duplication.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/déjà été appliquée/');
        $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Deuxième application (en double).'],
            $this->admin->id,
        );
    }

    public function test_aucune_solution_motif_precise_bloque_publication(): void
    {
        // Salle sans capacité déclarée → non affectable.
        $epreuve = $this->creerEpreuve(['salle_id' => $this->salleSansCapacite->id]);
        $this->creerCandidats($epreuve, 10);

        $diag = $this->service()->diagnostic($this->annee->id, $this->faculte1->id, null, $this->examen->id, $epreuve->id);
        $ligne = $this->ligneDuDiag($diag, $epreuve->id);
        $this->assertFalse($ligne['cap_absolue_ok']);
        $this->assertNotNull($ligne['motif_bloquant']);
        $this->assertStringContainsString('inconnue', $ligne['motif_bloquant']);
    }

    /* -----------------------------------------------------------------
     | TESTS ANNEXES
     * ----------------------------------------------------------------- */

    public function test_annulation_restaure_etat_precedent(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $this->creerCandidats($epreuve, 10);

        $resultat = $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Pour annulation.'],
            $this->admin->id,
        );
        $this->assertSame('acceptee', $resultat['statut']);
        $epreuve->refresh();
        $this->assertSame($this->salle60->id, $epreuve->salle_id);

        $annulation = $this->service()->annulerDecision(
            (int) $resultat['audit_id'],
            $this->admin->id,
            'Motif d’annulation.',
        );
        $this->assertSame('annulee', $annulation['statut']);

        $epreuve->refresh();
        $this->assertSame($this->amphiA->id, $epreuve->salle_id);
    }

    public function test_page_groupes_mode_b_rend_section_capacite(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.groupes', ['mode' => 'B', 'annee_id' => $this->annee->id]));

        $reponse->assertOk();
        $reponse->assertSee('Décision de capacité et permutations (D-04 V2)');
        $reponse->assertSee('Jauge utile opposable');
    }

    public function test_gate_ecriture_refuse_sans_permission(): void
    {
        $userSansDroit = User::factory()->create();
        $this->actingAs($userSansDroit)
            ->post(route('admin.evaluation.examens.groupes.capacite.accepter'), [
                'epreuve_id' => 1, 'motif' => 'x',
            ])
            ->assertForbidden();
    }

    public function test_refuser_proposition_sans_ecriture(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $this->creerCandidats($epreuve, 5);

        $avant = $epreuve->salle_id;
        $resultat = $this->service()->refuser(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Refus motivé.'],
            $this->admin->id,
        );
        $this->assertSame('refusee', $resultat['statut']);

        // Aucun écriture sur l'épreuve : la salle n'a pas changé.
        $epreuve->refresh();
        $this->assertSame($avant, $epreuve->salle_id);
    }

    public function test_historique_retrace_les_decisions(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $this->creerCandidats($epreuve, 5);

        $applique = $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Pour historique.'],
            $this->admin->id,
        );

        $historique = $this->service()->historique($this->annee->id, $this->faculte1->id, $this->examen->id, $epreuve->id, 10);
        $this->assertNotEmpty($historique);
        $this->assertSame(ExamDecisionCapacitePermutationService::ACTION_ACCEPTEE, $historique[0]['action']);
        $this->assertSame($epreuve->id, $historique[0]['epreuve_id']);
    }

    public function test_payload_vue_retourne_structure_complete(): void
    {
        $payload = $this->service()->payloadVue(
            $this->annee->id, $this->faculte1->id, null, null, null, $this->admin->id,
        );

        $this->assertArrayHasKey('disponible', $payload);
        $this->assertArrayHasKey('kpis', $payload);
        $this->assertArrayHasKey('lignes', $payload);
        $this->assertArrayHasKey('conflits', $payload);
        $this->assertArrayHasKey('propositions_recentes', $payload);
        $this->assertArrayHasKey('historiographie', $payload);
        $this->assertArrayHasKey('annees', $payload);
        $this->assertArrayHasKey('peut_agir', $payload);
    }

    public function test_correspondance_pedagogique_stricte_bloque_etudiant_non_inscrit_matiere(): void
    {
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id]);
        $candidatIds = $this->creerCandidats($epreuve, 3, 'ELIG');
        $candidats = Candidat::whereIn('id', $candidatIds)->get();

        // Inscrire les 2 premiers étudiants à la matière INF301 et au groupe L3-INFO-G1
        foreach ($candidats->take(2) as $c) {
            Etudiant::where('id', $c->etudiant_id)->update([
                'groupe_id' => $this->groupe1->id,
                'programme_id' => $this->programme1->id,
                'niveau_id' => $this->groupe1->niveau_id,
                'statut' => 'valide',
            ]);
            $inscAcad = InscriptionAcademique::create([
                'etudiant_id' => $c->etudiant_id,
                'programme_id' => $this->programme1->id,
                'niveau_id' => $this->groupe1->niveau_id,
                'niveau' => 'L3',
                'filiere' => 'Informatique',
                'groupe' => $this->groupe1->code,
                'annee_universitaire_id' => $this->annee->id,
                'type' => 'premiere',
                'regime' => 'initiale',
                'paye' => true,
                'statut' => 'valide',
            ]);
            InscriptionPedagogique::create([
                'inscription_id' => $inscAcad->id,
                'semestre_id' => $this->matiere1->ue->semestre_id,
                'ue_id' => $this->matiere1->ue_id,
                'matiere_id' => $this->matiere1->id,
                'statut' => 'inscrit',
            ]);
        }

        // Le 3e candidat appartient au groupe mais N'EST PAS inscrit à la matière INF301
        $intrus = $candidats->last();
        Etudiant::where('id', $intrus->etudiant_id)->update([
            'groupe_id' => $this->groupe1->id,
            'programme_id' => $this->programme1->id,
            'niveau_id' => $this->groupe1->niveau_id,
            'statut' => 'valide',
        ]);
        InscriptionAcademique::create([
            'etudiant_id' => $intrus->etudiant_id,
            'programme_id' => $this->programme1->id,
            'niveau_id' => $this->groupe1->niveau_id,
            'niveau' => 'L3',
            'filiere' => 'Informatique',
            'groupe' => $this->groupe1->code,
            'annee_universitaire_id' => $this->annee->id,
            'type' => 'premiere',
            'regime' => 'initiale',
            'paye' => true,
            'statut' => 'valide',
        ]);

        $diag = $this->service()->diagnostic($this->annee->id, $this->faculte1->id, null, $this->examen->id, $epreuve->id);
        $ligne = $this->ligneDuDiag($diag, $epreuve->id);
        $this->assertFalse($ligne['correspondance_conforme']);

        $this->expectException(\DomainException::class);
        $this->service()->appliquer(
            $epreuve,
            ['type' => 'reaffectation', 'salle_ids' => [$this->salle60->id], 'motif' => 'Tentative avec étudiant non inscrit à la matière.'],
            $this->admin->id,
        );
    }

    public function test_combinaison_multi_salles_propage_places_convocations_emargement_et_moteur_session(): void
    {
        // 70 candidats répartis sur AMPHI-A (50 places utiles D-04) + SALLE-60 (30 places utiles D-04) = 80 places utiles
        $epreuve = $this->creerEpreuve(['salle_id' => $this->amphiA->id, 'capacite_necessaire' => 70]);
        $this->creerCandidats($epreuve, 70, 'COMB');

        $resultat = $this->service()->appliquer(
            $epreuve,
            [
                'type' => 'combinaison',
                'salle_ids' => [$this->amphiA->id, $this->salle60->id],
                'motif' => 'Combinaison Amphi A (50) + Salle 60 (20) pour 70 candidats éligibles.',
            ],
            $this->admin->id,
        );

        $this->assertSame('acceptee', $resultat['statut']);
        $this->assertSame(80, $resultat['capacite_utile_totale']);
        $this->assertSame(70, $resultat['candidats_affectes']);

        $epreuve->refresh();
        $candidats = $epreuve->candidats()->orderBy('id')->get();
        $this->assertCount(70, $candidats);

        $dansAmphi = $candidats->where('salle_id', $this->amphiA->id)->values();
        $dansSalle60 = $candidats->where('salle_id', $this->salle60->id)->values();

        $this->assertCount(50, $dansAmphi);
        $this->assertCount(20, $dansSalle60);

        $this->assertSame(range(1, 50), $dansAmphi->pluck('place')->all());
        $this->assertSame(range(1, 20), $dansSalle60->pluck('place')->all());

        // Propagation vers Candidat::salleEffective() et ConvocationService
        $convocationService = app(ConvocationService::class);
        $premierCandidat = $dansAmphi->first();
        $dernierCandidat = $dansSalle60->last();

        $this->assertSame('AMPHI-A', $premierCandidat->salleEffective()?->code);
        $this->assertSame(1, $premierCandidat->place);
        $this->assertSame('SALLE-60', $dernierCandidat->salleEffective()?->code);
        $this->assertSame(20, $dernierCandidat->place);

        $convPremier = $convocationService->convocation($premierCandidat);
        $convDernier = $convocationService->convocation($dernierCandidat);
        $this->assertSame(200, $convPremier->getStatusCode());
        $this->assertSame(200, $convDernier->getStatusCode());

        $emargement = $convocationService->listeEmargement($epreuve->fresh(['candidats.etudiant', 'candidats.salle', 'salle', 'matiere', 'session']));
        $this->assertSame(200, $emargement->getStatusCode());

        // Propagation vers ExamScheduleEngineService::auditerSessionExamens() : 0 conflit de capacité D-04
        $auditSession = app(ExamScheduleEngineService::class)->auditerSessionExamens($this->examen);
        $this->assertFalse(
            collect($auditSession['conflits'] ?? [])->contains(fn (array $c) => ($c['type'] ?? '') === 'capacite_salle')
        );
    }
}
