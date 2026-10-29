<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * L'audit de rétention est un inventaire en lecture seule : il ne doit
 * jamais supprimer, anonymiser ou archiver, même lorsqu'une échéance
 * explicite est dépassée.
 */
class RetentionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_est_en_lecture_seule_et_signale_les_echeances_explicites(): void
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $etudiant = $this->creerEtudiant($faculte);

        StudentSensitiveRecord::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'medical',
            'titre' => 'Aménagement temporaire',
            'statut' => 'cloture',
            'niveau' => 'confidentiel',
            'retention_until' => now()->subDay()->toDateString(),
        ]);

        $code = Artisan::call('portails:audit-retention', ['--json' => true]);
        $this->assertSame(0, $code);

        $sortie = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($sortie['lecture_seule']);
        $this->assertFalse($sortie['suppression_effectuee']);
        $this->assertSame(1, $sortie['ressources']['etudiants']['total']);
        $this->assertSame(1, $sortie['ressources']['student_sensitive_records']['total']);
        $this->assertSame(1, $sortie['ressources']['student_sensitive_records']['echeance_explicite_depassee']);
        $this->assertNull($sortie['ressources']['student_sensitive_records']['duree_jours']);
        $this->assertSame('donnee_personnelle_sensible', $sortie['ressources']['student_sensitive_records']['classe']);

        // Aucune destruction : l'étudiant et le dossier sensible existent
        // toujours, y compris leur éventuel soft delete.
        $this->assertSame(1, Etudiant::count());
        $this->assertSame(1, StudentSensitiveRecord::withTrashed()->count());
    }

    private function creerEtudiant(Faculte $faculte): Etudiant
    {
        return Etudiant::create([
            'cin' => 'RET123456',
            'nom' => 'BEN ALI',
            'prenom' => 'Mohamed',
            'email' => 'retention@example.com',
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => '45 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'mention_bac' => 'Bien',
            'password' => 'MotdepasseSecret123',
        ]);
    }
}
