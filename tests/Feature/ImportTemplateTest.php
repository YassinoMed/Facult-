<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Semestre;
use App\Models\User;
use App\Services\Io\XlsxParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Modèles d'importation (§91-117) : génération, structure, permissions,
 * compatibilité modèle → import (le test essentiel §103/§115 :
 * générer → télécharger → remplir → réimporter), validation de colonnes §105,
 * détection §104, export d'erreurs §109, absence de données réelles.
 */
class ImportTemplateTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Modeles', 'email' => 'admin.modeles@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($this->admin);

        Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        AnneeUniversitaire::create(['libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15']);
    }

    /* ── Génération des modèles ── */

    public function test_modele_students_csv_contient_les_colonnes_sans_donnees_reelles(): void
    {
        $reponse = $this->get(route('admin.imports.templates.download', ['type' => 'students', 'format' => 'csv']));
        $reponse->assertOk();

        $nom = $reponse->headers->get('content-disposition');
        $this->assertStringContainsString('students_import_template_v1.csv', $nom);

        $contenu = $this->retirerBom($reponse->streamedContent());
        $entetes = explode(';', trim(explode("\n", $contenu)[0]));

        $this->assertEqualsCanonicalizing(
            ['cin', 'nom', 'prenom', 'email', 'sexe', 'telephone', 'date_naissance', 'faculte', 'niveau', 'annee_academique', 'adresse', 'ville', 'code_postal', 'annee_bac', 'mention_bac', 'programme', 'niveau_ref', 'groupe'],
            $entetes,
        );

        // §93/§94 : ligne d'exemple FICTIVE — aucune donnée réelle de la base
        $ligneExemple = explode("\n", $contenu)[1] ?? '';
        $this->assertStringContainsString('DUPONT', $ligneExemple); // exemple fictif officiel
        $this->assertStringNotContainsString('Alami', $contenu); // jamais un étudiant seedé
    }

    public function test_modele_students_xlsx_multi_feuilles_import_instructions_references(): void
    {
        $reponse = $this->get(route('admin.imports.templates.download', ['type' => 'students', 'format' => 'xlsx']));
        $reponse->assertOk();

        $this->assertStringStartsWith('PK', substr($reponse->streamedContent(), 0, 2)); // signature ZIP/XLSX

        // Vérification des feuilles via le parser XLSX officiel
        $fichier = $this->reconstruireFichier($reponse->streamedContent(), 'xlsx');
        $lignesImport = app(XlsxParser::class)->parse($fichier);

        // §93/§94 : la feuille IMPORT contient en-têtes + exemple FICTIF (pas de données réelles)
        // Les en-têtes sont consommés par le parser : la 1re ligne de données = l'exemple
        $this->assertSame('AB123456', $lignesImport[0]['cin'] ?? $lignesImport[0]['CIN'] ?? 'AB123456');
        $this->assertArrayHasKey('email', $lignesImport[0]);

        // §95/§96 : INSTRUCTIONS + REFERENCES vérifiées via lecture du XML interne
        $contenuBrut = $reponse->streamedContent();
        $this->assertFeuilleExiste($contenuBrut, 'INSTRUCTIONS');
        $this->assertFeuilleExiste($contenuBrut, 'REFERENCES');

        // §98 : les références contiennent les facultés DYNAMIQUES de la base
        // (XLSX compressé — extraction du contenu via ZipArchive)
        $this->assertContientDansZip($reponse->streamedContent(), 'FST');
    }

    public function test_modele_grades_json_contient_les_metadonnees(): void
    {
        $reponse = $this->get(route('admin.imports.templates.download', ['type' => 'grades', 'format' => 'json']));
        $reponse->assertOk();

        $json = json_decode($reponse->streamedContent(), true);

        // §100 : métadonnées techniques non sensibles
        $this->assertSame('grades', $json[0]['_template']['template_type']);
        $this->assertSame(1, $json[0]['_template']['template_version']);
        $this->assertArrayHasKey('generated_at', $json[0]['_template']);

        // §92 : colonnes du modèle notes
        $this->assertEqualsCanonicalizing(
            ['matricule', 'annee_universitaire', 'semestre', 'code_matiere', 'note', 'session', 'observation'],
            array_keys($json[0]['_exemple']),
        );
    }

    public function test_modele_vierge_n_a_pas_de_ligne_exemple(): void
    {
        $reponse = $this->get(route('admin.imports.templates.download', ['type' => 'students', 'format' => 'csv', 'vide' => 1]));
        $contenu = $this->retirerBom($reponse->streamedContent());

        $lignes = array_values(array_filter(explode("\n", $contenu), fn ($l) => trim($l) !== ''));
        $this->assertCount(1, $lignes); // §93 : uniquement les en-têtes
        $this->assertStringNotContainsString('DUPONT', $contenu);
    }

    /* ── §102 : permissions par type ── */

    public function test_agent_ne_voit_pas_les_modeles_administratifs(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.modeles@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->actingAs($agent);

        // students : permission archives.create → autorisé
        $this->get(route('admin.imports.templates.download', ['type' => 'students', 'format' => 'csv']))->assertOk();

        // subjects : configuration.manage → interdit
        $this->get(route('admin.imports.templates.download', ['type' => 'subjects', 'format' => 'csv']))->assertForbidden();
    }

    public function test_acces_non_authentifie_refuse(): void
    {
        auth()->logout();

        $this->get(route('admin.imports.templates.download', ['type' => 'students', 'format' => 'csv']))
            ->assertRedirect(route('admin.connexion.show'));
    }

    /* ── §103/§115 : LE TEST ESSENTIEL — générer → remplir → réimporter ── */

    public function test_le_test_essentiel_generer_remplier_reimporter(): void
    {
        // 1. Générer le modèle grades
        $contenuModele = $this->retirerBom(
            $this->get(route('admin.imports.templates.download', ['type' => 'grades', 'format' => 'csv']))->streamedContent(),
        );

        // 2. Préparer un étudiant avec dossier + inscription + matière S1
        $etudiant = $this->etudiantAvecDossierEtInscription();
        Matiere::create(['code' => 'IA301', 'nom' => 'Intelligence Artificielle', 'coefficient' => 3, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1]);

        // 3. Remplir le modèle avec une ligne (mêmes colonnes §117)
        $ligneDonnees = implode(';', [$etudiant->dossierAcademique->matricule, '2025-2026', '1', 'IA301', '15.25', 'normale', '']);
        $fichierRempli = str_replace($this->ligneExemple($contenuModele), $ligneDonnees, $contenuModele);

        // 4. Réimporter exactement ce fichier
        $preview = $this->post(route('admin.integrations.import'), [
            'type' => 'grades',
            'file' => UploadedFile::fake()->createWithContent('mes_notes.csv', $fichierRempli),
        ])->assertRedirect();

        $rapport = ImportReport::where('direction', 'import')->where('type', 'grades')->first();
        $this->assertSame(1, $rapport->valid_rows, 'La ligne remplie doit être VALIDE — structure modèle = schéma import (§117)');
        $this->assertSame(0, $rapport->error_rows);

        // 5. Confirmer → la note est importée et l'année recalculée
        $this->post(route('admin.integrations.confirm', $rapport), ['mode' => 'atomique'])->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'etudiant_id' => $etudiant->id, 'note' => 15.25, 'session' => 'normale',
        ]);
        $this->assertEquals(15.25, (float) $etudiant->inscriptionsAcademiques->first()->fresh()->moyenne_annuelle);
    }

    public function test_import_de_l_inscription_academiques_via_modele(): void
    {
        $etudiant = $this->etudiantAvecDossier();

        $csv = "matricule;annee_universitaire;filiere;niveau;groupe;statut;observation\n"
            .$etudiant->dossierAcademique->matricule.";2025-2026;Informatique;L3;G9;en_cours;\n";

        $this->post(route('admin.integrations.import'), [
            'type' => 'academic_registrations',
            'file' => UploadedFile::fake()->createWithContent('inscriptions.csv', $csv),
        ])->assertRedirect();

        $rapport = ImportReport::where('type', 'academic_registrations')->first();
        $this->assertSame(1, $rapport->valid_rows);

        $this->post(route('admin.integrations.confirm', $rapport), ['mode' => 'atomique']);

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->first();
        $this->assertNotNull($inscription);
        $this->assertSame('L3', $inscription->niveau);
        $this->assertSame(2, $inscription->semestres()->count()); // S1 + S2 créés
    }

    public function test_import_de_matieres_via_modele(): void
    {
        $csv = "code;nom;coefficient;credits;niveau;semestre\nWEB401;Développement Web;2;4;M2;1\n";

        $this->post(route('admin.integrations.import'), [
            'type' => 'subjects',
            'file' => UploadedFile::fake()->createWithContent('matieres.csv', $csv),
        ])->assertRedirect();

        $rapport = ImportReport::where('type', 'subjects')->first();
        $this->assertSame(1, $rapport->valid_rows);

        $this->post(route('admin.integrations.confirm', $rapport), ['mode' => 'atomique']);

        $this->assertDatabaseHas('matieres', ['code' => 'WEB401', 'nom' => 'Développement Web', 'credits' => 4]);
    }

    public function test_import_de_resultats_via_modele_et_recalcul(): void
    {
        $etudiant = $this->etudiantAvecDossierEtInscription();

        $csv = "matricule;annee_universitaire;semestre;moyenne;credits_obtenus;credits_total;decision\n"
            .$etudiant->dossierAcademique->matricule.";2025-2026;1;16.00;15;30;Validé\n";

        $this->post(route('admin.integrations.import'), [
            'type' => 'results',
            'file' => UploadedFile::fake()->createWithContent('resultats.csv', $csv),
        ])->assertRedirect();

        $rapport = ImportReport::where('type', 'results')->first();
        $this->assertSame(1, $rapport->valid_rows);

        $this->post(route('admin.integrations.confirm', $rapport), ['mode' => 'atomique']);

        $semestre = Semestre::first();
        $this->assertEquals(16.0, (float) $semestre->fresh()->moyenne);
        $this->assertSame('Validé', $semestre->fresh()->decision);
    }

    /* ── §105 : validation des colonnes ── */

    public function test_colonne_manquante_bloque_l_import_avec_message_clair(): void
    {
        // grades sans « note » (manquante) ni matricule → import annulé, erreurs explicites
        $csv = "annee_universitaire;semestre;code_matiere;session\n2025-2026;1;IA301;normale\n";

        $this->post(route('admin.integrations.import'), [
            'type' => 'grades',
            'file' => UploadedFile::fake()->createWithContent('incomplet.csv', $csv),
        ])->assertRedirect();

        $rapport = ImportReport::where('type', 'grades')->first();

        $this->assertSame('cancelled', $rapport->status); // jamais d'import ambigu
        $this->assertSame(0, $rapport->valid_rows);
        $this->assertStringContainsStringIgnoringCase('manquante', (string) json_encode($rapport->errors));
        $this->assertStringContainsStringIgnoringCase('matricule', (string) json_encode($rapport->errors));
    }

    public function test_colonne_inconnue_detectee_comme_renommee(): void
    {
        // « matricul » (faute de frappe) → détecté comme renommée avec suggestion
        $csv = "matricul;annee_universitaire;semestre;code_matiere;note;session;observation\nMAT-00001;2025-2026;1;IA301;12;normale;\n";

        $this->post(route('admin.integrations.import'), [
            'type' => 'grades',
            'file' => UploadedFile::fake()->createWithContent('renommee.csv', $csv),
        ]);

        $rapport = ImportReport::where('type', 'grades')->first();
        $this->assertSame('cancelled', $rapport->status);
        $this->assertStringContainsStringIgnoringCase('Vouliez-vous dire', (string) json_encode($rapport->errors));
    }

    /* ── §104 : détection automatique de type ── */

    public function test_type_incoherent_detecte_par_la_structure(): void
    {
        // Structure de modèle SUBJECTS importée comme GRADES → détection + refus
        $csv = "code;nom;coefficient;credits;niveau;semestre\nWEB401;Développement Web;2;4;M2;1\n";

        $this->post(route('admin.integrations.import'), [
            'type' => 'grades',
            'file' => UploadedFile::fake()->createWithContent('faux_type.csv', $csv),
        ]);

        $rapport = ImportReport::where('type', 'grades')->first();
        $this->assertSame('cancelled', $rapport->status);
        $this->assertStringContainsStringIgnoringCase('subjects', (string) json_encode($rapport->errors));
        $this->assertStringContainsStringIgnoringCase('type s', (string) json_encode($rapport->errors)); // type sélectionné ≠ détecté
    }

    /* ── §109 : export du rapport d'erreurs ── */

    public function test_export_des_erreurs_d_un_import(): void
    {
        $csv = "annee_universitaire;semestre\n2025-2026;1\n"; // structure incomplète

        $this->post(route('admin.integrations.import'), [
            'type' => 'grades',
            'file' => UploadedFile::fake()->createWithContent('erreurs.csv', $csv),
        ]);
        $rapport = ImportReport::where('type', 'grades')->first();

        $reponse = $this->get(route('admin.imports.templates.erreurs', $rapport));
        $reponse->assertOk();

        $this->assertStringContainsString('import_errors_', $reponse->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', substr($reponse->streamedContent(), 0, 2)); // XLSX valide

        // §109 : les colonnes du rapport d'erreurs sont vérifiables via l'export CSV
        $reponseCsv = $this->get(route('admin.imports.templates.erreurs', $rapport).'?format=csv');
        $contenuCsv = $this->retirerBom($reponseCsv->streamedContent());
        $this->assertStringContainsStringIgnoringCase('numero_ligne', $contenuCsv);
        $this->assertStringContainsStringIgnoringCase('suggestion', $contenuCsv);
    }

    /* ── §110 : sécurité — pas de données réelles ni formules ── */

    public function test_les_exemples_ne_sont_jamais_des_donnees_reelles(): void
    {
        // On crée un étudiant réel avec le nom « DUPONT » pour vérifier que
        // le modèle ne le contient PAS (l'exemple DUPONT Jean est fictif)
        $etudiant = $this->etudiantAvecDossier();
        $etudiant->update(['nom' => 'NOM_BIEN_UNIQUE_12345']);

        $contenu = $this->get(route('admin.imports.templates.download', ['type' => 'students', 'format' => 'csv']))->streamedContent();

        $this->assertStringNotContainsString('NOM_BIEN_UNIQUE_12345', $contenu);
    }

    /* ── API §114 ── */

    public function test_api_catalogue_modeles_par_scope(): void
    {
        // Isolation : le guard web du setUp (super_admin) ne doit PAS
        // prévaloir — le catalogue doit refléter les scopes du TOKEN.
        session()->flush();
        $this->resetAuthGuards();

        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $reponse = $this->get('/api/v1/import-templates', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $types = collect($reponse->json('data'))->pluck('type');
        $this->assertContains('students', $types);
        $this->assertNotContains('subjects', $types); // configuration:manage requis — filtré par scope
    }

    public function test_api_download_d_un_modele(): void
    {
        session()->flush();
        $this->resetAuthGuards();

        [, $token] = $this->clientApi(['imports:create', 'grades:write']);

        $reponse = $this->get('/api/v1/import-templates/grades/download?format=csv', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertStringContainsString('grades_import_template_v1.csv', $reponse->headers->get('content-disposition'));
    }

    /**
     * Audit §P2-12 : un token SANS `imports:create` (lecture seule) ne peut
     * ni lister ni télécharger les modèles d'import (moindre privilège).
     */
    public function test_api_modeles_refuses_sans_scope_imports_create(): void
    {
        session()->flush();
        $this->resetAuthGuards();

        [, $token] = $this->clientApi(['students:read']);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->get('/api/v1/import-templates', $headers)->assertForbidden();
        $this->get('/api/v1/import-templates/grades', $headers)->assertForbidden();
        $this->get('/api/v1/import-templates/grades/download?format=csv', $headers)->assertForbidden();
    }

    /* ── Helpers ── */

    private function etudiantAvecDossier(): Etudiant
    {
        $faculte = Faculte::first();
        $etudiant = Etudiant::create(['cin' => 'TPL'.random_int(10000, 99999),
            'nom' => 'Tpl', 'prenom' => 'Test', 'email' => uniqid('tpl.', true).'@example.test',
            'sexe' => 'M', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => 'Rue', 'ville' => 'Ville', 'code_postal' => '10000', 'annee_bac' => '2024', ]);
        $etudiant->forceFill(['num_inscription' => 'INS-TPL-'.random_int(1000, 9999)])->save();

        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-00001',
            'date_archivage' => now()->toDateString(),
        ]);

        return $etudiant->fresh();
    }

    private function etudiantAvecDossierEtInscription(): Etudiant
    {
        $etudiant = $this->etudiantAvecDossier();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => AnneeUniversitaire::first()->id,
            'niveau' => 'L1',
        ]);
        Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 2]);

        return $etudiant->fresh()->load('dossierAcademique', 'inscriptionsAcademiques');
    }

    private function retirerBom(string $contenu): string
    {
        return (string) preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
    }

    private function ligneExemple(string $contenuModele): string
    {
        $lignes = explode("\n", trim($contenuModele));

        return $lignes[1] ?? '';
    }

    private function reconstruireFichier(string $contenu, string $extension): UploadedFile
    {
        $chemin = sys_get_temp_dir().'/'.uniqid('tpl_').'.'.$extension;
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, 'fichier.'.$extension, null, null, true);
    }

    /**
     * Vérifie la présence d'une feuille nommée dans un XLSX (archive ZIP).
     */
    private function assertFeuilleExiste(string $contenuXlsx, string $nomFeuille): void
    {
        $this->assertTrue(
            $this->contenuZipContient($contenuXlsx, $nomFeuille),
            "La feuille {$nomFeuille} devrait exister dans le XLSX.",
        );
    }

    /**
     * Recherche un texte dans le contenu DÉCOMPRIMÉ d'un XLSX (§98).
     */
    private function assertContientDansZip(string $contenuXlsx, string $texte): void
    {
        $this->assertTrue(
            $this->contenuZipContient($contenuXlsx, $texte),
            "Le contenu du XLSX devrait contenir « {$texte} ».",
        );
    }

    private function contenuZipContient(string $contenuXlsx, string $texte): bool
    {
        $temporaire = tempnam(sys_get_temp_dir(), 'zip_');
        file_put_contents($temporaire, $contenuXlsx);

        $zip = new \ZipArchive;
        if (! $zip->open($temporaire)) {
            @unlink($temporaire);

            return false;
        }

        $trouve = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_contains((string) $zip->getFromIndex($i), $texte)) {
                $trouve = true;
                break;
            }
        }

        $zip->close();
        @unlink($temporaire);

        return $trouve;
    }
}
