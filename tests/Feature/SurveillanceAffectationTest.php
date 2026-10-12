<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP AFFECTATION INTELLIGENTE — simulation pure, garantie Principal,
 * cockpit §14, preview UX, message manuel explicite, multi-niveaux.
 * Le moteur (besoins, scoring, transaction) est réutilisé, jamais doublé.
 */
class SurveillanceAffectationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private SessionExamen $session;

    private Examen $examen;

    private Matiere $matiere;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.intelligent@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-INTEL',
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-20', 'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $this->examen = Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examens S1', 'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $this->admin->id,
        ]);
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        $this->compteur++;

        return Epreuve::create(array_merge([
            'code' => 'EPR-INT-'.$this->compteur,
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'date' => '2027-01-11',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'type_evaluation' => 'examen',
            'statut' => 'scheduled',
        ], $attributs));
    }

    private function creerEnseignants(int $nombre): array
    {
        $dep = Departement::create([
            'code' => 'DEP-INT', 'nom' => 'Département', 'faculte_id' => $this->faculte->id,
        ]);

        $liste = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $liste[] = Teacher::create([
                'matricule' => 'ENS-INT-'.$this->compteur.'-'.$i,
                'nom' => 'Nom'.$i, 'prenom' => 'Prenom'.$i,
                'email' => "intel{$this->compteur}_{$i}@universite.test",
                'departement_id' => $dep->id, 'actif' => true,
            ]);
        }

        return $liste;
    }

    private function creerSalle(string $code, int $capacite): Room
    {
        return Room::create([
            'code' => $code, 'nom' => $code, 'capacite' => $capacite,
            'actif' => true, 'faculte_id' => $this->faculte->id,
        ]);
    }

    public function test_simulation_pure_sans_ecriture(): void
    {
        $salle = $this->creerSalle('AMPHI-A', 150);
        $this->creerEpreuve(['salle_id' => $salle->id, 'capacite_necessaire' => 60]);
        $this->creerEnseignants(4);

        $logsAvant = IntegrationLog::count();
        $gardesAvant = Surveillance::count();

        app(SurveillanceEquilibrageService::class)->simulerSession($this->session);

        // §8/§12 : simulation pure — 0 écriture, 0 audit métier.
        $this->assertSame($logsAvant, IntegrationLog::count());
        $this->assertSame($gardesAvant, Surveillance::count());
    }

    public function test_principal_garanti_sans_principal_existant(): void
    {
        $salles = [$this->creerSalle('S1', 40), $this->creerSalle('S2', 40), $this->creerSalle('S3', 40)];
        $epreuve = $this->creerEpreuve(['salle_id' => $salles[0]->id]);

        // 3 salles via candidats → besoin de 3 actifs.
        foreach ($salles as $index => $salle) {
            $etudiant = Etudiant::create([
                'cin' => 'INT'.$this->compteur.'00'.$index, 'nom' => 'Etu', 'prenom' => 'X'.$index,
                'email' => "etu{$this->compteur}_{$index}@test.ma", 'date_naissance' => '2004-01-01',
                'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
                'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
                'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            ]);
            $etudiant->definirStatut('valide');
            Candidat::create([
                'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
                'salle_id' => $salle->id, 'statut' => 'present',
            ]);
        }

        // 2 adjoints pré-existants SANS principal (saisie manuelle).
        [$t1, $t2, $t3, $t4] = $this->creerEnseignants(4);
        $service = app(SurveillanceService::class);
        $service->assigner($epreuve, ['teacher_id' => $t1->id, 'role' => 'surveillant']);
        $service->assigner($epreuve, ['teacher_id' => $t2->id, 'role' => 'surveillant']);

        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);

        $roles = collect($proposition['surveillants'])->pluck('role')->all();
        $this->assertContains('principal', $roles);
        $this->assertSame(1, collect($roles)->filter(fn ($r) => $r === 'principal')->count());
    }

    public function test_affectation_manuelle_conflit_message_explicite(): void
    {
        $epreuve1 = $this->creerEpreuve(['code' => 'ALG101', 'date' => '2027-01-12']);
        $epreuve2 = $this->creerEpreuve(['code' => 'ALG102', 'date' => '2027-01-12']);
        [$enseignant] = $this->creerEnseignants(1);

        app(SurveillanceService::class)->assigner($epreuve1, ['teacher_id' => $enseignant->id]);

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.evaluations.surveillants.store', $epreuve2),
            ['teacher_id' => $enseignant->id]
        );

        $reponse->assertSessionHasErrors('surveillant');
        $erreurs = session('errors')->get('surveillant');
        $this->assertStringContainsString('12/01/2027', (string) $erreurs[0]);
        $this->assertStringContainsString('Algorithmique', strip_tags((string) $erreurs[0]));
    }

    public function test_multi_niveaux_l1_l2_l3(): void
    {
        foreach (['L1' => 'B101', 'L2' => 'B201', 'L3' => 'B301'] as $niveau => $code) {
            $matiere = Matiere::create([
                'code' => $code, 'nom' => "Matière {$niveau}",
                'niveau' => $niveau, 'semestre' => 1, 'credits' => 4, 'coefficient' => 1,
            ]);
            $this->creerEpreuve(['matiere_id' => $matiere->id, 'code' => 'EPR-'.$niveau]);
        }
        $this->creerEnseignants(9);

        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertSame(3, $simulation['total_epreuves']);
        $this->assertSame(3, count($simulation['propositions_epreuves']));
    }

    public function test_performance_session_20_epreuves_500_candidats(): void
    {
        $grandeSalle = $this->creerSalle('AMPHI-A', 600);

        for ($i = 0; $i < 20; $i++) {
            $this->creerEpreuve([
                'code' => 'EPR-VOL-'.$i,
                'date' => '2027-01-'.sprintf('%02d', 10 + ($i % 8)),
                'heure_debut' => sprintf('%02d:00:00', 8 + ($i % 3) * 3),
                'heure_fin' => sprintf('%02d:00:00', 10 + ($i % 3) * 3),
            ]);
        }
        $this->creerEnseignants(30);

        // 500 candidats sur la première épreuve (volume étudiants).
        $epreuveChargee = Epreuve::where('code', 'EPR-VOL-0')->firstOrFail();
        $epreuveChargee->update(['salle_id' => $grandeSalle->id]);

        for ($i = 0; $i < 500; $i++) {
            $etudiant = Etudiant::create([
                'cin' => 'VOL'.$i, 'nom' => 'Etu', 'prenom' => 'V'.$i,
                'email' => "vol{$i}@test.ma", 'date_naissance' => '2004-01-01',
                'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
                'annee_academique' => '2026-2027', 'adresse' => '1 rue', 'ville' => 'Casa',
                'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            ]);
            $etudiant->definirStatut('valide');
            Candidat::create([
                'epreuve_id' => $epreuveChargee->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present',
            ]);
        }

        $debut = microtime(true);
        \DB::enableQueryLog();
        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        $duree = microtime(true) - $debut;

        fwrite(
            STDERR,
            "\n[PERF-SESSION] 20 épreuves / 30 enseignants / 500 candidats : ".
            round($duree, 2)."s, {$requetes} requêtes, pic=".
            round(memory_get_peak_usage(true) / 1048576, 1)."Mo, statut={$simulation['statut']}\n"
        );

        $this->assertSame(20, $simulation['total_epreuves']);
        $this->assertLessThan(120, $requetes, "Session 20 épreuves : {$requetes} requêtes.");
        $this->assertLessThan(120, $duree, 'Simulation session trop lente.');
    }

    public function test_remplacer_principal_par_principal(): void
    {
        // Non-régression : la garde d'unicité Principal ne doit pas
        // bloquer le remplacement (l'ancien, marqué remplace d'abord,
        // n'occupe plus le rôle).
        $epreuve = $this->creerEpreuve();
        [$t1, $t2] = $this->creerEnseignants(2);

        $service = app(SurveillanceService::class);
        $principal = $service->assigner($epreuve, ['teacher_id' => $t1->id, 'role' => 'principal']);
        $remplacant = $service->remplacer($principal->fresh(), ['teacher_id' => $t2->id, 'role' => 'principal'], 'Absent');

        $this->assertSame('remplace', $principal->fresh()->statut);
        $this->assertSame('principal', $remplacant->role);
        $this->assertSame('assigne', $remplacant->statut);
        $this->assertSame(
            1,
            Surveillance::where('epreuve_id', $epreuve->id)
                ->where('role', 'principal')->whereIn('statut', ['assigne', 'confirme'])->count()
        );
    }
}
