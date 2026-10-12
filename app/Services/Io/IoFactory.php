<?php

namespace App\Services\Io;

use Illuminate\Http\UploadedFile;

/**
 * Factory des formats d'import/export (§36) — extension détectée côté
 * serveur, jamais depuis la déclaration du client.
 */
final class IoFactory
{
    public const FORMATS = ['csv', 'json', 'xml', 'xlsx', 'ods'];

    private const PARSERS = [
        'csv' => CsvParser::class,
        'json' => JsonParser::class,
        'xml' => XmlParser::class,
        'xlsx' => XlsxParser::class,
        'ods' => OdsParser::class,
    ];

    private const SERIALIZERS = [
        'csv' => CsvSerializer::class,
        'json' => JsonSerializer::class,
        'xml' => XmlSerializer::class,
        'xlsx' => XlsxSerializer::class,
        'pdf' => PdfSerializer::class,
    ];

    public static function parserPour(string $format): ParsesFiles
    {
        $classe = self::PARSERS[self::normaliser($format)] ?? null;

        if ($classe === null) {
            throw IoException::formatInvalide($format);
        }

        return app($classe);
    }

    public static function serializerPour(string $format): SerializesExports
    {
        $classe = self::SERIALIZERS[self::normaliser($format)] ?? null;

        if ($classe === null) {
            throw IoException::formatInvalide($format);
        }

        return app($classe);
    }

    /**
     * Détection serveur du format depuis l'extension réelle du fichier.
     */
    public static function detecterDepuisFichier(UploadedFile $fichier): string
    {
        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: '');

        if (! in_array($extension, self::FORMATS, true)) {
            throw IoException::formatInvalide($extension ?: 'inconnu');
        }

        return $extension;
    }

    public static function formatValide(string $format): bool
    {
        return in_array(self::normaliser($format), self::FORMATS, true);
    }

    private static function normaliser(string $format): string
    {
        return mb_strtolower(trim($format));
    }
}
