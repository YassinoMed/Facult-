<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * LOOP saisies : formats consolidés — CIN 8 chiffres aux trois points
 * d'entrée (formulaire public, pré-remplissage, imports), attributs
 * HTML alignés sur le serveur, `exists` sur les lots de masse, heures
 * au format domaine. Base de test isolée.
 */
class SaisiesFormatsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'T', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'saisies@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        // Période d'inscription ouverte (bornes larges : le formulaire
        // public n'est actif que dans une période ouverte).
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], $this->admin->id);
    }

    // ── CIN : les 7 cas du référentiel, aux trois points d'entrée ──────

    public function test_cin_form_public_sept_cas_referentiels(): void
    {
        $donnees = [
            'nom' => 'Alami', 'prenom' => 'Mehdi', 'email' => 'format@univ.test',
            'telephone' => '+21612345678', 'date_naissance' => '2004-03-15', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2024', 'password' => 'Etudiant@123', 'password_confirmation' => 'Etudiant@123',
        ];

        // Format valide → création (référentiel MENS seedé : le store
        // re-vérifie l'identification, fail-closed sans MENS).
        MensReference::create([
            'cin_normalise' => '12345678', 'cin_original' => '12345678',
            'date_naissance' => '2004-03-15', 'nom' => 'Alami', 'prenom' => 'Mehdi',
            'annee' => '2026-2027', 'source_hash' => hash('sha256', '123456782004-03-15'),
        ]);

        $this->post(route('inscription.store'), $donnees + ['cin' => '12345678'])
            ->assertRedirect(route('inscription.success'));
        $this->assertDatabaseHas('etudiants', ['cin' => '12345678']);

        // CIN invalide → refus de validation, rien créé.
        foreach (['1234567', '123456789', '12A45678', '12 45678'] as $cinInvalide) {
            $this->post(route('inscription.store'), $donnees + ['cin' => $cinInvalide, 'email' => 'x'.md5($cinInvalide).'@univ.test'])
                ->assertSessionHasErrors('cin');
        }

        $this->assertSame(1, Etudiant::count());
    }

    public function test_cin_pre_remplissage_sept_cas_referentiels(): void
    {
        foreach (['1234567', '123456789', '12A45678', '12 45678', ''] as $cinInvalide) {
            $this->postJson(route('inscription.verifierCin'), ['cin' => $cinInvalide, 'date_naissance' => '2004-03-15'])
                ->assertStatus(422)->assertJsonValidationErrors(['cin']);
        }

        // Format valide (CIN inconnue → exists = false, 200).
        $this->postJson(route('inscription.verifierCin'), ['cin' => '12345678', 'date_naissance' => '2004-03-15'])
            ->assertOk()->assertExactJson(['exists' => false]);

        // Zéro initial : préservé comme chaîne.
        $this->postJson(route('inscription.verifierCin'), ['cin' => '01234567', 'date_naissance' => '2004-03-15'])
            ->assertOk()->assertExactJson(['exists' => false]);
    }

    public function test_cin_import_etudiants_8_chiffres_obligatoire(): void
    {
        // Ligne CIN alphanumérique → rejetée (code INVALID, ligne précise).
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/etudiants/import/preview', ['fichier' => UploadedFile::fake()->createWithContent(
                'etudiants.csv',
                "matricule;cin;nom;prenom;sexe;faculte;niveau\nETU-1;BK123456;Alami;Mehdi;M;FST;L1\nETU-2;12345678;Trabelsi;Sara;F;FST;L1\nETU-3;01234567;Haddad;Manel;F;FST;L1\n",
                'text/csv',
            )])
            ->assertOk();

        // Seul le format 8 chiffres ASCII est validé (zéro initial inclus).
        $this->assertStringContainsString('INVALID', (string) $reponse->getContent());

        $token = $reponse->viewData('token');
        $this->actingAs($this->admin)
            ->post('/admin/donnees/etudiants/import/confirmer', ['token' => $token, 'mode' => 'creer'])
            ->assertOk();

        $this->assertDatabaseHas('etudiants', ['cin' => '12345678']);
        $this->assertDatabaseHas('etudiants', ['cin' => '01234567']);
        $this->assertDatabaseMissing('etudiants', ['cin' => 'BK123456']);
    }

    // ── HTML : attributs alignés sur la validation serveur (§14) ───────

    public function test_page_publique_cin_attributs_html_alignes(): void
    {
        $reponse = $this->get(route('inscription.create'))->assertOk();

        // Le navigateur renforce (UX) ce que le serveur impose (sécurité)
        // — assertions sur le HTML brut (quotes non échappées).
        $reponse->assertSee('pattern="[0-9]{8}"', false);
        $reponse->assertSee('maxlength="8"', false);
        $reponse->assertSee('inputmode="numeric"', false);
    }

    // ── Lots de masse : pas d'IDs fantômes (§10) ───────────────────────

    public function test_lancer_lot_documents_etudiant_ids_fantomes_refuses(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/documents-center/lots', [
                'type' => 'attestation_scolarite',
                'etudiant_ids' => [999999],
            ])
            ->assertSessionHasErrors('etudiant_ids.0');

        $this->assertDatabaseCount('document_batches', 0);
    }

    public function test_inscrire_ecues_lot_matiere_ids_fantomes_refuses(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '61000099', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'lot@univ.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Tunis', 'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.inscriptions.ecues.lot', $inscription), ['matiere_ids' => [999999]])
            ->assertSessionHasErrors('matiere_ids.0');
    }

    // ── Heures : format domaine H:i (alignement surveillance) ──────────

    public function test_heures_presence_format_domaine_controle(): void
    {
        $enseignant = Teacher::create([
            'nom' => 'Gharbi', 'prenom' => 'Omar', 'email' => 'o.gharbi@univ.test',
            'faculte_id' => $this->faculte->id,
        ]);
        $matiere = Matiere::create(['code' => 'INF01', 'nom' => 'Informatique', 'coefficient' => 2, 'credits' => 6, 'niveau' => 'L1', 'semestre' => 1]);
        $salle = Room::create(['code' => 'B12', 'nom' => 'Salle B12', 'capacite' => 40]);
        $groupe = Group::create([
            'code' => 'G-TEST-1', 'nom' => 'Groupe Test', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $planning = Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id, 'groupe_id' => $groupe->id, 'semestre' => 1, 'statut' => 'published',
        ]);
        $slot = ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $matiere->id,
            'teacher_id' => $enseignant->id, 'room_id' => $salle->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
        $presence = TeacherPresence::create([
            'schedule_slot_id' => $slot->id, 'teacher_id' => $enseignant->id,
            'date' => now()->toDateString(), 'statut_calcule' => 'presence_confirmee',
        ]);

        // Heure malformée → refusée (le domaine utilise H:i partout).
        $this->actingAs($this->admin)
            ->post(route('admin.presences.enseignants.confirmer', $presence), [
                'heure_arrivee' => 'pas-une-heure',
            ])
            ->assertSessionHasErrors('heure_arrivee');
    }
}
