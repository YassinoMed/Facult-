<?php

namespace App\Services\Io\Templates;

use App\Models\ImportReport;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Service de génération des modèles d'importation (§91-112).
 *
 * - Modèles VIERGES de données métier (§93) ou avec ligne d'exemple
 *   100 % FICTIVE (§94) ;
 * - XLSX multi-feuilles : IMPORT + INSTRUCTIONS + RÉFÉRENCES
 *   dynamiques depuis la base (§95, §96, §98) ;
 * - CSV protégé contre la formula injection (§110) ;
 * - versionnage dans le nom de fichier + métadonnées (§99, §100) ;
 * - export du rapport d'erreurs d'import (§109).
 *
 * Les modèles sont générés côté serveur — jamais téléchargés depuis
 * une URL externe (§110).
 */
class ImportTemplateService
{
    /**
     * Génère le fichier du modèle.
     *
     * @return array{contenu: string, nom: string, mime: string}
     */
    public function generer(string $type, string $format, bool $avecExemple = true): array
    {
        $definition = ImportTemplateDefinition::definition($type);
        abort_if($definition === null, 404, "Type de modèle inconnu : {$type}.");

        $version = ImportTemplateDefinition::VERSIONS[$type];
        $nom = sprintf('%s_import_template_v%d.%s', $type, $version, $format);

        $contenu = match ($format) {
            'csv' => $this->csv($definition, $avecExemple),
            'json' => $this->json($type, $version, $definition, $avecExemple),
            'xlsx' => $this->xlsx($type, $version, $definition, $avecExemple),
            default => throw IoExceptionDefinition::formatInvalide($format),
        };

        return [
            'contenu' => $contenu,
            'nom' => $nom,
            'mime' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : ($format === 'json' ? 'application/json; charset=UTF-8' : 'text/csv; charset=UTF-8'),
        ];
    }

    /* ── CSV (§93, §110 : neutralisation des formules) ── */

    private function csv(array $definition, bool $avecExemple): string
    {
        $sortie = fopen('php://temp', 'r+');
        fwrite($sortie, "\xEF\xBB\xBF");

        $colonnes = array_keys($definition['colonnes']);
        fputcsv($sortie, $colonnes, ';');

        if ($avecExemple) {
            $exemple = array_map(fn ($c) => $c['exemple'], $definition['colonnes']);
            fputcsv($sortie, array_map(fn ($v) => $this->neutraliser((string) $v), $exemple), ';');
        }

        rewind($sortie);
        $contenu = (string) stream_get_contents($sortie);
        fclose($sortie);

        return $contenu;
    }

    /* ── JSON (§100 : métadonnées non sensibles) ── */

