<?php

namespace Tests\Feature\DataMesh;

use App\DataMesh\Outbox\OutboxMessage;
use App\DataMesh\Outbox\OutboxService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests du Transactional Outbox, de la déduplication et de l'idempotence (§7, §11).
 */
class DataMeshOutboxAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private OutboxService $outbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outbox = new OutboxService;
        OutboxService::reinitialiserAbonnements();
    }

    public function test_enregistrement_atomique_dans_une_transaction_validee(): void
    {
        DB::transaction(function () {
            $this->outbox->enregistrer(
                typeEvenement: 'scolarite.inscription_validee',
                typeAgregat: 'inscription_academique',
                agregatId: 42,
                chargeUtile: ['reference' => 'INS-2026-0042', 'niveau' => 'L1'],
                cleDeduplication: 'dedup_test_001'
            );
        });

        $this->assertDatabaseHas('data_mesh_outbox', [
            'type_evenement' => 'scolarite.inscription_validee',
            'agregat_id' => 42,
            'cle_deduplication' => 'dedup_test_001',
            'statut' => OutboxMessage::STATUT_PENDING,
            'tentatives' => 0,
        ]);
    }

    public function test_annulation_de_transaction_ne_laisse_aucun_message_outbox(): void
    {
        try {
            DB::transaction(function () {
                $this->outbox->enregistrer(
                    typeEvenement: 'scolarite.inscription_validee',
                    typeAgregat: 'inscription_academique',
                    agregatId: 99,
                    chargeUtile: ['reference' => 'INS-ROLLBACK'],
                    cleDeduplication: 'dedup_test_rollback'
                );

                throw new Exception('Erreur métier forçant le rollback.');
            });
        } catch (Exception) {
            // Rollback attendu
        }

        $this->assertDatabaseMissing('data_mesh_outbox', [
            'cle_deduplication' => 'dedup_test_rollback',
        ]);
    }

    public function test_publication_post_commit_vers_abonne(): void
    {
        $messageRecu = null;

        OutboxService::abonner('scolarite.inscription_validee', function (OutboxMessage $msg) use (&$messageRecu) {
            $messageRecu = $msg;
        });

        $msgEnregistre = $this->outbox->enregistrer(
            typeEvenement: 'scolarite.inscription_validee',
            typeAgregat: 'inscription_academique',
            agregatId: 101,
            chargeUtile: ['reference' => 'INS-POST-COMMIT'],
            cleDeduplication: 'dedup_test_publish'
        );

        $nbPublies = $this->outbox->publierEnAttente();

        $this->assertSame(1, $nbPublies);
        $this->assertNotNull($messageRecu);
        $this->assertSame('dedup_test_publish', $messageRecu->cle_deduplication);

        $this->assertDatabaseHas('data_mesh_outbox', [
            'id' => $msgEnregistre->id,
            'statut' => OutboxMessage::STATUT_PUBLISHED,
        ]);
    }

    public function test_deduplication_stricte_empeche_les_doublons(): void
    {
        $msg1 = $this->outbox->enregistrer(
            typeEvenement: 'affectation.groupe_assigne',
            typeAgregat: 'etudiant',
            agregatId: 200,
            chargeUtile: ['groupe' => 'G1'],
            cleDeduplication: 'cle_identique_unique'
        );

        // Deuxième appel avec la même clé d'idempotence
        $msg2 = $this->outbox->enregistrer(
            typeEvenement: 'affectation.groupe_assigne',
            typeAgregat: 'etudiant',
            agregatId: 200,
            chargeUtile: ['groupe' => 'G1_DIFFERENT'],
            cleDeduplication: 'cle_identique_unique'
        );

        $this->assertSame($msg1->id, $msg2->id, 'L’enregistrement avec la même clé doit retourner le message existant.');
        $this->assertSame(1, OutboxMessage::where('cle_deduplication', 'cle_identique_unique')->count());
    }

    public function test_gestion_des_erreurs_et_reprises_jusqu_au_seuil(): void
    {
        OutboxService::abonner('evenement.erreur', function () {
            throw new Exception('Panne temporaire de service consommateur');
        });

        $message = $this->outbox->enregistrer(
            typeEvenement: 'evenement.erreur',
            typeAgregat: 'test',
            agregatId: 300,
            chargeUtile: ['info' => 'test'],
            cleDeduplication: 'dedup_echec'
        );

        // Premier passage : échec
        $publies = $this->outbox->publierEnAttente();
        $this->assertSame(0, $publies);

        $message->refresh();
        $this->assertSame(OutboxMessage::STATUT_FAILED, $message->statut);
        $this->assertSame(1, $message->tentatives);
        $this->assertStringContainsString('Panne temporaire', (string) $message->derniere_erreur);

        // Reprise : tentative suivante
        $reessais = $this->outbox->retenterEchecs(maxTentatives: 3);
        $this->assertSame(0, $reessais); // Toujours en échec

        $message->refresh();
        $this->assertSame(2, $message->tentatives);

        // Si le seuil maxTentatives est dépassé, aucune nouvelle tentative n'est effectuée
        $this->outbox->retenterEchecs(maxTentatives: 2);
        $message->refresh();
        $this->assertSame(2, $message->tentatives);
    }

    public function test_verification_de_l_idempotence_consommateur(): void
    {
        $cle = 'cle_consommateur_001';

        $this->assertFalse($this->outbox->estDejaTraite($cle));

        $this->outbox->enregistrer(
            typeEvenement: 'test.idempotence',
            typeAgregat: 'test',
            agregatId: 500,
            chargeUtile: [],
            cleDeduplication: $cle
        );

        $this->outbox->publierEnAttente();

        $this->assertTrue($this->outbox->estDejaTraite($cle));
    }

    public function test_course_concurrente_resolue_par_contrainte_unique(): void
    {
        $cle = 'cle_course_001';

        // Simule la course : un worker concurrent insère la même clé entre
        // notre SELECT (manqué) et notre INSERT, via l'événement creating
        // (insert() brut ne redéclenche pas l'événement : pas de récursion).
        OutboxMessage::creating(function (OutboxMessage $modele) use ($cle): void {
            if ($modele->cle_deduplication === $cle && ! OutboxMessage::where('cle_deduplication', $cle)->exists()) {
                OutboxMessage::query()->insert([
                    'type_evenement' => 'test.course',
                    'type_agregat' => 'test',
                    'agregat_id' => 501,
                    'cle_deduplication' => $cle,
                    'charge_utile' => json_encode([]),
                    'statut' => OutboxMessage::STATUT_PENDING,
                    'tentatives' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        try {
            $message = $this->outbox->enregistrer(
                typeEvenement: 'test.course',
                typeAgregat: 'test',
                agregatId: 501,
                chargeUtile: [],
                cleDeduplication: $cle
            );
        } finally {
            OutboxMessage::flushEventListeners();
        }

        // Aucune exception, une seule ligne : le perdant récupère l'existant.
        $this->assertSame(1, OutboxMessage::where('cle_deduplication', $cle)->count());
        $this->assertNotNull($message->id);
    }

    public function test_transitions_terminales_gardees(): void
    {
        $message = $this->outbox->enregistrer(
            typeEvenement: 'test.terminal',
            typeAgregat: 'test',
            agregatId: 502,
            chargeUtile: [],
            cleDeduplication: 'cle_terminale_001'
        );

        $message->marquerPublie();
        $publieLe = $message->fresh()->publie_le;

        // Republier = no-op idempotent (horodatage conservé).
        $message->marquerPublie();
        $this->assertSame(OutboxMessage::STATUT_PUBLISHED, $message->fresh()->statut);
        $this->assertEquals($publieLe, $message->fresh()->publie_le);

        // Published → failed interdit (ni perte ni republication fantôme).
        $this->expectException(\LogicException::class);
        $message->marquerEchec('panne tardive');
    }

    public function test_purge_ne_supprime_que_les_publies_anciens(): void
    {
        $vieux = $this->outbox->enregistrer('test.purge', 'test', 510, [], 'cle_purge_vieux');
        $vieux->forceFill(['statut' => OutboxMessage::STATUT_PUBLISHED, 'publie_le' => now()->subDays(31)])->save();
        $recent = $this->outbox->enregistrer('test.purge', 'test', 511, [], 'cle_purge_recent');
        $recent->forceFill(['statut' => OutboxMessage::STATUT_PUBLISHED, 'publie_le' => now()])->save();
        $attente = $this->outbox->enregistrer('test.purge', 'test', 512, [], 'cle_purge_attente');
        $rate = $this->outbox->enregistrer('test.purge', 'test', 513, [], 'cle_purge_rate');
        $rate->marquerEchec('panne');

        $this->assertSame(1, $this->outbox->purgerPublies(30));
        $this->assertDatabaseMissing('data_mesh_outbox', ['cle_deduplication' => 'cle_purge_vieux']);
        $this->assertDatabaseHas('data_mesh_outbox', ['cle_deduplication' => 'cle_purge_recent']);
        $this->assertDatabaseHas('data_mesh_outbox', ['cle_deduplication' => 'cle_purge_attente']);
        $this->assertDatabaseHas('data_mesh_outbox', ['cle_deduplication' => 'cle_purge_rate']);
    }

    public function test_commande_publie_les_messages_en_attente(): void
    {
        $this->outbox->enregistrer('test.commande', 'test', 520, [], 'cle_commande_001');

        $this->artisan('datamesh:outbox-publier')->assertSuccessful();

        $this->assertSame(OutboxMessage::STATUT_PUBLISHED, OutboxMessage::where('cle_deduplication', 'cle_commande_001')->firstOrFail()->statut);
    }
}
