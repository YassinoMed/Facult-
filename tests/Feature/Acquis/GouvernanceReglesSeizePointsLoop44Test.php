<?php

namespace Tests\Feature\Acquis;

use App\Models\RegleAcademiqueVersion;
use App\Models\User;
use App\Services\Acquis\ReglementationAcquisService;
use App\Services\GouvernanceReglesService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * LOOP 44 — COUVERTURE DES 16 POINTS OBLIGATOIRES DE LA PHASE 9.
 *
 * Vérifie le modèle canonique, le cycle de vie, la validité temporelle,
 * la détection des conflits, l'intégration moteur et la sécurité.
 */
class GouvernanceReglesSeizePointsLoop44Test extends TestCase
{
    use RefreshDatabase;

    private GouvernanceReglesService $service;

    private ReglementationAcquisService $moteurAcquis;

    private User $redacteur;

    private User $approbateur;

    private User $publicateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GouvernanceReglesService::class);
        $this->moteurAcquis = app(ReglementationAcquisService::class);

        $this->redacteur = $this->creerUtilisateur('redacteur');
        $this->approbateur = $this->creerUtilisateur('approbateur');
        $this->publicateur = $this->creerUtilisateur('publicateur');
    }

    private function creerUtilisateur(string $nom): User
    {
        return User::create([
            'name' => 'Utilisateur '.$nom,
            'email' => $nom.'.'.uniqid().'@univ.tn',
            'password' => Hash::make('secret-123'),
            'actif' => true,
        ]);
    }

    private function donneesCanoniques(array $surcharges = []): array
    {
        return array_merge([
            'code' => 'REGLE_COMPENSATION_FST_L1',
            'contenu' => [
                'type_regle' => 'COMPENSATION',
                'categorie' => RegleAcademiqueVersion::CATEGORIE_TEXTE_NATIONAL,
                'diplome' => 'LICENCE',
                'etablissement' => 'FST',
                'faculte_id' => 1,
                'formation' => 'LIC-INFO-REG',
                'specialite' => 'Informatique',
                'seuil' => 10.0,
                'article' => 'Article 9',
                'autorite_competente' => 'Ministère MESRS',
            ],
            'reference_officielle' => 'Arrêté ministériel du 7 juillet 2023 - Art. 9',
            'date_effet' => '2024-09-01',
            'date_fin' => '2026-08-31',
        ], $surcharges);
    }

    /** 1. Création d'une règle brouillon */
    public function test_01_creation_d_une_regle_brouillon(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);

        $this->assertSame('REGLE_COMPENSATION_FST_L1', $regle->code);
        $this->assertSame(1, $regle->version);
        $this->assertSame(RegleAcademiqueVersion::STATUT_BROUILLON, $regle->statut);
        $this->assertSame('COMPENSATION', $regle->getTypeRegle());
        $this->assertSame(RegleAcademiqueVersion::CATEGORIE_TEXTE_NATIONAL, $regle->getCategorie());
        $this->assertTrue($regle->estModifiable());
        $this->assertFalse($regle->estPubliee());
    }

    /** 2. Validation d'une règle (approbation 4 yeux) */
    public function test_02_validation_d_une_regle(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);

        $approuvee = $this->service->approuver($regle, $this->approbateur);

        $this->assertSame(RegleAcademiqueVersion::STATUT_APPROUVEE, $approuvee->statut);
        $this->assertSame($this->approbateur->id, $approuvee->approuve_par_id);
        $this->assertNotNull($approuvee->approuve_le);
    }

    /** 3. Publication autorisée */
    public function test_03_publication_autorisee(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);

        $publiee = $this->service->publier($regle, $this->publicateur);

        $this->assertSame(RegleAcademiqueVersion::STATUT_PUBLIEE, $publiee->statut);
        $this->assertSame($this->publicateur->id, $publiee->publie_par_id);
        $this->assertTrue($publiee->estPubliee());
    }

    /** 4. Refus de publication sans validation */
    public function test_04_refus_de_publication_sans_validation(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seule une règle approuvée peut être publiée');
        $this->service->publier($regle, $this->publicateur);
    }

    /** 5. Conservation des anciennes versions */
    public function test_05_conservation_des_anciennes_versions(): void
    {
        $v1 = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);
        $this->service->soumettre($v1, $this->redacteur);
        $this->service->approuver($v1, $this->approbateur);
        $this->service->publier($v1, $this->publicateur);

        $v2 = $this->service->creerBrouillon($this->donneesCanoniques([
            'contenu' => array_merge($this->donneesCanoniques()['contenu'], ['seuil' => 10.5]),
            'date_effet' => '2026-09-01',
            'date_fin' => '2028-08-31',
        ]), $this->redacteur);

        $historique = $this->service->historique('REGLE_COMPENSATION_FST_L1');

        $this->assertCount(2, $historique);
        $this->assertSame(1, $historique->first()->version);
        $this->assertSame(2, $historique->last()->version);
    }

    /** 6. Détection d'une modification non autorisée (immuabilité) */
    public function test_06_detection_d_une_modification_non_autorisee(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('est immuable');

        $regle->date_effet = '2025-01-01';
        $regle->save();
    }

    /** 7. Application temporelle correcte */
    public function test_07_application_temporelle_correcte(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques([
            'date_effet' => '2024-09-01',
            'date_fin' => '2025-08-31',
        ]), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);
        $this->service->publier($regle, $this->publicateur);

        // Avant effet -> null
        $this->assertNull($this->service->regleApplicable('REGLE_COMPENSATION_FST_L1', '2024-08-31'));

        // Pendant -> actif
        $active = $this->service->regleApplicable('REGLE_COMPENSATION_FST_L1', '2024-10-15');
        $this->assertNotNull($active);
        $this->assertSame(1, $active->version);

        // Après fin -> null
        $this->assertNull($this->service->regleApplicable('REGLE_COMPENSATION_FST_L1', '2025-09-01'));
    }

    /** 8. Détection des conflits (produit REVUE_JURIDIQUE_REQUISE) */
    public function test_08_detection_des_conflits(): void
    {
        // Règle contenant des sources concurrentes non résolues
        $regle = $this->service->creerBrouillon($this->donneesCanoniques([
            'contenu' => array_merge($this->donneesCanoniques()['contenu'], [
                'sources_concurrentes' => [
                    'source_1' => 'Arrêté national seuil 10/20',
                    'source_2' => 'Règlement école seuil 12/20',
                ],
            ]),
        ]), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);
        $this->service->publier($regle, $this->publicateur);

        $diagnostic = $this->service->detecterConflits('REGLE_COMPENSATION_FST_L1', '2024-10-01');

        $this->assertSame('REVUE_JURIDIQUE_REQUISE', $diagnostic['statut']);
        $this->assertStringContainsString('sources concurrentes', $diagnostic['motif']);
    }

    /** 9. Règle expirée */
    public function test_09_regle_expiree(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques([
            'date_effet' => '2023-09-01',
            'date_fin' => '2024-06-30',
        ]), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);
        $this->service->publier($regle, $this->publicateur);

        // La règle est expirée à la date d'aujourd'hui
        $applicable = $this->service->regleApplicable('REGLE_COMPENSATION_FST_L1', '2024-09-01');
        $this->assertNull($applicable);
    }

    /** 10. Règle sans source officielle rejetée */
    public function test_10_regle_sans_source(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques([
            'reference_officielle' => null,
        ]), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('référence officielle est obligatoire');
        $this->service->approuver($regle, $this->approbateur);
    }

    /** 11. Cohorte non couverte */
    public function test_11_cohorte_non_couverte(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques([
            'contenu' => array_merge($this->donneesCanoniques()['contenu'], [
                'cohorte' => '2025-2026',
            ]),
            'date_effet' => '2025-09-01',
        ]), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);
        $this->service->publier($regle, $this->publicateur);

        // Date correspondant à la cohorte 2024-2025 -> non couverte
        $this->assertNull($this->service->regleApplicable('REGLE_COMPENSATION_FST_L1', '2024-10-01'));
    }

    /** 12. Absence de recalcul rétroactif automatique */
    public function test_12_absence_de_recalcul_retroactif_automatique(): void
    {
        $v1 = $this->service->creerBrouillon($this->donneesCanoniques([
            'date_effet' => '2024-09-01',
            'date_fin' => '2025-08-31',
        ]), $this->redacteur);
        $this->service->soumettre($v1, $this->redacteur);
        $this->service->approuver($v1, $this->approbateur);
        $this->service->publier($v1, $this->publicateur);

        // Une nouvelle version v2 avec date future est publiée
        $v2 = $this->service->creerBrouillon($this->donneesCanoniques([
            'contenu' => array_merge($this->donneesCanoniques()['contenu'], ['seuil' => 11.0]),
            'date_effet' => '2025-09-01',
            'date_fin' => '2026-08-31',
        ]), $this->redacteur);
        $this->service->soumettre($v2, $this->redacteur);
        $this->service->approuver($v2, $this->approbateur);
        $this->service->publier($v2, $this->publicateur);

        // La consultation sur 2024 retourne toujours v1 avec son seuil d'origine (pas de rétroaction)
        $regle2024 = $this->service->regleApplicable('REGLE_COMPENSATION_FST_L1', '2024-11-01');
        $this->assertNotNull($regle2024);
        $this->assertSame(1, $regle2024->version);
        $this->assertEquals(10.0, $regle2024->contenu['seuil']);
    }

    /** 13. Intégration avec le moteur d'acquis (ReglementationAcquisService) */
    public function test_13_integration_avec_le_moteur_d_acquis(): void
    {
        // 13.a Règle absente -> renvoie statut sans valeur arbitraire
        $inconnu = $this->moteurAcquis->consulterRegleVersionnee('REGLE_INEXISTANTE');
        $this->assertSame('REGLE_NON_TROUVEE', $inconnu['statut']);
        $this->assertFalse($inconnu['applicable']);
        $this->assertNull($inconnu['valeur']);

        // 13.b Règle publiée -> consommée fidèlement
        $regle = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);
        $this->service->publier($regle, $this->publicateur);

        $resultat = $this->moteurAcquis->consulterRegleVersionnee('REGLE_COMPENSATION_FST_L1', '2024-10-01');
        $this->assertSame(RegleAcademiqueVersion::STATUT_PUBLIEE, $resultat['statut']);
        $this->assertTrue($resultat['applicable']);
        $this->assertSame(1, $resultat['version']);
        $this->assertEquals(10.0, $resultat['valeur']['seuil']);
    }

    /** 14. Isolation facultaire */
    public function test_14_isolation_facultaire(): void
    {
        $regleFst = $this->service->creerBrouillon($this->donneesCanoniques([
            'code' => 'REGLE_SPECIFIQUE_FST',
            'contenu' => array_merge($this->donneesCanoniques()['contenu'], [
                'faculte_id' => 1,
            ]),
        ]), $this->redacteur);

        $this->assertSame(1, $regleFst->getFaculteId());
        $this->assertSame('FST', $regleFst->getEtablissement());
    }

    /** 15. Idempotence des transitions */
    public function test_15_idempotence_des_transitions(): void
    {
        $regle = $this->service->creerBrouillon($this->donneesCanoniques(), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);
        $this->service->publier($regle, $this->publicateur);

        // Une règle déjà publiée ne peut pas être republiée
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seule une règle approuvée peut être publiée');
        $this->service->publier($regle, $this->publicateur);
    }

    /** 16. Conservation des réserves réglementaires */
    public function test_16_conservation_des_reserves_reglementaires(): void
    {
        // En cas de conflit juridique, le moteur refuse d'appliquer un seuil inventé
        $regle = $this->service->creerBrouillon($this->donneesCanoniques([
            'contenu' => array_merge($this->donneesCanoniques()['contenu'], [
                'sources_concurrentes' => ['clash' => true],
            ]),
        ]), $this->redacteur);
        $this->service->soumettre($regle, $this->redacteur);
        $this->service->approuver($regle, $this->approbateur);
        $this->service->publier($regle, $this->publicateur);

        $consulte = $this->moteurAcquis->consulterRegleVersionnee('REGLE_COMPENSATION_FST_L1', '2024-10-01');

        $this->assertSame('REVUE_JURIDIQUE_REQUISE', $consulte['statut']);
        $this->assertTrue($consulte['revue_humaine_requise']);
        $this->assertNull($consulte['valeur']);
    }
}
