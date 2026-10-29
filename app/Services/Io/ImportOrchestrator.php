<?php

namespace App\Services\Io;

use App\Jobs\Io\ImportGradesJob;
use App\Jobs\Io\ImportStudentsJob;
use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Services\DisponibiliteWorkerService;
use App\Services\Io\Templates\ImportTemplateDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrateur des imports — workflow sécurisé en deux phases (§37) :
 *
 *   Fichier → Upload → Validation fichier → Détection format → Parse
 *   → Validation schéma/métier → PRÉVISUALISATION (rapport pending)
 *   → Confirmation utilisateur (mode atomique/partiel)
 *   → Transaction → Import → Rapport final → Journal d'intégration.
 *
 * Le fichier est conservé dans le disque privé entre les deux phases ;
 * la phase de confirmation RE-PARSE et RE-VALIDE le fichier (anti-TOCTOU).
 */
class ImportOrchestrator
{
    public function __construct(
        private readonly StudentsImporter $studentsImporter,
        private readonly GradesImporter $gradesImporter,
        private readonly InscriptionsAcademiquesImporter $inscriptionsImporter,
        private readonly SubjectsImporter $subjectsImporter,
        private readonly ResultsImporter $resultsImporter,
    ) {}

    /**
     * Importer dédié au type (routage unique — tout type passe par ici §90).
     */
    private function importerPour(string $type)
    {
        return match ($type) {
            'students' => $this->studentsImporter,
            'grades' => $this->gradesImporter,
            'academic_registrations' => $this->inscriptionsImporter,
            'subjects' => $this->subjectsImporter,
            'results' => $this->resultsImporter,
            'documents' => new DocumentsImporter(auth()->user()),
            default => throw IoException::malforme("type d'import « {$type} » inconnu (".implode('|', ImportTemplateDefinition::types()).').'),
        };
    }

