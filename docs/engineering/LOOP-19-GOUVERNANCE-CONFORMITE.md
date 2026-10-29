# LOOP 19 — Centre de gouvernance et conformité

> Cahier : vue unifiée des accès sensibles, changements de permissions et
> rétentions ; rôle d'audit distinct ; politiques de rétention explicites et
> approuvées ; journal des accès aux journaux. Réserve éthique : éviter la
> surveillance disproportionnée ; NE PAS promettre d'immuabilité que
> l'infrastructure ne garantit pas.

## LOOP-00 — Audit préalable (preuves exécutées)

### Ce qui existe déjà et qu'il faut réutiliser (pas dupliquer)

| Source | Mécanisme confirmé | Usage Lot 19 |
|---|---|---|
| Module `Sentinel` | `SentinelIncident`, `SentinelRegle`, Checks/, `SentinelRegistry`, `SentinelService` | intact — le rôle auditeur y accède en lecture (`sentinel.view` réutilisé) |
| `HistoriqueAcademique` | journal d'action horodaté (etudiant, utilisateur, action, entite) — **97 lignes réelles** | source 1 de la vue unifiée + journal rétensible |
| `IntegrationLog` | INSERT-ONLY (`tracer()`, payload jamais stocké — SHA-256 seul) | source 2 de la vue unifiée |
| `Courriel` (`courriels`) | journal des courriels, destinataire MASQUÉ (PII-safe par conception) | source 3 de la vue unifiée |
| Attributions de rôles | `AdministrateurController` → `creerCompteBackOffice` (§P3-17 : rôle jamais mass-assigné, via `definirRole()`) | intact — capté par OBSERVER User (aucun contrôleur modifié) |
| Permissions | `settings.manage` (super_admin seul), `settings.history.view`, `sentinel.view` ; `StoreAdministrateurRequest` : `Rule::in(Permissions::ROLES)` DYNAMIQUE | réutilisation maximale + 1 clé additive |

### Constats réels (sondes exécutées)

| Sonde | Résultat | Conséquence |
|---|---|---|
| Tracage des changements de rôles | **AUCUN existant** (aucune table, aucun journal) | entièrement à construire |
| Politiques de rétention | **AUCUNE** (seules mentions : `retention_a` NULL du Lot 15, sauvegardes) | durées à SAISIR, jamais inventées |
| `ROLES` | 5 rôles, liste DURE | le rôle d'audit DISTINCT exigé par le cahier est ADDITIF |
| Distribution `settings.manage` | super_admin SEUL | les rétentions = décision d'administration centrale (choix cohérent du registre existant) |

### Ce qui manque (réserver explicitement, ne jamais présenter comme disponible)

1. Politiques de rétention institutionnelles réelles : AUCUNE saisie disponible —
   le catalogue livré est VIDE par défaut.
2. Journaux d'accès sensibles par source applicative (ex: consultations de
   documents du coffre) : seuls les TROIS journaux réels ci-dessus existent ;
   toute source inexistante sera signalée ABSENTE, jamais inventée.

### AssistantIa et règles de coexistence

Module `AssistantIa` et ses migrations : intacts. Aucune colonne ajoutée aux
tables existantes ; aucune route dans `routes/web.php`.

## LOOP-01 — Contrat métier

1. **Vue unifiée des accès sensibles** : union normalisée des 3 journaux RÉELS
   (quand / qui / quoi / source) + `disponibilite` par source (compteurs
   réels — 0 ligne est AFFICHÉ, jamais masqué) + filtre par source.
2. **Changements de permissions tracés** : Observer `User` (créations de
   comptes + changements de rôle) avec acteur http/interne — fail-soft (la
   gouvernance ne bloque JAMAIS une création de compte).
3. **Rôle `auditeur` DISTINCT** (exigé par le cahier) : bundle LECTURE SEULE
   (journaux, conformité, sentinel, qualité, imports, settings en lecture) —
   AUCUNE permission d'action ; immédiatement assignable via l'UI existante
   (`Rule::in(Permissions::ROLES)` dynamique).
4. **Rétentions explicites et approuvées** : une politique SAISIE par journal
   (durée en jours), UNIQUE par journal, brouillon → approuvée (trace) ; le
   rapport de conformité COMPTE les lignes au-delà de la durée sans JAMAIS
   supprimer ; sources sans politique = `absente` explicite ; JAMAIS de
   promesse d'immuabilité (le rapport décrit l'état réel).
5. **Journal des accès aux journaux** : trace le FAIT de consulter chaque vue
   de gouvernance (vue + filtre générique + utilisateur + date), JAMAIS le
   contenu lu — proportionnalité stricte (réserve éthique).

