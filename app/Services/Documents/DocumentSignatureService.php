<?php

namespace App\Services\Documents;

use App\Modules\StudentDossiers\Models\GeneratedDocument;

/**
 * Signature d'intégrité des documents officiels (LOOP-07, R-07/6).
 *
 * HMAC-SHA256 sur les quatre éléments qui identifient le document émis :
 * type, référence, empreinte de contenu et code de vérification. La clé
 * dédiée `DOCUMENTS_SIGNATURE_KEY` est prioritaire ; à défaut, une clé
 * dérivée de `APP_KEY` garantit qu'aucune constante partagée n'est
 * utilisée. La signature est conservée dans `snapshot_donnees` et
 * revérifiée à chaque consultation publique.
 */
final class DocumentSignatureService
{
    public const ALGORITHME = 'HMAC-SHA256';

    /**
     * Signe les éléments immuables d'un document déjà référencé.
     */
    public function signer(
        string $type,
        string $reference,
        string $contenuHash,
        string $codeVerification
    ): string {
        return hash_hmac('sha256', $this->charge($type, $reference, $contenuHash, $codeVerification), $this->cle());
    }

    /**
     * Vérifie la signature stockée dans le snapshot.
     *
     * @return bool|null `null` lorsque le document est antérieur à la
     *                   signature (compatibilité), `false` si elle est
     *                   présente mais invalide.
     */
    public function verifier(GeneratedDocument $document): ?bool
    {
        $snapshot = $document->snapshot_donnees ?? [];
        $signature = $snapshot['signature_document'] ?? null;

        if (! is_array($signature) || ! isset($signature['valeur']) || ! is_string($signature['valeur'])) {
            return null;
        }

        if (($signature['algorithme'] ?? null) !== self::ALGORITHME) {
            return false;
        }

        $attendue = $this->signer(
            (string) $document->type,
            (string) $document->reference,
            (string) $document->contenu_hash,
            (string) $document->code_verification
        );

        return hash_equals($attendue, $signature['valeur']);
    }

    /**
     * Charge canonique signée : ordre et encodage figés.
     */
    private function charge(
        string $type,
        string $reference,
        string $contenuHash,
        string $codeVerification
    ): string {
        return json_encode([
            'type' => $type,
            'reference' => $reference,
            'contenu_hash' => $contenuHash,
            'code_verification' => $codeVerification,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Clé dédiée si configurée, sinon dérivation déterministe d'APP_KEY.
     */
    private function cle(): string
    {
        $dediee = config('documents-officiels.signature.cle');

        if (is_string($dediee) && trim($dediee) !== '') {
            return $dediee;
        }

        return hash_hmac(
            'sha256',
            'uniregistre:documents-officiels:signature:v1',
            (string) config('app.key')
        );
    }
}
