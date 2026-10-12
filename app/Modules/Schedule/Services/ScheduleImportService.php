<?php

namespace App\Modules\Schedule\Services;

use App\Models\AnneeUniversitaire;
use App\Models\User;
use App\Modules\Schedule\Events\ScheduleChanged;
use App\Modules\Schedule\Import\Contracts\ScheduleImporterInterface;
use App\Modules\Schedule\Import\CsvScheduleImporter;
use App\Modules\Schedule\Import\ImportResult;
use App\Modules\Schedule\Import\JsonScheduleImporter;
use App\Modules\Schedule\Import\ScheduleImportValidator;
use App\Modules\Schedule\Import\ScheduleNormalizer;
use App\Modules\Schedule\Import\XlsxScheduleImporter;
use App\Modules\Schedule\Import\XmlScheduleImporter;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleImport;
use App\Services\Io\IoException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import semestriel (§4/§11/§17-19) : upload → parse → normalisation →
 * validation → PRÉVISUALISATION (session serveur, jamais de données
 * client) → confirmation explicite → transaction → planning + rapport.
 *
 * Modes : `creer` (nouveau planning) ou `remplacer` (confirmation
 * explicite exigée si un planning existe — jamais automatique).
 */
class ScheduleImportService
{
    /**
     * @var array<string, class-string<ScheduleImporterInterface>>
     */
    private const IMPORTERS = [
        'csv' => CsvScheduleImporter::class,
        'json' => JsonScheduleImporter::class,
        'xml' => XmlScheduleImporter::class,
        'xlsx' => XlsxScheduleImporter::class,
        // api : même pipeline (fichier re-transmis), pas de nouveau format.
    ];

    public function __construct(
        private readonly ScheduleImportValidator $validateur,
        private readonly ScheduleService $plannings,
        private readonly ScheduleNotificationService $notifications,
    ) {}

    public static function formats(): string
    {
        return implode(',', array_keys(self::IMPORTERS));
    }

    /**
     * Phase 1 : parse + normalise + valide. AUCUNE écriture métier —
     * le payload est staged en session serveur sous un token.
     *
     * @param  array{annee_universitaire_id?: ?int, semestre?: ?string, groupe_id?: ?int}  $attendus
     * @return array{token: string, format: string, resultat: ImportResult}
     */
    public function preparer(UploadedFile $fichier, array $attendus, int $auteur): array
    {
        ['format' => $format, 'normalise' => $normalise, 'resultat' => $resultat] = $this->analyser($fichier, $attendus);

        $token = Str::uuid()->toString();
        session()->put("schedule_imports.{$token}", [
            'format' => $format,
            'nom_fichier' => $fichier->getClientOriginalName(),
            'entete' => $normalise['entete'],
            'lignes' => $resultat->valides,
            'existant_id' => $resultat->existant['id'] ?? null,
            'auteur' => $auteur,
        ]);

        return ['token' => $token, 'format' => $format, 'resultat' => $resultat];
    }

    /**
     * Analyse partagée web/API (§4 : même pipeline) : format → parse →
     * normalisation → validation. Aucune écriture.
     *
     * @param  array{annee_universitaire_id?: ?int, semestre?: ?string, groupe_id?: ?int}  $attendus
     * @return array{format: string, normalise: array{entete: array<string, mixed>, lignes: array<int, array<string, mixed>>}, resultat: ImportResult}
     */
    public function analyser(UploadedFile $fichier, array $attendus): array
    {
        $format = $this->detecterFormat($fichier);
        $this->validerFichier($fichier, $format);

        $importer = app(self::IMPORTERS[$format]);
        $brut = $importer->importer($fichier);
        $normalise = ScheduleNormalizer::normaliser($brut);

        return [
            'format' => $format,
            'normalise' => $normalise,
            'resultat' => $this->validateur->valider($normalise, $attendus),
        ];
    }

