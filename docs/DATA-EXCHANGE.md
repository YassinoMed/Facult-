# Import / Export unifié (DataExchange)

Centre : **Administration → Import / Export** (`/admin/donnees`).
Un seul pipeline pour tous les référentiels : fichier → analyse →
validation → prévisualisation → confirmation → transaction → rapport.

## §1 Modules couverts

| Module | Import | Export | Formats |
|---|---|---|---|
| Enseignants | ✓ | ✓ | CSV, XLSX, JSON |
| Salles | ✓ | ✓ | CSV, XLSX, JSON |
| Groupes | ✓ | ✓ (scopé faculté) | CSV, XLSX, JSON |
| Matières | ✓ | ✓ | CSV, XLSX, JSON |
| Départements | ✓ | ✓ | CSV, XLSX, JSON |
| UE | ✓ | ✓ | CSV, XLSX, JSON |
| ECUE (Matiere, sans doublon) | ✓ | ✓ | CSV, XLSX, JSON |
| Emplois du temps | via module Schedule existant (`schedules.import`) | via Schedule | CSV, XLSX, JSON, XML, API |
| Étudiants / notes / archives | via imports existants (`imports:create`) | via exports existants | CSV, XLSX, JSON, XML, PDF selon module |
| Demandes en ligne | NON (workflow) | métadonnées via module | — |
| Documents (fichiers) | NON (upload contrôlé) | métadonnées uniquement | — |

### Explicitement NON importables / NON exportables en direct

- **Utilisateurs : mots de passe** (`password`, `remember_token`) — jamais
  importés ni exportés. L'export enseignants ne projette que
  `nom, prenom, email`.
- **Notifications** : aucun import direct (contournerait le système) ;
  export réservé à l'audit via les logs existants.
- **Demandes administratives (OnlineRequests)** : import interdit (contournerait
  le workflow) ; seul l'export de métadonnées est pertinent.
- **Fichiers privés** (`storage/app/private`) : jamais exposés directement ;
  téléchargement contrôlé par policy + ZIP si pertinent.
- **Paramètres métier** : pas d'import générique (cohérence) ; modification
  via écrans dédiés uniquement.

## §2 Pipeline

```
CSV/XLSX/JSON/XML/API → Parser (IoFactory) → Normalizer → Validator →
Duplicate Detector (fichier) → Conflits (base, avertissements) →
Preview (token session serveur) → Confirmation explicite →
Transaction unique (DB::transaction) → ImportReport + IntegrationLog
```

- Plafond : **5000 lignes / 10 Mo** — au-delà, découper le fichier.
- Mode par défaut : `creer`. `remplacer` : jamais automatique, jamais via
  API, confirmation écrite exigée, journalisé. Seules les définitions dont
  `modes()` inclut `remplacer` l'autorisent (ex. `referentiel-mens` : `vider()`
  no-op — le référentiel national n'est jamais vidé).
- Identifiants stables : code (salles, groupes, matières), email puis
  nom+prénom (enseignants). Jamais le nom seul quand un code existe.

## §3-4 Architecture

```
app/Services/DataExchange/
├── Contracts/ImportDefinitionInterface.php  (colonnes, règles, clé doublon,
│                                            trouverExistant, persister, modes, vider)
├── Contracts/ExportDefinitionInterface.php  (colonnes autorisées, formats,
│                                            requete scopée, filtrer, ligne)
├── Definitions/TeacherDefinition.php        (import + export)
├── Definitions/RoomDefinition.php
├── Definitions/GroupDefinition.php          (faculté code→id, scope faculté)
├── Definitions/MatiereDefinition.php
├── DefinitionRegistry.php                   (ajouter une entité = 1 définition ici)
├── DataImportService.php                    (preparer/analyser/revalider/
│                                            executer/confirmer/annuler)
├── DataExportService.php                    (filtres, sélection, anti-injection)
└── ImportResult.php                         (valides, erreurs, avertissements, aperçu)
```

Ajouter un module : créer une `XxxDefinition` (import et/ou export),
l'enregistrer dans `DefinitionRegistry`, ajouter l'entité aux `whereIn`
des routes web — rien d'autre.

## §5-18 Import

- 6 étapes UX : Fichier → Analyse → Validation → Prévisualisation →
  Confirmation → Résultat (stepper Design System).
- Templates téléchargeables : `/admin/donnees/{entite}/modele/{csv,xlsx}`.
- En-têtes : normalisation unique translittérée (`NormaliseurEnTetes` —
  « Prénom » → `prenom`), canonique prioritaire, alias, ambiguïté refusée.
