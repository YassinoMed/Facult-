<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Attendance\Models\Presence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D-02 — téléchargement sécurisé du justificatif d'absence.
 *
 * Couvre : session étudiante, propriété, statut validé, chemins serveur,
 * MIME réel, en-têtes, nom de fichier, audit, lien UI, autorisation
 * back-office validatrice et absence de requête par ligne.
 */
class JustificatifAbsenceTelechargementTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    private Faculte $faculte;

    private User $scolarite;

    private User $agent;

    private Etudiant $etudiant;

    private Etudiant $autreEtudiant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.attendance.active' => true]);
        Storage::fake('local');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté D-02',
            'code' => 'D02',
            'description' => 'Justificatifs',
            'active' => true,
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité D-02',
            'email' => 'scolarite.d02@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent D-02',
            'email' => 'agent.d02@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->etudiant = $this->creerEtudiant('D02-001');
        $this->autreEtudiant = $this->creerEtudiant('D02-002');
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'D02',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    private function creerPresence(Etudiant $etudiant, string $date = '2026-10-07'): Presence
    {
        return Presence::create([
            'etudiant_id' => $etudiant->id,
            'date' => $date,
            'seance' => 'S1',
            'statut' => 'absent',
            'enregistre_par' => $this->scolarite->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function creerJustification(
        Presence $presence,
        Etudiant $etudiant,
        string $statut = 'approved',
        array $attributs = [],
    ): Justification {
        return Justification::create(array_merge([
            'presence_id' => $presence->id,
            'etudiant_id' => $etudiant->id,
            'motif' => 'Rendez-vous médical',
            'piece_chemin' => "justifications/etudiant_{$etudiant->id}/piece-{$presence->id}.pdf",
            'piece_nom' => 'ordonnance.pdf',
            'statut' => $statut,
        ], $attributs));
    }

    private function stockerPdf(Justification $justification, ?string $chemin = null): void
    {
        Storage::disk('local')->put($chemin ?? $justification->piece_chemin, self::PDF);
    }

    private function sessionEtudiante(Etudiant $etudiant): self
    {
        return $this->withSession(['etudiant_auth_id' => $etudiant->id]);
    }

    public function test_etudiant_proprietaire_telecharge_son_justificatif_approuve(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant);
        $this->stockerPdf($justification);

        $response = $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertOk()
            ->assertDownload();

        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $response->assertHeaderContains('cache-control', 'no-store');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        $this->assertSame(self::PDF, $response->streamedContent());
        $this->assertSame('approved', $justification->fresh()->statut);
        $this->assertSame('absent', $presence->fresh()->statut);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'attendance.justificatif_telecharge',
            'entite' => 'justification',
            'entite_id' => $justification->id,
        ]);
    }

    public function test_utilisateur_non_authentifie_redirige_vers_la_connexion_etudiante(): void
    {
        $justification = $this->creerJustification(
            $this->creerPresence($this->etudiant),
            $this->etudiant,
        );

        $this->get(route('etudiant.absences.justificatif', $justification))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_compte_back_office_sans_session_etudiante_ne_peut_pas_utiliser_la_route_etudiante(): void
    {
        $justification = $this->creerJustification(
            $this->creerPresence($this->etudiant),
            $this->etudiant,
        );

        $this->actingAs($this->scolarite)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_etudiant_ne_peut_pas_telecharger_le_justificatif_d_un_autre_etudiant(): void
    {
        $presence = $this->creerPresence($this->autreEtudiant);
        $justification = $this->creerJustification($presence, $this->autreEtudiant);
        $this->stockerPdf($justification);

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertNotFound();
    }

    public function test_identifiant_inexistant_repond_404(): void
    {
        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', 999999))
            ->assertNotFound();
    }

    public function test_justificatif_en_attente_ou_rejete_refuse_pour_etudiant(): void
    {
        foreach (['pending', 'rejected'] as $index => $statut) {
            $presence = $this->creerPresence($this->etudiant, '2026-10-0'.($index + 1));
            $justification = $this->creerJustification($presence, $this->etudiant, $statut);
            $this->stockerPdf($justification);

            $this->sessionEtudiante($this->etudiant)
                ->get(route('etudiant.absences.justificatif', $justification))
                ->assertNotFound();
        }
    }

    public function test_absence_sans_justificatif_ne_produit_pas_de_lien(): void
    {
        $this->creerPresence($this->etudiant);

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.index'))
            ->assertOk()
            ->assertDontSee('Télécharger le justificatif');
    }

    public function test_lien_etudiant_visible_uniquement_pour_justificatif_approuve_avec_piece(): void
    {
        $approuve = $this->creerJustification(
            $this->creerPresence($this->etudiant, '2026-10-01'),
            $this->etudiant,
        );

        $enAttente = $this->creerJustification(
            $this->creerPresence($this->etudiant, '2026-10-02'),
            $this->etudiant,
            'pending',
        );

        $sansPiece = $this->creerJustification(
            $this->creerPresence($this->etudiant, '2026-10-03'),
            $this->etudiant,
            'approved',
            ['piece_chemin' => null],
        );

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.index'))
            ->assertOk()
            ->assertSee(route('etudiant.absences.justificatif', $approuve))
            ->assertSee('Télécharger le justificatif')
            ->assertDontSee(route('etudiant.absences.justificatif', $enAttente))
            ->assertDontSee(route('etudiant.absences.justificatif', $sansPiece));

        $this->assertStringNotContainsString(
            'justifications/etudiant_',
            route('etudiant.absences.justificatif', $approuve),
        );
    }

    public function test_chemin_absent_ou_fichier_manquant_refuse(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $sansChemin = $this->creerJustification($presence, $this->etudiant, 'approved', [
            'piece_chemin' => null,
        ]);

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $sansChemin))
            ->assertNotFound();

        $cheminVide = $this->creerJustification(
            $this->creerPresence($this->etudiant, '2026-10-08'),
            $this->etudiant,
            'approved',
            ['piece_chemin' => '   '],
        );

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $cheminVide))
            ->assertNotFound();

        $fichierAbsent = $this->creerJustification(
            $this->creerPresence($this->etudiant, '2026-10-09'),
            $this->etudiant,
        );

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $fichierAbsent))
            ->assertNotFound();
    }

    public function test_chemins_absolus_malformes_et_traversee_refuses(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant);
        $id = $this->etudiant->id;

        $chemins = [
            'absolu linux' => '/etc/passwd',
            'absolu windows' => 'C:/Windows/win.ini',
            'antislash' => "justifications\\etudiant_{$id}\\piece.pdf",
            'traversee' => "justifications/etudiant_{$id}/../etudiant_{$this->autreEtudiant->id}/piece.pdf",
            'segment point' => "justifications/etudiant_{$id}/./piece.pdf",
            'double slash' => "justifications//etudiant_{$id}/piece.pdf",
            'slash final' => "justifications/etudiant_{$id}/piece.pdf/",
            'mauvais dossier' => "justifications/etudiant_{$this->autreEtudiant->id}/piece.pdf",
            'octet nul' => "justifications/etudiant_{$id}/piece.pdf\0.png",
        ];

        foreach ($chemins as $libelle => $chemin) {
            $justification->update(['piece_chemin' => $chemin]);

            $this->sessionEtudiante($this->etudiant)
                ->get(route('etudiant.absences.justificatif', $justification))
                ->assertNotFound("Chemin refusé attendu : {$libelle}");
        }
    }

    public function test_mime_non_autorise_refuse(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant);

        Storage::disk('local')->put(
            $justification->piece_chemin,
            '<html><body><script>alert(1)</script></body></html>',
        );

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertNotFound();
    }

    public function test_nom_de_telechargement_maitrise_et_sans_injection(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant, 'approved', [
            'piece_nom' => "../../evil\r\nX-Injected: 1.pdf",
        ]);
        $this->stockerPdf($justification);

        $response = $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertOk()
            ->assertDownload();

        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringContainsString('evil', $disposition);
        $this->assertStringNotContainsString('../', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertNull($response->headers->get('X-Injected'));
    }

    public function test_telechargement_ne_modifie_pas_les_statuts_ni_la_validation(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant, 'approved', [
            'commentaire_validation' => 'Pièce conforme',
            'valide_par' => $this->scolarite->id,
            'validee_a' => now(),
        ]);
        $this->stockerPdf($justification);

        $avant = [
            'statut' => $justification->statut,
            'commentaire_validation' => $justification->commentaire_validation,
            'valide_par' => $justification->valide_par,
            'validee_a' => $justification->validee_a?->toDateTimeString(),
        ];

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertOk();

        $apres = $justification->fresh();

        $this->assertSame($avant, [
            'statut' => $apres->statut,
            'commentaire_validation' => $apres->commentaire_validation,
            'valide_par' => $apres->valide_par,
            'validee_a' => $apres->validee_a?->toDateTimeString(),
        ]);
        $this->assertSame('absent', $presence->fresh()->statut);
    }

    public function test_audit_ne_contient_pas_le_chemin_ni_le_nom_de_piece(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant);
        $this->stockerPdf($justification);

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertOk();

        $audit = DB::table('historique_academique')
            ->where('action', 'attendance.justificatif_telecharge')
            ->where('entite_id', $justification->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('justifications/etudiant_', (string) $audit->nouvelle_valeur);
        $this->assertStringNotContainsString('ordonnance.pdf', (string) $audit->nouvelle_valeur);
        $this->assertStringContainsString('"par":"etudiant"', (string) $audit->nouvelle_valeur);
    }

    public function test_aucun_chemin_de_stockage_n_est_expose_dans_la_reponse(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant);
        $this->stockerPdf($justification);

        $response = $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertOk();

        $entetes = (string) $response->headers;
        $contenu = $response->streamedContent();

        $this->assertStringNotContainsString('justifications/etudiant_', $entetes);
        $this->assertStringNotContainsString('storage/app', $entetes);
        $this->assertStringNotContainsString('justifications/etudiant_', $contenu);
    }

    public function test_back_office_validateur_telecharge_piece_en_attente(): void
    {
        $presence = $this->creerPresence($this->etudiant);
        $justification = $this->creerJustification($presence, $this->etudiant, 'pending');
        $this->stockerPdf($justification);

        $response = $this->actingAs($this->scolarite)
            ->get(route('admin.justifications.piece', $justification))
            ->assertOk()
            ->assertDownload();

        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame('pending', $justification->fresh()->statut);

        $this->assertDatabaseHas('historique_academique', [
            'utilisateur_id' => $this->scolarite->id,
            'etudiant_id' => $this->etudiant->id,
            'action' => 'attendance.justificatif_telecharge',
            'entite' => 'justification',
            'entite_id' => $justification->id,
        ]);
    }

    public function test_back_office_sans_permission_de_validation_refuse(): void
    {
        $justification = $this->creerJustification(
            $this->creerPresence($this->etudiant),
            $this->etudiant,
            'pending',
        );
        $this->stockerPdf($justification);

        $this->actingAs($this->agent)
            ->get(route('admin.justifications.piece', $justification))
            ->assertForbidden();
    }

    public function test_back_office_non_authentifie_redirige_vers_la_connexion_admin(): void
    {
        $justification = $this->creerJustification(
            $this->creerPresence($this->etudiant),
            $this->etudiant,
        );

        $this->get(route('admin.justifications.piece', $justification))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_etudiant_ne_peut_pas_utiliser_la_route_back_office(): void
    {
        $justification = $this->creerJustification(
            $this->creerPresence($this->etudiant),
            $this->etudiant,
        );

        $this->sessionEtudiante($this->etudiant)
            ->get(route('admin.justifications.piece', $justification))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_back_office_identifiant_inexistant_repond_404(): void
    {
        $this->actingAs($this->scolarite)
            ->get(route('admin.justifications.piece', 999999))
            ->assertNotFound();
    }

    public function test_lien_piece_visible_pour_validateur_dans_file_scolarite(): void
    {
        $justification = $this->creerJustification(
            $this->creerPresence($this->etudiant),
            $this->etudiant,
            'pending',
        );

        $this->actingAs($this->scolarite)
            ->get(route('admin.justifications.index'))
            ->assertOk()
            ->assertSee(route('admin.justifications.piece', $justification));

        $this->actingAs($this->agent)
            ->get(route('admin.justifications.index'))
            ->assertOk()
            ->assertDontSee(route('admin.justifications.piece', $justification));
    }

    public function test_routes_du_lot_sont_fermees_si_le_module_est_desactive(): void
    {
        $justification = $this->creerJustification(
            $this->creerPresence($this->etudiant),
            $this->etudiant,
        );

        config(['modules.attendance.active' => false]);

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.justificatif', $justification))
            ->assertNotFound();

        $this->actingAs($this->scolarite)
            ->get(route('admin.justifications.piece', $justification))
            ->assertNotFound();
    }

    public function test_liste_etudiante_charge_les_justifications_en_une_requete(): void
    {
        foreach (range(1, 5) as $jour) {
            $presence = $this->creerPresence($this->etudiant, '2026-10-0'.$jour);
            $this->creerJustification($presence, $this->etudiant);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->sessionEtudiante($this->etudiant)
            ->get(route('etudiant.absences.index'))
            ->assertOk();

        $requetes = collect(DB::getQueryLog())
            ->filter(fn (array $requete) => str_contains($requete['query'], 'attendance_justifications'));

        DB::disableQueryLog();

        $this->assertCount(1, $requetes);
    }
}
