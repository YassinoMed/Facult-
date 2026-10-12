<?php

namespace App\Services\Io;

use DOMDocument;
use DOMElement;

/**
 * Sérialiseur XML — échappement natif DOM (aucune concaténation de chaînes),
 * sans DTD, sans entité : aucun vecteur d'injection côté consommateur.
 */
class XmlSerializer implements SerializesExports
{
    public function format(): string
    {
        return 'xml';
    }

    public function mimeType(): string
    {
        return 'application/xml; charset=UTF-8';
    }

    public function serialize(array $entetes, array $lignes): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $racine = $document->createElement('exports');
        $document->appendChild($racine);

        foreach ($lignes as $ligne) {
            /** @var DOMElement $element */
            $element = $racine->appendChild($document->createElement('enregistrement'));

            foreach ($entetes as $entete) {
                $champ = $document->createElement(
                    preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-', '.'], '_', mb_strtolower($entete))) ?: 'champ',
                    (string) ($ligne[$entete] ?? ''),
                );
                $element->appendChild($champ);
            }
        }

        return (string) $document->saveXML();
    }
}