    /**
     * Phase 2 : confirmation explicite → transaction unique (§18).
     * Re-validation depuis le payload serveur (anti-TOCTOU).
     *
     * @return array{rapport: ScheduleImport, planning: Schedule}
     */
    public function confirmer(string $token, string $mode, int $auteur, bool $confirmation = false): array
    {
        if (! in_array($mode, ['creer', 'remplacer'], true)) {
            throw IoException::malforme('mode d\'import inconnu.');
        }

        $payload = session()->get("schedule_imports.{$token}");

        if (! is_array($payload) || ($payload['auteur'] ?? null) !== $auteur) {
            throw IoException::malforme('prévisualisation expirée ou invalide : recommencez l\'import.');
        }

        // Périmètre faculté re-vérifié sur le groupe du fichier (§25) :
        // les sélecteurs du formulaire ne font jamais foi.
        $utilisateur = User::findOrFail($auteur);
        $groupeFichier = Group::where('code', $payload['entete']['group'] ?? '')->first();

        if ($groupeFichier !== null && ! $utilisateur->peutAccederFaculte((int) $groupeFichier->faculte_id)) {
            throw IoException::malforme('groupe hors de votre périmètre.');
        }

        $resultat = $this->validateur->valider(
            ['entete' => $payload['entete'], 'lignes' => $payload['lignes']],
            [],
            $mode === 'remplacer' ? ($payload['existant_id'] ?? null) : null,
        );

        if ($resultat->bloque()) {
            throw IoException::malforme('le fichier contient des erreurs bloquantes.');
        }

        $rapport = ScheduleImport::create([
            'imported_by' => $auteur,
            'filename' => mb_substr($payload['nom_fichier'], 0, 255),
            'format' => $payload['format'],
            'status' => 'pending',
            'total_rows' => $resultat->total(),
            'integration' => 'web',
        ]);

        try {
            [$planning, $importes] = DB::transaction(fn () => $this->importerLignes(
                $payload['entete'],
                $resultat->valides,
                $mode,
                $auteur,
                $confirmation,
                mb_strtoupper($payload['format']).' — '.$payload['nom_fichier'],
            ));

            $rapport->update([
                'schedule_id' => $planning->id,
                'status' => 'completed',
                'successful_rows' => $importes,
                'warnings_count' => 0,
            ]);

            $this->apresImport($planning->id, $mode);
        } catch (\Throwable $e) {
            $rapport->update([
                'status' => 'failed',
                'failed_rows' => $rapport->total_rows,
                'errors' => [['ligne' => '-', 'champ' => '-', 'message' => $e instanceof IoException ? $e->getMessage() : 'Erreur technique pendant l\'import.']],
            ]);

            // Échec transactionnel (rollback intégral, §18) : le rapport
            // d'erreur s'affiche au lieu d'une page 500. Les erreurs de
            // validation PRÉ-transaction (token, mode, doublons) ont déjà
            // été levées avant la création du rapport et remontent en redirect.
            return ['rapport' => $rapport->fresh(), 'planning' => null];
        } finally {
            session()->forget("schedule_imports.{$token}");
        }

        return ['rapport' => $rapport->fresh(), 'planning' => $planning];
    }

    public function annuler(string $token): void
    {
        session()->forget("schedule_imports.{$token}");
    }

    public function detecterFormatPublique(UploadedFile $fichier): string
    {
        return $this->detecterFormat($fichier);
    }

    /**
     * Post-import commun web/API : invalidation cache + event.
     */
    public function apresImport(int $planningId, string $mode): void
    {
        $this->notifications->oublierVersion($planningId);
        ScheduleChanged::dispatch($planningId, 0, $mode === 'remplacer' ? 'remplace' : 'importe');
    }

