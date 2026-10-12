<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 6 — Exports : enveloppe (type, nom, BOM), volume exact,
 * caractères spéciaux et formules neutralisés, sans PII.
 */
class ArchivesExportLoop6Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_export_enveloppe_et_volume(): void
    {
        $this->scolarite('super_admin');
        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $etudiant = Etudiant::create([
            'cin' => 'BK654321', 'nom' => 'Export', 'prenom' => 'Test',
            'email' => 'export6@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-EXP-6', 'date_archivage' => now()->toDateString(),
        ]);
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'niveau' => 'L1', 'filiere' => 'Info',
            'annee_universitaire_id' => $annee->id, 'statut' => 'en_cours',
        ]);

        $reponse = $this->get(route('admin.archives.export', $dossier))->assertOk();
        $reponse->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('archive-MAT-EXP-6.csv', (string) $reponse->headers->get('Content-Disposition'));

        $contenu = (string) $reponse->streamedContent();
        // BOM UTF-8 + en-tête + 1 ligne de données.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu);
        $this->assertSame(2, count(array_filter(explode("\n", trim($contenu)))));
        // Aucune PII dans les colonnes : ni CIN ni email.
        $this->assertStringNotContainsString('BK654321', $contenu);
        $this->assertStringNotContainsString('export6@example.com', $contenu);
    }

    public function test_export_formule_et_caracteres_speciaux_neutralises(): void
    {
        $this->scolarite('super_admin');
        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $etudiant = Etudiant::create([
            'cin' => 'BK654322', 'nom' => 'Export; "Piege"', 'prenom' => 'Test',
            'email' => 'export6b@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-EXP-7', 'date_archivage' => now()->toDateString(),
        ]);
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'niveau' => 'L1', 'filiere' => '=CMD("calc")|+calc',
            'annee_universitaire_id' => $annee->id, 'statut' => 'en_cours',
        ]);

        $contenu = (string) $this->get(route('admin.archives.export', $dossier))->assertOk()->streamedContent();

        $this->assertStringNotContainsString('"=CMD', $contenu);
        $this->assertStringContainsString("'=CMD", $contenu);
    }
}
