<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Journal d'audit : chaque action sensible est tracée avec son acteur,
 * son IP, et ses anciennes/nouvelles valeurs — sans jamais contenir
 * de secrets (mots de passe, tokens).
 */
class AuditLogTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function scenarioComplet(): array
    {
        $agent = $this->scolarite('admin_scolarite');
        $etudiant = $this->etudiantAvecDossier();
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
        ]);
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id, 'matricule' => 'MAT-AUD-1', 'date_archivage' => now()->toDateString(),
        ]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id, 'niveau' => 'L1',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        $matiere = Matiere::create([
            'code' => 'AUD101', 'nom' => 'Matière Audit', 'coefficient' => 2, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 1,
        ]);

        return compact('agent', 'etudiant', 'annee', 'dossier', 'inscription', 'semestre', 'matiere');
    }

    public function test_toutes_les_actions_critiques_sont_journalisees(): void
    {
        $c = $this->scenarioComplet();

        // Consultation
        $this->get(route('admin.archives.show', $c['dossier']))->assertOk();

        // Saisie de note
        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $c['matiere']->id, 'note' => 13.5, 'session' => 'normale',
        ])->assertRedirect();

        // Upload d'un document
        $this->post(route('admin.archives.documents.store', $c['dossier']), [
            'type_document' => 'attestation',
            'document' => UploadedFile::fake()->createWithContent('audit.pdf', "%PDF-1.4\naudit\n"),
        ])->assertRedirect();

        // Téléchargement
        $document = DocumentAcademique::first();
        $this->get(route('admin.archives.documents.download', $document))->assertOk();

        $actions = HistoriqueAcademique::whereIn('action', [
            'consultation_dossier', 'creation_note', 'upload_document', 'telechargement_document',
        ])->pluck('action')->unique()->values()->all();

        $this->assertEqualsCanonicalizing([
            'consultation_dossier', 'creation_note', 'upload_document', 'telechargement_document',
        ], $actions);
    }

    public function test_journal_capture_acteur_et_contexte_reseau(): void
    {
        $c = $this->scenarioComplet();
        $agent = $c['agent']; // le scénario s'authentifie lui-même
        HistoriqueAcademique::truncate();

        $this->get(route('admin.archives.show', $c['dossier']))->assertOk();

        $entree = HistoriqueAcademique::first();

        $this->assertEquals($agent->id, $entree->utilisateur_id);
        $this->assertEquals($c['etudiant']->id, $entree->etudiant_id);
        $this->assertEquals('consultation_dossier', $entree->action);
        $this->assertNotNull($entree->adresse_ip);
        $this->assertNotNull($entree->created_at);
        $this->assertNull($entree->updated_at ?? null); // journal immuable : pas de updated_at
    }

    public function test_correction_de_note_tracée_ancienne_et_nouvelle_valeurs(): void
    {
        $c = $this->scenarioComplet();
        $note = Note::create([
            'etudiant_id' => $c['etudiant']->id, 'inscription_academique_id' => $c['inscription']->id,
            'semestre_id' => $c['semestre']->id, 'matiere_id' => $c['matiere']->id,
            'note' => 9.5, 'coefficient' => 2, 'credits' => 3, 'session' => 'normale',
        ]);

        $this->patch(route('admin.archives.notes.update', $note), [
            'note' => 14.25,
            'raison' => 'Erreur de saisie materialisée le jour J',
        ])->assertRedirect();

        $entree = HistoriqueAcademique::where('action', 'modification_note')->first();

        $this->assertNotNull($entree);
        $this->assertStringContainsString('9.5', (string) $entree->ancienne_valeur);
        $this->assertStringContainsString('14.25', (string) $entree->nouvelle_valeur);
    }

    public function test_le_journal_ne_contient_jamais_de_secrets(): void
    {
        $c = $this->scenarioComplet();
        $this->post(route('admin.connexion.login'), [
            'email' => 'admin@universite.ac.ma',
            'password' => 'MotDePasseUltraSecret@42',
        ]);

        // On déclenche des actions tracées…
        $this->get(route('admin.archives.show', $c['dossier']));

        // …et on s'assure qu'aucune entrée du journal ne fuite un secret
        HistoriqueAcademique::all()->each(function (HistoriqueAcademique $entree) {
            $contenu = implode(' ', [
                (string) $entree->ancienne_valeur, (string) $entree->nouvelle_valeur, $entree->action, $entree->entite,
            ]);

            $this->assertStringNotContainsString('MotDePasseUltraSecret', $contenu);
            $this->assertStringNotContainsStringIgnoringCase('password', $contenu);
            $this->assertStringNotContainsStringIgnoringCase('token', $contenu);
        });
    }

    public function test_les_actions_etudiant_sont_egalement_journalisees(): void
    {
        $c = $this->scenarioComplet();
        Storage::disk('local')->put('archives/etudiant_1/documents/etudiant.pdf', 'x');

        $document = DocumentAcademique::create([
            'etudiant_id' => $c['etudiant']->id,
            'type_document' => 'attestation',
            'nom_original' => 'mon_attestation.pdf',
            'chemin' => 'archives/etudiant_1/documents/etudiant.pdf',
            'mime_type' => 'application/pdf', 'taille' => 1,
            'hash_fichier' => str_repeat('c', 64),
        ]);

        $this->etudiantConnecte($c['etudiant']);

        // L'étudiant consulte sa propre archive
        $this->get(route('etudiant.archives.show'))->assertOk();

        $entree = HistoriqueAcademique::where('action', 'telechargement_document')
            ->where('etudiant_id', $c['etudiant']->id)
            ->first();

        $this->assertNull($entree); // pas encore : il n'a pas téléchargé

        $this->get(route('etudiant.archives.documents.download', $document))->assertOk();

        // L'action est tracée pour le bon étudiant et le bon document.
        // NB : en environnement de test, la session scolarité issue du scénario
        // coexiste avec la session étudiante — on vérifie donc le couple
        // étudiant/document, indépendamment de l'acteur administratif.
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $c['etudiant']->id,
            'action' => 'telechargement_document',
            'entite_id' => $document->id,
        ]);
    }
}