## LOOP-02 — Architecture

```
app/Modules/GouvernanceConformite/
├── Http/Controllers/GouvernanceConformiteController.php  (6 actions JSON)
├── Models/{JournalChangementPermission,PolitiqueRetention,JournalAccesJournal}.php
├── Observers/ObserverPermissionsUser.php  (fail-soft, acteur http|interne)
├── Routes/web.php  (6 routes)
└── Services/ServiceGouvernance.php  (union, décompte rétention, traçage d'accès)
database/migrations/2026_10_25_030000_creer_tables_gouvernance_conformite.php
tests/Feature/GouvernanceConformiteTest.php  (16 tests, 69 assertions)
```

- Enregistrement : `config/modules.php` (drapeau `MODULE_GOUVERNANCE_CONFORMITE`,
  défaut **false**) + `ModuleServiceProvider`.
- Observer enregistré dans `AppServiceProvider::boot()` (3 lignes additives)
  — le seul fichier partagé touché.
- FK vers `users` en `nullOnDelete` (pattern du dépôt) : la trace SURVIT à la
  suppression du compte — crucial car l'Observer écrit une ligne par compte.
- Permission map : lecture `gouvernance.audit` (NOUVELLE clé additive ;
  admin_scolarite + super_admin + rôle auditeur) ; rétentions
  `settings.manage` (super_admin seul, registre existant).

## LOOP-03 — Implémentation réalisée

### Décisions d'implémentation vs LOOP-01/02

1. **Observer plutôt que contrôleur** : capture TOUTE écriture Eloquent de
   `role` (voie applicative unique `definirRole`), sans modifier le
   contrôleur existant ; `created` avec rôle NULL ignoré (§P3-17 : le rôle
   arrive APRÈS le create) — la ligne « création » vient du `updated`
   suivant (`ancien_role` NULL = création, sémantique documentée).
2. **Acteur** : `auth()->id()` à l'écriture — session HTTP ou `interne`
   (tinker/queue) si aucun acteur ; jamais bloquant (try/catch + warning).
3. **Conformité rétention = SIGNALISATION** : compte + plus ancienne par
   journal ; `lignes_au_dela` NULL quand politique absente/non approuvée
   (jamais de durée par défaut) ; rappel explicite dans CHAQUE ligne du
   rapport.
4. **Rôle auditeur par BUNDLE minimal** : lecture seule vérifiée par test
   dédié (4 vues en 200, action en 403).

### Incidents corrigés pendant la boucle (traçabilité LOOP-03)