    private function json(string $type, int $version, array $definition, bool $avecExemple): string
    {
        $contenu = [
            '_template' => [
                'template_type' => $type,
                'template_version' => $version,
                'generated_at' => now()->toIso8601String(),
                'note' => 'Supprimez la clé _template avant l\'import, ou laissez-la : elle est ignorée.',
            ],
        ];

        if ($avecExemple) {
            $contenu['_exemple'] = array_combine(
                array_keys($definition['colonnes']),
                array_map(fn ($c) => $c['exemple'], $definition['colonnes']),
            );
        }

        return (string) json_encode([$contenu], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* ── XLSX multi-feuilles (§95, §96, §111) ── */

    private function xlsx(string $type, int $version, array $definition, bool $avecExemple): string
    {
        $options = new Options;
        $writer = new Writer($options);

        $fichier = tempnam(sys_get_temp_dir(), 'template_').'.xlsx';
        $writer->openToFile($fichier);

        // Feuille 1 — IMPORT (§93 : en-têtes + éventuelle ligne d'exemple fictive)
        $writer->getCurrentSheet()->setName('IMPORT');
        $colonnes = array_keys($definition['colonnes']);
        $writer->addRow(Row::fromValues(array_map(fn ($c) => mb_strtoupper($c), $colonnes)));

        if ($avecExemple) {
            $writer->addRow(Row::fromValues(array_map(
                fn ($c) => $this->neutraliser($c['exemple']),
                $definition['colonnes'],
            )));
            $writer->addRow(Row::fromValues(array_fill(0, count($colonnes), '')));
        }

        // Feuille 2 — INSTRUCTIONS (§95)
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('INSTRUCTIONS');

        $writer->addRow(Row::fromValues(["MODÈLE D'IMPORT : ".mb_strtoupper($definition['label'])]));
        $writer->addRow(Row::fromValues(["template_type = {$type}", "template_version = {$version}", 'generated_at = '.now()->toIso8601String()]));
        $writer->addRow(Row::fromValues([$definition['description']]));
        $writer->addRow(Row::fromValues(['Remplissez UNIQUEMENT la feuille IMPORT, puis importez le fichier via Intégrations → Import.']));
        $writer->addRow(Row::fromValues(['Les données de la ligne d\'exemple sont FICTIVES — remplacez-les ou supprimez-les.']));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Colonne', 'Obligatoire', 'Format', 'Exemple', 'Description']));
        foreach ($definition['colonnes'] as $cle => $meta) {
            $writer->addRow(Row::fromValues([
                $cle,
                $meta['obligatoire'] ? 'Oui' : 'Facultatif',
                $meta['format'],
                $this->neutraliser($meta['exemple']),
                $meta['description'],
            ]));
        }

        // Feuille 3 — RÉFÉRENCES (§96, §98 : valeurs dynamiques de la base)
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('REFERENCES');

        $dynamiques = ImportTemplateDefinition::referencesDynamiques();
        $ecrites = [];
        foreach ($definition['colonnes'] as $cle => $meta) {
            $valeurs = is_string($meta['references'] ?? null)
                ? ($dynamiques[$meta['references']] ?? [])
                : ($meta['references'] ?? []);

            if ($valeurs === [] || isset($ecrites[$cle])) {
                continue;
            }
            $ecrites[$cle] = true;

            $writer->addRow(Row::fromValues([mb_strtoupper($cle).' — valeurs autorisées :']));
            foreach ($valeurs as $valeur) {
                $writer->addRow(Row::fromValues([$this->neutraliser((string) $valeur)]));
            }
            $writer->addRow(Row::fromValues(['']));
        }

        $writer->close();

        $contenu = (string) file_get_contents($fichier);
        @unlink($fichier);

        return $contenu;
    }

    /* ── §109 : export du rapport d'erreurs d'import ── */

    /**
     * @return array{contenu: string, nom: string, mime: string}
     */
    public function exporterErreurs(ImportReport $rapport, string $format = 'xlsx'): array
    {
        $entetes = ['numero_ligne', 'champ', 'valeur', 'erreur', 'suggestion'];

        $lignes = [];
        foreach ((array) ($rapport->errors ?? []) as $erreur) {
            $lignes[] = [
                'numero_ligne' => $erreur['ligne'] ?? '',
                'champ' => $erreur['champ'] ?? '',
                'valeur' => $erreur['valeur'] ?? '',
                'erreur' => $erreur['message'] ?? (is_string($erreur) ? $erreur : ''),
                'suggestion' => $erreur['suggestion'] ?? '',
            ];
        }

        if ($format === 'csv') {
            $sortie = fopen('php://temp', 'r+');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, $entetes, ';');
            foreach ($lignes as $ligne) {
                fputcsv($sortie, array_map(fn ($v) => $this->neutraliser((string) $v), array_values($ligne)), ';');
            }
            rewind($sortie);
            $contenu = (string) stream_get_contents($sortie);
            fclose($sortie);
        } else {
            $contenu = $this->construireXlsxSimple($entetes, $lignes);
        }

        return [
            'contenu' => $contenu,
            'nom' => sprintf('import_errors_%s_%05d.%s', $rapport->created_at?->format('Ymd') ?? now()->format('Ymd'), $rapport->id, $format),
            'mime' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    private function construireXlsxSimple(array $entetes, array $lignes): string
    {
        $options = new Options;
        $writer = new Writer($options);
        $fichier = tempnam(sys_get_temp_dir(), 'erreurs_').'.xlsx';
        $writer->openToFile($fichier);

        $writer->addRow(Row::fromValues($entetes));
        foreach ($lignes as $ligne) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => $this->neutraliser((string) $v), array_values($ligne))));
        }
        $writer->close();

        $contenu = (string) file_get_contents($fichier);
        @unlink($fichier);

        return $contenu;
    }

    /**
     * §110 : neutralisation formula injection (les exemples commencent parfois par =).
     */
    private function neutraliser(string $valeur): string
    {
        if ($valeur !== '' && in_array($valeur[0], ['=', '+', '-', '@'], true)) {
            return "'".$valeur;
        }

        return $valeur;
    }
}

/**
 * Exception locale minime (évite d'importer IoException pour deux lignes).
 */
class IoExceptionDefinition extends \Exception
{
    public static function formatInvalide(string $format): self
    {
        return new self("Format de modèle « {$format} » non supporté.");
    }
}
