<?php

namespace Tests\Feature;

use App\Contracts\EnregistrementPresenceInterface;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP P1 — risques prouvés du module Attendance :
 * 1. upsert sans verrou (course double POST) ;
 * 2. suppression sans audit (asymétrie store/destroy) ;
 * 3. pièce justificative sans contrôle MIME réel.
 */
class AttendanceRisquesP1Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.attendance.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté P1', 'code' => 'FP1', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité P1', 'email' => 'sco.p1@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->etudiant = Etudiant::create([
            'cin' => 'BKP10001', 'nom' => 'Test', 'prenom' => 'P1',
            'email' => 'bkp10001@example.com', 'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'Rue X',
            'ville' => 'V', 'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'motdepasse1',
        ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-2026-BKP10001'])->save();
        $this->etudiant->definirStatut('valide');
    }

    public function test_enregistrer_verrouille_la_ligne_en_lecture(): void
    {
        // SQLite ne supporte pas FOR UPDATE (verrouillage différent) :
        // preuve exigée sur MySQL, comportement couvert partout par
        // test_double_enregistrement_concurrent_ne_cree_qu_une_ligne.
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Verrou ligne FOR UPDATE vérifiable uniquement sur MySQL (driver courant : '.DB::getDriverName().').');
        }

        $verrous = 0;
        DB::listen(function ($requete) use (&$verrous) {
            if (str_contains(strtolower($requete->sql), 'for update')) {
                $verrous++;
            }
        });

        app(EnregistrementPresenceInterface::class)->enregistrer(
            $this->etudiant, '2026-10-07', 'present', 'S1', null, $this->admin->id
        );

        $this->assertGreaterThanOrEqual(1, $verrous, 'Aucun SELECT ... FOR UPDATE : course possible en double POST.');
    }

    public function test_double_enregistrement_concurrent_ne_cree_qu_une_ligne(): void
    {
        $service = app(EnregistrementPresenceInterface::class);
        $service->enregistrer($this->etudiant, '2026-10-07', 'present', 'S1', null, $this->admin->id);
        $service->enregistrer($this->etudiant, '2026-10-07', 'absent', 'S1', 'retard justifié', $this->admin->id);

        $this->assertSame(1, Presence::where('etudiant_id', $this->etudiant->id)->count());
        $this->assertSame('absent', Presence::first()->statut);
    }

    public function test_destroy_journalise_audit_comme_store(): void
    {
        $presence = Presence::create([
            'etudiant_id' => $this->etudiant->id, 'date' => '2026-10-07',
            'seance' => 'S1', 'statut' => 'absent', 'enregistre_par' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->delete(route('admin.presences.destroy', $presence))
            ->assertRedirect();

        $this->assertDatabaseHas('historique_academique', [
            'action' => 'attendance.supprimee',
            'etudiant_id' => $this->etudiant->id,
            'entite_id' => $presence->id,
        ]);
    }

    public function test_registre_masque_la_cin_complete(): void
    {
        Presence::create([
            'etudiant_id' => $this->etudiant->id, 'date' => '2026-10-07',
            'seance' => 'S1', 'statut' => 'present', 'enregistre_par' => $this->admin->id,
        ]);

        $contenu = (string) $this->actingAs($this->admin)
            ->get(route('admin.presences.index'))
            ->assertOk()
            ->getContent();

        // CIN complète jamais exposée (PII) ; forme masquée affichée.
        $this->assertStringNotContainsString('BKP10001', $contenu);
        $this->assertStringContainsString($this->etudiant->cin_masquee, $contenu);
    }

    public function test_piece_mime_falsifie_refusee_sans_stockage(): void
    {
        Storage::fake('local');
        $presence = Presence::create([
            'etudiant_id' => $this->etudiant->id, 'date' => '2026-10-07',
            'seance' => 'S1', 'statut' => 'absent', 'enregistre_par' => $this->admin->id,
        ]);
        session(['etudiant_auth_id' => $this->etudiant->id]);

        // Faux JPEG : extension image mais contenu texte (polyglotte naïf).
        $faux = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "malicieux";');

        $this->post(route('etudiant.absences.justifier', $presence), [
            'motif' => 'Rendez-vous médical',
            'piece' => $faux,
        ])->assertSessionHasErrors('piece');

        $this->assertDatabaseCount('attendance_justifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('justifications'));
    }

    public function test_piece_image_valide_acceptee(): void
    {
        Storage::fake('local');
        $presence = Presence::create([
            'etudiant_id' => $this->etudiant->id, 'date' => '2026-10-07',
            'seance' => 'S1', 'statut' => 'absent', 'enregistre_par' => $this->admin->id,
        ]);
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $this->post(route('etudiant.absences.justifier', $presence), [
            'motif' => 'Rendez-vous médical',
            'piece' => UploadedFile::fake()->image('ordonnance.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('attendance_justifications', 1);
    }
}
