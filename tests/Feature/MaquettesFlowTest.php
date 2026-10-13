<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Faculte;
use App\Models\User;
use App\Services\Documents\DocumentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * LOT 3 — Maquettes documentaires : import (saisie + upload),
 * versions immuables, publication, archivage, duplication, aperçu,
 * périmètre faculté. Cycle de vie réutilisé, zéro table créée.
 */
class MaquettesFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.maq@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function payload(array $surcharge = []): array
    {
        return array_merge([
            'type' => 'attestation_scolarite',
            'nom' => 'Modèle scolarité FST',
            'locale' => 'fr',
            'contenu' => '<h1>{{ titreDocument }}</h1><p>{{ etudiant.nom }}</p>',
            'schema' => '{"titreDocument": "Titre", "etudiant.nom": "Nom"}',
        ], $surcharge);
    }

    public function test_index_et_nav(): void
    {
        $this->actingAs($this->admin)->get(route('admin.maquettes.index'))->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Maquettes', false)
            ->assertSee(route('admin.maquettes.index'), false);
    }

    public function test_store_cree_brouillon_v1_trace(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload())
            ->assertRedirect();

        $maquette = DocumentTemplate::where('nom', 'Modèle scolarité FST')->firstOrFail();
        $this->assertSame('brouillon', $maquette->statut);
        $this->assertSame(1, $maquette->versions()->count());
        $this->assertNotNull($maquette->version_courante_id);
        $this->assertSame(['titreDocument' => 'Titre', 'etudiant.nom' => 'Nom'], $maquette->versionCourante()->schema_variables);
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'documents-maquettes', 'operation' => 'modele.cree',
        ]);
    }

    public function test_store_upload_fichier(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'modele.html', '<h1>{{ titreDocument }}</h1>', 'text/html'
        );

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => null, 'fichier' => $fichier]))
            ->assertRedirect();

        $maquette = DocumentTemplate::where('nom', 'Modèle scolarité FST')->firstOrFail();
        $this->assertStringContainsString('{{ titreDocument }}', (string) $maquette->versionCourante()->contenu);
    }

    public function test_store_refuse_code_arbitraire(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => '<p>@php echo 1; @endphp</p>']))
            ->assertRedirect()
            ->assertSessionHasErrors('contenu');

        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_store_upload_malveillant_refuse(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'modele.html', '<script>alert(1)</script>', 'text/html'
        );

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => null, 'fichier' => $fichier]))
            ->assertRedirect()
            ->assertSessionHasErrors('contenu');

        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_store_schema_invalide_refuse(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['schema' => '{"mauvaise cle!": "x"}']))
            ->assertRedirect()
            ->assertSessionHasErrors('contenu');

        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_store_upload_docx_extrait_texte(): void
    {
        $fichier = $this->fichierZip('modele.docx', 'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Bonjour DOCX</w:t></w:r></w:p><w:p><w:r><w:t>Deuxieme</w:t></w:r></w:p></w:body></w:document>',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => null, 'fichier' => $fichier]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $maquette = DocumentTemplate::firstOrFail();
        $this->assertStringContainsString('Bonjour DOCX', (string) $maquette->versionCourante()->contenu);
        $this->assertStringNotContainsString('<w:', (string) $maquette->versionCourante()->contenu);
    }

    public function test_store_upload_odt_rtf_md(): void
    {
        $odt = $this->fichierZip('modele.odt', 'content.xml',
            '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text><text:p>Salut ODT</text:p></office:text></office:body></office:document-content>',
            'application/vnd.oasis.opendocument.text');

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => null, 'fichier' => $odt]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringContainsString('Salut ODT', (string) DocumentTemplate::firstOrFail()->versionCourante()->contenu);

        $rtf = UploadedFile::fake()->createWithContent('modele.rtf', '{\\rtf1\\ansi Bonjour RTF\\par Fin}', 'application/rtf');

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['nom' => 'RTF', 'contenu' => null, 'fichier' => $rtf]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringContainsString('Bonjour RTF', (string) DocumentTemplate::where('nom', 'RTF')->firstOrFail()->versionCourante()->contenu);

        $md = UploadedFile::fake()->createWithContent('modele.md', '# Titre MD', 'text/markdown');

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['nom' => 'MD', 'contenu' => null, 'fichier' => $md]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringContainsString('# Titre MD', (string) DocumentTemplate::where('nom', 'MD')->firstOrFail()->versionCourante()->contenu);
    }

    public function test_store_pdf_et_docm_refuses(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('modele.pdf', "%PDF-1.4\ncontenu", 'application/pdf');

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => null, 'fichier' => $pdf]))
            ->assertRedirect()
            ->assertSessionHasErrors('fichier');

        $docm = UploadedFile::fake()->createWithContent('modele.docm', 'contenu', 'application/vnd.ms-word.document.macroenabled.12');

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => null, 'fichier' => $docm]))
            ->assertRedirect()
            ->assertSessionHasErrors('fichier');

        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_store_docx_xxe_neutralise(): void
    {
        $fichier = $this->fichierZip('piege.docx', 'word/document.xml',
            '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY xxe "SECRET">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $this->payload(['contenu' => null, 'fichier' => $fichier]))
            ->assertRedirect()
            ->assertSessionHasErrors('fichier');

        $this->assertSame(0, DocumentTemplate::count());
    }

    /**
     * Construit un faux conteneur ZIP (docx/odt) avec une entrée XML.
     */
    private function fichierZip(string $nom, string $entree, string $xml, string $mime): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'maq').'.zip';
        $archive = new \ZipArchive;
        $archive->open($chemin, \ZipArchive::CREATE);
        $archive->addFromString($entree, $xml);
        $archive->close();

        return new UploadedFile($chemin, $nom, $mime, null, true);
    }

    public function test_visualisation_directe_interpole_sans_executer(): void
    {
        $this->actingAs($this->admin)->post(route('admin.maquettes.store'), $this->payload([
            'contenu' => '<h1>{{ titreDocument }}</h1><p>{{ inconnue }}</p>',
        ]));

        $maquette = DocumentTemplate::firstOrFail();

        $rendu = app(DocumentTemplateService::class)->renduDirect($maquette->versionCourante());

        $this->assertStringContainsString('<h1>Exemple titreDocument</h1>', $rendu);
        $this->assertStringContainsString('{{ inconnue }}', $rendu);
        $this->assertStringNotContainsString('{{ titreDocument }}', $rendu);
    }

    public function test_show_affiche_visualisation_directe(): void
    {
        $this->actingAs($this->admin)->post(route('admin.maquettes.store'), $this->payload());

        $maquette = DocumentTemplate::firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.maquettes.show', $maquette))
            ->assertOk()
            // SEC-12 (13/10) : le contenu est affiché échappé — le rendu
            // réel passe par l'aperçu PDF (Dompdf n'exécute pas le JS).
            ->assertSee('Contenu de la version', false)
            ->assertSee('Exemple titreDocument', false);
    }

    public function test_apercu_pdf_en_ligne_sans_ecriture(): void
    {
        $this->actingAs($this->admin)->post(route('admin.maquettes.store'), $this->payload());

        $maquette = DocumentTemplate::firstOrFail();

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.maquettes.apercu-pdf', $maquette))
            ->assertOk();

        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $reponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $reponse->streamedContent());
    }

    public function test_apercu_pdf_perimetre_et_permission(): void
    {
        $this->actingAs($this->admin)->post(route('admin.maquettes.store'), $this->payload());

        $maquette = DocumentTemplate::firstOrFail();
        $maquette->update(['faculte_id' => Faculte::where('code', 'FST')->firstOrFail()->id]);

        $fmp = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac2', 'email' => 'fac2.maq@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $fmp->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)
            ->get(route('admin.maquettes.apercu-pdf', $maquette))
            ->assertNotFound();

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent2', 'email' => 'agent2.maq@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)
            ->get(route('admin.maquettes.apercu-pdf', $maquette))
            ->assertForbidden();
    }

    public function test_show_affiche_apercu_echappe(): void
    {
        $this->actingAs($this->admin)->post(route('admin.maquettes.store'), $this->payload());

        $maquette = DocumentTemplate::firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.maquettes.show', $maquette))
            ->assertOk()
            ->assertSee('v1', false)
            ->assertSee('Exemple titreDocument', false);
    }

    public function test_versionner_fige_ancienne(): void
    {
        $this->actingAs($this->admin)->post(route('admin.maquettes.store'), $this->payload());
        $maquette = DocumentTemplate::firstOrFail();
        $v1 = $maquette->versionCourante()->contenu;

        $this->actingAs($this->admin)->post(route('admin.maquettes.versionner', $maquette), [
            'contenu' => '<h1>{{ titreDocument }} v2</h1>',
        ])->assertRedirect();

        $maquette->refresh();
        $this->assertSame(2, $maquette->versions()->count());
        $this->assertSame($v1, $maquette->versions()->where('version', 1)->firstOrFail()->contenu);
        $this->assertSame(2, (int) $maquette->versionCourante()->version);
    }

    public function test_publier_archiver_dupliquer(): void
    {
        $this->actingAs($this->admin)->post(route('admin.maquettes.store'), $this->payload());
        $maquette = DocumentTemplate::firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.maquettes.publier', $maquette))->assertRedirect();
        $this->assertSame('actif', $maquette->fresh()->statut);

        // Métadonnées d'une publiée : refusées.
        $this->actingAs($this->admin)
            ->patch(route('admin.maquettes.update', $maquette), ['nom' => 'Piraté'])
            ->assertStatus(422);

        $this->actingAs($this->admin)->post(route('admin.maquettes.dupliquer', $maquette))->assertRedirect();
        $copie = DocumentTemplate::where('nom', 'Modèle scolarité FST (copie)')->firstOrFail();
        $this->assertSame('brouillon', $copie->statut);

        $this->actingAs($this->admin)->post(route('admin.maquettes.archiver', $maquette))->assertRedirect();
        $this->assertSame('archive', $maquette->fresh()->statut);
    }

    public function test_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.maq@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->get(route('admin.maquettes.index'))->assertForbidden();
        $this->actingAs($agent)->post(route('admin.maquettes.store'), $this->payload())->assertForbidden();
    }

    public function test_perimetre_faculte(): void
    {
        $fmp = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $fst = Faculte::where('code', 'FST')->firstOrFail();

        $globale = DocumentTemplate::create(['type' => 'quittance', 'nom' => 'Globale', 'statut' => 'brouillon']);
        $privee = DocumentTemplate::create([
            'type' => 'quittance', 'nom' => 'FST privée', 'statut' => 'brouillon', 'faculte_id' => $fst->id,
        ]);

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.maq@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $fmp->id,
        ], 'admin_faculte');

        // admin_faculte : lecture seule des modèles (template.view).
        $contenu = $this->actingAs($adminFaculte)->get(route('admin.maquettes.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Globale', $contenu);
        $this->assertStringNotContainsString('FST privée', $contenu);

        $this->actingAs($adminFaculte)->get(route('admin.maquettes.show', $privee))->assertNotFound();
        $this->actingAs($adminFaculte)->get(route('admin.maquettes.show', $globale))->assertOk();
    }
}
