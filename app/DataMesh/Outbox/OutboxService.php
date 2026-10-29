<?php

namespace App\DataMesh\Outbox;

use App\DataMesh\Contrats\OutboxInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service d'orchestration Transactional Outbox.
 * Assure la livraison au moins une fois (at-least-once) post-commit,
 * l'idempotence par clé unique et la reprise sur erreur.
 */
class OutboxService implements OutboxInterface
{
    /** @var array<string, array<int, callable>> Abonnements en mémoire */
    private static array $abonnements = [];

    /**
     * Enregistre un message dans la table outbox.
     * Doit être appelé à l'intérieur de la transaction métier.
     */
    public function enregistrer(
        string $typeEvenement,
        string $typeAgregat,
        int $agregatId,
        array $chargeUtile,
        ?string $cleDeduplication = null,
    ): OutboxMessage {
        $cle = $cleDeduplication ?? hash('sha256', "{$typeEvenement}_{$typeAgregat}_{$agregatId}_".microtime(true).'_'.random_int(1000, 9999));

        // Idempotence : si un message avec la même clé existe déjà, on le retourne.
        // Chemin rapide (cas nominal) ; la contrainte UNIQUE reste le garde-fou
        // atomique en cas de course entre le SELECT et l'INSERT (P0-1).
        $existant = OutboxMessage::where('cle_deduplication', $cle)->first();
        if ($existant !== null) {
            return $existant;
        }

        try {
            return OutboxMessage::create([
                'type_evenement' => $typeEvenement,
                'type_agregat' => $typeAgregat,
                'agregat_id' => $agregatId,
                'cle_deduplication' => $cle,
                'charge_utile' => $chargeUtile,
                'statut' => OutboxMessage::STATUT_PENDING,
                'tentatives' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Course perdue : un worker concurrent a inséré la même clé
            // entre notre SELECT et notre INSERT — on retourne l'existant
            // au lieu de propager une erreur (livraison au moins une fois).
            return OutboxMessage::where('cle_deduplication', $cle)->firstOrFail();
        }
    }

    /**
     * Publie les messages en attente vers les abonnés et les événements Laravel.
     */
    public function publierEnAttente(int $limite = 50): int
    {
        $messages = OutboxMessage::enAttente()
            ->orderBy('id')
            ->limit($limite)
            ->get();

        $publies = 0;

        foreach ($messages as $message) {
            try {
                $this->expedier($message);
                $message->marquerPublie();
                $publies++;
            } catch (Throwable $e) {
                $message->marquerEchec($e->getMessage());
                Log::error('DataMesh Outbox : Échec d’expédition de message', [
                    'message_id' => $message->id,
                    'type' => $message->type_evenement,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }

        return $publies;
    }

    /**
     * Réessaie les messages en échec ayant un nombre de tentatives inférieur au seuil.
     */
    public function retenterEchecs(int $maxTentatives = 3): int
    {
        $messages = OutboxMessage::echoues()
            ->where('tentatives', '<', $maxTentatives)
            ->orderBy('id')
            ->get();

        $reussis = 0;

        foreach ($messages as $message) {
            try {
                $this->expedier($message);
                $message->marquerPublie();
                $reussis++;
            } catch (Throwable $e) {
                $message->marquerEchec($e->getMessage());
            }
        }

        return $reussis;
    }

    /**
     * Permet à un domaine de s'abonner explicitement à un type d'événement Outbox.
     */
    public static function abonner(string $typeEvenement, callable $handler): void
    {
        self::$abonnements[$typeEvenement][] = $handler;
    }

    /**
     * Réinitialise les abonnements en mémoire (utile entre chaque test).
     */
    public static function reinitialiserAbonnements(): void
    {
        self::$abonnements = [];
    }

    /**
     * Vérifie si une clé d'idempotence a déjà été traitée avec succès.
     */
    public function estDejaTraite(string $cleDeduplication): bool
    {
        return OutboxMessage::where('cle_deduplication', $cleDeduplication)
            ->where('statut', OutboxMessage::STATUT_PUBLISHED)
            ->exists();
    }

    /**
     * Purge les messages publiés anciens. Ne touche JAMAIS aux messages
     * pending/failed (encore nécessaires). Rétention configurable.
     *
     * @return int Nombre de messages supprimés
     */
    public function purgerPublies(int $jours = 30): int
    {
        if ($jours < 1) {
            throw new \InvalidArgumentException('Purge outbox : le délai doit être ≥ 1 jour.');
        }

        return OutboxMessage::where('statut', OutboxMessage::STATUT_PUBLISHED)
            ->where('publie_le', '<', now()->subDays($jours))
            ->delete();
    }

    private function expedier(OutboxMessage $message): void
    {
        // 1. Dispatch vers les abonnés explicites du registre DataMesh
        if (! empty(self::$abonnements[$message->type_evenement])) {
            foreach (self::$abonnements[$message->type_evenement] as $handler) {
                $handler($message);
            }
        }

        // 2. Dispatch vers le bus d'événements Laravel standard
        Event::dispatch("datamesh.{$message->type_evenement}", [$message]);
    }
}
