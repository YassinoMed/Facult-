<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Models\SurveillanceConstraintConfig;
use App\Modules\Evaluation\Services\ContraintesSurveillance;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dimensionnement des surveillants selon les salles et leur capacité.
 *
 * Moteur unique réutilisé (`SurveillanceEquilibrageService`) : besoin par
 * salle (candidats réellement affectés) agrégé, héritage salle → faculté /
 * session → système, diagnostics sans réduction silencieuse.
 */
class SurveillanceDimensionnementSallesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    private Examen $examen;

    private Matiere $matiere;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.dim-salles@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $programme = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-DIM', 'nom' => 'Session dimensionnement',
            'type' => 'principale', 'statut' => 'open', 'date_debut' => '2027-01-10', 'date_fin' => '2027-01-20',
        ]);
        $this->examen = Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme->id, 'libelle' => 'Examens', 'type' => 'examen', 'statut' => 'scheduled',
        ]);
        $this->matiere = Matiere::create([
            'code' => 'ALGO', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1,
            'coefficient' => 2, 'credits' => 4,
        ]);
    }

    private function creerSalle(string $code, int $capacite, array $surcharge = []): Room
    {
        return Room::create(array_merge([
            'code' => $code, 'nom' => $code, 'capacite' => $capacite,
            'actif' => true, 'faculte_id' => $this->faculte->id,
        ], $surcharge));
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'code' => 'EPR-'.(++$this->compteur).'-'.uniqid(),
            'examen_id' => $this->examen->id, 'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $this->matiere->id,
            'date' => '2027-01-12', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00',
            'duree_minutes' => 120, 'type_evaluation' => 'examen', 'statut' => 'scheduled',
        ], $attributs));
    }

    private function creerEnseignants(int $nombre, ?int $faculteId = null): array
    {
        $dep = Departement::create([
            'code' => 'DEP-DIM-'.(++$this->compteur).'-'.uniqid(), 'nom' => 'Département',
            'faculte_id' => $faculteId ?? $this->faculte->id,
        ]);

        $liste = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $liste[] = Teacher::create([
                'matricule' => 'DIM-'.$this->compteur.'-'.$i.'-'.uniqid(),
                'nom' => 'Nom'.$i, 'prenom' => 'Prenom'.$i,
                'email' => "dim{$this->compteur}_{$i}_".uniqid().'@universite.test',
                'departement_id' => $dep->id, 'actif' => true,
            ]);
        }

        return $liste;
    }

    /**
     * Ventile $repartition (code salle => nombre de candidats) sur l'épreuve.
     */
    private function ventilerCandidats(Epreuve $epreuve, array $repartition): void
    {
        foreach ($repartition as $salleId => $nombre) {
            for ($i = 0; $i < $nombre; $i++) {
                $etudiant = Etudiant::create([
                    'cin' => 'DIM'.(++$this->compteur).'C'.$i.'E'.$epreuve->id, 'nom' => 'Etu', 'prenom' => 'X'.$i,
                    'email' => "dim{$this->compteur}_{$i}_{$epreuve->id}@test.ma", 'date_naissance' => '2004-01-01',
                    'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
                    'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
                    'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
                ]);
                $etudiant->definirStatut('valide');
                Candidat::create([
                    'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
                    'salle_id' => $salleId, 'statut' => 'present',
                ]);
            }
        }
    }

    public function test_petite_salle_25_candidats_un_surveillant_par_salle(): void
    {
        $salle = $this->creerSalle('S-PETITE', 40);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 25]);

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve);

        $this->assertCount(1, $besoin['besoins_salles']);
        $this->assertSame(25, $besoin['besoins_salles'][0]['candidats']);
        $this->assertSame(1, $besoin['besoins_salles'][0]['besoin']);
        // Plancher global historique conservé : 2 actifs au total.
        $this->assertSame(2, $besoin['besoin_surveillants_actifs']);
    }

    public function test_salle_70_candidats_deux_surveillants(): void
    {
        $salle = $this->creerSalle('S-MOY', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 70]);

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve);

        $this->assertSame(2, $besoin['besoins_salles'][0]['besoin']);
        $this->assertSame(2, $besoin['besoin_surveillants_actifs']);
    }

    public function test_grand_amphi_180_candidats_quatre_surveillants(): void
    {
        $amphi = $this->creerSalle('AMPHI', 200);
        $epreuve = $this->creerEpreuve(['salle_id' => $amphi->id]);
        $this->ventilerCandidats($epreuve, [$amphi->id => 180]);

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve);

        // Plus de deux surveillants : le dimensionnement suit la salle.
        $this->assertSame(4, $besoin['besoins_salles'][0]['besoin']);
        $this->assertSame(4, $besoin['besoin_surveillants_actifs']);
    }

    public function test_multi_salles_besoins_differencies_agreges(): void
    {
        $salleA = $this->creerSalle('S-A', 40);
        $salleB = $this->creerSalle('S-B', 100);
        $amphi = $this->creerSalle('S-AMPHI', 200);
        $epreuve = $this->creerEpreuve(['salle_id' => $salleA->id]);
        $this->ventilerCandidats($epreuve, [$salleA->id => 25, $salleB->id => 70, $amphi->id => 180]);

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve);

        $parCode = collect($besoin['besoins_salles'])->keyBy('code');
        $this->assertSame(1, $parCode['S-A']['besoin']);
        $this->assertSame(2, $parCode['S-B']['besoin']);
        $this->assertSame(4, $parCode['S-AMPHI']['besoin']);
        // Somme par salle (7), pas le seul total ceil(275/50)=6.
        $this->assertSame(7, $besoin['besoin_surveillants_actifs']);
    }

    public function test_salle_sans_candidats_zero_besoin(): void
    {
        // Une salle affectée mais sans aucun candidat ne requiert personne
        // au niveau salle (le plancher global de l'épreuve reste applicable).
        $salle = $this->creerSalle('S-VIDE', 40);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id, 'capacite_necessaire' => 0]);

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve);

        $this->assertSame(0, $besoin['candidats_reels']);
        $this->assertCount(1, $besoin['besoins_salles']);
        $this->assertSame(0, $besoin['besoins_salles'][0]['candidats']);
        $this->assertSame(0, $besoin['besoins_salles'][0]['besoin']);

        // Formule unitaire : 0 candidat → 0, jamais de minimum fantôme.
        $this->assertSame(0, (new ContraintesSurveillance)->besoinSalle(0));
    }

    public function test_capacite_insuffisante_bloque_sans_ecriture(): void
    {
        $salle = $this->creerSalle('S-PET', 10);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 60]);
        $this->creerEnseignants(6);

        $service = app(SurveillanceEquilibrageService::class);
        $besoin = $service->evaluerBesoinsEpreuve($epreuve);
        $this->assertFalse($besoin['capacite_valide']);
        $this->assertNotNull($besoin['erreur_capacite']);

        $avant = Surveillance::count();
        $simulation = $service->simulerSession($this->session);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertGreaterThan(0, $simulation['problemes_capacite']);
        $this->assertSame($avant, Surveillance::count());
    }

    public function test_enseignants_insuffisants_diagnostic_par_salle_sans_reduction(): void
    {
        $salleB = $this->creerSalle('S-B', 100);
        $amphi = $this->creerSalle('S-AMPHI', 200);
        $epreuve = $this->creerEpreuve(['salle_id' => $salleB->id, 'code' => 'EPR-DIAG']);
        $this->ventilerCandidats($epreuve, [$salleB->id => 70, $amphi->id => 180]);
        // Besoin : 2 + 4 = 6 actifs (+1 garde), un seul enseignant.
        $this->creerEnseignants(1);

        $avant = Surveillance::count();
        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertSame($avant, Surveillance::count());

        $anomalies = implode("\n", $simulation['anomalies']);
        $this->assertStringContainsString('S-B', $anomalies);
        $this->assertStringContainsString('S-AMPHI', $anomalies);
        $this->assertStringContainsString('70 candidats', $anomalies);
        $this->assertStringContainsString('180 candidats', $anomalies);
        $this->assertStringContainsString('enseignant(s) éligible(s)', $anomalies);
        $this->assertStringContainsString('Règles :', $anomalies);

        // Le besoin obligatoire n'est jamais réduit silencieusement.
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);
        $this->assertSame(6, $proposition['besoin_surveillants_actifs']);
        $this->assertFalse($proposition['complet']);

        // L'application d'un plan impossible reste bloquée (transaction préservée).
        $this->expectException(DomainException::class);
        app(SurveillanceEquilibrageService::class)->appliquerSession($this->session, $simulation, $this->admin->id);
    }

    public function test_actifs_et_garde_distincts_principal_unique(): void
    {
        $salle = $this->creerSalle('S-ACT', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 70]);
        $this->creerEnseignants(6);

        // 2 actifs + 1 garde : rôles distincts, principal jamais doublé.
        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);

        $this->assertTrue($proposition['complet']);
        $roles = collect($proposition['surveillants'])->pluck('role')->all();
        $this->assertSame(1, collect($roles)->filter(fn ($r) => $r === 'principal')->count());
        $this->assertSame(1, collect($roles)->filter(fn ($r) => $r === 'reserve')->count());

        $ids = collect($proposition['surveillants'])->pluck('teacher_id')->all();
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_conflit_cours_exclut_enseignant_indisponible(): void
    {
        // Mardi 12/01/2027 : l'enseignant en cours à ce créneau est exclu.
        $salle = $this->creerSalle('S-CONF', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id, 'date' => '2027-01-12']);
        $this->ventilerCandidats($epreuve, [$salle->id => 20]);
        [$occupe, $libreA, $libreB, $libreC] = $this->creerEnseignants(4);

        $groupe = Group::create([
            'faculte_id' => $this->faculte->id, 'code' => 'G-CONF', 'nom' => 'G-CONF',
            'niveau' => 'L1', 'actif' => true,
        ]);
        $programme = Programme::where('faculte_id', $this->faculte->id)->firstOrFail();
        $planning = Schedule::create([
            'nom' => 'EDT conflit', 'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id, 'programme_id' => $programme->id,
            'groupe_id' => $groupe->id, 'semestre' => 1, 'statut' => 'published',
        ]);
        ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $occupe->id, 'room_id' => $salle->id, 'day_of_week' => 'tuesday',
            'start_time' => '09:00:00', 'end_time' => '11:00:00', 'type' => 'course', 'status' => 'scheduled',
        ]);

        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);

        $ids = collect($proposition['surveillants'])->pluck('teacher_id')->all();
        // 2 actifs + 1 garde pourvus sans l'enseignant en conflit.
        $this->assertTrue($proposition['complet']);
        $this->assertNotContains($occupe->id, $ids);
        $this->assertContains($libreA->id, $ids);
    }

    public function test_isolation_faculte(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'active' => true]);
        $salle = $this->creerSalle('S-ISO', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 20]);
        $intrus = $this->creerEnseignants(3, $autreFaculte->id)[0];

        $simulation = app(SurveillanceEquilibrageService::class)
            ->simulerSession($this->session, ['faculte_id' => $this->faculte->id]);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);
        if ($proposition !== null) {
            $ids = collect($proposition['surveillants'])->pluck('teacher_id')->all();
            $this->assertNotContains($intrus->id, $ids);
        }
    }

    public function test_determinisme(): void
    {
        $salle = $this->creerSalle('S-DET', 200);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 120]);
        $this->creerEnseignants(8);

        $service = app(SurveillanceEquilibrageService::class);
        $premier = $service->simulerSession($this->session);
        $second = $service->simulerSession($this->session);

        $this->assertSame(
            json_encode($premier['propositions_epreuves']),
            json_encode($second['propositions_epreuves'])
        );
    }

    public function test_simulation_sans_ecriture(): void
    {
        $salle = $this->creerSalle('S-PURE', 200);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 120]);
        $this->creerEnseignants(8);

        $avantSurveillances = Surveillance::count();
        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertSame($avantSurveillances, Surveillance::count());
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);
        $this->assertNotEmpty($proposition['besoins_salles']);
    }

    public function test_absence_requetes_n_plus_un(): void
    {
        $salle = $this->creerSalle('S-N1', 300);
        for ($i = 0; $i < 5; $i++) {
            $epreuve = $this->creerEpreuve(['salle_id' => $salle->id, 'code' => 'EPR-N1-'.$i]);
            $this->ventilerCandidats($epreuve, [$salle->id => 40]);
        }
        $this->creerEnseignants(10);

        \DB::enableQueryLog();
        app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(60, $requetes, "Session 5 épreuves : {$requetes} requêtes.");
    }

    public function test_exception_salle_prioritaire_sur_regle_generale(): void
    {
        // Minimum spécifique 3 sur la salle : 10 candidats → 3 (pas 1).
        $salle = $this->creerSalle('S-EXC', 100, ['min_surveillants' => 3]);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 10]);

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve);

        $this->assertSame('salle', $besoin['besoins_salles'][0]['source_regle']);
        $this->assertSame(3, $besoin['besoins_salles'][0]['besoin']);
        $this->assertSame(3, $besoin['besoin_surveillants_actifs']);
    }

    public function test_ratio_salle_specifique(): void
    {
        // Ratio 1/10 sur la salle : 25 candidats → 3.
        $salle = $this->creerSalle('S-RATIO', 100, ['ratio_surveillants' => 10]);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 25]);

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve);

        $this->assertSame('salle', $besoin['besoins_salles'][0]['source_regle']);
        $this->assertSame(3, $besoin['besoins_salles'][0]['besoin']);
    }

    public function test_heritage_min_par_salle_general(): void
    {
        $salle = $this->creerSalle('S-HERIT', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 25]);

        $contraintes = new ContraintesSurveillance(minSurveillants: 2, minParSalle: 2, ratioEtudiants: 50);
        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve, $contraintes);

        $this->assertSame('generale', $besoin['besoins_salles'][0]['source_regle']);
        $this->assertSame(2, $besoin['besoins_salles'][0]['minimum_applique']);
        $this->assertSame(2, $besoin['besoins_salles'][0]['besoin']);
    }

    public function test_validation_serveur_min_par_salle(): void
    {
        $this->actingAs($this->admin);

        $base = [
            'min_surveillants' => 2, 'ratio_etudiants' => 50, 'nb_gardes' => 1,
            'max_jours' => 3, 'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0, 'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10, 'poids_charge_jour' => 15, 'poids_gardes' => 8,
            'penalite_depassement_jours' => 35, 'penalite_dispersion' => 10, 'penalite_nouveau_jour' => 2,
        ];

        // Hors bornes refusé côté serveur.
        $this->post(route('admin.evaluations.surveillances.contraintes.store'), $base + ['min_par_salle' => 0])
            ->assertSessionHasErrors(['min_par_salle']);

        // Incohérent avec le plafond refusé (min/salle 5 > plafond 2).
        $this->post(route('admin.evaluations.surveillances.contraintes.store'), $base + [
            'min_par_salle' => 5, 'max_surveillants' => 2,
            'faculte_id' => $this->faculte->id, 'annee_universitaire_id' => $this->annee->id,
        ])->assertSessionHasErrors(['min_par_salle']);

        // Valide : persisté et résolu pour la portée.
        $this->post(route('admin.evaluations.surveillances.contraintes.store'), $base + [
            'min_par_salle' => 2,
            'faculte_id' => $this->faculte->id, 'annee_universitaire_id' => $this->annee->id,
        ])->assertSessionHasNoErrors();

        $config = SurveillanceConstraintConfig::where('faculte_id', $this->faculte->id)->firstOrFail();
        $this->assertSame(2, (int) $config->min_par_salle);
    }

    public function test_salle_exception_configurable_et_simuler_affiche_besoins(): void
    {
        $this->actingAs($this->admin);

        // Exception par salle via le CRUD existant (héritage si vide).
        $salle = $this->creerSalle('S-GRANDE', 250);
        $this->put(route('admin.salles.update', $salle), [
            'code' => 'S-GRANDE', 'min_surveillants' => 3, 'ratio_surveillants' => 50,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, (int) $salle->fresh()->min_surveillants);

        // Refus serveur hors bornes.
        $this->put(route('admin.salles.update', $salle), ['min_surveillants' => 0])
            ->assertSessionHasErrors(['min_surveillants']);

        // La simulation expose les besoins par salle avant toute écriture.
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 180]);
        $this->creerEnseignants(8);

        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);
        $this->assertSame(4, $proposition['besoin_surveillants_actifs']);
        $this->assertSame(4, $proposition['besoins_salles'][0]['besoin']);

        $avant = Surveillance::count();
        $this->get(route('admin.evaluations.surveillances.contraintes.index'))->assertOk();
        $this->assertSame($avant, Surveillance::count());
    }

    public function test_seuil_grande_salle_impose_minimum_renforce(): void
    {
        // Salle de 120 places avec 80 candidats.
        // Ratio standard 50 donnerait ceil(80/50) = 2.
        // Mais seuil_grande_salle = 100 avec min_surveillants_grande_salle = 3
        // détecte la capacité 120 >= 100 et impose 3 surveillants.
        $amphi = $this->creerSalle('AMPHI-120', 120);
        $epreuve = $this->creerEpreuve(['salle_id' => $amphi->id]);
        $this->ventilerCandidats($epreuve, [$amphi->id => 80]);

        $contraintes = new ContraintesSurveillance(
            minSurveillants: 2,
            minParSalle: 1,
            ratioEtudiants: 50,
            seuilGrandeSalle: 100,
            minSurveillantsGrandeSalle: 3
        );

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve, $contraintes);

        $this->assertSame('grande_salle', $besoin['besoins_salles'][0]['source_regle']);
        $this->assertSame(3, $besoin['besoins_salles'][0]['minimum_applique']);
        $this->assertSame(3, $besoin['besoins_salles'][0]['besoin']);
        $this->assertSame(3, $besoin['besoin_surveillants_actifs']);
    }

    public function test_ordre_resolution_trois_niveaux_sans_ambiguite(): void
    {
        // Niveau 1 : Salle A avec règle explicite valide sur la salle (min 4).
        $salleA = $this->creerSalle('S-A-EXPLICITE', 150, ['min_surveillants' => 4]);
        // Niveau 2 : Salle B sans règle de salle, mais capacité 120 >= seuil 100 -> règle grande salle (min 3).
        $salleB = $this->creerSalle('S-B-GRANDE', 120);
        // Niveau 3 : Salle C petite (capacité 40 < 100) -> règle générale (min 1, ratio 50).
        $salleC = $this->creerSalle('S-C-STANDARD', 40);

        $epreuve = $this->creerEpreuve(['salle_id' => $salleA->id]);
        $this->ventilerCandidats($epreuve, [
            $salleA->id => 30, // 30 < 50, mais min explicite 4
            $salleB->id => 60, // ceil(60/50)=2, mais min grande salle 3
            $salleC->id => 20, // ceil(20/50)=1, min générale 1
        ]);

        $contraintes = new ContraintesSurveillance(
            minSurveillants: 2,
            minParSalle: 1,
            ratioEtudiants: 50,
            seuilGrandeSalle: 100,
            minSurveillantsGrandeSalle: 3
        );

        $besoin = app(SurveillanceEquilibrageService::class)->evaluerBesoinsEpreuve($epreuve, $contraintes);
        $parCode = collect($besoin['besoins_salles'])->keyBy('code');

        // Vérification stricte des 3 niveaux de résolution :
        $this->assertSame('salle', $parCode['S-A-EXPLICITE']['source_regle']);
        $this->assertSame(4, $parCode['S-A-EXPLICITE']['besoin']);

        $this->assertSame('grande_salle', $parCode['S-B-GRANDE']['source_regle']);
        $this->assertSame(3, $parCode['S-B-GRANDE']['besoin']);

        $this->assertSame('generale', $parCode['S-C-STANDARD']['source_regle']);
        $this->assertSame(1, $parCode['S-C-STANDARD']['besoin']);

        // Somme globale : 4 + 3 + 1 = 8 actifs requis
        $this->assertSame(8, $besoin['besoin_surveillants_actifs']);
    }

    public function test_validation_serveur_seuil_et_min_grande_salle(): void
    {
        $this->actingAs($this->admin);

        $base = [
            'min_surveillants' => 2, 'min_par_salle' => 1, 'ratio_etudiants' => 50, 'nb_gardes' => 1,
            'max_jours' => 3, 'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0, 'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10, 'poids_charge_jour' => 15, 'poids_gardes' => 8,
            'penalite_depassement_jours' => 35, 'penalite_dispersion' => 10, 'penalite_nouveau_jour' => 2,
        ];

        // Incohérent avec le plafond : min_surveillants_grande_salle (5) > max_surveillants (3)
        $this->post(route('admin.evaluations.surveillances.contraintes.store'), $base + [
            'seuil_grande_salle' => 100,
            'min_surveillants_grande_salle' => 5,
            'max_surveillants' => 3,
            'faculte_id' => $this->faculte->id,
            'annee_universitaire_id' => $this->annee->id,
        ])->assertSessionHasErrors(['min_surveillants_grande_salle']);

        // Valide : enregistré correctement
        $this->post(route('admin.evaluations.surveillances.contraintes.store'), $base + [
            'seuil_grande_salle' => 100,
            'min_surveillants_grande_salle' => 3,
            'faculte_id' => $this->faculte->id,
            'annee_universitaire_id' => $this->annee->id,
        ])->assertSessionHasNoErrors();

        $config = SurveillanceConstraintConfig::where('faculte_id', $this->faculte->id)->firstOrFail();
        $this->assertSame(100, (int) $config->seuil_grande_salle);
        $this->assertSame(3, (int) $config->min_surveillants_grande_salle);
    }

    public function test_attribution_salles_specifiques_dans_propositions_et_persistance(): void
    {
        $salleA = $this->creerSalle('S-A-ATTR', 40);
        $salleB = $this->creerSalle('S-B-ATTR', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salleA->id]);
        $this->ventilerCandidats($epreuve, [
            $salleA->id => 20, // Besoin 1
            $salleB->id => 70, // Besoin 2
        ]);
        $this->creerEnseignants(6);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);

        $this->assertTrue($proposition['complet']);
        $surveillantsActifs = collect($proposition['surveillants'])->where('role', '!=', 'reserve');

        // 3 actifs : 1 affecté à Salle A, 2 affectés à Salle B
        $actifsSalleA = $surveillantsActifs->where('salle_id', $salleA->id);
        $actifsSalleB = $surveillantsActifs->where('salle_id', $salleB->id);
        $this->assertCount(1, $actifsSalleA);
        $this->assertCount(2, $actifsSalleB);

        // Application en base : les salle_id sont correctement persistés
        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        $surveillancesA = Surveillance::where('epreuve_id', $epreuve->id)
            ->where('salle_id', $salleA->id)
            ->where('role', '!=', 'reserve')
            ->count();
        $surveillancesB = Surveillance::where('epreuve_id', $epreuve->id)
            ->where('salle_id', $salleB->id)
            ->where('role', '!=', 'reserve')
            ->count();

        $this->assertSame(1, $surveillancesA);
        $this->assertSame(2, $surveillancesB);
    }
}
