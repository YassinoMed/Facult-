<?php

namespace App\DataMesh\Observabilite;

use App\DataMesh\Catalogue;
use App\DataMesh\Outbox\OutboxMessage;
use App\DataMesh\Qualite\ControlesDonnees;
use App\Models\AnneeUniversitaire;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/**
 * Service de lecture et d'agrégation pour le Data Mesh Observability Center.
 *
 * Lecture seule : aucune écriture métier, aucun déclenchement de flux,
 * aucune correction de données. Le périmètre facultaire est appliqué côté
 * serveur (jamais un paramètre navigateur ne fait foi). Les réponses sont
 * des tableaux versionnés stables (DTO), sans modèle Eloquent exposé tel
 * quel, sans PII (CIN, emails personnels, charge utile outbox).
 */
final class ServiceObservabilite
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * Étapes du flux vertical, dans l'ordre confirmé par
     * FluxVerticalDataMesh::executer(). L'étape 5 (traçabilité) est
     * alimentée par le journal d'audit, jamais par une exécution.
     *
     * @var array<int, array{ordre: int, libelle: string, produit: string|null}>
     */
    public const ETAPES_FLUX = [
        ['ordre' => 1, 'libelle' => 'Catalogue académique', 'produit' => 'catalogue_academique'],
        ['ordre' => 2, 'libelle' => 'Inscriptions validées', 'produit' => 'inscriptions_validees'],
        ['ordre' => 3, 'libelle' => 'Affectations de groupes', 'produit' => 'affectations_groupes'],
        ['ordre' => 4, 'libelle' => 'Analytique facultaire', 'produit' => 'analytique_faculte'],
        ['ordre' => 5, 'libelle' => 'Audit et bilan global', 'produit' => null],
    ];

    public function __construct(private readonly ControlesDonnees $controles) {}

    /**
     * Taxonomie des états (LOOP correction 01, critères DEGRADE en 02).
     * États mutuellement exclusifs pour le comptage par produit ; un seul
     * état final par produit, priorité :
     * ERREUR > DEGRADE > NON_VERIFIE > INCONNU > OPERATIONNEL.
     *
     * - ERREUR : anomalie bloquante avérée (overlay qualité, prioritaire).
     * - DEGRADE : avertissements avérés ≥ seuil configurable
     *   (`datamesh_observabilite.seuil_degrade_avertissements`, défaut 1),
     *   sans bloquante. Un signal avéré prime sur l'absence de mesure.
     * - NON_VERIFIE : ni mesure ni signal qualité (absence de données,
     *   jamais un état sain).
     * - INCONNU : mesure connue mais contrôle qualité indisponible
     *   (l'overlay n'a pas pu être calculé).
     * - OPERATIONNEL : mesure connue, contrôle disponible, aucun signal.
     * - NON_MESURE : nœuds non-produits sans mesure propre (domaines,
     *   consommateur BI).
     */
    public const ETATS_PRODUIT = ['OPERATIONNEL', 'DEGRADE', 'ERREUR', 'NON_VERIFIE', 'INCONNU', 'NON_MESURE'];

    /**
     * Politique de fraîcheur explicite par type de métrique. `seuil_secondes`
     * null = aucun SLA configuré : jamais PERIME, seulement NON_VERIFIE si
     * la mesure est inconnue. Fuseau unique : UTC (DATE_ATOM).
     *
     * @return array<string, array{source: string, seuil_secondes: int|null, perime_apres: string}>
     */
    public static function politiqueFraicheur(): array
    {
        return [
            'graphe' => ['source' => 'agrégation cache 60 s (clé user+faculté+année)', 'seuil_secondes' => 300, 'perime_apres' => 'PERIME si genere_le > 5 min'],
            'apercu' => ['source' => 'agrégation cache 60 s (clé user+faculté+année)', 'seuil_secondes' => 300, 'perime_apres' => 'PERIME si genere_le > 5 min'],
            'qualite' => ['source' => 'ControlesDonnees::controler, cache 60 s (clé user+faculté+année)', 'seuil_secondes' => 300, 'perime_apres' => 'PERIME si date_controle > 5 min'],
            'outbox_compteurs' => ['source' => 'lecture directe data_mesh_outbox (temps réel)', 'seuil_secondes' => 300, 'perime_apres' => 'PERIME si genere_le > 5 min'],
            'fraicheur_produit' => ['source' => 'MAX(updated_at) de la source de vérité', 'seuil_secondes' => null, 'perime_apres' => 'aucun SLA configuré : NON_VERIFIE si inconnue, jamais PERIME'],
            'flux_trace' => ['source' => 'journal immuable historique_academique', 'seuil_secondes' => null, 'perime_apres' => 'une trace ne périme pas ; absence = INCONNU'],
        ];
    }

    /**
     * Faculté effective (même règle que ProduitSocle) : refus explicite hors
     * périmètre, forçage de la faculté restreinte, null = transversal.
     *
     * @throws AuthorizationException
     */
    public function perimetreEffectif(User $utilisateur, ?int $demandee): ?int
    {
        if ($demandee !== null && ! $utilisateur->peutAccederFaculte($demandee)) {
            throw new AuthorizationException('Observabilité Data Mesh : faculté hors périmètre.');
        }

        return $utilisateur->faculteRestreinteId() ?? $demandee;
    }

    /**
     * Fiches produits : catalogue réel + fraîcheur mesurée. État BRUT
     * (fraîcheur seule) : `operationnel` si une date est connue,
     * `non_verifie` sinon. L'état FINAL unique (taxonomie §ETATS_PRODUIT,
     * overlay qualité inclus) est résolu par resoudreEtatProduit() dans
     * graphe() et apercu() — seuls ces états finaux sont comptés.
     *
     * @return array<int, array<string, mixed>>
     */
    public function produits(User $utilisateur, ?int $faculteId = null): array
    {
        $faculte = $this->perimetreEffectif($utilisateur, $faculteId);
        $fiches = [];

        foreach (Catalogue::codes() as $code) {
            $produit = Catalogue::produit($code);
            $description = $produit->decrire();
            $schema = $produit->schema();
            $fraicheur = $produit->fraicheur();
            $sensibles = count(array_filter($schema, fn ($c) => ($c['sensible'] ?? false) === true));

            $fiches[] = [
                'code' => $produit->code(),
                'nom' => $description['nom'] ?? $produit->code(),
                'domaine' => $produit->domaine(),
                'version' => $produit->version(),
                'description' => $description['description'] ?? '',
                'proprietaire_metier' => $description['proprietaire_metier'] ?? null,
                'source_verite' => $description['source_verite'] ?? null,
                'classification' => $description['classification'] ?? 'interne',
                'fraicheur_attendue' => $description['fraicheur_attendue'] ?? null,
                'fraicheur' => $fraicheur,
                'fraicheur_connue' => $fraicheur !== null,
                'nb_champs_schema' => count($schema),
                'nb_champs_sensibles' => $sensibles,
                'donnees_sensibles' => $sensibles > 0,
                'masquage_pii' => true,
                'etat' => $fraicheur !== null ? 'operationnel' : 'non_verifie',
                'perimetre' => $faculte === null ? 'toutes' : 'faculte='.$faculte,
            ];
        }

        return $fiches;
    }

    /**
     * Graphe complet : nœuds + relations orientées. Seules les relations
     * confirmées dans le code portent `confirmation = confirmee` ; les
     * autres sont explicitement marquées `declaree` ou `inconnue`.
     *
     * @return array{version_schema: string, genere_le: string, perimetre: string, noeuds: array, relations: array}
     */
    public function graphe(User $utilisateur, ?int $faculteId = null, ?int $anneeId = null): array
    {
        $faculte = $this->perimetreEffectif($utilisateur, $faculteId);
        $cle = 'datamesh-observabilite:graphe:v1:u'.$utilisateur->id.':f'.($faculte ?? 0).':a'.($anneeId ?? 0);

        return Cache::remember($cle, 60, function () use ($utilisateur, $faculte, $anneeId) {
            $fiches = $this->produits($utilisateur, $faculte);
            $qualite = $this->qualiteResumes($utilisateur, $faculte, $anneeId, $fiches);
            $outbox = $this->compteursOutbox();
            $derniereExecution = $this->derniereExecutionFlux($utilisateur, $faculte);

            $noeuds = [];
            $domaines = [];

            foreach ($fiches as $fiche) {
                $domaine = $fiche['domaine'];
                if (! isset($domaines[$domaine])) {
                    $domaines[$domaine] = true;
                    $noeuds[] = [
                        'id' => 'domaine:'.$domaine,
                        'type' => 'domaine',
                        'libelle' => ucfirst($domaine),
                        'domaine' => $domaine,
                        'etat' => 'non_mesure',
                        'detail' => 'Domaine propriétaire (regroupement logique, sans mesure propre).',
                    ];
                }

                $resolution = $this->resoudreEtatProduit($fiche, $qualite);

                $noeuds[] = [
                    'id' => 'produit:'.$fiche['code'],
                    'type' => 'produit',
                    'libelle' => $fiche['nom'],
                    'code' => $fiche['code'],
                    'domaine' => $fiche['domaine'],
                    'version' => $fiche['version'],
                    'etat' => $resolution['etat'],
                    'origine_etat' => $resolution['origine_etat'],
                    'diagnostic' => $resolution['diagnostic'],
                    'dernier_controle' => $qualite['date_controle'],
                    'fraicheur' => $fiche['fraicheur'],
                    'fraicheur_connue' => $fiche['fraicheur_connue'],
                    'donnees_sensibles' => $fiche['donnees_sensibles'],
                    'detail' => $fiche['description'],
                ];
            }

            $etatControle = 'non_verifie';
            if ($qualite['disponible']) {
                $etatControle = $qualite['total_bloquants'] > 0 ? 'erreur'
                    : ($qualite['total_avertissements'] > 0 ? 'degrade' : 'operationnel');
            }

            $noeuds[] = [
                'id' => 'controle:qualite',
                'type' => 'controle',
                'libelle' => 'Contrôles qualité',
                'domaine' => 'transverse',
                'etat' => $etatControle,
                'detail' => '8 règles ControlesDonnees (6 bloquantes, 1 avertissement, 1 info).',
            ];

            $noeuds[] = [
                'id' => 'flux:vertical',
                'type' => 'flux',
                'libelle' => 'Flux vertical',
                'domaine' => 'transverse',
                'etat' => $derniereExecution !== null ? 'operationnel' : 'inconnu',
                'detail' => $derniereExecution !== null
                    ? 'Dernière trace d’exécution connue (journal d’audit).'
                    : 'Aucune trace d’exécution mesurée — état inconnu, pas d’exécution déclenchée par cette page.',
            ];

            $etatOutbox = 'non_verifie';
            if ($outbox['disponible']) {
                $etatOutbox = $outbox['echecs'] > 0 ? 'erreur'
                    : ($outbox['en_attente'] > 0 ? 'degrade' : ($outbox['publies'] > 0 ? 'operationnel' : 'non_verifie'));
            }

            $noeuds[] = [
                'id' => 'outbox:transactionnelle',
                'type' => 'outbox',
                'libelle' => 'Outbox transactionnelle',
                'domaine' => 'transverse',
                'etat' => $etatOutbox,
                'detail' => 'Livraison au moins une fois, idempotente (jamais exactement une fois).',
            ];

            $noeuds[] = [
                'id' => 'consommateur:bi',
                'type' => 'consommateur',
                'libelle' => 'BI / Pilotage',
                'domaine' => 'pilotage',
                'etat' => 'non_mesure',
                'detail' => 'Consommateur aval (BIService) — statut non mesuré par ce module.',
            ];

            // Relations confirmées par FluxVerticalDataMesh (ordre des étapes).
            $relations = [
                ['id' => 'r1', 'de' => 'produit:catalogue_academique', 'vers' => 'produit:inscriptions_validees', 'libelle' => 'référentiel → scolarité', 'confirmation' => 'confirmee', 'source' => 'FluxVerticalDataMesh étapes 1→2'],
                ['id' => 'r2', 'de' => 'produit:inscriptions_validees', 'vers' => 'produit:affectations_groupes', 'libelle' => 'éligibles → affectation', 'confirmation' => 'confirmee', 'source' => 'FluxVerticalDataMesh étapes 2→3'],
                ['id' => 'r3', 'de' => 'produit:affectations_groupes', 'vers' => 'produit:analytique_faculte', 'libelle' => 'affectations → pilotage', 'confirmation' => 'confirmee', 'source' => 'FluxVerticalDataMesh étapes 3→4'],
                ['id' => 'r4', 'de' => 'produit:analytique_faculte', 'vers' => 'consommateur:bi', 'libelle' => 'agrégats dérivés', 'confirmation' => 'confirmee', 'source' => 'AnalytiqueFaculte source_verite BIService'],
            ];

            foreach ($fiches as $fiche) {
                $relations[] = [
                    'id' => 'rq-'.$fiche['code'],
                    'de' => 'produit:'.$fiche['code'],
                    'vers' => 'controle:qualite',
                    'libelle' => 'observé par',
                    'confirmation' => 'confirmee',
                    'source' => 'ControlesDonnees lit les tables sources des produits',
                ];
                $relations[] = [
                    'id' => 'ro-'.$fiche['code'],
                    'de' => 'produit:'.$fiche['code'],
                    'vers' => 'outbox:transactionnelle',
                    'libelle' => 'événements (le cas échéant)',
                    'confirmation' => 'declaree',
                    'source' => 'Aucun appel OutboxService::enregistrer confirmé dans app/ pour ce produit',
                ];
                $relations[] = [
                    'id' => 'rd-'.$fiche['code'],
                    'de' => 'domaine:'.$fiche['domaine'],
                    'vers' => 'produit:'.$fiche['code'],
                    'libelle' => 'possède',
                    'confirmation' => 'confirmee',
                    'source' => 'ProduitDonnees::domaine()',
                ];
            }

            $relations[] = [
                'id' => 'r5', 'de' => 'flux:vertical', 'vers' => 'produit:catalogue_academique', 'libelle' => 'orchestre (lecture)', 'confirmation' => 'confirmee', 'source' => 'FluxVerticalDataMesh::executer()',
            ];

            return [
                'version_schema' => self::SCHEMA_VERSION,
                'genere_le' => now()->format(DATE_ATOM),
                'perimetre' => $faculte === null ? 'toutes' : 'faculte='.$faculte,
                'noeuds' => $noeuds,
                'relations' => $relations,
            ];
        });
    }

    /**
     * Bandeau de synthèse : chaque métrique porte sa date de référence et
     * son état de disponibilité. `donnees_manquantes_signalees` vaut true
     * dès qu'une métrique est indisponible (jamais assimilée à un état sain).
     *
     * @return array<string, mixed>
     */
    public function apercu(User $utilisateur, ?int $faculteId = null, ?int $anneeId = null): array
    {
        $faculte = $this->perimetreEffectif($utilisateur, $faculteId);
        $cle = 'datamesh-observabilite:apercu:v1:u'.$utilisateur->id.':f'.($faculte ?? 0).':a'.($anneeId ?? 0);

        return Cache::remember($cle, 60, function () use ($utilisateur, $faculte, $anneeId) {
            $fiches = $this->produits($utilisateur, $faculte);
            $qualite = $this->qualiteResumes($utilisateur, $faculte, $anneeId, $fiches);
            $outbox = $this->compteursOutbox();
            $derniereExecution = $this->derniereExecutionFlux($utilisateur, $faculte);

            // États finaux UNIQUES (taxonomie §ETATS_PRODUIT) : la somme des
            // compteurs vaut toujours le nombre de produits (6).
            $enrichies = [];
            foreach ($fiches as $fiche) {
                $resolution = $this->resoudreEtatProduit($fiche, $qualite);
                $enrichies[] = [
                    'code' => $fiche['code'],
                    'version' => $fiche['version'],
                    'domaine' => $fiche['domaine'],
                    'etat' => $resolution['etat'],
                    'origine_etat' => $resolution['origine_etat'],
                    'diagnostic' => $resolution['diagnostic'],
                    'dernier_controle' => $qualite['date_controle'],
                    'date_mesure' => $fiche['fraicheur'],
                ];
            }

            $compter = fn (string $etat) => count(array_filter($enrichies, fn ($f) => $f['etat'] === $etat));
            $operationnels = $compter('operationnel');
            $enErreur = $compter('erreur');
            $degrades = $compter('degrade');
            $nonVerifies = $compter('non_verifie');
            $inconnus = $compter('inconnu');

            // Date de référence = plus récente des mesures connues (jamais la
            // date de calcul, jamais la date du flux comme universelle).
            $mesures = array_filter(array_column($enrichies, 'date_mesure'));
            $dateReference = $mesures !== [] ? max($mesures) : null;

            $manquantes = ($nonVerifies + $inconnus) > 0 || ! $qualite['disponible'] || ! $outbox['disponible'] || $derniereExecution === null;

            return [
                'version_schema' => self::SCHEMA_VERSION,
                'genere_le' => now()->format(DATE_ATOM),
                'fuseau' => 'UTC',
                'politique_fraicheur' => self::politiqueFraicheur(),
                'perimetre' => $faculte === null ? 'toutes' : 'faculte='.$faculte,
                'donnees_manquantes_signalees' => $manquantes,
                'produits' => [
                    'enregistres' => count($enrichies),
                    'operationnels' => $operationnels,
                    'en_erreur' => $enErreur,
                    'degrades' => $degrades,
                    'non_verifies' => $nonVerifies,
                    'inconnus' => $inconnus,
                    'etats_exclusifs' => true,
                    'calcule_le' => now()->format(DATE_ATOM),
                    'date_reference' => $dateReference,
                    'disponible' => true,
                ],
                'detail_par_produit' => $enrichies,
                'flux' => [
                    'derniere_execution' => $derniereExecution,
                    'date_reference' => $derniereExecution['date'] ?? null,
                    'disponible' => $derniereExecution !== null,
                ],
                'qualite' => [
                    'total_bloquants' => $qualite['total_bloquants'],
                    'total_avertissements' => $qualite['total_avertissements'],
                    'total_infos' => $qualite['total_infos'],
                    'date_controle' => $qualite['date_controle'],
                    'date_reference' => $qualite['date_controle'],
                    'disponible' => $qualite['disponible'],
                ],
                'outbox' => [
                    'en_attente' => $outbox['en_attente'],
                    'echecs' => $outbox['echecs'],
                    'publies' => $outbox['publies'],
                    'sain_interprete' => false,
                    'note_zero' => 'Zéro message ne signifie pas système sain : pipeline non vérifiée (automatisation non configurée).',
                    'perimetre_applicable' => false,
                    'note_perimetre' => 'Table globale sans cloisonnement facultaire : chiffres transverses, même pour un admin de faculté.',
                    'calcule_le' => now()->format(DATE_ATOM),
                    'date_reference' => now()->format(DATE_ATOM),
                    'disponible' => $outbox['disponible'],
                ],
                'fraicheur_produits' => array_map(fn ($f) => [
                    'code' => $f['code'],
                    'version' => $f['version'],
                    'fraicheur' => $f['fraicheur'],
                    'disponible' => $f['fraicheur_connue'],
                ], $fiches),
            ];
        });
    }

    /**
     * Vue Qualité : réutilise ControlesDonnees::controler sans réimplémenter
     * les règles et sans synchronisation (lecture seule). Chaque règle
     * compte 1 violation par entité listée (pas de doublon intra-règle) ;
     * en revanche AUCUNE déduplication transverse n'est effectuée : un même
     * enregistrement peut être compté dans plusieurs règles.
     *
     * @return array<string, mixed>
     */
    public function qualite(User $utilisateur, ?int $faculteId = null, ?int $anneeId = null): array
    {
        $faculte = $this->perimetreEffectif($utilisateur, $faculteId);
        $annee = $anneeId ?? (int) (AnneeUniversitaire::active()?->id ?? AnneeUniversitaire::value('id') ?? 0);
        $cle = 'datamesh-observabilite:qualite:v2:u'.$utilisateur->id.':f'.($faculte ?? 0).':a'.($annee ?: 0);

        return Cache::remember($cle, 60, function () use ($utilisateur, $faculte, $annee) {
            $rapport = $this->controles->controler($utilisateur, $faculte, $annee ?: null);

            // Unité de comptage prouvée par la construction de chaque règle
            // (1 entrée = 1 entité listée, voir ControlesDonnees).
            $unites = [
                'inscriptions_sans_programme' => 'inscriptions',
                'references_lmd_incoherentes' => 'inscriptions',
                'affectations_incompatibles' => 'affectations',
                'doublons_affectations' => 'étudiants',
                'groupes_surcharges' => 'groupes',
                'capacites_inconnues' => 'groupes',
                'incoherences_effectifs_agregats' => 'comparaisons',
                'fraicheur_produits_depassee' => 'produits',
            ];

            $resultats = array_map(fn ($r) => [
                'regle' => $r['regle'],
                'perimetre' => $r['perimetre'],
                'violations' => $r['anomalies'],
                'anomalies' => $r['anomalies'],
                'unite_comptage' => $unites[$r['regle']] ?? 'cas',
                'enregistrements_distincts' => (int) ($r['anomalies'] ?? 0),
                'deduplique_intra_regle' => true,
                'gravite' => $r['gravite'],
                'exemples' => array_values($r['exemples'] ?? []),
            ], $rapport['resultats']);

            return [
                'version_schema' => self::SCHEMA_VERSION,
                'genere_le' => now()->format(DATE_ATOM),
                'fuseau' => 'UTC',
                'date_controle' => $rapport['date_controle'],
                'perimetre' => $faculte === null ? 'toutes' : 'faculte='.$faculte,
                'disponible' => true,
                'total_violations' => array_sum(array_column($resultats, 'violations')),
                'deduplication_transverse' => false,
                'note_deduplication' => 'Chaque règle compte ses entités sans doublon, mais un même enregistrement peut apparaître dans plusieurs règles : aucun total dédupliqué n’est annoncé.',
                'resultats' => $resultats,
            ];
        });
    }

    /**
     * Vue Outbox en lecture seule : compteurs + derniers messages en
     * attente/échec, sans charge utile (PII potentielle), erreur expurgée.
     *
     * @return array<string, mixed>
     */
    public function outbox(User $utilisateur, ?int $faculteId = null): array
    {
        $this->perimetreEffectif($utilisateur, $faculteId);
        $compteurs = $this->compteursOutbox();

        $messages = OutboxMessage::query()
            ->whereIn('statut', [OutboxMessage::STATUT_PENDING, OutboxMessage::STATUT_FAILED])
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'type_evenement', 'type_agregat', 'agregat_id', 'statut', 'tentatives', 'derniere_erreur', 'created_at', 'publie_le']);

        return [
            'version_schema' => self::SCHEMA_VERSION,
            'genere_le' => now()->format(DATE_ATOM),
            'disponible' => $compteurs['disponible'],
            'compteurs' => [
                'en_attente' => $compteurs['en_attente'],
                'echecs' => $compteurs['echecs'],
                'publies' => $compteurs['publies'],
            ],
            'livraison' => 'au_moins_une_fois',
            'note_livraison' => 'Livraison au moins une fois et idempotente ; jamais exactement une fois.',
            'automatisation' => $this->etatAutomatisation(),
            'retention' => [
                'jours' => 30,
                'purge_planifiee' => false,
                'note' => 'Purge via purgerPublies(30j), non déclenchée depuis cette page. Voir bloc automatisation (état mesuré au runtime).',
            ],
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'type_evenement' => $m->type_evenement,
                'type_agregat' => $m->type_agregat,
                'agregat_id' => $m->agregat_id,
                'statut' => $m->statut,
                'tentatives' => $m->tentatives,
                'anciennete_heures' => round(abs(now()->diffInHours($m->created_at, false)), 1),
                'derniere_erreur' => $this->expurgerErreur($m->derniere_erreur),
                'cree_le' => $m->created_at?->format(DATE_ATOM),
                'publie_le' => $m->publie_le?->format(DATE_ATOM),
            ])->all(),
        ];
    }

    /**
     * Vue Flux en lecture seule : états dérivés des fraîcheurs mesurées +
     * dernière trace d'audit. N'exécute JAMAIS FluxVerticalDataMesh.
     *
     * @return array<string, mixed>
     */
    public function flux(User $utilisateur, ?int $faculteId = null): array
    {
        $faculte = $this->perimetreEffectif($utilisateur, $faculteId);
        $fiches = $this->produits($utilisateur, $faculte);
        $parCode = [];
        foreach ($fiches as $fiche) {
            $parCode[$fiche['code']] = $fiche;
        }

        $derniereExecution = $this->derniereExecutionFlux($utilisateur, $faculte);

        $etapes = [];
        foreach (self::ETAPES_FLUX as $etape) {
            if ($etape['produit'] === null) {
                $etapes[] = [
                    'ordre' => $etape['ordre'],
                    'libelle' => $etape['libelle'],
                    'produit' => null,
                    'etat' => $derniereExecution !== null ? 'terminee' : 'inconnue',
                    'date_reference' => $derniereExecution['date'] ?? null,
                    'disponible' => $derniereExecution !== null,
                    'note' => $derniereExecution !== null
                        ? 'Trace d’audit connue.'
                        : 'Aucune trace d’exécution mesurée — état inconnu.',
                ];

                continue;
            }

            $fiche = $parCode[$etape['produit']] ?? null;
            $connue = $fiche !== null && $fiche['fraicheur_connue'];

            $etapes[] = [
                'ordre' => $etape['ordre'],
                'libelle' => $etape['libelle'],
                'produit' => $etape['produit'],
                'version' => $fiche['version'] ?? null,
                'etat' => $connue ? 'terminee' : 'inconnue',
                'date_reference' => $fiche['fraicheur'] ?? null,
                'disponible' => $connue,
                'note' => $connue
                    ? 'Fraîcheur mesurée sur la source de vérité.'
                    : 'Aucune date d’actualisation connue — résultat vide non présenté comme un succès.',
            ];
        }

        return [
            'version_schema' => self::SCHEMA_VERSION,
            'genere_le' => now()->format(DATE_ATOM),
            'perimetre' => $faculte === null ? 'toutes' : 'faculte='.$faculte,
            'lecture_seule' => true,
            'note' => 'Aucun traitement métier déclenché par cette page.',
            'derniere_execution' => $derniereExecution,
            'etapes' => $etapes,
        ];
    }

    /**
     * Détail d'un nœud pour le panneau latéral : fiche catalogue complète
     * (produit), dépendances confirmées, règles d'accès, liens autorisés.
     *
     * @return array<string, mixed>|null
     */
    public function detailNoeud(User $utilisateur, string $idNoeud, ?int $faculteId = null, ?int $anneeId = null): ?array
    {
        $graphe = $this->graphe($utilisateur, $faculteId, $anneeId);

        $noeud = null;
        foreach ($graphe['noeuds'] as $candidat) {
            if ($candidat['id'] === $idNoeud) {
                $noeud = $candidat;

                break;
            }
        }

        if ($noeud === null) {
            return null;
        }

        $entrantes = array_values(array_filter($graphe['relations'], fn ($r) => $r['vers'] === $idNoeud));
        $sortantes = array_values(array_filter($graphe['relations'], fn ($r) => $r['de'] === $idNoeud));

        $detail = [
            'noeud' => $noeud,
            'dependances_entrantes' => $entrantes,
            'dependances_sortantes' => $sortantes,
            'regles_acces' => 'Back-office authentifié, permission statistiques.view, périmètre facultaire appliqué côté serveur.',
            'genere_le' => now()->format(DATE_ATOM),
        ];

        if (($noeud['type'] ?? null) === 'produit' && isset($noeud['code'])) {
            $produit = Catalogue::produit($noeud['code']);
            $description = $produit->decrire();
            $detail['contrat'] = [
                'code' => $produit->code(),
                'domaine' => $produit->domaine(),
                'version' => $produit->version(),
                'description' => $description['description'] ?? '',
                'proprietaire_metier' => $description['proprietaire_metier'] ?? null,
                'source_verite' => $description['source_verite'] ?? null,
                'classification' => $description['classification'] ?? 'interne',
                'fraicheur_attendue' => $description['fraicheur_attendue'] ?? null,
                'compatibilite' => $description['compatibilite'] ?? null,
                'schema' => $produit->schema(),
            ];
            $detail['derniere_execution_flux'] = $this->derniereExecutionFlux($utilisateur, $this->perimetreEffectif($utilisateur, $faculteId));
        }

        return $detail;
    }

    /**
     * État final unique d'un produit (taxonomie §ETATS_PRODUIT). Priorité :
     * ERREUR (bloquante avérée) > DEGRADE (avertissements ≥ seuil, sans
     * bloquante) > NON_VERIFIE (ni mesure ni signal) > INCONNU (mesure sans
     * contrôle) > OPERATIONNEL. Retourne aussi l'origine et un diagnostic
     * expurgé (noms de règles uniquement, aucune donnée individuelle).
     *
     * @param  array<string, mixed>  $fiche
     * @param  array{disponible: bool, bloquants_par_produit: array<string, int>, regles_par_produit: array<string, array<int, string>>, avertissements_par_produit: array<string, int>, date_controle: string|null}  $resume
     * @return array{etat: string, origine_etat: string, diagnostic: string|null}
     */
    public function resoudreEtatProduit(array $fiche, array $resume): array
    {
        $code = $fiche['code'];

        if (($resume['bloquants_par_produit'][$code] ?? 0) > 0) {
            $regles = implode(', ', $resume['regles_par_produit'][$code] ?? []);

            return [
                'etat' => 'erreur',
                'origine_etat' => 'qualite',
                'diagnostic' => $resume['bloquants_par_produit'][$code].' anomalie(s) bloquante(s) avérée(s)'.($regles !== '' ? " (règles : {$regles})" : '').'.',
            ];
        }

        $seuil = max(1, (int) config('datamesh_observabilite.seuil_degrade_avertissements', 1));
        if (($resume['avertissements_par_produit'][$code] ?? 0) >= $seuil) {
            return [
                'etat' => 'degrade',
                'origine_etat' => 'qualite',
                'diagnostic' => $resume['avertissements_par_produit'][$code]." avertissement(s) avéré(s) (seuil DEGRADE : {$seuil}), sans bloquante.",
            ];
        }

        if (! ($fiche['fraicheur_connue'] ?? false)) {
            return [
                'etat' => 'non_verifie',
                'origine_etat' => 'fraicheur',
                'diagnostic' => 'Aucune date d’actualisation connue sur la source de vérité — absence de données, pas un état sain.',
            ];
        }

        if (! ($resume['disponible'] ?? false)) {
            return [
                'etat' => 'inconnu',
                'origine_etat' => 'qualite',
                'diagnostic' => 'Mesure connue mais contrôle qualité indisponible — overlay non calculé.',
            ];
        }

        return ['etat' => 'operationnel', 'origine_etat' => 'fraicheur', 'diagnostic' => null];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $fiches  Fiches déjà
     *                                                         mesurées (fraîcheurs réutilisées par la règle 8, zéro doublon).
     * @return array{disponible: bool, total_bloquants: int, total_avertissements: int, total_infos: int, date_controle: string|null, bloquants_par_produit: array<string, int>, regles_par_produit: array<string, array<int, string>>, avertissements_par_produit: array<string, int>}
     */
    private function qualiteResumes(User $utilisateur, ?int $faculte, ?int $anneeId, ?array $fiches = null): array
    {
        try {
            $annee = $anneeId ?? (int) (AnneeUniversitaire::active()?->id ?? AnneeUniversitaire::value('id') ?? 0);
            $fraicheurs = null;
            if ($fiches !== null) {
                $fraicheurs = [];
                foreach ($fiches as $fiche) {
                    $fraicheurs[$fiche['code']] = $fiche['fraicheur'];
                }
            }
            $rapport = $this->controles->controler($utilisateur, $faculte, $annee ?: null, $fraicheurs);
        } catch (\Throwable) {
            return [
                'disponible' => false, 'total_bloquants' => 0, 'total_avertissements' => 0,
                'total_infos' => 0, 'date_controle' => null, 'bloquants_par_produit' => [],
                'regles_par_produit' => [], 'avertissements_par_produit' => [],
            ];
        }

        // Correspondance règle → produit (documentée, conservative : les
        // règles transverses ne colorent aucun produit en erreur à tort).
        $correspondance = [
            'inscriptions_sans_programme' => 'inscriptions_validees',
            'references_lmd_incoherentes' => 'inscriptions_validees',
            'affectations_incompatibles' => 'affectations_groupes',
            'doublons_affectations' => 'affectations_groupes',
            'groupes_surcharges' => 'affectations_groupes',
            'capacites_inconnues' => 'affectations_groupes',
            'incoherences_effectifs_agregats' => 'analytique_faculte',
            'fraicheur_produits_depassee' => null,
        ];

        // Avertissements mappés (même correspondance) pour l'état DEGRADE.
        $correspondanceAvertissements = [
            'capacites_inconnues' => 'affectations_groupes',
            'incoherences_effectifs_agregats' => 'analytique_faculte',
        ];

        $bloquants = 0;
        $avertissements = 0;
        $infos = 0;
        $parProduit = [];
        $reglesParProduit = [];
        $avertissementsParProduit = [];

        foreach ($rapport['resultats'] as $resultat) {
            $gravite = $resultat['gravite'] ?? 'info';
            $nb = (int) ($resultat['anomalies'] ?? 0);

            if ($gravite === 'bloquant') {
                $bloquants += $nb;
            } elseif ($gravite === 'avertissement') {
                $avertissements += $nb;
            } else {
                $infos += $nb;
            }

            $produit = $correspondance[$resultat['regle']] ?? null;
            if ($produit !== null && $gravite === 'bloquant' && $nb > 0) {
                $parProduit[$produit] = ($parProduit[$produit] ?? 0) + $nb;
                $reglesParProduit[$produit][] = $resultat['regle'];
            }

            $produitAverti = $correspondanceAvertissements[$resultat['regle']] ?? null;
            if ($produitAverti !== null && $gravite === 'avertissement' && $nb > 0) {
                $avertissementsParProduit[$produitAverti] = ($avertissementsParProduit[$produitAverti] ?? 0) + $nb;
            }
        }

        return [
            'disponible' => true,
            'total_bloquants' => $bloquants,
            'total_avertissements' => $avertissements,
            'total_infos' => $infos,
            'date_controle' => $rapport['date_controle'],
            'bloquants_par_produit' => $parProduit,
            'regles_par_produit' => $reglesParProduit,
            'avertissements_par_produit' => $avertissementsParProduit,
        ];
    }

    /** Compteurs outbox en UNE requête agrégée (GROUP BY statut). */
    private function compteursOutbox(): array
    {
        try {
            $lignes = OutboxMessage::query()
                ->selectRaw('statut, COUNT(*) as n')
                ->groupBy('statut')
                ->pluck('n', 'statut')
                ->all();

            return [
                'disponible' => true,
                'en_attente' => (int) ($lignes[OutboxMessage::STATUT_PENDING] ?? 0),
                'echecs' => (int) ($lignes[OutboxMessage::STATUT_FAILED] ?? 0),
                'publies' => (int) ($lignes[OutboxMessage::STATUT_PUBLISHED] ?? 0),
            ];
        } catch (\Throwable) {
            return ['disponible' => false, 'en_attente' => 0, 'echecs' => 0, 'publies' => 0];
        }
    }

    /**
     * État de l'automatisation du publisher, MESURÉ au runtime (jamais codé
     * en dur) : présence de `datamesh:outbox-publier` dans le scheduler +
     * fraîcheur du heartbeat `supervision:scheduler-dernier`. Dernière
     * exécution et dernier succès : aucune table ne les stocke → INCONNU
     * prouvé (disponible: false). Aucune planification effectuée ici.
     *
     * @return array{etat: string, evenements_planifies: int, heartbeat_scheduler: string|null, heartbeat_frais: bool, derniere_execution_publisher: null, dernier_succes_confirme: null, prochain_declenchement: null|string}
     */
    public function etatAutomatisation(): array
    {
        try {
            $planificateur = app(Schedule::class);
            $planifies = 0;
            $expression = null;
            foreach ($planificateur->events() as $evenement) {
                if (str_contains((string) $evenement->command, 'datamesh:outbox-publier')) {
                    $planifies++;
                    $expression = $evenement->expression;
                }
            }
        } catch (\Throwable) {
            return [
                'etat' => 'INCONNU', 'evenements_planifies' => 0,
                'heartbeat_scheduler' => null, 'heartbeat_frais' => false,
                'derniere_execution_publisher' => null, 'dernier_succes_confirme' => null,
                'prochain_declenchement' => null,
                'note' => 'Scheduler illisible au runtime — état inconnu, pas un état sain.',
            ];
        }

        if ($planifies === 0) {
            return [
                'etat' => 'NON_CONFIGURE', 'evenements_planifies' => 0,
                'heartbeat_scheduler' => Cache::get('supervision:scheduler-dernier'),
                'heartbeat_frais' => false,
                'derniere_execution_publisher' => null, 'dernier_succes_confirme' => null,
                'prochain_declenchement' => null,
                'note' => 'Commande datamesh:outbox-publier absente du scheduler : aucune publication automatique. Planification interdite sans autorisation explicite.',
            ];
        }

        $heartbeat = Cache::get('supervision:scheduler-dernier');
        $frais = $heartbeat !== null && abs(now()->diffInSeconds($heartbeat)) < 600;

        return [
            'etat' => $frais ? 'ACTIF' : 'INCONNU',
            'evenements_planifies' => $planifies,
            'heartbeat_scheduler' => $heartbeat,
            'heartbeat_frais' => $frais,
            'derniere_execution_publisher' => null, 'dernier_succes_confirme' => null,
            'prochain_declenchement' => $expression,
            'note' => $frais
                ? 'Planifié et heartbeat frais ; dernier succès individuel non stocké (non mesuré).'
                : 'Planifié mais exécution du scheduler non confirmée (heartbeat absent ou périmé).',
        ];
    }

    /**
     * Dernière trace d'exécution du flux vertical (journal d'audit).
     * L'acteur est résolu sans exposer d'identité : rôle du compte
     * `utilisateur_id`, repli sur `nouvelle_valeur.utilisateur_id` (le
     * tracer lit auth()->user(), NULL hors HTTP), « compte supprimé » si
     * introuvable. Rôle seul, jamais d'email ni de session.
     */
    private function derniereExecutionFlux(User $utilisateur, ?int $faculte): ?array
    {
        try {
            $entree = HistoriqueAcademique::query()
                ->where('action', 'data_mesh.flux_vertical_execute')
                ->when($faculte !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculte)))
                ->with('utilisateur:id,role')
                ->orderByDesc('id')
                ->first(['id', 'action', 'entite', 'utilisateur_id', 'nouvelle_valeur', 'source_systeme', 'created_at']);

            if ($entree === null) {
                return null;
            }

            $acteur = $entree->utilisateur;
            $compteSupprime = false;

            if ($acteur === null) {
                // Repli : l'acteur passé au flux est persisté dans le détail
                // JSON (voir FluxVerticalDataMesh, clé utilisateur_id).
                $detail = $entree->nouvelle_valeur;
                if (is_string($detail)) {
                    $detail = json_decode($detail, true);
                }
                $idActeur = is_array($detail) ? ($detail['utilisateur_id'] ?? null) : null;
                $acteur = $idActeur !== null ? User::find($idActeur, ['id', 'role']) : null;
                $compteSupprime = $idActeur !== null && $acteur === null;
            }

            return [
                'trace_id' => $entree->id,
                'date' => $entree->created_at?->format(DATE_ATOM),
                'acteur_role' => $acteur?->role,
                'acteur_compte_supprime' => $compteSupprime,
                'source' => $entree->source_systeme,
                'disponible' => true,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Expurge une erreur technique de toute donnée sensible (emails, CIN
     * potentielles, tokens) avant exposition.
     */
    private function expurgerErreur(?string $erreur): ?string
    {
        if ($erreur === null || $erreur === '') {
            return null;
        }

        $expurgee = (string) preg_replace(
            ['/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '/\b[A-Z]{1,2}\d{6,8}\b/'],
            ['[email expurgé]', '[identifiant expurgé]'],
            $erreur
        );

        return mb_substr($expurgee, 0, 500);
    }
}
