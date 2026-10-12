<?php

namespace App\Modules\StudentDossiers\Contracts;

/**
 * Contrat d'extraction de document d'identité (§11) : le module
 * StudentDossiers ne connaît AUCUN fournisseur OCR (Tesseract ou
 * autre). Le futur module OCR fournira l'implémentation ; en son
 * absence, le job échoue silencieusement (fail-soft).
 */
interface IdentityDocumentExtractorInterface
{
    /**
     * @param  array{storage_path: string, mime_type: string, category: string}  $contexte
     * @return array<string, mixed> Données extraites + confiance par champ.
     */
    public function extraire(array $contexte): array;
}
