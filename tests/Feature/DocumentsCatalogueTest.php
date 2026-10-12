<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Finances\Models\Paiement;
use App\Modules\Finances\Models\Tarif;
use App\Modules\Finances\Services\FinanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\Documents\DocumentEligibiliteService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOT 2 — Catalogue documentaire (15 types) + moteur de règles
 * centralisé (`DocumentEligibiliteService`) : chaque type n'est
 * généré que si ses conditions métier sont satisfaites, jamais sinon.
 */
class DocumentsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.cat@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        Matiere::create([
            'code' => 'INF101', 'nom' => 'Info', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BKCAT001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkcat001@cat.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
            'moyenne_annuelle' => 12.5, 'credits_obtenus' => 30, 'credits_total' => 30,
        ]);
    }

    private function deliberer(string $decision): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programme->id,
            'statut' => 'published', 'created_by' => $this->admin->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $this->inscription->id,
            'moyenne' => 12.5, 'credits_obtenus' => 30, 'credits_total' => 30,
            'decision' => $decision, 'publie' => true,
        ]);
    }

    private function verifier(string $type, ?InscriptionAcademique $inscription = null, array $options = []): array
    {
        return app(DocumentEligibiliteService::class)->verifier(
            $this->etudiant, $type, $inscription ?? $this->inscription, $options
        );
    }

    // ── Catalogue ────────────────────────────────────────────────

    public function test_catalogue_15_types_avec_cles_requises(): void
    {
        $catalogue = OfficialDocumentService::catalogue();

        $this->assertCount(15, $catalogue);

        foreach ($catalogue as $code => $definition) {
            $this->assertArrayHasKey('libelle', $definition, $code);
            $this->assertArrayHasKey('gabarit', $definition, $code);
            $this->assertArrayHasKey('prefixe', $definition, $code);
            $this->assertArrayHasKey('revocable', $definition, $code);
            $this->assertArrayHasKey('qr', $definition, $code);
            $this->assertTrue(view()->exists($definition['gabarit']), "Gabarit manquant : {$code}");
        }
    }

    public function test_prefixes_numerotation_uniques(): void
    {
        $parPrefixe = [];

        foreach (OfficialDocumentService::catalogue() as $code => $definition) {
            $parPrefixe[$definition['prefixe']][] = $code;
        }

        // Seule la famille relevés partage son préfixe (REL) par conception.
        foreach ($parPrefixe as $prefixe => $codes) {
            if ($prefixe === 'REL') {
                $this->assertEqualsCanonicalizing(['releve_notes', 'releve_semestriel', 'releve_cursus'], $codes);
            } else {
                $this->assertCount(1, $codes, "Préfixe dupliqué : {$prefixe}");
            }
        }
    }

    // ── Scolarité / certificat ───────────────────────────────────

    public function test_scolarite_eligible_si_inscription_active_et_dossier_valide(): void
    {
        $controle = $this->verifier('attestation_scolarite');

        $this->assertTrue($controle['eligible']);
        $this->assertSame([], $controle['blocages']);
        $this->assertNotEmpty($controle['satisfaits']);
    }

    public function test_scolarite_bloquee_si_inscription_inactive(): void
    {
        $this->inscription->update(['statut' => 'ajourne']);

        $controle = $this->verifier('attestation_scolarite');

        $this->assertFalse($controle['eligible']);
        $this->assertSame('INSCRIPTION_INACTIVE', $controle['blocages'][0]['code']);
    }

    public function test_scolarite_bloquee_si_dossier_non_valide(): void
    {
        $this->etudiant->definirStatut('en_attente');

        $controle = $this->verifier('certificat_scolarite');

        $this->assertFalse($controle['eligible']);
        $this->assertSame('DOSSIER_NON_VALIDE', $controle['blocages'][0]['code']);
    }

    // ── Présence / assiduité ─────────────────────────────────────

    public function test_presence_bloquee_sans_donnee(): void
    {
        $controle = $this->verifier('attestation_presence');

        $this->assertFalse($controle['eligible']);
        $this->assertSame('PRESENCE_ABSENTE', $controle['blocages'][0]['code']);
    }

    public function test_assiduite_eligible_avec_taux_calcule(): void
    {
        config(['modules.attendance.active' => true]);

        foreach (['present', 'present', 'absent', 'retard'] as $i => $statut) {
            Presence::create([
                'etudiant_id' => $this->etudiant->id,
                'date' => '2026-09-'.(10 + $i),
                'statut' => $statut,
            ]);
        }

        $controle = $this->verifier('attestation_assiduite');

        $this->assertTrue($controle['eligible']);

        $stats = app(DocumentEligibiliteService::class)->statistiquesPresence($this->etudiant);
        $this->assertSame(4, $stats['seances']);
        $this->assertSame(75.0, $stats['taux']);
    }

    // ── Admis / réussite ─────────────────────────────────────────

    public function test_admis_exige_admis_strict_publie(): void
    {
        $this->deliberer('admis');

        $this->assertTrue($this->verifier('attestation_admis')['eligible']);

        $this->inscription->update(['statut' => 'valide']);
        $this->assertTrue($this->verifier('certificat_reussite')['eligible']);
    }

    public function test_admis_refuse_compensation(): void
    {
        $this->deliberer('admis_compensation');

        $controle = $this->verifier('attestation_admis');

        $this->assertFalse($controle['eligible']);
        $this->assertSame('DECISION_MANQUANTE', $controle['blocages'][0]['code']);
    }

    public function test_certificat_reussite_exige_inscription_validee(): void
    {
        $this->deliberer('admis');

        // Inscription encore en_cours : décision OK mais validation
        // administrative manquante.
        $controle = $this->verifier('certificat_reussite');

        $this->assertFalse($controle['eligible']);
        $this->assertSame('INSCRIPTION_NON_VALIDEE', $controle['blocages'][0]['code']);
    }

    // ── Abandon / transfert ──────────────────────────────────────

    public function test_abandon_eligible_si_statut_abandon(): void
    {
        $this->inscription->update(['statut' => 'abandon', 'observation' => 'Abandon volontaire']);

        $controle = $this->verifier('attestation_abandon');

        $this->assertTrue($controle['eligible']);
    }

    public function test_abandon_bloque_si_inscription_active(): void
    {
        $controle = $this->verifier('attestation_abandon');

        $this->assertFalse($controle['eligible']);
        $this->assertSame('ABANDON_NON_CONSTATE', $controle['blocages'][0]['code']);
    }

    public function test_transfert_eligible_avec_situation(): void
    {
        SituationEtudiant::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'transfert',
            'ancienne_valeur' => 'LMI', 'nouvelle_valeur' => 'LGL',
            'motif' => 'Réorientation', 'auteur_id' => $this->admin->id,
            'date_effet' => '2026-10-01',
        ]);

        $controle = $this->verifier('attestation_transfert');

        $this->assertTrue($controle['eligible']);
        $this->assertNotNull($controle['transfert']);
    }

    public function test_transfert_bloque_sans_situation(): void
    {
        $controle = $this->verifier('attestation_transfert');

        $this->assertFalse($controle['eligible']);
        $this->assertSame('TRANSFERT_NON_CONSTATE', $controle['blocages'][0]['code']);
    }

    // ── Quittance ────────────────────────────────────────────────

    private function payerConfirme(int $montant = 500000): Paiement
    {
        config(['modules.finances.active' => true]);
        Tarif::create([
            'code' => 'INSC', 'libelle' => 'Inscription', 'montant_centimes' => $montant,
            'annee_universitaire_id' => $this->annee->id, 'type_frais' => 'inscription', 'actif' => true,
        ]);

        $finances = app(FinanceService::class);
        $facture = $finances->emettreFacture($this->inscription, $this->admin->id);
        $finances->enregistrerPaiement($facture, $montant, 'especes', $this->admin->id);
        $paiement = Paiement::where('facture_id', $facture->id)->firstOrFail();
        $finances->confirmerPaiement($paiement, $this->admin->id);

        return $paiement->fresh();
    }

    public function test_quittance_eligible_si_paiement_confirme(): void
    {
        $paiement = $this->payerConfirme();

        $controle = $this->verifier('quittance');

        $this->assertTrue($controle['eligible']);
        $this->assertSame($paiement->id, $controle['paiement']->id);
    }

    public function test_quittance_bloquee_si_paiement_non_confirme(): void
    {
        $paiement = $this->payerConfirme();
        $paiement->update(['statut' => 'pending']);

        $controle = $this->verifier('quittance', $this->inscription, ['paiement_id' => $paiement->id]);

        $this->assertFalse($controle['eligible']);
        $this->assertSame('PAIEMENT_NON_CONFIRME', $controle['blocages'][0]['code']);
    }

    public function test_quittance_bloquee_si_paiement_autre_etudiant(): void
    {
        $paiement = $this->payerConfirme();

        $autre = Etudiant::create([
            'cin' => 'BKCAT002', 'nom' => 'Alaoui', 'prenom' => 'Mehdi',
            'email' => 'bkcat002@cat.test', 'date_naissance' => '2003-05-05',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'Y',
            'ville' => 'Rabat', 'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);

        $controle = app(DocumentEligibiliteService::class)->verifier(
            $autre, 'quittance', null, ['paiement_id' => $paiement->id]
        );

        $this->assertFalse($controle['eligible']);
        $this->assertSame('PAIEMENT_INTROUVABLE', $controle['blocages'][0]['code']);
    }

    // ── Génération bout en bout ──────────────────────────────────

    public function test_generer_scolarite_bout_en_bout(): void
    {
        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'attestation_scolarite', $this->inscription->id, $this->admin->id
        );

        $this->assertStringStartsWith('ATS-', $document->reference);
        $this->assertSame('valide', $document->statut);
        $this->assertNotNull($document->code_verification);
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'document.genere',
        ]);
        $this->assertDatabaseHas('generated_documents', [
            'id' => $document->id, 'type' => 'attestation_scolarite',
        ]);
    }

    public function test_generer_quittance_bout_en_bout(): void
    {
        $paiement = $this->payerConfirme();

        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'quittance', $this->inscription->id, $this->admin->id,
            null, ['paiement_id' => $paiement->id]
        );

        $this->assertStringStartsWith('QUI-', $document->reference);
        $this->assertSame('valide', $document->statut);
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertSame($paiement->reference, $document->snapshot_donnees['paiement']['reference']);
    }

    public function test_generer_inscription_autre_etudiant_introuvable(): void
    {
        $autre = Etudiant::create([
            'cin' => 'BKCAT003', 'nom' => 'Tazi', 'prenom' => 'Lina',
            'email' => 'bkcat003@cat.test', 'date_naissance' => '2005-02-02',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'Z',
            'ville' => 'Fès', 'code_postal' => '30000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);

        $this->expectException(ModelNotFoundException::class);

        app(OfficialDocumentService::class)->generer(
            $autre, 'attestation_scolarite', $this->inscription->id, $this->admin->id
        );
    }

    public function test_type_hors_catalogue_refuse(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('hors catalogue');

        app(DocumentEligibiliteService::class)->verifier($this->etudiant, 'diplome_martien', $this->inscription);
    }

    public function test_regles_historiques_inchangees(): void
    {
        // Les 6 types historiques gardent leurs messages et comportements.
        $service = app(OfficialDocumentService::class);

        try {
            $service->generer($this->etudiant, 'attestation_reussite', $this->inscription->id, $this->admin->id);
            $this->fail('Sans décision publiée, la génération aurait dû être refusée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('aucune décision publiée requise', $e->getMessage());
        }

        $this->deliberer('ajourne');

        try {
            $service->generer($this->etudiant, 'diplome', $this->inscription->id, $this->admin->id);
            $this->fail('Ajourné : le diplôme aurait dû être refusé.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('aucune décision publiée requise', $e->getMessage());
        }
    }
}
