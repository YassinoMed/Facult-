<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API v1 — dossiers académiques, notes et moyennes (§44-45).
 */
class ApiAcademicRecordTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'Faculté Test', 'code' => 'FTEST', 'description' => 'T', 'active' => true]);
        $this->etudiant = Etudiant::create(['cin' => 'RECAPI0001',
            'nom' => 'Record', 'prenom' => 'Api', 'email' => 'record.api@example.com',
            'sexe' => 'F', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => 'Rue', 'ville' => 'Rabat', 'code_postal' => '10000', 'annee_bac' => '2024', ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-REC-00001'])->save();

        DossierAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'matricule' => 'MAT-REC-1', 'date_archivage' => now()->toDateString(),
        ]);

        $annee = AnneeUniversitaire::create(['libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15']);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $annee->id, 'niveau' => 'L1',
            'moyenne_annuelle' => 13.5, 'credits_obtenus' => 30, 'credits_total' => 30, 'decision' => 'Admis(e)', 'statut' => 'valide',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1, 'moyenne' => 13.5, 'credits_obtenus' => 15, 'credits_total' => 15, 'decision' => 'Validé']);
        $matiere = Matiere::create(['code' => 'REC101', 'nom' => 'Mathématiques', 'coefficient' => 3, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1]);

        Note::create([
            'etudiant_id' => $this->etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
            'note' => 15.5, 'coefficient' => 3, 'credits' => 5, 'session' => 'normale',
        ]);
    }

    public function test_dossier_academique_complet_via_api(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $reponse = $this->getApi('/api/v1/students/'.$this->etudiant->id.'/academic-record', $token)
            ->assertOk()
            ->assertJsonPath('data.matricule', 'MAT-REC-1');

        // Structure complète : inscriptions → semestres → notes
        $inscription = $reponse->json('data.inscriptions.0');
        $this->assertSame('2025-2026', $inscription['annee_universitaire']);
        $this->assertSame(13.5, $inscription['moyenne_annuelle']);
        $this->assertSame('Admis(e)', $inscription['decision']);

        $semestre = $inscription['semestres.0'] ?? $inscription['semestres'][0];
        $this->assertSame('Validé', $semestre['decision']);

        $note = $semestre['notes'][0];
        $this->assertSame(15.5, $note['note']);
        $this->assertSame('REC101', $note['matiere']['code']);
    }

    public function test_etudiant_sans_dossier_renvoie_404_propre(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $faculte = Faculte::create(['nom' => 'F2', 'code' => 'F2', 'description' => 'T', 'active' => true]);
        $sansDossier = Etudiant::create(['cin' => 'RECAPI0002',
            'nom' => 'Sans', 'prenom' => 'Dossier', 'email' => 'sans.dossier@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => 'R', 'ville' => 'R', 'code_postal' => '1', 'annee_bac' => '2024', ]);
        $sansDossier->forceFill(['num_inscription' => 'INS-REC-00002'])->save();

        $this->getApi('/api/v1/students/'.$sansDossier->id.'/academic-record', $token)
            ->assertStatus(404);
    }

    public function test_notes_d_un_etudiant(): void
    {
        [, $token] = $this->clientApi(['grades:read']);

        $reponse = $this->getApi('/api/v1/students/'.$this->etudiant->id.'/grades', $token)
            ->assertOk();

        $note = $reponse->json('data.0');
        $this->assertSame(15.5, $note['note']);
        $this->assertSame('REC101', $note['matiere']['code']);
        $this->assertFalse($note['verrouillee']);
    }
}