- Prédiagnostic sans écriture (`ImportPrediagnosticService`) : mapping
  par colonne (canonique/alias/inconnue + suggestion jamais appliquée),
  requises manquantes motivées, encodage/délimiteur, volume, empreinte
  SHA-256 + déjà-traité. Refus propres (entité/format/fichier).
- Corrections automatiques tracées (`CorrecteurImportService`, règles
  `ESPACES/CHIFFRES/TELEPHONE/DATE@1.0`) : valeurs non ambiguës seules,
  champs sensibles (CIN/nom/prénom/e-mail/notes) jamais touchés,
  ambigu abstenu en `a_revoir`, idempotentes.
- Revue semi-automatique (`POST …/import/revoir`, `can:data.import`) :
  accepter / modifier (re-validé) / rejeter / attente, décisions
  individuelles, token+auteur+entité vérifiés, collision intra-lot
  refusée, revue entamée = revue complète exigée au confirm, trace
  (ligne+action, sans valeurs) dans le rapport.
- Doublons gradués (`DetecteurDoublonsService`) : exact fichier
  (`DUPLICATE_FILE` bloquant), mise à jour (avertissement), conflit
  d'identité et doublon potentiel (avertissement + `a_revoir`, jamais
  fusionnés), identité non résolue (entités nominatives seules).
  `EtudiantDefinition::trouverExistant` fail-closed (sans identifiant :
  aucun existant — plus d'écrasement du premier étudiant).
- Doublons fichier (`DUPLICATE_FILE`) + existants base (avertissement upsert).
- Rapport `ImportReport` (`type = data.{entite}`) + `IntegrationLog`
  (`data-exchange`, `request_id`).
- Rapport d'erreurs : `/admin/donnees/rapports/{id}/erreurs/{csv,json}`
  (`row,error_code,message,field,value`).

## Vérification après import (œil 👁️)

- Page `Voir` (`GET /admin/donnees/{entite}/donnees`, `can:data.import`) :
  inspection paginée (25/ligne) des enregistrements **en base** via la
  définition d'export (requête scopée faculté + colonnes blanchies).
  Accessible depuis le hub, le rapport d'import et l'historique — le
  bouton n'apparaît que si l'entité est exportable (`referentiel-mens`,
  import-seul, répond 404, jamais 500). Entités export-seul
  (`factures`, `anomalies_qualite`, `demandes`, `presences`) aussi
  visualisables ; `factures` exige `finances.view` comme l'export.
- Imports legacy (`/admin/integrations` : étudiants, notes, inscriptions,
  matières, résultats, documents) : bouton « Vérifier » sur chaque import
  **terminé**, vers l'entité Données correspondante (ou le module métier
  en repli selon les permissions, rien si non autorisé).

## §19-25 Export

- Filtres actifs respectés (`search`) + sélection (`ids[]`).
- Colonnes en liste blanche par définition — jamais `SELECT *`.
- Scope faculté appliqué (groupes : `faculteRestreinteId()`).
- Listes étudiants : `moyenne/crédits/décision` LUES sur l'inscription
  officielle de l'année affichée (source : délibérations, repli année la
  plus récente, vides si absentes — jamais calculées ; `crédits` =
  crédits validés, le total figure dans les exports académiques).
  Eager `inscriptionsAcademiques.anneeUniversitaire` par lots (pas de N+1).
- Anti CSV-injection : `CsvSerializer::neutraliserFormule()` + double
  garde `DataExportService::neutraliser()` (préfixe `'`).
- PDF : via modules existants (fiches, plannings) — les référentiels
  simples exposent CSV/XLSX/JSON (PDF non adapté à une table brute).

## §31-34 API

| Route | Scope | Throttle |
|---|---|---|
| `POST /api/v1/data/{entite}/import/preview` | `data:exchange` | `api-imports` |
| `POST /api/v1/data/{entite}/import/confirm` | `data:exchange` | `api-imports` |
| `GET /api/v1/data/{entite}/export/{format}` | `data:exchange` | `api-exports` |

- Stateless : fichier re-transmis à chaque phase (pas de session).
- `preview` = dry-run (aucune écriture) ; `dry_run=true` accepté et documenté.
- `confirm` : modes `creer|upsert` uniquement (pas de `remplacer` via API).
- Événements tracés : `data.import.*` / `data.export.*` (started/completed/
  failed via `IntegrationLog` + `request_id`).

## §35 Permissions

