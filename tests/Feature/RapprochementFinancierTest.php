<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Finances\Models\DossierPaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le rapprochement financier est un constat : il ne confirme aucun
 * paiement, ne corrige rien et signale les états contradictoires.
 */
class RapprochementFinancierTest extends TestCase
{
    use RefreshDatabase;

    public function test_rapport_detecte_les_contradictions_sans_muter_les_donnees(): void
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $etudiant = $this->creerEtudiant($faculte);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'paye' => false,
        ]);

        $dossier = DossierPaiement::create([
            'inscription_academique_id' => $inscription->id,
            'statut' => DossierPaiement::VALIDE,
        ]);

        // Reçu marqué VALIDE mais dont le booléen de validation est faux :
        // contradiction impossible à corriger automatiquement.
        DB::table('recus_paiement')->insert([
            'dossier_paiement_id' => $dossier->id,
            'inscription_academique_id' => $inscription->id,
            'annee_universitaire_id' => $annee->id,
            'version' => 1,
            'storage_path' => 'paiements-inscriptions/2026-2027/1/recus-paiement/x.pdf',
            'original_filename' => 'recu.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
            'sha256' => str_repeat('a', 64),
            'montant_attendu_centimes' => 100000,
            'source_montant' => 'tarif',
            'montant_declare_centimes' => 100000,
            'reference_paiement' => 'REF-CONTRADICTION',
            'date_paiement' => '2026-10-01',
            'statut' => 'VALIDE',
            'depose_le' => now(),
            'est_valide' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $avant = [
            'dossiers' => DB::table('dossiers_paiement')->count(),
            'recus' => DB::table('recus_paiement')->count(),
            'statut_dossier' => DB::table('dossiers_paiement')->where('id', $dossier->id)->value('statut'),
            'est_valide' => DB::table('recus_paiement')->where('reference_paiement', 'REF-CONTRADICTION')->value('est_valide'),
        ];

        $code = Artisan::call('finances:audit-rapprochement', ['--json' => true]);
        $this->assertSame(0, $code);

        $rapport = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($rapport['lecture_seule']);
        $this->assertFalse($rapport['mutation_effectuee']);
        $this->assertSame(1, $rapport['volumes']['dossiers']);
        $this->assertSame(1, $rapport['volumes']['recus']);
        $this->assertGreaterThanOrEqual(1, $rapport['anomalies']['recu_valide_incoherent']['total']);
        $this->assertGreaterThanOrEqual(1, $rapport['anomalies']['dossier_valide_sans_recu_valide']['total']);
        $this->assertGreaterThanOrEqual(1, $rapport['anomalies']['indicateur_paye_contradictoire']['total']);
        $this->assertGreaterThan(0, $rapport['nombre_anomalies']);

        $this->assertSame($avant, [
            'dossiers' => DB::table('dossiers_paiement')->count(),
            'recus' => DB::table('recus_paiement')->count(),
            'statut_dossier' => DB::table('dossiers_paiement')->where('id', $dossier->id)->value('statut'),
            'est_valide' => DB::table('recus_paiement')->where('reference_paiement', 'REF-CONTRADICTION')->value('est_valide'),
        ]);

        $this->assertSame(1, Artisan::call('finances:audit-rapprochement', ['--strict' => true]));
    }

    private function creerEtudiant(Faculte $faculte): Etudiant
    {
        return Etudiant::create([
            'cin' => 'FIN123456',
            'nom' => 'BEN ALI',
            'prenom' => 'Mohamed',
            'email' => 'finance@example.com',
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '45 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'mention_bac' => 'Bien',
            'password' => 'MotdepasseSecret123',
        ]);
    }
}
