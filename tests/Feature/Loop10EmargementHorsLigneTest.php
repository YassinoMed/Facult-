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
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOOP-10 — émargement numérique hors ligne.
 *
 * 1. Le pré-cache de la page de repli ne doit jamais conserver le shell
 *    authentifié (nom, rôle, jetons CSRF).
 * 2. La file locale ne stocke que des identifiants techniques et un statut.
 * 3. La synchronisation serveur est idempotente, bornée au périmètre
 *    facultaire et refuse une épreuve verrouillée.
 */
class Loop10EmargementHorsLigneTest extends TestCase
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
            'name' => 'Scolarité', 'email' => 'sco.loop10.'.uniqid().'@universite.test',
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

    /* ── Gardes statiques de la file locale ── */

    public function test_le_service_worker_pre_cache_la_page_hors_ligne_sans_session(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("credentials: 'omit'", $sw);
        $this->assertStringContainsString("cache: 'no-store'", $sw);
        $this->assertStringNotContainsString('cache.add(HORS_LIGNE)', $sw);
    }

    public function test_le_service_worker_ne_met_jamais_en_cache_les_mutations_ni_l_api(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("request.method !== 'GET'", $sw);
        $this->assertStringContainsString("url.pathname.startsWith('/api/')", $sw);
    }

    public function test_la_page_hors_ligne_reste_accessible_sans_authentification(): void
    {
        $this->get('/hors-ligne')
            ->assertOk()
            ->assertSee('Connexion perdue')
            ->assertSee('aucune donnée', false);
    }

    public function test_la_file_locale_ne_stocke_que_des_identifiants_et_un_statut(): void
    {
        $js = (string) file_get_contents(public_path('js/emargement-hors-ligne.js'));

        // Le contenu envoyé au serveur est strictement borné.
        $this->assertStringContainsString('cle_idempotence', $js);
        $this->assertStringContainsString('candidat_id', $js);
        $this->assertStringContainsString('statut', $js);

        // Aucun champ de donnée personnelle dans la file.
        $this->assertStringNotContainsString('justification', $js);
        $this->assertStringNotContainsString('email', $js);
        $this->assertStringNotContainsString('matricule', $js);
        $this->assertStringNotContainsString('telephone', $js);

        // TTL 24 h et purge.
        $this->assertStringContainsString('24 * 60 * 60 * 1000', $js);
        $this->assertStringContainsString('purgerExpirees', $js);
    }

    /* ── Synchronisation serveur ── */

    public function test_synchronisation_idempotente_d_une_file_hors_ligne(): void
    {
        [$epreuve, $candidat] = $this->creerEpreuveEtCandidat();
        $cle = (string) Str::uuid();

        $charge = ['presences' => [[
            'cle_idempotence' => $cle, 'candidat_id' => $candidat->id, 'statut' => 'present',
        ]]];

        $this->actingAs($this->admin)
            ->postJson(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuve), $charge)
            ->assertOk()
            ->assertJson(['synchronises' => 1, 'deja_synchronises' => 0, 'ignores' => 0]);

        $this->assertSame('present', $candidat->fresh()->statut);
        $this->assertDatabaseCount('emargements_hors_ligne', 1);

        // Rejeu réseau : même clé, aucun second pointage.
        $this->actingAs($this->admin)
            ->postJson(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuve), $charge)
            ->assertOk()
            ->assertJson(['synchronises' => 0, 'deja_synchronises' => 1]);

        $this->assertDatabaseCount('emargements_hors_ligne', 1);
    }

    public function test_un_candidat_hors_epreuve_est_ignore_sans_ecriture(): void
    {
        [$epreuve] = $this->creerEpreuveEtCandidat();
        [, $candidatAutre] = $this->creerEpreuveEtCandidat('EP-002');

        $this->actingAs($this->admin)
            ->postJson(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuve), [
                'presences' => [[
                    'cle_idempotence' => (string) Str::uuid(),
                    'candidat_id' => $candidatAutre->id,
                    'statut' => 'present',
                ]],
            ])
            ->assertOk()
            ->assertJson(['synchronises' => 0, 'ignores' => 1]);

        $this->assertSame('non_pointe', $candidatAutre->fresh()->statut);
        $this->assertDatabaseCount('emargements_hors_ligne', 0);
    }

    public function test_une_epreuve_verrouillee_refuse_la_synchronisation(): void
    {
        [$epreuve, $candidat] = $this->creerEpreuveEtCandidat(statut: 'locked');

        $this->actingAs($this->admin)
            ->postJson(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuve), [
                'presences' => [[
                    'cle_idempotence' => (string) Str::uuid(),
                    'candidat_id' => $candidat->id,
                    'statut' => 'present',
                ]],
            ])
            ->assertStatus(409);

        $this->assertSame('non_pointe', $candidat->fresh()->statut);
    }

    public function test_un_statut_illegal_est_refuse(): void
    {
        [$epreuve, $candidat] = $this->creerEpreuveEtCandidat();

        $this->actingAs($this->admin)
            ->postJson(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuve), [
                'presences' => [[
                    'cle_idempotence' => (string) Str::uuid(),
                    'candidat_id' => $candidat->id,
                    'statut' => 'diplome',
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_permission_update_requise(): void
    {
        [$epreuve, $candidat] = $this->creerEpreuveEtCandidat();

        $lecteur = User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consult.loop10.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)
            ->postJson(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuve), [
                'presences' => [[
                    'cle_idempotence' => (string) Str::uuid(),
                    'candidat_id' => $candidat->id,
                    'statut' => 'present',
                ]],
            ])
            ->assertForbidden();
    }

    public function test_isolation_faculte_sur_la_synchronisation(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDroit', 'code' => 'FD', 'description' => 'T', 'active' => true]);
        $groupeB = Group::create(['code' => 'GD1', 'nom' => 'Droit 1', 'faculte_id' => $faculteB->id, 'niveau' => 'L1', 'actif' => true]);

        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac A', 'email' => 'facA.loop10.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        [$epreuveB, $candidatB] = $this->creerEpreuveEtCandidat('EP-B', 'draft', $groupeB->id, null);

        $this->actingAs($adminRestreint)
            ->postJson(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuveB), [
                'presences' => [[
                    'cle_idempotence' => (string) Str::uuid(),
                    'candidat_id' => $candidatB->id,
                    'statut' => 'present',
                ]],
            ])
            ->assertNotFound();
    }

    public function test_la_vue_expose_le_panneau_hors_ligne_et_l_url_de_synchronisation(): void
    {
        [$epreuve, $candidat] = $this->creerEpreuveEtCandidat();

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.epreuves.emargement', $epreuve))
            ->assertOk()
            ->assertSee('Saisie hors ligne')
            ->assertSee(route('admin.evaluations.epreuves.emargement.hors-ligne', $epreuve), false);
    }

    /* ── Helpers ── */

    /**
     * @return array{0: Epreuve, 1: Candidat}
     */
    private function creerEpreuveEtCandidat(
        string $code = 'EP-001',
        string $statut = 'draft',
        ?int $groupeId = null,
        ?int $salleId = null,
        ?int $faculteId = null,
    ): array {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-'.$code, 'nom' => 'Principale 2026-2027', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20', 'statut' => 'draft',
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        $epreuve = Epreuve::create([
            'code' => $code, 'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->ecue->id,
            'salle_id' => $salleId === null && $groupeId === null ? $this->salle->id : $salleId,
            'groupe_id' => $groupeId,
            'date' => '2027-01-06', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'statut' => $statut,
        ]);

        $etudiant = Etudiant::create([
            'cin' => 'EE'.random_int(100000, 999999),
            'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sara.'.random_int(10000, 99999).'@test.ma',
            'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $faculteId ?? $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Tests', 'ville' => 'Casablanca',
            'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.random_int(10000, 99999)])->save();

        $candidat = Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
            'salle_id' => $epreuve->salle_id, 'statut' => 'non_pointe',
        ]);

        return [$epreuve, $candidat];
    }
}
