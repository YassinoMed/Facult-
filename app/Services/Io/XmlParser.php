<?php

namespace App\Services\Io;

use DOMDocument;
use Illuminate\Http\UploadedFile;

/**
 * Parser XML — traitement PARANOÏAQUE (§61) :
 * - refus de tout DOCTYPE / ENTITY (anti-XXE) ;
 * - LIBXML_NONET : aucune résolution réseau ;
 * - entités externes et DTD explicitement désactivées ;
 * - limites de taille et de profondeur.
 */
class XmlParser implements ParsesFiles
{
    private const PROFONDEUR_MAX = 16;

    public function format(): string
    {
        return 'xml';
    }

    public function parse(UploadedFile $fichier): array
    {
        $contenu = $fichier->getContent();

        // Défense primaire anti-XXE : refus de tout DTD/entité
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $contenu) === 1) {
            throw IoException::malforme('les DTD et entités XML sont interdites (protection XXE).');
        }

        $precedent = libxml_use_internal_errors(true);

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->preserveWhiteSpace = false;

        $charge = $document->loadXML($contenu, LIBXML_NONET | LIBXML_COMPACT);

        libxml_clear_errors();
        libxml_use_internal_errors($precedent);

        if (! $charge || $document->documentElement === null) {
            throw IoException::malforme('XML invalide.');
        }

        // Plafond anti-DoS : le refus DOCTYPE bloque Billion Laughs, mais
        // un document plat géant reste possible — on borne les enregistrements.
        $maxLignes = max(1, (int) config('integrations.import_max_rows', 10000));
        $compteur = 0;

        $lignes = [];
        foreach ($document->documentElement->childNodes as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            if (++$compteur > $maxLignes) {
                throw IoException::malforme("le fichier dépasse la limite de {$maxLignes} enregistrements.");
            }

            $ligne = [];
            foreach ($element->childNodes as $champ) {
                if (! $champ instanceof \DOMElement) {
                    continue;
                }
                if ($this->profondeur($champ) > self::PROFONDEUR_MAX) {
                    throw IoException::malforme('profondeur XML excessive.');
                }
                $ligne[$this->normaliserCle($champ->nodeName)] = trim($champ->textContent);
            }
            $lignes[] = $ligne;
        }

        if ($lignes === []) {
            throw IoException::malforme('aucun enregistrement trouvé (éléments enfants attendus).');
        }

        return $lignes;
    }

    private function profondeur(\DOMElement $element, int $niveau = 1): int
    {
        $max = $niveau;
        foreach ($element->childNodes as $enfant) {
            if ($enfant instanceof \DOMElement) {
                $max = max($max, $this->profondeur($enfant, $niveau + 1));
            }
        }

        return $max;
    }

    private function normaliserCle(string $cle): string
    {
        return NormaliseurEnTetes::cle($cle) ?: $cle;
    }
}
