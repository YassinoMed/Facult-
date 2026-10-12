<?php

namespace App\Services\Io;

class JsonSerializer implements SerializesExports
{
    public function format(): string
    {
        return 'json';
    }

    public function mimeType(): string
    {
        return 'application/json; charset=UTF-8';
    }

    public function serialize(array $entetes, array $lignes): string
    {
        $propres = [];
        foreach ($lignes as $ligne) {
            $normalisee = [];
            foreach ($entetes as $entete) {
                $normalisee[$entete] = $ligne[$entete] ?? null;
            }
            $propres[] = $normalisee;
        }

        return (string) json_encode($propres, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
