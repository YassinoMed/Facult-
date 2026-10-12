<?php

namespace App\Services\DataExchange;

use App\Jobs\ExecuteDataImportJob;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Services\DataExchange\Contracts\ImportContextuel;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportSansPii;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Services\Io\IoFactory;
use App\Services\Io\NormaliseurEnTetes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Moteur d'import unifié (§2/§17) : parse (IoFactory) → normalise →
 * valide → doublons → preview (session serveur) → confirmation →
 * transaction → rapport ImportReport + IntegrationLog (request_id).
 *
 * Plafond : 5000 lignes (au-delà : découper le fichier — documenté).
 */
class DataImportService
{
    public const LIGNES_MAX = 5000;

    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly ?ImportDependencyService $dependencies = null,
        private readonly ?CorrecteurImportService $correcteur = null,
        private readonly ?DetecteurDoublonsService $doublons = null,
    ) {}

    /**
     * @return array<string, class-string>
     */
    public static function formats(): array
    {
        return ['csv' => 1, 'xlsx' => 1, 'ods' => 1, 'json' => 1, 'xml' => 1];
    }

    /**
     * Phase 1 : analyse sans écriture. Retourne token de preview.
     *
     * @param  array<string, mixed>  $contexte  Contexte serveur (ex. épreuve),
     *                                          jamais issu du fichier : posé par l'appelant depuis la route.
     * @return array{token: string, format: string, resultat: ImportResult, diagnostic: array<string, mixed>}
     */
    public function preparer(UploadedFile $fichier, string $entite, int $auteurId, array $contexte = []): array
    {
        ['format' => $format, 'resultat' => $resultat, 'lignes' => $lignes] = $this->analyser($fichier, $entite, $auteurId, $contexte);

        // Idempotence fichier : même contenu déjà importé avec succès → avertissement.
        $hash = hash_file('sha256', $fichier->getRealPath());

        if ($hash !== false && ImportReport::where('type', 'data.'.$entite)->where('file_hash', $hash)->where('status', 'completed')->exists()) {
            $resultat = new ImportResult(
                $resultat->valides,
                $resultat->erreurs,
                [...$resultat->avertissements, 'Fichier déjà importé avec succès (IMPORT_ALREADY_PROCESSED) : la confirmation appliquera le mode choisi sans créer de doublons.'],
                $resultat->apercu,
                $resultat->corrections,
                $resultat->aRevoir,
            );
        }

        $token = Str::uuid()->toString();
        session()->put("data_imports.{$token}", [
            'entite' => $entite,
            'format' => $format,
            'nom_fichier' => $fichier->getClientOriginalName(),
            'file_hash' => $hash === false ? null : $hash,
            'lignes' => $lignes,
            'auteur' => $auteurId,
            'contexte' => $contexte,
            // PHASE 6 — points à revoir (ligne:champ) : si une revue est
            // entamée, la confirmation exige qu'ils soient tous décidés.
            'a_signaler' => array_values(array_unique(array_map(
                fn ($r) => $r['ligne'].':'.$r['champ'],
                $resultat->aRevoir
            ))),
        ]);

        return ['token' => $token, 'format' => $format, 'resultat' => $resultat, 'diagnostic' => $this->diagnostic($fichier, $entite, $auteurId)];
    }

    /**
     * PHASE 8 — prédiagnostic pour le cockpit (mapping, inconnues,
     * volume, empreinte) : lecture seule, jamais bloquant pour le flux
     * (un refus ici n'empêche pas la prévisualisation déjà calculée).
     *
     * @return array<string, mixed>
     */
    private function diagnostic(UploadedFile $fichier, string $entite, int $auteurId): array
    {
        try {
            return app(ImportPrediagnosticService::class)->diagnostiquer($fichier, $entite, $auteurId);
        } catch (\Throwable) {
            return ['refus' => null, 'mapping' => [], 'inconnues' => [], 'blocages' => [], 'pret' => false];
        }
    }

    /**
     * Analyse stateless partagée web/API (§32 dry-run) : aucune écriture,
     * aucune session. Retourne les lignes valides prêtes à persister.
     *
     * @param  array<string, mixed>  $contexte  Voir preparer().
     * @return array{format: string, resultat: ImportResult, lignes: array<int, array<string, mixed>>}
     */
    public function analyser(UploadedFile $fichier, string $entite, ?int $auteurId = null, array $contexte = []): array
    {
        $deps = $this->dependencies ?? app(ImportDependencyService::class);
        $manquants = $deps->verifierPrerequis($entite, $auteurId);

        $definition = $this->definitions->import($entite);

        if ($definition instanceof ImportContextuel && $contexte !== []) {
            $definition->definirContexte($contexte);
        }

        $format = $this->detecterFormat($fichier);
        $this->validerFichier($fichier, $format);

        $brut = $this->parser($fichier, $format);
        ['lignes' => $lignes, 'erreurs' => $erreursStructure] = $this->normaliser($brut, $definition);

        // PHASE 3 — corrections automatiques tracées AVANT validation
        // (déterministes, champs sensibles exclus, ambigu abstenu).
        $correcteur = $this->correcteur ?? app(CorrecteurImportService::class);
        [
            'lignes' => $lignes,
            'corrections' => $corrections,
            'a_revoir' => $aRevoir,
        ] = $correcteur->corriger($lignes, $definition);

        $valides = [];
        $erreurs = $erreursStructure;
        $avertissements = [];
        foreach ($manquants as $manquant) {
            $avertissements[] = "Dépendance préalable : {$manquant}";
        }
        $vus = [];
        $vusMetier = [];

        foreach ($lignes as $ligne) {
            $validation = Validator::make($ligne, $definition->regles());

            if ($validation->fails()) {
                foreach ($validation->errors()->toArray() as $champ => $messages) {
                    foreach ($messages as $message) {
                        $erreurs[] = [
                            'ligne' => $ligne['_ligne'],
                            'champ' => $champ,
                            'message' => $message,
                            'code' => 'INVALID',
                        ];
                    }
                }

                continue;
            }

            $valeursCle = array_filter(array_map(fn ($c) => mb_strtolower(trim((string) ($ligne[$c] ?? ''))), $definition->cleDoublon()));

            if (! empty($valeursCle)) {
                $cle = implode('|', array_map(fn ($c) => mb_strtolower(trim((string) ($ligne[$c] ?? ''))), $definition->cleDoublon()));

                if (isset($vus[$cle])) {
                    $erreurs[] = [
                        'ligne' => $ligne['ligne'],
                        'champ' => 'doublon',
                        'message' => "Doublon détecté ligne {$ligne['ligne']} (déjà présent ligne {$vus[$cle]}).",
                        'code' => 'DUPLICATE_FILE',
                    ];

                    continue;
                }
                $vus[$cle] = $ligne['ligne'];
            }

            // Validation métier avancée (résolution, cohérence, verrous) :
            // erreurs structurées par ligne, jamais d'exception globale.
            $erreursMetier = $definition instanceof ImportValidationAvancee
                ? $definition->validerLigneMetier($ligne, $auteurId)
                : [];

            foreach ($erreursMetier as $erreur) {
                if (is_string($erreur)) {
                    $erreurs[] = [
                        'ligne' => $ligne['ligne'],
                        'champ' => 'valeur',
                        'message' => $erreur,
                        'code' => 'INVALID',
                    ];
                } else {
                    $erreurs[] = [
                        'ligne' => $ligne['ligne'],
                        'champ' => $erreur['champ'] ?? 'valeur',
                        'message' => $erreur['message'] ?? (string) $erreur,
                        'code' => $erreur['code'] ?? 'INVALID',
                    ];
                }
            }

            if ($erreursMetier !== []) {
                continue;
            }

            // Doublon sur clé métier résolue (même étudiant via deux
            // identifiants différents dans le même fichier).
            if ($definition instanceof ImportValidationAvancee
                && ($cleMetier = $definition->cleMetier($ligne)) !== null) {
                if (isset($vusMetier[$cleMetier])) {
                    $erreurs[] = [
                        'ligne' => $ligne['ligne'],
                        'champ' => 'doublon',
                        'message' => "Doublon détecté ligne {$ligne['ligne']} (même étudiant/matière/session que la ligne {$vusMetier[$cleMetier]}).",
                        'code' => 'DUPLICATE_FILE',
                    ];

                    continue;
                }

                $vusMetier[$cleMetier] = $ligne['ligne'];
            }

            // PHASE 5 — classification graduée (l'existant déjà résolu
            // est réutilisé : zéro requête ajoutée dans les cas exacts).
            $detecteur = $this->doublons ?? app(DetecteurDoublonsService::class);
            $classement = $detecteur->classifier($ligne, $definition, $definition->trouverExistant($ligne));

            if ($classement['statut'] === DetecteurDoublonsService::MISE_A_JOUR) {
                $avertissements[] = "Ligne {$ligne['ligne']} : existe déjà — sera mise à jour (mode upsert).";
            } elseif ($classement['statut'] === DetecteurDoublonsService::CONFLIT) {
                $candidat = $classement['candidat'];
                $avertissements[] = "Ligne {$ligne['ligne']} : la clé existe déjà (fiche #{$candidat['id']})"
                    .' mais l\'identité diverge ('.implode(', ', $classement['divergences'])
                    .') — vérification requise (CONFLIT_IDENTITE).';
                $aRevoir[] = [
                    'ligne' => $ligne['ligne'], 'champ' => 'identite',
                    'valeur' => trim((string) ($ligne['nom'] ?? '')).' '.trim((string) ($ligne['prenom'] ?? '')),
                    'regle' => 'DOUBLONS@'.DetecteurDoublonsService::VERSION,
                    'raison' => 'Clé existante, identité divergente ('.implode(', ', $classement['divergences']).').',
                ];
            } elseif ($classement['statut'] === DetecteurDoublonsService::POTENTIEL) {
                $candidat = $classement['candidat'];
                $avertissements[] = "Ligne {$ligne['ligne']} : ressemble à la fiche existante « {$candidat['nom']} »"
                    ." (#{$candidat['id']}) — à vérifier avant import (DOUBLON_POTENTIEL).";
                $aRevoir[] = [
                    'ligne' => $ligne['ligne'], 'champ' => 'identite',
                    'valeur' => trim((string) ($ligne['nom'] ?? '')).' '.trim((string) ($ligne['prenom'] ?? '')),
                    'regle' => 'DOUBLONS@'.DetecteurDoublonsService::VERSION,
                    'raison' => "Ressemble à la fiche #{$candidat['id']} ({$candidat['nom']}).",
                ];
            } elseif ($classement['statut'] === DetecteurDoublonsService::NON_RESOLU) {
                $avertissements[] = "Ligne {$ligne['ligne']} : aucun identifiant fiable (matricule/CIN/e-mail)"
                    .' — vérification requise (IDENTITE_NON_RESOLUE).';
                $aRevoir[] = [
                    'ligne' => $ligne['ligne'], 'champ' => 'identite',
                    'valeur' => trim((string) ($ligne['nom'] ?? '')).' '.trim((string) ($ligne['prenom'] ?? '')),
                    'regle' => 'DOUBLONS@'.DetecteurDoublonsService::VERSION,
                    'raison' => 'Aucun identifiant fiable (matricule/CIN/e-mail).',
                ];
            }

            unset($ligne['_ligne']);
            $valides[] = $ligne;
        }

        $resultat = new ImportResult($valides, $erreurs, $avertissements, array_slice($valides, 0, 10), $corrections, $aRevoir);

        return ['format' => $format, 'resultat' => $resultat, 'lignes' => $valides];
    }

    /**
     * Phase 2 : confirmation → transaction unique (§17).
     *
     * @return array{rapport: ImportReport}
     */
    public function confirmer(string $token, string $mode, int $auteurId, string $integration = 'web'): array
    {
        if (! in_array($mode, ['creer', 'upsert', 'remplacer'], true)) {
            throw IoException::malforme('mode d\'import inconnu.');
        }

        $payload = session()->get("data_imports.{$token}");

        if (! is_array($payload) || ($payload['auteur'] ?? null) !== $auteurId) {
            throw IoException::malforme('prévisualisation expirée ou invalide : recommencez l\'import.');
        }

        $deps = $this->dependencies ?? app(ImportDependencyService::class);
        $deps->bloqueSiDependancesManquantes($payload['entite'], $auteurId);

        $definition = $this->definitions->import($payload['entite']);

        // Contexte serveur rejoué (jamais client) : la confirmation
        // revalide dans le même périmètre que la preview.
        if ($definition instanceof ImportContextuel) {
            $definition->definirContexte(is_array($payload['contexte'] ?? null) ? $payload['contexte'] : []);
        }

        if ($mode === 'remplacer' && ! in_array('remplacer', $definition->modes(), true)) {
            throw IoException::malforme('ce module n\'autorise pas le remplacement.');
        }

        // PHASE 6 — revue entamée = revue complète exigée : chaque ligne
        // signalée (correction ambiguë, conflit, doublon potentiel, sans
        // identifiant) doit être décidée ou retirée. Sans revue, le
        // comportement historique est conservé (compatibilité API).
        if (isset($payload['revue']) && is_array($payload['revue'])) {
            $decidees = [];

            foreach ($payload['revue']['decisions'] ?? [] as $decision) {
                $decidees[(int) ($decision['ligne'] ?? 0)] = true;
            }

            $nonDecidees = [];

            foreach ($payload['a_signaler'] ?? [] as $cle) {
                $numero = (int) explode(':', (string) $cle, 2)[0];

                if (! isset($decidees[$numero])) {
                    $nonDecidees[] = $numero;
                }
            }

            if ($nonDecidees !== []) {
                throw IoException::malforme(
                    'revue incomplète : lignes '.implode(', ', array_unique($nonDecidees)).' à décider avant confirmation.'
                );
            }
        }

        // Re-validation anti-TOCTOU depuis le payload serveur.
        $recontrole = $this->revalider($definition, $payload['lignes'], $auteurId);

        if ($recontrole->bloque()) {
            throw IoException::malforme('le fichier contient des erreurs bloquantes.');
        }

        // LOOP 10 §10.4 : consommation atomique du token AVANT écriture —
        // une seconde confirmation (double-clic, retry, worker rejoué)
        // trouve un token expiré au lieu de réimporter (le `finally`
        // reste en filet). La revalidation ci-dessus a déjà tranché :
        // ce point est le seul engagement d'écriture.
        session()->forget("data_imports.{$token}");

        // C2 (LOT 3) : gros volume → file d'attente (staging Phase 17
        // sur tables, jamais de requête HTTP de plusieurs minutes ;
        // notification au responsable en fin de traitement).
        if (count($payload['lignes']) > (int) config('integrations.queue_threshold', 500)) {
            $job = $this->mettreEnFile($payload, $mode, $auteurId, $integration);

            session()->forget("data_imports.{$token}");

            return ['queued' => true, 'job' => $job];
        }

        try {
            ['rapport' => $rapport] = $this->executer(
                $definition, $payload['entite'], $payload['lignes'],
                $payload['nom_fichier'], $payload['format'],
                $mode, $auteurId, $integration, $payload['file_hash'] ?? null,
            );

            // Traçabilité PHASE 4 : décisions de revue (ligne + action,
            // sans les valeurs) conservées dans le rapport.
            if (isset($payload['revue']) && is_array($payload['revue'])) {
                $rapport->update(['preview' => array_merge(
                    is_array($rapport->preview) ? $rapport->preview : [],
                    ['revue' => $payload['revue']],
                )]);
            }
        } finally {
            session()->forget("data_imports.{$token}");
        }

        return ['rapport' => $rapport];
    }

    /**
     * C2 : staging persisté (Phase 17, jamais de session géante) +
     * dispatch. Lignes déjà re-validées → `valide` ; le job rejoue
     * `executer` (qui garde la transaction + le rapport).
     *
     * @param  array{entite: string, format: string, nom_fichier: string, file_hash: ?string, lignes: array<int, array<string, mixed>>}  $payload
     */
    private function mettreEnFile(array $payload, string $mode, int $auteurId, string $integration): ImportJob
    {
        return DB::transaction(function () use ($payload, $mode, $auteurId, $integration) {
            $job = ImportJob::create([
                'entite' => $payload['entite'], 'nom_fichier' => $payload['nom_fichier'],
                'hash_fichier' => $payload['file_hash'], 'format' => $payload['format'],
                'mode' => $mode, 'statut' => 'en_file',
                'total_lignes' => count($payload['lignes']),
                'auteur_id' => $auteurId,
                'options' => [
                    'integration' => $integration,
                    'contexte' => is_array($payload['contexte'] ?? null) ? $payload['contexte'] : [],
                    'revue' => is_array($payload['revue'] ?? null) ? $payload['revue'] : null,
                ],
            ]);

            $numero = 0;

            foreach (array_chunk($payload['lignes'], 500) as $lot) {
                $rangees = [];

                foreach ($lot as $ligne) {
                    $rangees[] = [
                        'import_job_id' => $job->id,
                        'numero_ligne' => ++$numero,
                        'donnees_brutes' => json_encode($ligne),
                        'donnees_normalisees' => json_encode($ligne),
                        'statut' => 'valide',
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }

                ImportJobRow::insert($rangees);
            }

            ExecuteDataImportJob::dispatch($job->id);

            return $job;
        });
    }

    /**
     * PHASE 6 — collision d'une ligne modifiée avec le reste du lot :
     * même clé de doublon (insensible casse) ou même clé métier résolue.
     * La ligne elle-même est exclue. Retourne le numéro en conflit ou null.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    private function collisionIntraLot(array $ligne, array $lignes, int $index, ImportDefinitionInterface $definition): ?int
    {
        $cle = implode('|', array_map(
            fn ($c) => mb_strtolower(trim((string) ($ligne[$c] ?? ''))),
            $definition->cleDoublon()
        ));

        $cleMetier = $definition instanceof ImportValidationAvancee
            ? $definition->cleMetier($ligne)
            : null;

        foreach ($lignes as $i => $autre) {
            if ($i === $index) {
                continue;
            }

            $autreCle = implode('|', array_map(
                fn ($c) => mb_strtolower(trim((string) ($autre[$c] ?? ''))),
                $definition->cleDoublon()
            ));

            if ($cle !== '' && $cle === $autreCle) {
                return (int) ($autre['ligne'] ?? '?');
            }

            if ($cleMetier !== null
                && $definition instanceof ImportValidationAvancee
                && $cleMetier === $definition->cleMetier($autre)) {
                return (int) ($autre['ligne'] ?? '?');
            }
        }

        return null;
    }

    /**
     * Re-validation partagée web/API (règles métier, sans doublons
     * déjà tranchés en preview).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    public function revalider(ImportDefinitionInterface $definition, array $lignes, ?int $auteurId = null): ImportResult
    {
        $valides = [];
        $erreurs = [];

        foreach ($lignes as $ligne) {
            $validation = Validator::make($ligne, $definition->regles());

            if ($validation->fails()) {
                foreach ($validation->errors()->toArray() as $champ => $messages) {
                    foreach ($messages as $message) {
                        $erreurs[] = [
                            'ligne' => $ligne['ligne'] ?? '?',
                            'champ' => $champ,
                            'message' => $message,
                            'code' => 'INVALID',
                        ];
                    }
                }

                continue;
            }

            // Re-contrôle métier anti-TOCTOU (résolution, verrous, scope).
            if ($definition instanceof ImportValidationAvancee) {
                foreach ($definition->validerLigneMetier($ligne, $auteurId) as $erreur) {
                    if (is_string($erreur)) {
                        $erreurs[] = [
                            'ligne' => $ligne['ligne'] ?? '?',
                            'champ' => 'valeur',
                            'message' => $erreur,
                            'code' => 'INVALID',
                        ];
                    } else {
                        $erreurs[] = [
                            'ligne' => $ligne['ligne'] ?? '?',
                            'champ' => $erreur['champ'] ?? 'valeur',
                            'message' => $erreur['message'] ?? (string) $erreur,
                            'code' => $erreur['code'] ?? 'INVALID',
                        ];
                    }
                }
            }

            $valides[] = $ligne;
        }

        return new ImportResult($valides, $erreurs);
    }

    /**
     * Exécution transactionnelle partagée web/API (§17-18).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    public function executer(
        ImportDefinitionInterface $definition,
        string $entite,
        array $lignes,
        string $nomFichier,
        string $format,
        string $mode,
        int $auteurId,
        string $integration,
        ?string $fileHash = null,
    ): array {
        if ($lignes === []) {
            throw IoException::malforme('aucune ligne valide à importer.');
        }

        $rapport = ImportReport::create([
            'user_id' => $auteurId,
            'integration' => $integration,
            'direction' => 'import',
            'type' => 'data.'.$entite,
            'format' => $format,
            'filename' => mb_substr($nomFichier, 0, 255),
            'file_hash' => $fileHash,
            'external_reference' => 'DX-'.now()->format('Ymd-His'),
            'status' => 'pending',
            'mode' => 'atomique',
            'total_rows' => count($lignes),
        ]);

        $debut = microtime(true);
        $requestId = (string) Str::uuid();

        try {
            $importes = DB::transaction(function () use ($definition, $lignes, $mode, $auteurId) {
                if ($mode === 'remplacer') {
                    $definition->vider($auteurId);
                }

                $importes = 0;
                foreach (array_chunk($lignes, 500) as $paquet) {
                    foreach ($paquet as $ligne) {
                        $definition->persister($ligne, $mode, $auteurId);
                        $importes++;
                    }
                }

                return $importes;
            });

            $rapport->update([
                'status' => 'completed',
                'valid_rows' => $importes,
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            IntegrationLog::tracer('data-exchange', 'import', "data.import.{$entite}", 'completed', null, 200, (int) ((microtime(true) - $debut) * 1000), null, $requestId);
        } catch (\Throwable $e) {
            $rapport->update([
                'status' => 'failed',
                'errors' => [['ligne' => '-', 'champ' => '-', 'message' => $e instanceof IoException ? $e->getMessage() : 'Erreur technique pendant l\'import.']],
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            IntegrationLog::tracer('data-exchange', 'import', "data.import.{$entite}", 'failed', null, null, (int) ((microtime(true) - $debut) * 1000), 'import_failed', $requestId);

            throw $e;
        }

        return ['rapport' => $rapport->fresh(), 'importes' => $importes ?? 0];
    }

    public function annuler(string $token): void
    {
        session()->forget("data_imports.{$token}");
    }

    /**
     * PHASE 4 — Revue semi-automatique d'un lot en prévisualisation.
     * Chaque décision est validée INDIVIDUELLEMENT (aucun contournement
     * par lot) ; les lignes rejetées/en attente sont retirées du jeu à
     * confirmer ; les modifications sont re-validées (règles + métier).
     * Décisions tracées (ligne + action, sans les valeurs) pour le rapport.
     *
     * @param  array<int, array{ligne: int, action: string, valeurs?: array<string, mixed>}>  $decisions
     * @return array{restantes: int, acceptees: int, modifiees: int, rejetees: int, en_attente: int}
     */
    public function revoir(string $token, string $entite, array $decisions, int $auteurId): array
    {
        $payload = session()->get("data_imports.{$token}");

        if (! is_array($payload) || ($payload['auteur'] ?? null) !== $auteurId) {
            throw IoException::malforme('prévisualisation expirée ou invalide : recommencez l\'import.');
        }

        // PHASE 9 — le token est lié à son entité (jamais de revue
        // croisée via une autre route d'entité).
        if (($payload['entite'] ?? null) !== $entite) {
            throw IoException::malforme('prévisualisation invalide pour cette entité.');
        }

        $definition = $this->definitions->import($payload['entite']);
        $colonnes = $definition->colonnes();

        $vus = [];
        $resume = ['restantes' => 0, 'acceptees' => 0, 'modifiees' => 0, 'rejetees' => 0, 'en_attente' => 0];
        $trace = [];
        $retirer = [];

        foreach ($decisions as $decision) {
            $numero = (int) ($decision['ligne'] ?? 0);
            $action = (string) ($decision['action'] ?? '');

            if (! in_array($action, ['accepter', 'modifier', 'rejeter', 'attente'], true)) {
                throw IoException::malforme("action de revue inconnue (ligne {$numero}).");
            }

            if (isset($vus[$numero])) {
                throw IoException::malforme("décision en double pour la ligne {$numero}.");
            }

            $vus[$numero] = true;
            $index = null;

            foreach ($payload['lignes'] as $i => $ligne) {
                if ((int) ($ligne['ligne'] ?? 0) === $numero) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                throw IoException::malforme("ligne {$numero} absente de la prévisualisation.");
            }

            if ($action === 'rejeter' || $action === 'attente') {
                $retirer[] = $index;
                $resume[$action === 'rejeter' ? 'rejetees' : 'en_attente']++;
                $trace[] = ['ligne' => $numero, 'action' => $action, 'par' => $auteurId];

                continue;
            }

            if ($action === 'modifier') {
                $valeurs = is_array($decision['valeurs'] ?? null) ? $decision['valeurs'] : [];
                $ligne = $payload['lignes'][$index];

                foreach ($valeurs as $champ => $valeur) {
                    if (! in_array($champ, $colonnes, true)) {
                        throw IoException::malforme("colonne « {$champ} » inconnue (ligne {$numero}).");
                    }

                    $ligne[$champ] = is_string($valeur) ? trim($valeur) : $valeur;
                }

                $validation = Validator::make($ligne, $definition->regles());

                if ($validation->fails()) {
                    throw IoException::malforme(
                        "modification invalide (ligne {$numero}) : ".implode(' ', $validation->errors()->all())
                    );
                }

                if ($definition instanceof ImportValidationAvancee) {
                    $metier = $definition->validerLigneMetier($ligne, $auteurId);

                    if ($metier !== []) {
                        $premierMessage = is_array($metier[0])
                            ? ($metier[0]['message'] ?? '')
                            : (string) $metier[0];

                        throw IoException::malforme(
                            "modification refusée par le contrôle métier (ligne {$numero}) : {$premierMessage}"
                        );
                    }
                }

                // PHASE 6 — la modification ne doit pas créer de collision
                // intra-lot (doublon avec une autre ligne du jeu à confirmer).
                $collision = $this->collisionIntraLot($ligne, $payload['lignes'], $index, $definition);

                if ($collision !== null) {
                    throw IoException::malforme(
                        "modification refusée (ligne {$numero}) : doublon avec la ligne {$collision}."
                    );
                }

                $payload['lignes'][$index] = $ligne;
                $resume['modifiees']++;
                $trace[] = ['ligne' => $numero, 'action' => 'modifier', 'par' => $auteurId];

                continue;
            }

            $resume['acceptees']++;
            $trace[] = ['ligne' => $numero, 'action' => 'accepter', 'par' => $auteurId];
        }

        foreach ($retirer as $index) {
            unset($payload['lignes'][$index]);
        }

        $payload['lignes'] = array_values($payload['lignes']);
        $payload['revue'] = ['par' => $auteurId, 'le' => now()->toIso8601String(), 'decisions' => $trace];
        $resume['restantes'] = count($payload['lignes']);

        session()->put("data_imports.{$token}", $payload);

        return $resume;
    }

    /**
     * Résolution des en-têtes (PHASE 2) : canonique prioritaire, alias
     * ensuite, conflits détectés — sans lever (l'appelant décide).
     * Partagée entre `normaliser()` (import) et le prédiagnostic.
     *
     * @param  array<int, string>  $entetes
     * @return array{correspondances: array<string, string>, conflits: array<int, string>, manquantes: array<int, string>}
     */
    public function resoudreCorrespondances(array $entetes, ImportDefinitionInterface $definition): array
    {
        // Alias d'en-têtes (ImportValidationAvancee) : les parsers Io
        // normalisent déjà les clés (translittération, minuscules,
        // séparateurs → `_`, caractères spéciaux supprimés) — la
        // comparaison se fait donc sur la même forme normalisée,
        // canonique prioritaire, jamais d'import silencieux d'une
        // colonne ambiguë (2 alias → 1 colonne).
        $norme = NormaliseurEnTetes::cle(...);

        $canoniques = [];

        foreach ($definition->colonnes() as $colonne) {
            $canoniques[$norme($colonne)] = $colonne;
        }

        $aliasVers = [];

        if ($definition instanceof ImportValidationAvancee) {
            foreach ($definition->aliasesColonnes() as $canonique => $variantes) {
                foreach ((array) $variantes as $variante) {
                    $aliasVers[$norme((string) $variante)] = $canonique;
                }
            }
        }

        $correspondances = [];
        $conflits = [];
        $canoniquesUtilises = [];

        // 1. Priorité aux colonnes canoniques exactes
        foreach ($entetes as $entete) {
            $cle = $norme((string) $entete);

            if (isset($canoniques[$cle])) {
                $canonique = $canoniques[$cle];
                $correspondances[$entete] = $canonique;
                $canoniquesUtilises[$canonique] = $entete;
            }
        }

        // 2. Résolution des alias pour les colonnes non encore associées
        foreach ($entetes as $entete) {
            if (isset($correspondances[$entete])) {
                continue;
            }

            $cle = $norme((string) $entete);

            if (isset($aliasVers[$cle])) {
                $canonique = $aliasVers[$cle];

                // Si la colonne canonique est déjà directement fournie par l'en-tête du fichier,
                // on ne mappe pas l'alias vers cette même colonne pour éviter un faux conflit.
                if (! isset($canoniquesUtilises[$canonique])) {
                    $correspondances[$entete] = $canonique;
                }
            }
        }

        // 3. Détection de véritables conflits (ex. deux alias distincts pour la même colonne)
        foreach ($correspondances as $entete => $canonique) {
            $doublons = array_keys($correspondances, $canonique, true);

            if (count($doublons) > 1) {
                $conflits = array_merge($conflits, $doublons);
            }
        }

        $manquantes = array_values(array_diff(
            $definition->colonnesRequises(),
            array_values(array_unique($correspondances))
        ));

        return [
            'correspondances' => $correspondances,
            'conflits' => array_values(array_unique($conflits)),
            'manquantes' => $manquantes,
        ];
    }

    /**
     * @return array{lignes: array<int, array<string, mixed>>, erreurs: array<int, array{ligne: int|string, champ: string, message: string, code: string}>}
     */
    private function normaliser(array $brut, ImportDefinitionInterface $definition): array
    {
        if ($brut === []) {
            throw IoException::malforme('fichier vide.');
        }

        $entetes = array_keys($brut[0]);

        // Colonnes interdites (ImportSansPii, fail-closed) : rejet du
        // fichier ENTIER, jamais d'ignorance silencieuse d'une colonne
        // d'identité dans un flux anonyme (PII_FIELD_FORBIDDEN).
        if ($definition instanceof ImportSansPii) {
            $normeBrute = NormaliseurEnTetes::cle(...);
            $interdites = array_map(
                fn ($c) => $normeBrute((string) $c),
                $definition->colonnesInterdites()
            );

            foreach ($entetes as $entete) {
                if (in_array($normeBrute((string) $entete), $interdites, true)) {
                    throw IoException::malforme("colonne interdite « {$entete} » dans ce flux (PII_FIELD_FORBIDDEN).");
                }
            }
        }

        [
            'correspondances' => $correspondances,
            'conflits' => $conflits,
            'manquantes' => $manquantes,
        ] = $this->resoudreCorrespondances($entetes, $definition);

        if ($conflits !== []) {
            throw IoException::malforme('colonnes ambiguës : '.implode(', ', $conflits).'.');
        }

        if ($manquantes !== []) {
            throw IoException::malforme('colonnes manquantes : '.implode(', ', $manquantes).'.');
        }

        if (count($brut) > self::LIGNES_MAX) {
            throw IoException::tropDeLignes(self::LIGNES_MAX);
        }

        $lignes = [];
        $erreurs = [];

        foreach ($brut as $index => $ligne) {
            $no = $index + 2;
            $normalisee = ['_ligne' => $no, 'ligne' => $no];

            foreach ($definition->colonnes() as $colonne) {
                $normalisee[$colonne] = null;
            }

            foreach ($ligne as $entete => $valeur) {
                if (! isset($correspondances[$entete])) {
                    continue;
                }

                $canonique = $correspondances[$entete];

                if ($normalisee[$canonique] === null && trim((string) $valeur) !== '') {
                    $normalisee[$canonique] = trim((string) $valeur);
                }
            }

            $lignes[] = $normalisee;
        }

        return ['lignes' => $lignes, 'erreurs' => $erreurs];
    }

    /**
     * Parse lecture seule partagé (PHASE 2) : mêmes parsers, mêmes
     * enveloppes JSON/XML et mêmes garde-fous que l'import.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parser(UploadedFile $fichier, string $format): array
    {
        // Enveloppes JSON/XML dédiées : le parser Io attend des listes ;
        // ici on normalise d'abord la structure vers des lignes.
        if ($format === 'json') {
            return $this->parserJson($fichier);
        }

        if ($format === 'xml') {
            return $this->parserXml($fichier);
        }

        // Un fichier corrompu (ZIP invalide, tableur tronqué) lève une
        // exception technique du lecteur : la convertir en erreur
        // métier propre (jamais de 500 sur un upload utilisateur).
        try {
            return IoFactory::parserPour($format)->parse($fichier);
        } catch (IoException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            throw IoException::malforme('fichier illisible ou corrompu.');
        }
    }

    private function parserJson(UploadedFile $fichier): array
    {
        try {
            $donnees = json_decode($fichier->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw IoException::malforme('JSON invalide.');
        }

        $lignes = isset($donnees['rows']) && is_array($donnees['rows'])
            ? $donnees['rows']
            : (isset($donnees['notes']) && is_array($donnees['notes']) ? $donnees['notes'] : $donnees);

        if (! is_array($lignes)) {
            throw IoException::malforme('structure JSON attendue : {rows: [...]}, {notes: [...]} ou [...].');
        }

        $normalisees = [];
        foreach (array_values($lignes) as $ligne) {
            if (! is_array($ligne)) {
                throw IoException::malforme('chaque élément doit être un objet.');
            }
            $normalisees[] = array_map(fn ($v) => is_scalar($v) ? $v : '', $ligne);
        }

        if ($normalisees === []) {
            throw IoException::malforme('JSON vide.');
        }

        return $normalisees;
    }

    private function parserXml(UploadedFile $fichier): array
    {
        $contenu = $fichier->getContent();

        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $contenu) === 1) {
            throw IoException::malforme('DOCTYPE et ENTITY sont interdits (anti-XXE).');
        }

        $precedent = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        try {
            $ok = $document->loadXML($contenu, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($precedent);
        }

        if (! $ok) {
            throw IoException::malforme('XML malformé.');
        }

        $lignes = [];

        foreach ($document->getElementsByTagName('row') as $row) {
            $ligne = [];
            foreach ($row->childNodes as $champ) {
                if ($champ->nodeType === XML_ELEMENT_NODE) {
                    $ligne[mb_strtolower(trim($champ->tagName))] = trim($champ->textContent);
                }
            }
            if ($ligne !== []) {
                $lignes[] = $ligne;
            }
        }

        // Forme documentée alternative (<note>, <ligne>, …) : enfants
        // directs de la racine porteurs de sous-éléments (les feuilles
        // homonymes, ex. <note>14.5</note>, sont ignorées).
        if ($lignes === [] && $document->documentElement !== null) {
            foreach ($document->documentElement->childNodes as $item) {
                if ($item->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                $ligne = [];

                foreach ($item->childNodes as $champ) {
                    if ($champ->nodeType === XML_ELEMENT_NODE && trim($champ->textContent) !== '') {
                        $ligne[mb_strtolower(trim($champ->tagName))] = trim($champ->textContent);
                    }
                }

                if ($ligne !== []) {
                    $lignes[] = $ligne;
                }
            }
        }

        if ($lignes === []) {
            throw IoException::malforme('aucune balise <row> trouvée.');
        }

        return $lignes;
    }

    private function detecterFormat(UploadedFile $fichier): string
    {
        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: '');

        if (! array_key_exists($extension, self::formats())) {
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

        $tailleMax = max(1, (int) config('integrations.upload_max', 10 * 1024 * 1024));

        if ($fichier->getSize() > $tailleMax) {
            throw IoException::tropVolumineux($tailleMax);
        }

        $mime = (string) $fichier->getMimeType();
        $contenu = $fichier->getContent();

        $attendus = [
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
            'ods' => [
                'application/vnd.oasis.opendocument.spreadsheet',
                'application/zip',
            ],
            'json' => ['application/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml', 'text/plain'],
        ];

        if (! in_array($mime, $attendus[$format], true)) {
            throw IoException::malforme("contenu incohérent (MIME détecté : {$mime}).");
        }

        if ($format === 'json') {
            try {
                json_decode($contenu, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw IoException::malforme('JSON invalide.');
            }
        }

        if ($format === 'xml' && preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $contenu) === 1) {
            throw IoException::malforme('DOCTYPE et ENTITY sont interdits (anti-XXE).');
        }
    }
}