    /**
     * Cœur transactionnel partagé web/API (§18) : création ou
     * remplacement explicite, rollback intégral en cas d'erreur.
     *
     * @param  array<string, mixed>  $entete
     * @param  array<int, array<string, mixed>>  $lignesValides
     * @return array{0: Schedule, 1: int}
     */
    public function importerLignes(
        array $entete,
        array $lignesValides,
        string $mode,
        int $auteur,
        bool $confirmation,
        string $origine,
    ): array {
        $annee = AnneeUniversitaire::where('libelle', $entete['academic_year'])->firstOrFail();
        $groupe = Group::where('code', $entete['group'])->firstOrFail();

        $existant = Schedule::where('groupe_id', $groupe->id)
            ->where('annee_universitaire_id', $annee->id)
            ->where('semestre', (int) $entete['semester'])
            ->first();

        if ($existant !== null && $mode !== 'remplacer') {
            throw IoException::malforme('un planning existe déjà : choisissez le remplacement avec confirmation explicite.');
        }

        if ($existant !== null && ! $confirmation) {
            throw IoException::malforme('remplacement : confirmation explicite requise.');
        }

        if ($existant !== null) {
            $existant->slots()->delete();
            $planning = $existant;
        } else {
            $planning = Schedule::create([
                'annee_universitaire_id' => $annee->id,
                'semestre' => (int) $entete['semester'],
                'groupe_id' => $groupe->id,
                'nom' => "{$groupe->code} — S{$entete['semester']}",
                'starts_at' => $entete['starts_at'],
                'ends_at' => $entete['ends_at'],
                'actif' => true,
            ]);
        }

        // Période du fichier (si fournie) — jamais écrasée par du vide.
        if (($entete['starts_at'] ?? null) !== null || ($entete['ends_at'] ?? null) !== null) {
            $planning->update([
                'starts_at' => $entete['starts_at'] ?? $planning->starts_at,
                'ends_at' => $entete['ends_at'] ?? $planning->ends_at,
            ]);
        }

        $importes = 0;
        foreach ($lignesValides as $ligne) {
            $creation = $this->plannings->creerCreneau($planning, [
                'matiere_id' => $ligne['matiere_id'],
                'teacher_id' => $ligne['teacher_id'],
                'room_id' => $ligne['room_id'],
                'day_of_week' => $ligne['day'],
                'start_time' => $ligne['start_time'],
                'end_time' => $ligne['end_time'],
                'type' => $ligne['type'],
                'notes' => $ligne['notes'] ?? null,
            ], $auteur, 'Import '.$origine);

            if (! isset($creation['slot'])) {
                throw IoException::malforme('conflit détecté à l\'import (ligne '.$ligne['ligne'].').');
            }
            $importes++;
        }

        return [$planning, $importes];
    }

    private function detecterFormat(UploadedFile $fichier): string
    {
        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: '');

        if (! array_key_exists($extension, self::IMPORTERS)) {
            throw IoException::formatInvalide($extension !== '' ? $extension : 'inconnu');
        }

        return $extension;
    }

    /**
     * @throws IoException
     */
    private function validerFichier(UploadedFile $fichier, string $format): void
    {
        if (! $fichier->isValid()) {
            throw IoException::malforme('fichier invalide ou téléversement incomplet.');
        }

        if ($fichier->getSize() > 10 * 1024 * 1024) {
            throw IoException::tropVolumineux(10 * 1024 * 1024);
        }

        // MIME réel + contenu cohérent (jamais l'extension seule, §26).
        $mime = (string) $fichier->getMimeType();
        $contenu = $fichier->getContent();

        match ($format) {
            'csv' => $this->validerTexte($mime, $contenu),
            'json' => $this->validerJson($contenu),
            'xml' => $this->validerXml($contenu),
            'xlsx' => $this->validerXlsx($mime),
            default => throw IoException::formatInvalide($format),
        };
    }

    /**
     * @throws IoException
     */
    private function validerTexte(string $mime, string $contenu): void
    {
        if (! str_starts_with($mime, 'text/') && ! in_array($mime, ['application/csv', 'application/vnd.ms-excel'], true)) {
            throw IoException::malforme("contenu incohérent (MIME détecté : {$mime}).");
        }

        if (! mb_check_encoding($contenu, 'UTF-8')) {
            throw IoException::malforme('encodage non UTF-8.');
        }
    }

    /**
     * @throws IoException
     */
    private function validerJson(string $contenu): void
    {
        try {
            json_decode($contenu, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw IoException::malforme('JSON invalide.');
        }
    }

    /**
     * @throws IoException
     */
    private function validerXlsx(string $mime): void
    {
        // Un XLSX est une archive ZIP : finfo retourne le MIME Office ou ZIP.
        if (! in_array($mime, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ], true)) {
            throw IoException::malforme("contenu incohérent (MIME détecté : {$mime}).");
        }
    }

    /**
     * @throws IoException
     */
    private function validerXml(string $contenu): void
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $contenu) === 1) {
            throw IoException::malforme('DOCTYPE et ENTITY sont interdits (anti-XXE).');
        }

        $precedent = libxml_use_internal_errors(true);
        try {
            $ok = (new \DOMDocument)->loadXML($contenu, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($precedent);
        }

        if (! $ok) {
            throw IoException::malforme('XML malformé.');
        }
    }
}
