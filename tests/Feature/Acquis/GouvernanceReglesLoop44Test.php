<?php

namespace Tests\Feature\Acquis;

use App\Models\RegleAcademiqueVersion;
use App\Models\User;
use App\Services\GouvernanceReglesService;
use App\Support\Permissions;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP 44 — GOUVERNANCE VERSIONNÉE DES RÈGLES ACADÉMIQUES.
 *
 * Vérifie :
 * - l'absence de toute règle métier livrée par le socle ;
 * - le cycle BROUILLON → EN_REVUE → APPROUVEE → PUBLIEE → RETIREE ;
 * - les quatre yeux (auteur ≠ approbateur, auteur ≠ publicateur) ;
 * - l'obligation de référence officielle et de date d'effet ;
 * - l'immuabilité des versions approuvées, publiées et retirées ;
 * - le refus de chevauchement entre deux versions publiées ;
 * - le fail-closed de `regleApplicable()` (aucune version, ambiguïté) ;
 * - la lisibilité historique d'une règle retirée ;
 * - la détection d'anomalies par `verifierCoherence()` ;
 * - la séparation RBAC des pouvoirs de rédaction, approbation et publication.
 *
 * Aucun seuil, quota ou délai officiel n'est inventé : les contenus utilisés
 * ici sont des données de test explicitement identifiées comme telles.
 */
class GouvernanceReglesLoop44Test extends TestCase
{
    use RefreshDatabase;

    private GouvernanceReglesService $service;

    private User $redacteur;

    private User $approbateur;

    private User $publicateur;

