<?php

namespace Tests\Feature\Security;

use App\Models\HistoriqueAcademique;
use App\Models\ImportReport;
use App\Models\User;
use App\Services\Io\CsvSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-SECURITY-REMEDIATION-01 — neutralisation CSV/formula injection
 * centralisée : plus aucun export ad hoc ne doit laisser passer une
 * cellule commençant par = + - @ (ou tabulation / retour chariot),
 * qui s'exécuterait comme formule à l'ouverture dans Excel/LibreOffice.
 */
class ExportCsvNeutralisationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Export',
            'email' => 'admin.export@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_neutraliser_ligne_prefixe_les_cellules_dangereuses(): void
    {
        $csv = app(CsvSerializer::class);

        $ligne = $csv->neutraliserLigne([
            '=1+1',
            '+CMD',
            '-2+3',
            '@SUM(A1:A9)',
            "\t=1+1",
            "\r=1+1",
            'Valeur normale',
            null,
            42,
        ]);

        $this->assertSame([
            "'=1+1",
            "'+CMD",
            "'-2+3",
            "'@SUM(A1:A9)",
            "'\t=1+1",
            "'\r=1+1",
            'Valeur normale',
            '',
            '42',
        ], $ligne);

        // Idempotence : une cellule déjà neutralisée n'est pas re-préfixée.
        $this->assertSame("'=1+1", $csv->neutraliserFormule("'=1+1"));
    }

    public function test_export_audit_neutralise_les_donnees_utilisateur(): void
    {
        $utilisateur = User::creerCompteBackOffice([
            'name' => '=cmd|\' /C calc\'!A0',
            'email' => 'formule.audit@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        HistoriqueAcademique::create([
            'utilisateur_id' => $utilisateur->id,
            'action' => 'test.neutralisation',
            'entite' => 'etudiant',
            'entite_id' => 4242,
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.audit.export'));

        $reponse->assertOk();

        $contenu = $reponse->streamedContent();

        $this->assertStringContainsString("'=cmd", $contenu);
        $this->assertStringNotContainsString(';=cmd', $contenu);
        $this->assertStringNotContainsString("\n=cmd", $contenu);
    }

    public function test_rapport_erreurs_import_neutralise_les_valeurs(): void
    {
        $rapport = ImportReport::create([
            'user_id' => $this->admin->id,
            'integration' => 'web',
            'type' => 'data.students',
            'format' => 'csv',
            'external_reference' => 'REF-NEUTRALISATION',
            'errors' => [[
                'ligne' => 3,
                'code' => 'format_invalide',
                'message' => 'Valeur invalide dans le fichier importé.',
                'champ' => 'nom',
                'valeur' => '=HYPERLINK("http://evil.test")',
            ]],
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.donnees.rapport.erreurs', [
            'rapport' => $rapport->id,
            'format' => 'csv',
        ]));

        $reponse->assertOk();

        $contenu = $reponse->streamedContent();

        $this->assertStringContainsString('format_invalide', $contenu);
        $this->assertStringNotContainsString(';=HYPERLINK', $contenu);
        $this->assertStringNotContainsString("\n=HYPERLINK", $contenu);
    }
}