`data.import`, `data.export` (Gate auto via `Permissions`) :

- `super_admin`, `admin_scolarite`, `admin_faculte` : import + export.
- `agent_scolarite` : export seul.
- `consultation` : aucun.
- Jamais de `isAdmin()` seul : `can:data.import` / `can:data.export` sur
  chaque route + scopes API.

## §38-39 Tests

`tests/Feature/DataExchangeTest.php` (29 tests) : phases, vide, colonnes,
types, doublons, relation inexistante + rollback, JSON/XML, anti-XXE,
permissions (invité/sans droit), extension falsifiée, MIME incohérent,
exports CSV/XLSX/JSON, filtres, sélection, CSV-injection, colonnes
autorisées, modèle, historique, rapport d'erreurs, annulation, API
(401/403/dry-run/confirm/erreurs/export/404).

Mission import intelligent (LOOP MASTER) : `DataExchangeContratTest`
(contrats registre/formats/messages),
`tests/Unit/NormaliseurEnTetesTest` + `ImportPrediagnosticTest`
(mapping, accents, refus propres, zéro écriture), `CorrecteurImportTest`
(règles, sensibles, idempotence), `ImportRevueTest` (revue + revue
complète + collision), `DoublonsGraduesTest` (conflit, homonymes,
potentiel, fail-closed), `ImportSecuriteElargieTest` (entité, rejeu,
auteur, 403 export), `ExportResultatsAcademiquesTest` (source officielle,
N+1), `ImportCockpitTest` (sections, sans-JS), `Performance/
ImportExportBenchmarkTest` (mesures + bornes).

## §40 Performance

- `array_chunk(..., 500)` dans une transaction unique ; `limit(10000)` exports.
- Streaming (`streamDownload`) — pas de fichier temporaire.
- Gros volumes : découper (plafond documenté) ; au-delà de `queue_threshold`
  (500 lignes), bascule en `ExecuteDataImportJob` (staging `import_jobs/rows`,
  notification auteur) ; exports listes volumineux via `GeneratePopulationExport`.
- Mesures locales sqlite (`storage/app/benchmark/import-export.json`,
  jamais une garantie de production) : analyser ~2 req/ligne
  (0,46 s à 1000), exports 4-5 requêtes quel que soit N, similarité 3
  requêtes. Bornes anti-N+1 verrouillées par `ImportExportBenchmarkTest`.

## Import des notes — association automatique (mission notes)

- Entité `notes` (`NoteImportDefinition`) : colonnes
  `matricule, cin, date_naissance, code_matiere, annee, semestre, note,
  session` + alias (`N°CIN`, `Code étudiant`, `matiere`, `annee_universitaire`…,
  insensibles casse/espaces, ambiguïté refusée).
- Chaîne d'identification : CIN exact → matricule → `num_inscription` →
  CIN + date de naissance. Jamais de nom seul.
- `ImportValidationAvancee` (contrat optionnel, rétrocompatible) :
  `aliasesColonnes()`, `validerLigneMetier()` (codes `ETUDIANT_INTROUVABLE`,
  `MATIERE_INTROUVABLE`, `NIVEAU_INCOHERENT`, `NOTE_VERROUILLEE`,
  `HORS_PERIMETRE`, … joués en preview **et** à la confirmation),
  `cleMetier()` (doublon intra-fichier même via identifiants différents).
- Notes verrouillées refusées (correction autorisée hors import) ; scope
  faculté auteur (import + export) ; `formats()` = csv/xlsx/json/xml.
- Idempotence fichier : `import_reports.file_hash` (SHA-256) +
  avertissement `IMPORT_ALREADY_PROCESSED` ; confirmation idempotente
  (token à usage unique).
- Exemples : `docs/notes/exemple-notes.{csv,json,xml}` (+ modèle XLSX via
  `/admin/donnees/notes/modele/xlsx`).

## Reste à faire (réel)

- Streaming des très gros exports API (`limit(10000)` en mémoire, mesuré
  PHASE 10 : acceptable jusqu'ici, à streamer si le plafond doit monter).
- Transcodage auto des CSV non-UTF-8 (détecté et signalé par le
  prédiagnostic, jamais converti sans décision métier).
- Comparateur visuel côte-à-côte fiche base dans la revue (raisons et
  fiche #id déjà affichées).
- Revue multi-relecteurs / 4-eyes sur les décisions d'import.
- Export PDF planning imprimable unifié (existe par module).
- Import `API` push (webhooks HMAC existants couvrent l'ingestion externe).