    private User $auditeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GouvernanceReglesService::class);

        $this->redacteur = $this->creerUtilisateur('admin_scolarite');
        $this->approbateur = $this->creerUtilisateur('direction_etudes');
        $this->publicateur = $this->creerUtilisateur('autorite_publication');
        $this->auditeur = $this->creerUtilisateur('auditeur');
    }

    private function creerUtilisateur(string $role): User
    {
        $utilisateur = User::create([
            'name' => 'Utilisateur '.$role,
            'email' => $role.'.'.uniqid().'@univ.tn',
            'password' => Hash::make('secret-test'),
            'actif' => true,
        ]);

        $utilisateur->definirRole($role);
        $utilisateur->save();

        return $utilisateur;
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function donneesRegle(array $surcharges = []): array
    {
        return array_merge([
            'code' => 'seuil_compensation_test',
            'contenu' => ['seuil' => 10, 'unite' => 'sur 20', 'origine' => 'DONNEE_DE_TEST'],
            'reference_officielle' => 'Décision de test LOOP 44 — non officielle',
            'date_effet' => '2024-09-01',
        ], $surcharges);
    }

    private function creerBrouillon(array $surcharges = []): RegleAcademiqueVersion
    {
        return $this->service->creerBrouillon($this->donneesRegle($surcharges), $this->redacteur);
    }

    /**
     * Crée puis approuve une règle, sans la publier.
     */
    private function creerRegleApprouvee(array $surcharges = []): RegleAcademiqueVersion
    {
        $regle = $this->creerBrouillon($surcharges);
        $this->service->soumettre($regle, $this->redacteur);

        return $this->service->approuver($regle, $this->approbateur);
    }

    private function creerReglePubliee(array $surcharges = []): RegleAcademiqueVersion
    {
        $regle = $this->creerRegleApprouvee($surcharges);

        return $this->service->publier($regle, $this->publicateur);
    }

    // =========================================================================
    // Socle vide & création
    // =========================================================================

    public function test_aucune_regle_metier_n_est_livree_par_le_socle(): void
    {
        $this->assertSame(0, RegleAcademiqueVersion::query()->count());
        $this->assertNull($this->service->regleApplicable('SEUIL_COMPENSATION_TEST'));
        $this->assertSame(
            ['total' => 0, 'anomalies' => []],
            $this->service->verifierCoherence()
        );
    }

    public function test_creation_brouillon_normalise_le_code_et_calcule_l_empreinte(): void
    {
        $regle = $this->service->creerBrouillon([
            'code' => '  seuil_compensation_test  ',
            'contenu' => ['seuil' => 10, 'origine' => 'DONNEE_DE_TEST'],
            'reference_officielle' => '  Référence de test  ',
            'date_effet' => '2024-09-01',
            'date_fin' => '2025-06-30',
        ], $this->redacteur);

        $this->assertSame('SEUIL_COMPENSATION_TEST', $regle->code);
        $this->assertSame(1, $regle->version);
        $this->assertSame(RegleAcademiqueVersion::STATUT_BROUILLON, $regle->statut);
        $this->assertSame('Référence de test', $regle->reference_officielle);
        $this->assertSame('2024-09-01', $regle->date_effet->toDateString());
        $this->assertSame('2025-06-30', $regle->date_fin->toDateString());
        $this->assertSame($this->redacteur->id, $regle->cree_par_id);
        $this->assertNull($regle->approuve_par_id);
        $this->assertNull($regle->publie_par_id);
        $this->assertSame(
            $this->service->checksum('SEUIL_COMPENSATION_TEST', 1, ['seuil' => 10, 'origine' => 'DONNEE_DE_TEST']),
            $regle->checksum
        );
        $this->assertTrue($regle->estModifiable());
        $this->assertFalse($regle->estPubliee());
    }

    public function test_versions_successives_par_code_et_independance_des_codes(): void
    {
        $v1 = $this->creerBrouillon();
        $v2 = $this->creerBrouillon(['contenu' => ['seuil' => 12, 'origine' => 'DONNEE_DE_TEST']]);
        $autre = $this->creerBrouillon(['code' => 'REGLE_SECONDAIRE_TEST']);

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertSame(1, $autre->version);
        $this->assertNotSame($v1->checksum, $v2->checksum);
        $this->assertCount(2, $this->service->historique('SEUIL_COMPENSATION_TEST'));
    }

    public function test_code_invalide_refuse(): void
    {
        foreach (['', '   ', 'A/B', 'RÈGLE_ACCENTUÉE', str_repeat('A', 121)] as $code) {
            try {
                $this->service->creerBrouillon($this->donneesRegle(['code' => $code]), $this->redacteur);
                $this->fail("Le code « {$code} » aurait dû être refusé.");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(0, RegleAcademiqueVersion::query()->count());
    }

    public function test_contenu_vide_ou_non_tableau_refuse(): void
    {
        foreach ([[], 'seuil 10', null] as $contenu) {
            try {
                $this->service->creerBrouillon($this->donneesRegle(['contenu' => $contenu]), $this->redacteur);
                $this->fail('Un contenu vide ou non tableau aurait dû être refusé.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(0, RegleAcademiqueVersion::query()->count());
    }

    public function test_date_invalide_ou_periode_inversee_refusee(): void
    {
        try {
            $this->service->creerBrouillon($this->donneesRegle(['date_effet' => 'pas-une-date']), $this->redacteur);
            $this->fail('Une date invalide aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Date invalide', $e->getMessage());
        }

        try {
            $this->service->creerBrouillon($this->donneesRegle([
                'date_effet' => '2025-06-30',
                'date_fin' => '2024-09-01',
            ]), $this->redacteur);
            $this->fail('Une période inversée aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('date de fin', $e->getMessage());
        }

        $this->assertSame(0, RegleAcademiqueVersion::query()->count());
    }

    // =========================================================================
    // Cycle de vie & quatre yeux
    // =========================================================================

    public function test_soumission_seulement_depuis_un_brouillon(): void
    {
        $regle = $this->creerBrouillon();

        $soumise = $this->service->soumettre($regle, $this->redacteur);
        $this->assertSame(RegleAcademiqueVersion::STATUT_EN_REVUE, $soumise->statut);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seul un brouillon peut être soumis');
        $this->service->soumettre($soumise, $this->redacteur);
    }

    public function test_modification_en_revue_annule_la_revue(): void
    {
        $regle = $this->creerBrouillon();
        $this->service->soumettre($regle, $this->redacteur);

        $modifiee = $this->service->mettreAJourBrouillon($regle, [
            'contenu' => ['seuil' => 11, 'origine' => 'DONNEE_DE_TEST'],
        ], $this->redacteur);

        $this->assertSame(RegleAcademiqueVersion::STATUT_BROUILLON, $modifiee->statut);
        $this->assertNull($modifiee->approuve_par_id);
        $this->assertNull($modifiee->approuve_le);
        $this->assertSame(11, $modifiee->contenu['seuil']);
        $this->assertSame(
            $this->service->checksum('SEUIL_COMPENSATION_TEST', 1, ['seuil' => 11, 'origine' => 'DONNEE_DE_TEST']),
            $modifiee->checksum
        );
    }

    public function test_approbation_quatre_yeux_interdit_a_l_auteur(): void
    {
        $regle = $this->creerBrouillon();
        $this->service->soumettre($regle, $this->redacteur);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Quatre yeux');
        $this->service->approuver($regle, $this->redacteur);
    }

    public function test_approbation_exige_une_reference_officielle(): void
    {
        $regle = $this->creerBrouillon(['reference_officielle' => null]);
        $this->service->soumettre($regle, $this->redacteur);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('référence officielle est obligatoire');
        $this->service->approuver($regle, $this->approbateur);
    }

    public function test_approbation_exige_une_date_d_effet(): void
    {
        $regle = $this->creerBrouillon(['date_effet' => null]);
        $this->service->soumettre($regle, $this->redacteur);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("date d'effet est obligatoire");
        $this->service->approuver($regle, $this->approbateur);
    }

    public function test_approbation_trace_l_acteur_et_la_date(): void
    {
        $regle = $this->creerBrouillon();
        $this->service->soumettre($regle, $this->redacteur);

        $approuvee = $this->service->approuver($regle, $this->approbateur);

        $this->assertSame(RegleAcademiqueVersion::STATUT_APPROUVEE, $approuvee->statut);
        $this->assertSame($this->approbateur->id, $approuvee->approuve_par_id);
        $this->assertNotNull($approuvee->approuve_le);
        $this->assertSame($this->redacteur->id, $approuvee->cree_par_id);
        $this->assertNull($approuvee->publie_par_id);
    }

    public function test_publication_interdite_a_l_auteur_meme_apres_approbation(): void
    {
        $regle = $this->creerRegleApprouvee();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Séparation des fonctions');
        $this->service->publier($regle, $this->redacteur);
    }

    public function test_publication_exige_le_statut_approuve(): void
    {
        $regle = $this->creerBrouillon();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seule une règle approuvée peut être publiée');
        $this->service->publier($regle, $this->publicateur);
    }

    public function test_publication_trace_l_acteur_et_rend_la_regle_applicable(): void
    {
        $regle = $this->creerReglePubliee();

        $this->assertSame(RegleAcademiqueVersion::STATUT_PUBLIEE, $regle->statut);
        $this->assertSame($this->publicateur->id, $regle->publie_par_id);
        $this->assertNotNull($regle->publie_le);
        $this->assertNotSame($regle->cree_par_id, $regle->publie_par_id);

        $applicable = $this->service->regleApplicable('SEUIL_COMPENSATION_TEST', '2024-10-01');
        $this->assertNotNull($applicable);
        $this->assertSame($regle->id, $applicable->id);
        $this->assertTrue($applicable->estActiveA('2024-10-01'));
        $this->assertFalse($applicable->estActiveA('2024-08-31'));
    }

    public function test_publication_refuse_un_chevauchement_avec_une_version_publiee(): void
    {
        $this->creerReglePubliee([
            'date_effet' => '2024-09-01',
            'date_fin' => '2025-06-30',
        ]);

        $chevauchante = $this->creerRegleApprouvee([
            'contenu' => ['seuil' => 11, 'origine' => 'DONNEE_DE_TEST'],
            'date_effet' => '2025-01-01',
            'date_fin' => '2025-12-31',
        ]);

        try {
            $this->service->publier($chevauchante, $this->publicateur);
            $this->fail('Un chevauchement de versions publiées aurait dû être refusé.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Chevauchement', $e->getMessage());
        }

        $this->assertSame(RegleAcademiqueVersion::STATUT_APPROUVEE, $chevauchante->fresh()->statut);

        // Une version adjacente (début le lendemain de la fin) reste publiable.
        $adjacente = $this->creerRegleApprouvee([
            'contenu' => ['seuil' => 12, 'origine' => 'DONNEE_DE_TEST'],
            'date_effet' => '2025-07-01',
            'date_fin' => '2026-06-30',
        ]);

        $publiee = $this->service->publier($adjacente, $this->publicateur);
        $this->assertSame(RegleAcademiqueVersion::STATUT_PUBLIEE, $publiee->statut);
        $this->assertCount(2, RegleAcademiqueVersion::query()->publiee()->get());
    }

    public function test_regle_applicable_est_fail_closed_hors_periode(): void
    {
        $this->creerReglePubliee([
            'date_effet' => '2024-09-01',
            'date_fin' => '2025-06-30',
        ]);

        $this->assertNull($this->service->regleApplicable('SEUIL_COMPENSATION_TEST', '2024-08-31'));
        $this->assertNotNull($this->service->regleApplicable('SEUIL_COMPENSATION_TEST', '2024-09-01'));
        $this->assertNotNull($this->service->regleApplicable('SEUIL_COMPENSATION_TEST', '2025-06-30'));
        $this->assertNull($this->service->regleApplicable('SEUIL_COMPENSATION_TEST', '2025-07-01'));
        $this->assertNull($this->service->regleApplicable('CODE_INCONNU', '2024-10-01'));
    }

    public function test_regle_applicable_leve_sur_ambiguite(): void
    {
        // Corruption volontaire : deux versions publiées qui se chevauchent,
        // insérées sans passer par le service.
        foreach ([1, 2] as $version) {
            RegleAcademiqueVersion::create([
                'code' => 'REGLE_AMBIGUE_TEST',
                'version' => $version,
                'statut' => RegleAcademiqueVersion::STATUT_PUBLIEE,
                'contenu' => ['version' => $version],
                'reference_officielle' => 'Référence de test',
                'date_effet' => '2024-09-01',
                'date_fin' => null,
                'checksum' => $this->service->checksum('REGLE_AMBIGUE_TEST', $version, ['version' => $version]),
                'cree_par_id' => $this->redacteur->id,
                'approuve_par_id' => $this->approbateur->id,
                'publie_par_id' => $this->publicateur->id,
            ]);
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ambiguïté');
        $this->service->regleApplicable('REGLE_AMBIGUE_TEST', '2024-10-01');
    }

    public function test_retrait_exige_un_statut_publie_et_un_motif(): void
    {
        $brouillon = $this->creerBrouillon();

        try {
            $this->service->retirer($brouillon, $this->publicateur, 'Motif');
            $this->fail('Une règle non publiée ne peut pas être retirée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Seule une règle publiée peut être retirée', $e->getMessage());
        }

        $publiee = $this->creerReglePubliee();

        try {
            $this->service->retirer($publiee, $this->publicateur, '   ');
            $this->fail('Un motif vide aurait dû être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('motif de retrait', $e->getMessage());
        }
    }

    public function test_regle_retiree_reste_consultable_et_lisible_pour_le_passe(): void
    {
        $regle = $this->creerReglePubliee([
            'date_effet' => '2024-09-01',
            'date_fin' => null,
        ]);

        $retiree = $this->service->retirer($regle, $this->publicateur, 'Abrogation de test');

        $this->assertSame(RegleAcademiqueVersion::STATUT_RETIREE, $retiree->statut);
        $this->assertSame($this->publicateur->id, $retiree->retire_par_id);
        $this->assertNotNull($retiree->retire_le);
        $this->assertSame('Abrogation de test', $retiree->motif_retrait);

        // Le contenu reste consultable et l'audit passé retrouve la règle.
        $this->assertSame(10, $retiree->contenu['seuil']);
        $this->assertSame($retiree->id, $this->service->regleApplicable('SEUIL_COMPENSATION_TEST', '2024-10-01')?->id);

        // Après le retrait, plus aucune règle n'est en vigueur.
        $this->assertNull($this->service->regleApplicable('SEUIL_COMPENSATION_TEST', Carbon::now()->addDay()));

        // La version retirée n'est pas supprimable.
        $this->expectException(DomainException::class);
        $retiree->delete();
    }

    // =========================================================================
    // Immuabilité
    // =========================================================================

    public function test_version_approuvee_est_immuable(): void
    {
        $regle = $this->creerRegleApprouvee();

        foreach ([
            'contenu' => ['seuil' => 19, 'origine' => 'DONNEE_DE_TEST'],
            'reference_officielle' => 'Autre référence',
            'date_effet' => '2025-01-01',
            'checksum' => str_repeat('0', 64),
        ] as $champ => $valeur) {
            $copie = $regle->fresh();
            $copie->{$champ} = $valeur;

            try {
                $copie->save();
                $this->fail("Le champ « {$champ} » d'une règle approuvée aurait dû être immuable.");
            } catch (DomainException $e) {
                $this->assertStringContainsString($champ, $e->getMessage());
            }
        }

        $this->assertSame(10, $regle->fresh()->contenu['seuil']);
    }

    public function test_version_publiee_est_immuable_et_non_supprimable(): void
    {
        $regle = $this->creerReglePubliee();

        $copie = $regle->fresh();
        $copie->contenu = ['seuil' => 0, 'origine' => 'DONNEE_DE_TEST'];

        try {
            $copie->save();
            $this->fail('Une règle publiée aurait dû être immuable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ne peut pas être supprimée');
        $regle->fresh()->delete();
    }

    public function test_brouillon_supprimable(): void
    {
        $brouillon = $this->creerBrouillon();

        $brouillon->delete();

        $this->assertSame(0, RegleAcademiqueVersion::query()->count());
    }

    // =========================================================================
    // Cohérence & audit
    // =========================================================================

    public function test_verifier_coherence_est_vide_sur_un_cycle_sain(): void
    {
        $this->creerReglePubliee();
        $this->creerReglePubliee([
            'code' => 'REGLE_SECONDAIRE_TEST',
            'date_effet' => '2024-09-01',
        ]);

        $rapport = $this->service->verifierCoherence();

        $this->assertSame(2, $rapport['total']);
        $this->assertSame([], $rapport['anomalies']);
    }

    public function test_verifier_coherence_detecte_un_checksum_invalide(): void
    {
        $regle = $this->creerReglePubliee();

        DB::table('regles_academiques_versions')
            ->where('id', $regle->id)
            ->update(['contenu' => json_encode(['seuil' => 99], JSON_UNESCAPED_UNICODE)]);

        $rapport = $this->service->verifierCoherence();

        $this->assertContains(
            ['code' => 'SEUIL_COMPENSATION_TEST', 'version' => 1, 'anomalie' => 'CHECKSUM_INVALIDE'],
            $rapport['anomalies']
        );
    }

    public function test_verifier_coherence_detecte_auto_approbation_et_auto_publication(): void
    {
        RegleAcademiqueVersion::create([
            'code' => 'REGLE_AUTO_APPROUVEE_TEST',
            'version' => 1,
            'statut' => RegleAcademiqueVersion::STATUT_PUBLIEE,
            'contenu' => ['origine' => 'DONNEE_DE_TEST'],
            'reference_officielle' => 'Référence de test',
            'date_effet' => '2024-09-01',
            'checksum' => $this->service->checksum('REGLE_AUTO_APPROUVEE_TEST', 1, ['origine' => 'DONNEE_DE_TEST']),
            'cree_par_id' => $this->redacteur->id,
            'approuve_par_id' => $this->redacteur->id,
            'publie_par_id' => $this->redacteur->id,
        ]);

        $anomalies = $this->service->verifierCoherence()['anomalies'];

        $this->assertContains(
            ['code' => 'REGLE_AUTO_APPROUVEE_TEST', 'version' => 1, 'anomalie' => 'AUTO_APPROBATION'],
            $anomalies
        );
        $this->assertContains(
            ['code' => 'REGLE_AUTO_APPROUVEE_TEST', 'version' => 1, 'anomalie' => 'AUTO_PUBLICATION'],
            $anomalies
        );
    }

    public function test_verifier_coherence_detecte_un_chevauchement_publie(): void
    {
        foreach ([1, 2] as $version) {
            RegleAcademiqueVersion::create([
                'code' => 'REGLE_CHEVAUCHANTE_TEST',
                'version' => $version,
                'statut' => RegleAcademiqueVersion::STATUT_PUBLIEE,
                'contenu' => ['version' => $version],
                'reference_officielle' => 'Référence de test',
                'date_effet' => '2024-09-01',
                'date_fin' => '2025-06-30',
                'checksum' => $this->service->checksum('REGLE_CHEVAUCHANTE_TEST', $version, ['version' => $version]),
                'cree_par_id' => $this->redacteur->id,
                'approuve_par_id' => $this->approbateur->id,
                'publie_par_id' => $this->publicateur->id,
            ]);
        }

        $anomalies = $this->service->verifierCoherence()['anomalies'];

        $this->assertContains(
            ['code' => 'REGLE_CHEVAUCHANTE_TEST', 'version' => 2, 'anomalie' => 'CHEVAUCHEMENT_PUBLIE'],
            $anomalies
        );
    }

    public function test_verifier_coherence_detecte_retrait_sans_motif_et_date_inversee(): void
    {
        RegleAcademiqueVersion::create([
            'code' => 'REGLE_RETIREE_INCOMPLETE_TEST',
            'version' => 1,
            'statut' => RegleAcademiqueVersion::STATUT_RETIREE,
            'contenu' => ['origine' => 'DONNEE_DE_TEST'],
            'reference_officielle' => 'Référence de test',
            'date_effet' => '2025-06-30',
            'date_fin' => '2024-09-01',
            'checksum' => $this->service->checksum('REGLE_RETIREE_INCOMPLETE_TEST', 1, ['origine' => 'DONNEE_DE_TEST']),
            'cree_par_id' => $this->redacteur->id,
            'approuve_par_id' => $this->approbateur->id,
            'publie_par_id' => $this->publicateur->id,
        ]);

        $anomalies = $this->service->verifierCoherence()['anomalies'];

        foreach (['RETIREE_SANS_DATE', 'RETIREE_SANS_MOTIF', 'DATE_FIN_AVANT_EFFET'] as $codeAnomalie) {
            $this->assertContains(
                ['code' => 'REGLE_RETIREE_INCOMPLETE_TEST', 'version' => 1, 'anomalie' => $codeAnomalie],
                $anomalies
            );
        }
    }

    public function test_verifier_coherence_detecte_publication_sans_reference_date_ni_approbation(): void
    {
        RegleAcademiqueVersion::create([
            'code' => 'REGLE_PUBLIEE_INCOMPLETE_TEST',
            'version' => 1,
            'statut' => RegleAcademiqueVersion::STATUT_PUBLIEE,
            'contenu' => ['origine' => 'DONNEE_DE_TEST'],
            'reference_officielle' => null,
            'date_effet' => null,
            'checksum' => $this->service->checksum('REGLE_PUBLIEE_INCOMPLETE_TEST', 1, ['origine' => 'DONNEE_DE_TEST']),
            'cree_par_id' => $this->redacteur->id,
        ]);

        $anomalies = $this->service->verifierCoherence()['anomalies'];

        foreach (['PUBLIEE_SANS_REFERENCE', 'PUBLIEE_SANS_DATE_EFFET', 'PUBLIEE_SANS_APPROBATION'] as $codeAnomalie) {
            $this->assertContains(
                ['code' => 'REGLE_PUBLIEE_INCOMPLETE_TEST', 'version' => 1, 'anomalie' => $codeAnomalie],
                $anomalies
            );
        }
    }

    // =========================================================================
    // RBAC : séparation des pouvoirs
    // =========================================================================

    public function test_permissions_academiques_separent_redaction_approbation_publication(): void
    {
        $redacteur = Permissions::pourRole('admin_scolarite');
        $approbateur = Permissions::pourRole('direction_etudes');
        $publicateur = Permissions::pourRole('autorite_publication');
        $auditeur = Permissions::pourRole('auditeur');
        $consultation = Permissions::pourRole('consultation');

        // Le rédacteur prépare et soumet, il n'approuve ni ne publie.
        $this->assertContains('regles.rediger', $redacteur);
        $this->assertContains('regles.soumettre', $redacteur);
        $this->assertNotContains('regles.approuver', $redacteur);
        $this->assertNotContains('regles.publier', $redacteur);

        // L'approbateur approuve, il ne publie pas.
        $this->assertContains('regles.approuver', $approbateur);
        $this->assertNotContains('regles.publier', $approbateur);
        $this->assertNotContains('regles.rediger', $approbateur);

        // L'autorité de publication publie et retire, elle ne rédige pas.
        $this->assertContains('regles.publier', $publicateur);
        $this->assertContains('regles.retirer', $publicateur);
        $this->assertNotContains('regles.rediger', $publicateur);
        $this->assertNotContains('regles.approuver', $publicateur);

        // L'auditeur et la consultation observent sans écrire.
        foreach ([$auditeur, $consultation] as $profil) {
            $this->assertContains('regles.consulter', $profil);
            $this->assertNotContains('regles.rediger', $profil);
            $this->assertNotContains('regles.approuver', $profil);
            $this->assertNotContains('regles.publier', $profil);
        }
    }
}
