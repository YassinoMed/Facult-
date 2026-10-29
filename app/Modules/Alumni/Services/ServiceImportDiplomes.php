<?php

namespace App\Modules\Alumni\Services;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Alumni\Models\Diplome;
use App\Modules\Alumni\Models\ImportDiplomes;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import officiel des diplômes (CSV).
 *
 * Règles non négociables :
 * - aucun diplôme n'est calculé depuis les notes, crédits ou délibérations ;
 * - l'identité est résolue par CIN vers `etudiants`, jamais recopiée ;
 * - une ligne invalide est rejetée, jamais « corrigée » silencieusement ;
 * - le rapport de rejet ne contient aucune donnée personnelle ;
 * - le même fichier (même empreinte) ne crée jamais un second registre.
 */
final class ServiceImportDiplomes
{
    public const COLONNES = [
        'cin', 'numero_diplome', 'type_diplome', 'programme', 'date_delivrance', 'mention',
    ];

    /** Types MIME acceptés pour un CSV texte (vérification serveur via finfo). */
    public const MIME_ACCEPTES = [
        'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel',
        'text/x-csv', 'application/x-csv',
    ];

    /**
     * @return array{import: ImportDiplomes, rejoue: bool}
     */
    public function importer(string $contenu, string $nomFichier, User $acteur, string $source = 'csv'): array
    {
        if (! in_array($source, Diplome::SOURCES, true)) {
            throw new DomainException('Source d’import inconnue.');
        }

        if ($contenu === '') {
            throw new DomainException('Le fichier est vide.');
        }

        if (str_contains($contenu, "\0")) {
            throw new DomainException('Le fichier contient des octets binaires : un CSV texte est attendu.');
        }

        if (! mb_check_encoding($contenu, 'UTF-8')) {
            throw new DomainException('Le fichier doit être encodé en UTF-8.');
        }

        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $contenu) ?: '';

