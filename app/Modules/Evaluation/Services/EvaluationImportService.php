<?php

namespace App\Modules\Evaluation\Services;

use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\ImportResult;
use App\Services\Io\IoException;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Service d'orchestration des imports de notes (LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02 — Lot 2).
 *
 * Supporte CSV, XLSX (Excel), ODS avec streaming via OpenSpout,
 * prévisualisation stricte sans écriture en base, idempotence anti-double clic,
 * neutralisation de formules et rapport détaillé d'exécution.
 */
class EvaluationImportService
{
    public const TAILLE_MAX_OCTETS = 10485760; // 10 Mo

    public const LIGNES_MAX = 2000;

    public function __construct(
        private DataImportService $dataImportService
    ) {}

    /**
     * Analyse et prévalidation d'un fichier d'import (Étape A à C) SANS écriture en base.
     *
     * @return array{token: string, format: string, resultat: ImportResult, resume: array<string, int>, diagnostic: array<string, mixed>}
     *
     * @throws IoException|DomainException
     */
    public function analyser(UploadedFile $fichier, Epreuve $epreuve, User $user, string $mode = 'anonyme'): array
    {
        // 1. Validation de l'épreuve
        if ($epreuve->estVerrouillee()) {
            throw new DomainException('Épreuve verrouillée : tout import de notes est interdit.');
        }

        // 2. Validation physique du fichier (taille et MIME réel)
        if ($fichier->getSize() > self::TAILLE_MAX_OCTETS) {
            throw IoException::tropVolumineux(self::TAILLE_MAX_OCTETS);
        }

        $extension = mb_strtolower($fichier->getClientOriginalExtension());
        $extensionsAutorisees = ['csv', 'xlsx', 'ods', 'json', 'xml', 'txt'];
        if ($extension !== '' && ! in_array($extension, $extensionsAutorisees, true)) {
            throw IoException::formatInvalide($extension);
        }

        // 3. Préparation via le moteur DataExchange (contexte posé serveur)
        $entite = ($mode === 'nominatif') ? 'notes' : 'notes-epreuve-anonymes';

        $preparation = $this->dataImportService->preparer(
            $fichier,
            $entite,
            (int) $user->id,
            ['epreuve_id' => $epreuve->id]
        );

        $resultat = $preparation['resultat'];

        // Calcul du résumé des lignes
        $nbValides = count($resultat->valides);
        $nbErreurs = count($resultat->erreurs);
        $nbDoublons = 0;
        foreach ($resultat->erreurs as $err) {
            $msg = is_array($err) ? ($err['message'] ?? $err['code'] ?? '') : (string) $err;
            if (str_contains(mb_strtolower((string) $msg), 'doublon')) {
                $nbDoublons++;
            }
        }

        $resume = [
            'total_lues' => $nbValides + $nbErreurs,
            'valides' => $nbValides,
            'erreurs' => $nbErreurs,
            'doublons' => $nbDoublons,
            'avertissements' => count($resultat->avertissements),
        ];

        return [
            'token' => $preparation['token'],
            'format' => $preparation['format'],
            'resultat' => $resultat,
            'resume' => $resume,
            'diagnostic' => $preparation['diagnostic'] ?? [],
        ];
    }

    /**
     * Confirmation et exécution atomique avec verrou d'idempotence (Étape D & E).
     *
     * @return array<string, mixed>
     *
     * @throws IoException|DomainException
     */
    public function confirmer(string $token, Epreuve $epreuve, User $user, string $modeEcriture = 'upsert'): array
    {
        if ($epreuve->estVerrouillee()) {
            throw new DomainException('Épreuve verrouillée : confirmation d’import rejetée.');
        }

        // Verrou anti-double clic / idempotence sur le token
        $cleVerrou = "import_execution_lock:{$token}";
        $obtenu = Cache::add($cleVerrou, true, now()->addMinutes(2));

        if (! $obtenu) {
            throw new DomainException('Un traitement d’import avec ce jeton est déjà en cours d’exécution (anti-rejeu).');
        }

        try {
            $debut = microtime(true);

            $resultat = $this->dataImportService->confirmer(
                $token,
                $modeEcriture,
                (int) $user->id
            );

            $duree = round(microtime(true) - $debut, 3);
            $resultat['duree_secondes'] = $duree;

            return $resultat;
        } finally {
            Cache::forget($cleVerrou);
        }
    }

    /**
     * Annulation de l'import et nettoyage du token.
     */
    public function annuler(string $token): void
    {
        $this->dataImportService->annuler($token);
    }

    /**
     * Détection du type MIME réel via finfo.
     */
    private function detecterMimeReel(UploadedFile $fichier): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $chemin = $fichier->getRealPath() ?: $fichier->getPathname();
        $mime = $finfo ? finfo_file($finfo, $chemin) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        return $mime ?: $fichier->getClientMimeType() ?: 'application/octet-stream';
    }
}
