<?php

namespace App\Services\Identity;

/**
 * Abstraction OCR (§1/§4) : LE contrat de l'application. Toute
 * implémentation (moteur local, service cloud…) doit produire un
 * OcrResult normalisé à partir d'un fichier — l'application n'est
 * jamais couplée à un moteur.
 */
interface OcrServiceInterface
{
    /** Langue inconnue → détection automatique (§2). */
    public const LANGUE_AUTO = 'auto';

    /**
     * Extrait et structure les données d'un document d'identité.
     *
     * @param  string  $filePath  chemin absolu du fichier temporaire
     * @param  string  $documentType  type déclaré par l'utilisateur (cin|passeport)
     * @param  string  $langue  indice de langue (§1) : LANGUE_AUTO, 'ara+lat'
     *                          (priorité CIN tunisienne §2), 'ara', 'lat'…
     */
    public function extract(string $filePath, string $documentType, string $langue = self::LANGUE_AUTO): OcrResult;
}