        if (! in_array($mime, self::MIME_ACCEPTES, true)) {
            throw new DomainException('Type de fichier non accepté : un CSV texte est attendu.');
        }

        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu) ?? $contenu;
        $contenu = str_replace(["\r\n", "\r"], "\n", $contenu);

        $lignes = array_values(array_filter(
            explode("\n", $contenu),
            fn (string $ligne): bool => trim($ligne) !== '',
        ));

        if ($lignes === []) {
            throw new DomainException('Le fichier ne contient aucune ligne.');
        }

        $entete = array_shift($lignes);
        $delimiteur = substr_count($entete, ';') >= substr_count($entete, ',') ? ';' : ',';
        $colonnes = array_map(
            fn (string $colonne): string => mb_strtolower(trim($colonne)),
            str_getcsv($entete, $delimiteur),
        );

        if ($colonnes !== self::COLONNES) {
            throw new DomainException(
                'En-tête CSV invalide. Colonnes attendues : '.implode(';', self::COLONNES).'.'
            );
        }

        $maximum = max(1, (int) config('modules.alumni.lignes_max', 5000));

        if (count($lignes) > $maximum) {
            throw new DomainException("Le fichier dépasse la limite de {$maximum} lignes.");
        }

        $hash = hash('sha256', $contenu);

        if ($existant = ImportDiplomes::where('fichier_sha256', $hash)->first()) {
            return ['import' => $existant, 'rejoue' => true];
        }

        try {
            return DB::transaction(function () use ($lignes, $nomFichier, $acteur, $source, $hash, $delimiteur): array {
                $import = ImportDiplomes::create([
                    // Référence provisoire courte (colonne 40) remplacée par
                    // l'identifiant lisible dès que la ligne possède son id.
                    'reference' => 'TMP-'.Str::upper(Str::random(20)),
                    'fichier_nom' => Str::limit(basename($nomFichier), 180, ''),
                    'fichier_sha256' => $hash,
                    'source' => $source,
                    'statut' => 'termine',
                    'importe_par' => $acteur->id,
                ]);

                $import->update([
                    'reference' => sprintf('IMP-%s-%06d', now()->format('Y'), $import->id),
                ]);

                $vus = [];
                $rapport = [];
                $importees = 0;

                foreach ($lignes as $index => $ligne) {
                    $numeroLigne = $index + 2;
                    $cellules = array_map(
                        fn ($cellule): string => trim((string) $cellule),
                        str_getcsv($ligne, $delimiteur),
                    );

                    $motif = $this->traiterLigne(
                        $cellules,
                        $vus,
                        $import->reference,
                        $source,
                    );

                    if ($motif !== null) {
                        // Rapport sans PII : ligne + code de motif uniquement.
                        $rapport[] = ['ligne' => $numeroLigne, 'motif' => $motif];

                        continue;
                    }

                    $importees++;
                }

                $total = count($lignes);
                $rejetees = count($rapport);

                $import->update([
                    'statut' => $importees === 0 && $rejetees > 0
                        ? 'rejete'
                        : ($rejetees > 0 ? 'partiel' : 'termine'),
                    'lignes_total' => $total,
                    'lignes_importees' => $importees,
                    'lignes_rejetees' => $rejetees,
                    'rapport' => $rapport,
                ]);

                return ['import' => $import->fresh(), 'rejoue' => false];
            }, 5);
        } catch (UniqueConstraintViolationException $e) {
            // Course entre deux imports du même fichier : la seconde requête
            // relit la trace créée par la première, sans doublon.
            $existant = ImportDiplomes::where('fichier_sha256', $hash)->first();

            if ($existant !== null) {
                return ['import' => $existant, 'rejoue' => true];
            }

            throw $e;
        }
    }

    /**
     * Traite une ligne et retourne un code de motif, ou null si importée.
     *
     * @param  array<int, string>  $cellules
     * @param  array<string, true>  $vus
     */
    private function traiterLigne(array $cellules, array &$vus, string $referenceImport, string $source): ?string
    {
        if (count($cellules) !== count(self::COLONNES)) {
            return 'nombre_colonnes';
        }

        [$cin, $numeroDiplome, $typeCode, $programmeCode, $dateStr, $mention] = $cellules;

        $etudiant = $cin !== '' ? Etudiant::where('cin', $cin)->first() : null;

        if ($etudiant === null) {
            return 'cin_inconnu';
        }

        if ($numeroDiplome === '' || mb_strlen($numeroDiplome) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $numeroDiplome)) {
            return 'numero_diplome_invalide';
        }

        if (isset($vus[$numeroDiplome])) {
            return 'numero_duplique';
        }

        $vus[$numeroDiplome] = true;

        if (Diplome::where('numero_diplome', $numeroDiplome)->exists()) {
            return 'numero_deja_importe';
        }

        $date = $this->analyserDate($dateStr);

        if ($date === null) {
            return 'date_invalide';
        }

        if ($date->isAfter(today())) {
            return 'date_future';
        }

        $type = TypeDiplome::where('code', mb_strtoupper($typeCode))->where('actif', true)->first();

        if ($type === null) {
            return 'type_inconnu';
        }

        $programme = Programme::where('code', mb_strtoupper($programmeCode))
            ->where('faculte_id', $etudiant->faculte_id)
            ->first();

        if ($programme === null) {
            // Distingue un code réellement absent d'un programme d'une autre
            // faculté : aucun rattachement inter-facultés n'est inventé.
            return Programme::where('code', mb_strtoupper($programmeCode))->exists()
                ? 'programme_hors_faculte'
                : 'programme_inconnu';
        }

        $mention = preg_replace('/[\x00-\x1F\x7F]/u', '', $mention) ?? '';

        if (mb_strlen($mention) > 40) {
            return 'mention_invalide';
        }

        Diplome::create([
            'numero_diplome' => $numeroDiplome,
            'etudiant_id' => $etudiant->id,
            'faculte_id' => $etudiant->faculte_id,
            'programme_id' => $programme->id,
            'type_diplome_id' => $type->id,
            'date_delivrance' => $date->format('Y-m-d'),
            'mention' => $mention !== '' ? $mention : null,
            'source' => $source,
            'reference_import' => $referenceImport,
            'importe_le' => now(),
        ]);

        return null;
    }

    private function analyserDate(string $valeur): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $valeur);
        } catch (\Throwable) {
            return null;
        }

        // `createFromFormat` peut déborder (2026-02-31 → 2026-03-03) :
        // la date reformatée doit être identique à l'entrée.
        return $date !== null && $date->format('Y-m-d') === $valeur ? $date : null;
    }
}