    /**
     * Validation avec périmètre faculté de l'auteur (Phase 2) : les
     * importers compatibles reçoivent le périmètre, les autres inchangés.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    private function validerPour(string $type, array $lignes, ?int $userId)
    {
        $importer = $this->importerPour($type);

        if ($importer instanceof StudentsImporter) {
            $auteur = $userId !== null ? User::find($userId) : null;

            return $importer->valider($lignes, $auteur?->faculteRestreinteId());
        }

        return $importer->valider($lignes);
    }

    /**
     * Phase 1 : analyse + prévisualisation. AUCUNE écriture métier.
     */
    public function preparer(UploadedFile $fichier, string $type, ?int $userId, string $integration = 'api'): ImportReport
    {
        if ($fichier->getSize() > (int) config('integrations.upload_max')) {
            throw IoException::tropVolumineux((int) config('integrations.upload_max'));
        }

        $format = IoFactory::detecterDepuisFichier($fichier);

        // Cohérence MIME réel ↔ extension AVANT tout parse : l'extension
        // cliente ne suffit pas à router vers un parser (défense en
        // profondeur, même pattern que DataImportService::validerFichier).
        $mime = (string) $fichier->getMimeType();
        $attendus = [
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'json' => ['application/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml', 'text/plain'],
        ];

        if (! in_array($mime, $attendus[$format] ?? [], true)) {
            throw IoException::malforme("contenu incohérent avec l'extension « .{$format} » (MIME détecté : {$mime}).");
        }

        // Parse + validation métier (mêmes règles que les formulaires internes §38)
        $lignes = IoFactory::parserPour($format)->parse($fichier);

        // §105 : validation STRICTE des colonnes contre le schéma officiel du type
        // (TEMPLATE = SCHEMA = VALIDATION §117) — jamais d'import ambigu.
        // §104 : détection automatique du type réel par la structure.
        $entetes = $lignes !== [] ? array_keys($lignes[0]) : [];
        $entetes = array_values(array_filter($entetes, fn ($e) => ! str_starts_with((string) $e, '_')));

        $erreurType = null;
        $typeDetecte = ImportTemplateDefinition::detecterType($entetes);
        if ($typeDetecte !== null && $typeDetecte !== $type) {
            $erreurType = "La structure du fichier correspond au modèle « {$typeDetecte} » mais le type sélectionné est « {$type} ».";
        }

        $erreursStructure = ImportTemplateDefinition::validerColonnes($type, $entetes);

        $preview = ($erreurType !== null || $erreursStructure !== [])
            ? new ImportPreview([], array_merge(
                $erreurType !== null ? [['ligne' => 'structure', 'champ' => 'type', 'message' => $erreurType, 'suggestion' => 'Changez le type d\'import ou utilisez le modèle officiel.']] : [],
                $erreursStructure,
            ), [])
            : $this->validerPour($type, $lignes, $userId);

        $contenu = $fichier->getContent();

        $rapport = ImportReport::create([
            'user_id' => $userId,
            'integration' => $integration,
            'direction' => 'import',
            'type' => $type,
            'format' => $format,
            'filename' => mb_substr($fichier->getClientOriginalName(), 0, 255),
            'external_reference' => 'IMP-'.now()->format('Ymd-His'),
            'status' => $preview->valides() > 0 ? 'pending' : 'cancelled',
            'total_rows' => $preview->total(),
            'valid_rows' => $preview->valides(),
            'duplicate_rows' => $preview->doublons(),
            'error_rows' => $preview->enErreur(),
            'preview' => $preview->tableau(),
            // Rétention PII (audit §P1-7) : le fichier importé sera purgé
            // au plus tard à cette échéance (commande planifiée exports:purge).
            'expires_at' => now()->addHours((int) config('integrations.import_retention_hours', 168)),
        ]);

        // Les erreurs structurées alimentent l'export d'erreurs §109
        $rapport->update(['errors' => array_map(
            fn ($e) => ['ligne' => $e['ligne'], 'champ' => $e['champ'], 'valeur' => $e['valeur'] ?? '', 'message' => $e['message'], 'suggestion' => $e['suggestion'] ?? ''],
            $preview->tableau()['detail_erreurs'],
        )]);

        // Stockage privé du fichier pour la phase de confirmation
        $chemin = "integrations/imports/{$rapport->id}.{$format}";
        Storage::disk('local')->put($chemin, $contenu);
        $rapport->update(['file_path' => $chemin]);

        IntegrationLog::tracer(
            integration: $integration,
            direction: 'import',
            operation: "import.preview.{$type}",
            status: 'completed',
            contenu: $contenu,
            externalReference: $rapport->external_reference,
        );

        return $rapport->fresh();
    }

    /**
     * Phase 2 : confirmation — transaction, import, rapport, journal.
     * Les fichiers volumineux passent par une job (§52).
     */
    public function confirmer(ImportReport $rapport, string $mode): ImportReport
    {
        if (! in_array($mode, ['atomique', 'partiel'], true)) {
            throw IoException::malforme('mode d\'import inconnu.');
        }

        // Transition ATOMIQUE pending → processing (audit §P3-22) : la clause
        // WHERE fait foi — si deux confirmations concurrentes arrivent, une
        // seule obtient la ligne (0 ligne affectée = déjà traitée, pas de
        // double import). Fini le check-then-act en deux requêtes.
        $reserve = ImportReport::whereKey($rapport->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'processing', 'mode' => $mode]);

        if ($reserve === 0) {
            throw IoException::malforme('ce rapport n\'est plus en attente de confirmation.');
        }

        $rapport->refresh();

        $arbitre = app(DisponibiliteWorkerService::class);
        if ($arbitre->doitBasculerEnFile((int) $rapport->valid_rows, (int) config('integrations.queue_threshold', 500))) {
            match ($rapport->type) {
                'students' => ImportStudentsJob::dispatch($rapport, $mode),
                'grades' => ImportGradesJob::dispatch($rapport, $mode),
                default => throw IoException::malforme('type d\'import inconnu.'),
            };

            return $rapport->fresh();
        }

