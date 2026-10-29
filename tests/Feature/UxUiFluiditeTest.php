<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\JuryMembre;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP UX/UI Premium & Fluidité — lots B, C, D, E.
 *
 * - B (§5.1) : les erreurs de saisie par ligne sont toutes affichées ;
 * - C (§5.2) : les boutons d'import suivent la permission des routes
 *   (`notes_eval.enter`, la route fait foi — fin de l'incohérence U3) ;
 * - D (§5.2) : quorum visible en direct et confirmation proportionnée
 *   avant publication ;
 * - E (§8.1) : mesures de référence SQL sur pages prioritaires
 *   (baselines documentées, gardes anti-explosion — pas d'optimisation
 *   sans démonstration).
 */
class UxUiFluiditeTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private User $adminScolarite;

    private User $superAdmin;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ux@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.ux@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
    }

    // ── Lot C : boutons d'import alignés sur la permission des routes ──

    public function test_les_boutons_d_import_suivent_la_permission_des_routes(): void
    {
        $epreuve = $this->creerEpreuve();

        // `admin_scolarite` possède `notes_eval.enter` (les routes
        // d'import l'exigent) mais PAS `notes_eval.import` : avant le
        // correctif U3, les boutons étaient masqués alors que les
        // routes auraient accepté la requête (403 après affichage dans
        // le cas inverse).
        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.notes.saisie', $epreuve))
            ->assertOk()
            ->assertSee('Import par lot de notes anonymes')
            ->assertSee('Prévisualiser l’import');
    }

    // ── Lot B : résumé d'erreurs de saisie par ligne ──

    public function test_les_erreurs_de_saisie_sont_affichees_par_ligne_et_non_uniquement_la_premiere(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiantValide = $this->creerEtudiant();
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiantValide->id, 'statut' => 'present']);

        // Ligne 2 : étudiant non convoqué → rejetée par le service.
        $etudiantHorsPerimetre = $this->creerEtudiant();

        // Patron canonique : `from()` fournit le Referer (comme un vrai
        // navigateur) → `back()` revient sur la saisie ; le suivi de
        // redirection rend la page avec les erreurs flashées.
        $reponse = $this->actingAs($this->adminScolarite)
            ->followingRedirects()
            ->from(route('admin.evaluations.notes.saisie', $epreuve))
            ->post(route('admin.evaluations.notes.store', $epreuve), [
                'lignes' => [
                    ['etudiant_id' => $etudiantValide->id, 'valeur' => 12],
                    ['etudiant_id' => $etudiantHorsPerimetre->id, 'valeur' => 14],
                ],
            ]);

        $reponse->assertOk()
            ->assertSee('erreur(s) de saisie')
            ->assertSee('non convoqué');

        // La ligne valide est tout de même enregistrée (tolérance partielle).
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiantValide->id,
            'valeur' => 12,
        ]);
    }

    // ── Lot D : quorum visible + confirmation de publication ──

    public function test_la_deliberation_affiche_le_quorum_en_direct(): void
    {
        $deliberation = $this->creerDeliberation();
        $this->creerMembre($deliberation, 'president', true);
        $this->creerMembre($deliberation, 'membre', true);
        $this->creerMembre($deliberation, 'membre', false);

        $page = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.deliberations.show', $deliberation))
            ->assertOk();

        $page->assertSee('2/3 membre(s) présent(s)');
        $page->assertSee('Quorum non atteint');
    }

    public function test_le_quorum_atteint_est_signale_et_la_publication_exige_une_confirmation(): void
    {
        $deliberation = $this->creerDeliberation();
        $this->creerMembre($deliberation, 'president', true);
        $this->creerMembre($deliberation, 'membre', true);
        $this->creerMembre($deliberation, 'membre', true);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.evaluations.deliberations.show', $deliberation))
            ->assertOk()
            ->assertSee('3/3 membre(s) présent(s)')
            ->assertSee('Quorum atteint')
            // Confirmation proportionnée (U4) : message visible côté client.
            ->assertSee('La publication rend les décisions visibles');
    }

    // ── Lot E : mesures de référence (baselines, environnement sqlite/phpunit) ──

    public function test_mesure_requetes_dashboard_etudiant(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->definirStatut('valide');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])->get(route('etudiant.dashboard'))->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withSession(['etudiant_auth_id' => $etudiant->id])->get(route('etudiant.dashboard'))->assertOk();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Garde anti-explosion (baseline documentée au rapport) — pas
        // une garantie de valeur exacte : l'environnement de mesure est
        // sqlite/phpunit, thème V2 par défaut.
        $this->assertLessThan(120, $requetes, "Dashboard étudiant : {$requetes} requêtes.");
    }

    public function test_mesure_requetes_registre_etudiants_admin(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->creerEtudiant();
        }

        $this->actingAs($this->adminScolarite)->get(route('admin.etudiants.index'))->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($this->adminScolarite)->get(route('admin.etudiants.index'))->assertOk();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(200, $requetes, "Registre étudiants : {$requetes} requêtes.");
    }

    public function test_mesure_requetes_page_saisie_notes(): void
    {
        $epreuve = $this->creerEpreuve();

        for ($i = 0; $i < 10; $i++) {
            $etudiant = $this->creerEtudiant();
            Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        }

        $this->actingAs($this->adminScolarite)->get(route('admin.evaluations.notes.saisie', $epreuve))->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($this->adminScolarite)->get(route('admin.evaluations.notes.saisie', $epreuve))->assertOk();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(120, $requetes, "Page de saisie (10 candidats) : {$requetes} requêtes.");
    }

    // ── Fixtures compacts ──

    private function creerEpreuve(): Epreuve
    {
        $this->compteur++;
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-UX-'.$this->compteur.'-'.random_int(100, 999),
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'open', 'created_by' => $this->superAdmin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->superAdmin->id,
        ]);

        return Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id, 'date' => '2027-01-'.sprintf('%02d', 10 + $this->compteur),
            'heure_debut' => '08:00', 'heure_fin' => '10:00', 'statut' => 'scheduled',
        ]);
    }

    private function creerEtudiant(): Etudiant
    {
        $suffixe = random_int(100000, 999999);

        $etudiant = Etudiant::create([
            'cin' => 'UX'.$suffixe,
            'nom' => 'Fassi',
            'prenom' => 'Amine',
            'email' => 'amine.'.$suffixe.'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '8 rue des Tests',
            'ville' => 'Fès',
            'code_postal' => '30000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $etudiant;
    }

    private function creerDeliberation(): Deliberation
    {
        return Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'statut' => 'draft',
            'created_by' => $this->superAdmin->id,
        ]);
    }

    private function creerMembre(Deliberation $deliberation, string $fonction, bool $present): JuryMembre
    {
        return $deliberation->membres()->create([
            'user_id' => $this->superAdmin->id,
            'fonction' => $fonction,
            'present' => $present,
        ]);
    }
}