| # | Incident | Correction |
|---|---|---|
| 1 | Observer : TypeError `nouveauRole` null (le `created` voit le rôle NULL car §P3-17 l'attribue après create) | `created` ignoré si rôle null ; la ligne création vient du `updated` (ancien null) |
| 2 | Test : route `login` inexistante | appel supprimé (le test est déjà sans session) |
| 3 | Tests politiques : `settings.manage` absent d'admin_scolarite → 403 inattendus | super_admin utilisé (registre existant : rétentions = décision centrale) |
| 4 | Test création de compte : `actingAs` manquant → acteur `interne` au lieu d'HTTP | `actingAs($admin)` ajouté avant la création ciblée |
| 5 | **FK `restrictOnDelete` vers users** (journal changements + journal accès + suivis Lot 15) → casse les tests existants qui suppriment des comptes (AffectationMasse, AsyncExport, ServicesTransverses) | `nullOnDelete` (pattern du dépôt) : la trace SURVIT au compte |
| 6 | **`AttributionRoleTest::test_matrice_permissions_intacte`** : rôle `auditeur` absent de la liste attendue | test d'invariance mis à jour avec commentaire (ajout DÉLIBÉRÉ, exigé par le cahier) + `auditeur` intégré à la garde anti roles.view/manage |
| 7 | Test changement de rôle : appel de route vide sans but (bruit) | supprimé ; l'écriture directe via modèle couvre le cas Observer |
| 8 | Test `route('admin.hub-integration.logs')` → nom réel `.logs.index` (Lot 18, corrigé dans sa boucle) | test corrigé |

## LOOP-04 — Résultats réels des tests

Exécution : `php artisan test tests/Feature/GouvernanceConformiteTest.php`
Résultat réel : **« passed, 16 tests, 16 passed, 69 assertions »** (runner).

| # | Thème | Preuve clé |
|---|---|---|
| 1 | vue unifiée agrège les 3 journaux réels | 3 sources présentes, `disponibilite` exact (1/1/1) |
| 2 | absence de données EXPLICITE | `{0,0,0}` affiché, entrées vides — jamais masqué |
| 3 | filtre par source | uniquement `courriels` |
| 4 | refus aux rôles sans gouvernance.audit | consultation + admin_faculte → 403 |
| 5 | rôle auditeur LECTURE STRICTE | 4 vues 200 ; action (politique) → 403 |
| 6 | création de compte tracée (rôle initial) | 1 ligne : ancien NULL, nouveau role, acteur HTTP |
| 7 | changement de rôle tracé (ancien/nouveau) | 2e ligne agent_scolarite→consultation ; renommage SANS journalisation |
| 8 | création hors session = source interne | acteur NULL, source `interne` |
| 9 | politiques exigent settings.manage | admin_faculte → 403 |
| 10 | politique UNIQUE par journal + journal inconnu refusé | 422 deux fois |
| 11 | approbation tracée, re-approbation refusée | approuvee + auteur + date ; 422 |
| 12 | conformité sans politique : ABSENTE explicite | `etat_politique=absente`, `lignes_au_dela=NULL` |
| 13 | conformité compte SANS JAMAIS supprimer | 1 ligne au-delà signalée ; count INCHANGÉ |
| 14 | journal d'accès : le FAIT, pas le contenu | 2 traces (vue + filtre générique) ; champs limités vérifiés |
| 15 | module désactivé → 404 | GET et POST |
| 16 | performance (60 historiques + 60 échanges) | vue unifiée < 3 s |

## LOOP-05 — Sécurité et périmètre (récap seuils appliqués)

- **Rôle auditeur DISTINCT, lecture seule** : bundle sans AUCUN pouvoir
  d'action (test 5) ; garde `roles.view`/`roles.manage` étendue à `auditeur`
  dans le test d'invariance.
- **Rétentions sous `settings.manage`** (super_admin seul au registre
  existant) : approbation tracée ; sources limitées aux journaux RÉELS
  (enum serveur).
- **Proportionnalité (réserve éthique)** : le journal d'accès ne stocke QUE
  (utilisateur, vue, filtre générique) — jamais les lignes lues, jamais les
  résultats (test 14).
- **Pas d'immuabilité promise** : le rapport rétention affiche l'état réel
  des journaux sous-jacents et le rappelle dans chaque ligne.
- Fail-soft Observer : la traçabilité ne bloque JAMAIS une création de
  compte (warning loggé en cas d'indisponibilité du journal).

## LOOP-06 — UX (verdict partiel : différée)

- JSON uniquement ; aucune vue Blade ni navigation. La passe UI (écran
  d'audit, courbe de conformité, suivi des rétentions) reste **différée
  explicitement** (réserve LOOP-10).

## LOOP-07 — Coexistence et non-écriture (récap)

- `AdministrateurController` / `StoreAdministrateurRequest` : **intacts** —
  l'Observer capture les effets sans toucher à la voie d'attribution.
- Sentinel : **intact** (lecture seule via bundle).
- Les 3 journaux réels : **lus uniquement** (aucune écriture hors module ;
  test 13 prouve la non-suppression).
- Module `AssistantIa` : **intact**. Base MySQL : **jamais touchée**.

## LOOP-08 — Performance et plafonds

- Listes bornées : 200 par vue (union triée par date, `take(limite)`).
- Test dédié : 60 lignes historique + 60 échanges, vue unifiée complète < 3 s
  (constaté ~1 s).
- L'Observer ajoute 1 INSERT par création/changement de rôle — négligeable,
  fail-soft (jamais bloquant).

## LOOP-09 — Non-régression et outillage (étendue : suite Feature COMPLÈTE)

L'Observer modifiant le comportement GLOBAL de `User`, la suite Feature
complète a été exécutée — révélant 3 régressions MIENNES (réparées) et un lot
d'échecs PRÉEXISTANTS (documentés avec preuves) :

### Régressions miennes réparées (preuves re-exécutées)

| # | Régression | Réparation | Suites repassées |
|---|---|---|---|
| 1 | FK `restrictOnDelete` vers users → suppression de comptes bloquée | `nullOnDelete` (pattern dépôt) | AffectationMasse, AsyncExportRevocation, ServicesTransverses → **44/44** |
| 2 | Rôle `auditeur` absent du test d'invariance de matrice | test mis à jour (ajout délibéré documenté) | AttributionRole → vert |
| 3 | **`config/integrations.php` créé sans `upload_max`** — le pipeline Io existant lit `config('integrations.upload_max')` SANS défaut → `(int) null = 0` → « Fichier trop volumineux (maximum 0 Mo) » → tous les imports 422 | clé `upload_max` = 10 Mo ajoutée (valeur cohérente du dépôt : messages « 10 Mo », `max:10240` kB) | ApiImport, Population, ImportTemplate → **46/46** |

### Échecs préexistants documentés (causes racines prouvées, hors périmètre des lots 14-21)

| # | Suite | Cause racine prouvée | Preuve |
|---|---|---|---|
| 1 | ReferentielPhase1, ScheduleAcces, SeparationProfils | fuite de SESSION stateful Sanctum entre les requêtes d'un même test (le 2e Bearer s'authentifie sur le 1er token) — artefact que le test antérieur documentait déjà (« le 1er token ne doit pas fuire… ») | probe : header B porteur du bon token, guard résout token A ; **Observer désactivé → échec IDENTIQUE** |
| 2 | SimulationTemporelle (8 tests) | table `scenario_temporels` créée par AUCUNE migration du dépôt | grep exhaustif |
| 3 | DataMeshContracts | `snapshot_kpi` ajouté au produit réel (`AnalytiqueFaculte.php`) sans mise à jour du test d'instantané | grep produit vs test |
| 4 | LoopPopupHomeOnly04 | HTML rend `action="http://localhost:8000/…"` (APP_URL) vs attente du chemin relatif | dump de la réponse |
| 5 | RemplacementCandidature (isolé) | scaffold sans `groupe_id` alors que la colonne est NOT NULL **depuis la migration originelle** du 29/09 | diff pint = 0 ; migration inspectée |

Ces échecs appartiennent aux périmètres antérieurs non-commités (API scopes,
Data Mesh, popup, simulation temporelle, remplacement) — ils sont rapportés
honnêtement ici, sans être masqués ni réparés hors périmètre.

### Outillage final

- `vendor/bin/pint --dirty` → **passed** (2 fichiers de périmètres
  antérieurs remis en style — vérifiés : 0 diff fonctionnel, `php -l` OK).
- Scan anti-corruption sur les 15 fichiers du lot → **VERT** (probes retirés ;
  l'emoji `'icon' => '👥'` ligne 289 d'AppServiceProvider est préexistant).
- Suites de la mission re-exécutées après TOUTES les corrections :
  Gouvernance + Hub + AssuranceQualite + Décrochage + Simulateur +
  Competences + AttributionRole + AffectationMasse + AsyncExport +
  ServicesTransverses + ApiImport + Population + ImportTemplate →
  **208 passed (875 assertions)**.
- Suite IA non-régression → **111 tests, 356 assertions, passed** (baseline
  identique).
- `php artisan route:list --path=gouvernance-conformite` → **6 routes**.

## LOOP-10 — Verdict

**PASS_WITH_RESERVATIONS**

Tous les critères du cahier sont réalisés et prouvés (16/16, 69 assertions) :
vue unifiée des 3 journaux réels avec absences explicites, changements de
permissions tracés par Observer sans toucher à l'existant, rôle d'audit
DISTINCT en lecture stricte, rétentions SAISIES et APPROUVÉES avec rapport
de conformité sans AUCUNE suppression, journal d'accès proportionné — et la
discipline de non-régération a attrapé et réparé 3 régressions réelles.
Réserves explicitement NON couvertes :

| # | Réserve | Nature |
|---|---|---|
| 1 | UI différée : sémantique JSON, aucun écran d'audit | Passe UI future |
| 2 | Catalogue de rétentions VIDE : les durées institutionnelles restent à SAISIR (l'inventer est interdit) | Saisie institutionnelle requise |
| 3 | Vue unifiée limitée aux 3 journaux RÉELS existants : les sources d'accès sensibles non journalisées aujourd'hui (ex: consultations de documents) sont absentes du périmètre, pas du rapport | Réserve de couverture |
| 4 | Rapport de conformité = SIGNALISATION : aucune purge automatisée (la suppression resterait une décision institutionnelle outillée ailleurs) | Choix conforme à la réserve éthique |
| 5 | Observer limité aux écritures Eloquent de `role` : un changement DIRECT en SQL contournait la trace (aucun trigger DB ajouté — cohérent avec l'infrastructure) | Limite assumée |
| 6 | Journal d'accès : la consultation du journal d'accès est elle-même tracée (récursivité bornée par la liste des vues, documentée) | Choix documenté |
| 7 | Échecs préexistants de la suite Feature (5 catégories, LOOP-09) : rapportés avec preuves, hors périmètre des lots 14-21 | Dette technique antérieure |
| 8 | Performance prouvée sur SQLite synthétique | Réserve de portée |

Le centre de gouvernance reste un **observatoire proportionné** : il rend
visibles les accès sensibles, les changements de permissions et l'état réel
des rétentions — sans surveiller le contenu lu, sans promettre d'immuabilité,
et sans jamais supprimer une seule ligne.