        return $this->executer($rapport, $mode);
    }

    /**
     * Exécution avec périmètre faculté de l'auteur (Phase 2).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    private function executerPour(ImportReport $rapport, array $lignes, string $mode): array
    {
        $importer = $this->importerPour($rapport->type);

        if ($importer instanceof StudentsImporter) {
            $auteur = $rapport->user_id !== null ? User::find($rapport->user_id) : null;

            return $importer->importer($lignes, $mode, $auteur?->faculteRestreinteId());
        }

        return $importer->importer($lignes, $mode);
    }

    /**
     * Exécution réelle (appelée en direct ou par la job).
     */
    public function executer(ImportReport $rapport, string $mode): ImportReport
    {
        $debut = microtime(true);
        $fichier = null;

        try {
            $contenu = (string) Storage::disk('local')->get($rapport->file_path);

            if ($contenu === '') {
                throw IoException::malforme('fichier source introuvable.');
            }

            // Re-parse du contenu stocké (le parser attend un UploadedFile : on le recrée)
            $fichier = $this->reconstruireFichier($contenu, $rapport);
            $lignes = IoFactory::parserPour($rapport->format)->parse($fichier);
            $resultat = $this->executerPour($rapport, $lignes, $mode);

            $rapport->update([
                'status' => 'completed',
                'total_rows' => $rapport->total_rows,
                'valid_rows' => $resultat['importes'],
                'duplicate_rows' => $resultat['ignores'],
                'error_rows' => $resultat['en_erreur'],
                'errors' => array_slice($resultat['erreurs'], 0, 100),
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            IntegrationLog::tracer(
                integration: $rapport->integration,
                direction: 'import',
                operation: "import.confirm.{$rapport->type}",
                status: 'completed',
                contenu: $contenu,
                externalReference: $rapport->external_reference,
            );
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                // En debug : message réel pour diagnostiquer rapidement (jamais en production)
                Log::error('Import échoué : '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            }
            $rapport->update([
                'status' => $mode === 'atomique' ? 'failed' : 'completed',
                'errors' => [$e instanceof IoException ? $e->getMessage() : 'Erreur technique pendant l\'import.'],
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            IntegrationLog::tracer(
                integration: $rapport->integration,
                direction: 'import',
                operation: "import.confirm.{$rapport->type}",
                status: 'failed',
                errorCode: 'import_failed',
                externalReference: $rapport->external_reference,
            );
        } finally {
            /*
             | §P1-10 : le fichier temporaire /tmp contient des PII — il est
             | supprimé dans TOUS les cas (succès, échec, exception de parse),
             | sans quoi il s'accumulerait indéfiniment sur l'hôte.
             */
            if ($fichier !== null) {
                @unlink($fichier->getPathname());
            }

            /*
             | §P1-7 : le fichier source n'est plus nécessaire après exécution
             | (prévisualisation et erreurs sont déjà persistées dans le
             | rapport) — suppression immédiate : minimisation de la
             | rétention des données personnelles importées.
             */
            if ($rapport->file_path) {
                Storage::disk('local')->delete($rapport->file_path);
                $rapport->update(['file_path' => null]);
            }
        }

        return $rapport->fresh();
    }

    /**
     * Reconstruit un UploadedFile depuis le contenu stocké pour re-parser.
     */
    private function reconstruireFichier(string $contenu, ImportReport $rapport): UploadedFile
    {
        /*
         | §P1-10 : tempnam() crée le fichier en mode EXCLUSIF (O_EXCL) avec
         | droits 0600 et un nom imprévisible — contrairement à uniqid() +
         | file_put_contents() qui laissent un nom devinable et suivent les
         | symlinks sur les hôtes mutualisés. L'appelant supprime ce fichier
         | (bloc finally) : aucune trace PII ne reste dans /tmp.
         */
        $chemin = (string) tempnam(sys_get_temp_dir(), 'import_');
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, 'rapport.'.$rapport->format, null, null, true);
    }
}
