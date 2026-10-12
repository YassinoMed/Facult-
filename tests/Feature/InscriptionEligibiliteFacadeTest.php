<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\BlocageInscriptionService;
use App\Services\DocumentsRequisService;
use App\Services\InscriptionEligibilityService;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP A — Façade d'éligibilité (§6 automatisation) : évaluation structurée
 * UNIQUE, agrégation pure des moteurs existants (périodes, blocages/pièces,
 * matrice documents). Dry-run strict : aucune écriture, aucune nouvelle
 * règle — la validation administrative reste une action humaine
 * (intervention_humaine toujours requise, jamais assimilée à un verdict).
 */
class InscriptionEligibiliteFacadeTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        config(['inscriptions.documents_requis_par_type' => [
            'premiere' => [], 'reinscription' => [], 'transfert' => [],
        ]]);
    }

    public function test_dossier_complet_eligible_mode_transition(): void
    {
        // Dossier véritablement propre : CIN standard, GED alimentée,
        // groupe affecté (l'étudiant « valide » du flux réel en a un via
        // l'affectation automatique) → aucun avertissement moteur.
        $etudiant = $this->creerEtudiant('10000001', [], true);
        $inscription = $etudiant->inscriptionsAcademiques()->first();
        // Rapprochement MENS concordant (dossier véritablement complet).
        MensReference::create([
            'cin_normalise' => MensReference::normaliserCin('10000001'),
            'cin_original' => '10000001', 'date_naissance' => '2004-01-01',
            'nom' => 'Benali', 'prenom' => 'Sara', 'annee' => '2026',
            'source_hash' => hash('sha256', '10000001'),
        ]);

        $synthese = app(InscriptionEligibilityService::class)->evaluer($inscription);

        $this->assertTrue($synthese['eligible']);
        $this->assertSame('ELIGIBLE', $synthese['verdict']);
        // Jamais d'assimilation auto : la validation reste humaine.
        $this->assertTrue($synthese['intervention_humaine']);
        $this->assertNotEmpty($synthese['actions_proposees']);
        $this->assertStringContainsString('validation administrative', mb_strtolower(implode(' | ', $synthese['actions_proposees'])));
        $this->assertContains(PeriodeInscriptionService::class, $synthese['sources']);
    }

    public function test_document_manquant_bloque_et_propose_le_complement(): void
    {
        config(['inscriptions.documents_requis_par_type' => ['premiere' => ['cin'], 'reinscription' => [], 'transfert' => []]]);

        $etudiant = $this->creerEtudiant('10000002');
        $inscription = $etudiant->inscriptionsAcademiques()->first();

        $synthese = app(InscriptionEligibilityService::class)->evaluer($inscription);

        $this->assertFalse($synthese['eligible']);
        $this->assertSame('BLOCAGE', $synthese['verdict']);
        $controlePieces = $this->controle($synthese, 'PIECES_OBLIGATOIRES');
        $this->assertSame('blocage', $controlePieces['statut']);
        $this->assertStringContainsString('cin', $synthese['documents']['manquants'][0] ?? '');
        $this->assertStringContainsString('cin', implode(' | ', $synthese['actions_proposees']));
    }

    public function test_periode_fermee_bloque(): void
    {
        Carbon::setTestNow('2026-10-05 10:00');
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59',
        ], null);

        $etudiant = $this->creerEtudiant('10000003');
        $inscription = $etudiant->inscriptionsAcademiques()->first();

        $synthese = app(InscriptionEligibilityService::class)->evaluer($inscription);

        $this->assertFalse($synthese['eligible']);
        $controlePeriode = $this->controle($synthese, 'PERIODE_INSCRIPTION');
        $this->assertSame('blocage', $controlePeriode['statut']);
        $this->assertStringContainsString('REGISTRATION_PERIOD_CLOSED', implode(' | ', $synthese['actions_proposees']));
        Carbon::setTestNow();
    }

    public function test_periode_normale_ouverte_passe(): void
    {
        Carbon::setTestNow('2026-09-15 10:00');
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59',
        ], null);

        $etudiant = $this->creerEtudiant('10000004');
        $inscription = $etudiant->inscriptionsAcademiques()->first();

        $synthese = app(InscriptionEligibilityService::class)->evaluer($inscription);

        $this->assertTrue($synthese['eligible']);
        $this->assertSame('pass', $this->controle($synthese, 'PERIODE_INSCRIPTION')['statut']);
        Carbon::setTestNow();
    }

    public function test_anomalie_bloquante_moteur_empeche_eligibilite(): void
    {
        // Identité incomplète (nom vide) → IdentiteCompleteRule bloquante.
        $etudiant = $this->creerEtudiant('10000099', ['nom' => '']);
        $inscription = $etudiant->inscriptionsAcademiques()->first();

        $synthese = app(InscriptionEligibilityService::class)->evaluer($inscription);

        $this->assertFalse($synthese['eligible']);
        $this->assertSame('BLOCAGE', $synthese['verdict']);
        $this->assertNotEmpty($synthese['blocages']);
        $this->assertSame('blocage', $this->controle($synthese, 'ANOMALIES_DOSSIER')['statut']);
        $this->assertStringContainsString('blocage', mb_strtolower(implode(' | ', $synthese['actions_proposees'])));
    }

    public function test_homonyme_avertissement_sans_blocage(): void
    {
        $premier = $this->creerEtudiant('10000005'); // nom Benali, prénom Sara, 2004-01-01
        $etudiant = $this->creerEtudiant('10000006');

        $synthese = app(InscriptionEligibilityService::class)->evaluer($etudiant->inscriptionsAcademiques()->first());

        $this->assertTrue($synthese['eligible'], 'Un homonyme (CIN différent) ne bloque pas — jamais de fusion sur le nom.');
        $this->assertSame('AVERTISSEMENT', $synthese['verdict']);
        $this->assertNotEmpty($synthese['avertissements']);
        $this->assertNotEmpty($premier->id);
    }

    public function test_purete_aucune_ecriture_base(): void
    {
        $etudiant = $this->creerEtudiant('10000007');
        $inscription = $etudiant->inscriptionsAcademiques()->first();

        $avant = $this->compteurs();
        app(InscriptionEligibilityService::class)->evaluer($inscription);
        $apres = $this->compteurs();

        $this->assertSame($avant, $apres, 'La façade est un dry-run strict : aucune écriture.');
    }

    public function test_structure_complete_et_sources_tracables(): void
    {
        $etudiant = $this->creerEtudiant('10000008');
        $synthese = app(InscriptionEligibilityService::class)->evaluer($etudiant->inscriptionsAcademiques()->first());

        foreach (['eligible', 'intervention_humaine', 'verdict', 'controles', 'blocages', 'avertissements', 'documents', 'actions_proposees', 'sources'] as $cle) {
            $this->assertArrayHasKey($cle, $synthese);
        }
        $this->assertContains(BlocageInscriptionService::class, $synthese['sources']);
        $this->assertContains(DocumentsRequisService::class, $synthese['sources']);
        $this->assertCount(4, $synthese['controles']);
    }

    /** @return array<string, int> */
    private function compteurs(): array
    {
        return [
            'etudiants' => DB::table('etudiants')->count(),
            'inscriptions' => DB::table('inscriptions_academiques')->count(),
            'verifications' => DB::table('dossier_verifications')->count(),
            'corrections' => DB::table('dossier_corrections')->count(),
            'notifications' => DB::table('notifications')->count(),
            'historique' => DB::table('historique_academique')->count(),
            'periodes' => DB::table('registration_periods')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $synthese
     * @return array<string, mixed>
     */
    private function controle(array $synthese, string $code): array
    {
        foreach ($synthese['controles'] as $controle) {
            if ($controle['code'] === $code) {
                return $controle;
            }
        }

        $this->fail("Contrôle {$code} absent de la synthèse.");
    }

    /**
     * @param  array<string, mixed>  $surcharge
     */
    private function creerEtudiant(?string $cin, array $surcharge = [], bool $propre = false): Etudiant
    {
        $programme = Programme::firstOrCreate(
            ['code' => 'LGL-EL', 'faculte_id' => $this->faculte->id],
            ['nom' => 'Licence GL', 'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180]
        );
        $niveau = Niveau::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'L1'],
            ['nom' => 'L1', 'ordre' => 1]
        );

        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin,
            'nom' => 'Benali', 'prenom' => 'Sara', 'email' => mb_strtolower((string) ($cin ?? 'sans.cin'.uniqid())).'@elig.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $surcharge));
        $etudiant->definirStatut('valide');

        if ($propre) {
            // Dossier complet au sens du moteur (DocumentsRequiredRule) :
            // GED avec les types attendus (cin, photo, baccalaureat) +
            // groupe affecté (l'étudiant « valide » du flux réel en a un
            // via l'affectation automatique).
            foreach (['cin', 'photo', 'baccalaureat'] as $typeDocument) {
                DocumentAcademique::create([
                    'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
                    'type_document' => $typeDocument, 'nom_original' => $typeDocument.'.pdf',
                    'chemin' => 'archives/test/'.$typeDocument.'-'.uniqid().'.pdf', 'mime_type' => 'application/pdf',
                    'taille' => 1024, 'hash_fichier' => bin2hex(random_bytes(12)),
                ]);
            }

            $groupe = Group::create([
                'code' => 'G-ELIG-1', 'nom' => 'Groupe éligibilité',
                'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
            ]);
            $etudiant->update(['groupe_id' => $groupe->id]);
        }

        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'niveau' => 'L1', 'type' => 'premiere', 'statut' => 'en_cours',
        ]);

        return $etudiant->fresh();
    }
}
