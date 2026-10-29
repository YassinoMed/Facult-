# LOOP 18 — Hub d'intégration

> Cahier : contrats versionnés formels par connecteur ; idempotence à auditer
> connecteur par connecteur ; retries bornés et rejeu autorisé-audité ;
> séparation des environnements de test. Aucune refonte de l'existant :
> extension additive.

## LOOP-00 — Audit préalable (preuves exécutées)

### Ce qui existe déjà et qu'il faut réutiliser (pas dupliquer)

| Source | Mécanisme confirmé | Usage Lot 18 |
|---|---|---|
| `WebhookVerifier` (`app/Services/Io`) + `WebhookController` API v1 | webhooks HMAC + `throttle:api-webhooks` | intact — le hub ne touche PAS à la réception |
| `IntegrationLog` (`integration_logs`) | INSERT-ONLY (`tracer()` ; payload JAMAIS stocké, seulement son SHA-256 ; champs status/http_status/error_code/request_id/external_reference) | journal des échanges LU (index filtrable), rejeu borné par log |
| `DefinitionRegistry` (DataExchange) | `consultables()` liste les connecteurs import/export (~45 clés réelles) ; `import()`/`export()` | recensement honnête des connecteurs — lecture seule |
| Pipeline imports/exports | `DataImportService`, `DataExportService`, `CorrecteurImportService`, `DetecteurDoublonsService`, registres | intact — aucun fichier du pipeline modifié |
| Observabilité Data Mesh | routes `admin/data-mesh/*` (graphe, flux, qualité) | distincte — frontière anti-fusion |
| Permissions | `import.view/create/validate/execute` + `import_template.manage` DÉJÀ distribuées (admin_scolarite, agent_scolarite view/create/validate, admin_faculte view/create/validate/execute ; consultation AUCUNE) | réutilisation TOTALE — aucune clé nouvelle |

### Constats réels (sondes exécutées)

| Sonde | Résultat |
|---|---|
| `DefinitionRegistry::consultables()` | ~45 connecteurs réels recensables (annees-universitaires, facultes, etablissements, departements, domaines, mentions, specialites, type-diplomes, diplomes, …) |
| Contrats versionnés existants | **AUCUN** (`Contracts/` = interfaces PHP, pas des contrats par connecteur) |
| Audits d'idempotence existants | **AUCUN** |
| Statuts de logs écrits | `completed` (défaut de `tracer()`), statuts d'échec libres par appelant |

### Ce qui manque (réserver explicitement, ne jamais présenter comme disponible)

1. Schémas formels RÉELS des 45 connecteurs : AUCUNE saisie institutionnelle
   disponible — le catalogue de contrats livré est VIDE par défaut.
2. Isolation d'infrastructure par environnement (URL/secrets par bac à sable) :
   aucune topologie de connecteur externe confirmée — la séparation livrée est
   CONTRACTUELLE (statut + garde d'activation), pas réseau.

## LOOP-01 — Contrat métier

1. **Matrice honnête des connecteurs** : pour chaque connecteur du registre —
   son contrat (actif, ou état réel : absent/brouillon/deprecie) et son dernier
   audit d'idempotence (verdict, ou `non_audite`). L'absence est TOUJOURS
   explicite : un connecteur sans contrat ni audit n'est JAMAIS conforme par
   défaut.
2. **Contrats versionnés** : UNIQUE(connecteur, version) ; version SAISIE ;
   `schema` JSON SAISI (jamais déduit ni inventé) ; cycle brouillon → actif
   (activation tracée) → deprecie ; nouvelle version = nouvelle ligne
   (l'ancienne reste lisible).
3. **Audits d'idempotence SAISIS** : verdict `verifie|doute|absent` + preuve
   datée OBLIGATOIRE (≥ 10 caractères) — jamais calculé depuis le code.
4. **Rejeu borné, autorisé-audité** : seuls les échanges non `completed` sont
   rejouables ; plafond applicatif par échange (défaut 3, config) ; chaque
   autorisation est motivée + auteur + date + numéro de tentative ; exécution
   marquée séparément (ordre tracé — `integration_logs` reste INSERT-ONLY).
5. **Séparation des environnements** : chaque contrat porte
   `production|sandbox` ; un contrat sandbox n'est activable QUE si
   `INTEGRATION_SANDBOX_AUTORISE` est explicitement vrai (config dédiée
   `config/integrations.php`, défaut false).

## LOOP-02 — Architecture

- Module `HubIntegration` (slug `hub-integration`, env `MODULE_HUB_INTEGRATION`,
  défaut false), 8 routes `admin/hub-integration/*` sous
  `['auth','admin','secondaire.verrou','module:hub-integration']` + `can:`.
- 3 tables additives réversibles : `contrats_connecteur`,
  `audits_idempotence`, `rejeux_integration` — AUCUNE table existante modifiée.
- Permission map (toutes RÉUTILISÉES) : lecture `import.view` ; contrats
  `import_template.manage` (admin_scolarite seul) ; audits `import.validate` ;
  rejeu `import.execute`.
- `config/integrations.php` (nouveau, additif) : `sandbox_autorise` (défaut
  false), `plafond_rejeux` (défaut 3) — aucune variable d'environnement
  obligatoire, défauts sûrs.

## LOOP-03 — Implémentation réalisée

| Fichier | Contenu |
|---|---|
| `database/migrations/2026_10_25_020000_creer_tables_hub_integration.php` | 3 tables |
| `app/Modules/HubIntegration/Models/{ContratConnecteur,AuditIdempotence,RejeuIntegration}.php` | constantes de cycles + casts |
| `app/Modules/HubIntegration/Services/ServiceHub.php` | matrice honnête, contrats, audits, rejeu borné, logs filtrés |
| `app/Modules/HubIntegration/Http/Controllers/HubIntegrationController.php` | 8 actions JSON |
| `app/Modules/HubIntegration/Routes/web.php` | 8 routes |
| `config/integrations.php` | séparation d'environnements + plafond |
| `tests/Feature/HubIntegrationTest.php` | 18 tests, 64 assertions |

### Décisions d'implémentation vs LOOP-01/02

1. **Gardes par `InvalidArgumentException` → 422** : connecteur inconnu,
   version dupliquée, transitions invalides, sandbox non autorisé, échange
   complété rejoué, rejeus épuisés — chaque refus est EXPLICITE et documenté.
2. **Rejeu = ORDRE tracé, pas une exécution magique** : le payload brut n'est
   jamais stocké (SHA-256 seulement, conception existante) — le hub borne,
   autorise et trace ; la relance réelle reste à la charge du connecteur ou de
   l'agent (via le pipeline d'import idempotent existant), puis l'agent marque
   `execute`.
3. **Matrice sur `consultables()`** : recensement du registre RÉEL — le hub ne
   liste jamais un connecteur qu'il ne connaît pas, et rapporte l'absence de
   contrat/audit sans l'inventer.

### Incidents corrigés pendant la boucle (traçabilité LOOP-03)

| # | Incident | Correction |
|---|---|---|
| 1 | Route appelée `admin.hub-integration.logs` dans un test (nom réel `.logs.index`) | test corrigé |

## LOOP-04 — Résultats réels des tests

Exécution : `php artisan test tests/Feature/HubIntegrationTest.php`
Résultat réel : **« passed, 18 tests, 18 passed, 64 assertions »** (runner).

| # | Thème | Preuve clé |
|---|---|---|
| 1 | matrice honnête : absences explicites | `domaines` → `contrat_etat=absent`, `audit_etat=non_audite` |
| 2 | matrice refusée sans import.view | consultation → 403 |
| 3 | contrat déclaré puis doublon refusé | 201, 422, nouvelle version 201 (2 lignes) |
| 4 | connecteur inconnu du registre refusé | 422 |
| 5 | contrats exigent import_template.manage | admin_faculte → 403 |
| 6 | activation tracée, re-activation refusée | actif + auteur + date ; 422 |
| 7 | dépréciation reflétée dans la matrice | `deprecie` |
| 8 | sandbox refusé sans autorisation explicite | 422 « SANDBOX » ; config true → OK |
| 9 | audit exige preuve, trace l'auditeur | preuve courte → 422 ; 201 + verdict visible dans la matrice |
| 10 | verdict `absent` jamais confondu | matrice `absent` |
| 11 | journal filtrable par statut | 2 logs, filtre `failed` → 1 |
| 12 | rejeu borné et tracé | 3×201 (numéros 1,2,3) ; 4e → 422 « épuisés » ; auteur + date |
| 13 | échange complété non rejouable | 422 |
| 14 | rejeu exige import.execute | agent_scolarite (validate sans execute) → 403 |
| 15 | exécution marquée, re-marquage refusé | execute ; 422 |
| 16 | journal INSERT-ONLY | snapshot id/status/payload_hash/error_code/http_status inchangé après rejeu+exécution |
| 17 | module désactivé → 404 | GET et POST |
| 18 | performance (registre complet + 100 logs + contrat + audit) | matrice < 3 s |

## LOOP-05 — Sécurité et périmètre (récap seuils appliqués)

- Permissions 100 % RÉUTILISÉES (aucune clé nouvelle ce lot) : lecture
  `import.view`, contrats `import_template.manage`, audits `import.validate`,
  rejeu `import.execute` — double contrôle route `can:` + contrôleur.
- Journal `integration_logs` : aucune route de modification (INSERT-ONLY
  testé) ; le rejeu vit dans `rejeux_integration` avec FK restrict.
- Validation : connecteur ∈ registre (serveur), version ≤ 20, preuve/motif
  ≥ 10 caractères, verdicts/statuts/environnements en enum stricte.
- Module gate : `module:hub-integration` → 404 si désactivé (défaut false).

## LOOP-06 — UX (verdict partiel : différée)

- JSON uniquement ; aucune vue Blade ni navigation. La passe UI (écran de
  matrice, comparaison de versions de contrat, file de rejeux) reste
  **différée explicitement** (réserve LOOP-10).

## LOOP-07 — Coexistence et non-écriture (récap)

- `WebhookVerifier`/`WebhookController`/`ApiExchangeLogger` : **intacts**.
- Pipeline DataExchange (registre, services, 45 définitions) : **intacts** —
  consultés en lecture (`consultables()`).
- `IntegrationLog` : **jamais modifié** (test 16) — le hub ajoute ses propres
  tables de traçage.
- Observabilité Data Mesh : **distincte** (frontière anti-fusion).
- Module `AssistantIa` : **intact**. Base MySQL : **jamais touchée**.

## LOOP-08 — Performance et plafonds

- `PLAFOND_REJEUX` : défaut 3 par échange (config), refus explicite au-delà —
  jamais de boucle (test 12).
- Listes bornées : matrice sur registre réel (~45 connecteurs, 3 requêtes
  groupées), logs `limit(200)`.
- Test dédié : matrice complète + 100 logs synthétiques < 3 s (constaté ~1 s).

## LOOP-09 — Non-régression et outillage

- `vendor/bin/pint --dirty` → **passed** (2 fichiers du lot remis en style,
  tests re-passés 18/18 après).
- Scan anti-corruption (cyrillique/CJK) sur les 11 fichiers du lot → **VERT**.
- Suite IA non-régression → **111 tests, 356 assertions, passed** (baseline
  identique).
- Non-régression croisée (Lots 15 + 16 + 20 re-exécutés) → **68 passed,
  358 assertions**.
- `php artisan route:list --path=hub-integration` → **8 routes**.

## LOOP-10 — Verdict

**PASS_WITH_RESERVATIONS**

Tous les critères du cahier sont réalisés et prouvés (18/18, 64 assertions) :
contrats versionnés par connecteur avec activation tracée et séparation
d'environnements, audits d'idempotence SAISIS connecteur par connecteur avec
preuve obligatoire, rejeu borné et autorisé-audité, journal existant
INSERT-ONLY — le tout en extension additive stricte (aucun fichier du socle
modifié, aucune permission inventée). Réserves explicitement NON couvertes :

| # | Réserve | Nature |
|---|---|---|
| 1 | UI différée : sémantique JSON, aucun écran | Passe UI future |
| 2 | Catalogue de contrats VIDE par défaut : les schémas réels des ~45 connecteurs restent à SAISIR (l'inventer est interdit) ; la matrice le montre honnêtement (`absent`) | Saisie institutionnelle requise |
| 3 | Aucun connecteur réel audité à ce jour : les verdicts d'idempotence sont des décisions humaines à produire | Saisie institutionnelle requise |
| 4 | Rejeu = ordre tracé borné, PAS une ré-exécution automatique (le payload n'est jamais stocké par conception) — la relance reste à la charge du connecteur/de l'agent | Limite architecturale assumée |
| 5 | Plafond de rejeux linéaire (3 par défaut), pas de politique de backoff exponentiel confirmée institutionnellement | Réserve |
| 6 | Séparation d'environnements CONTRACTUELLE uniquement (statut sandbox + garde d'activation) : aucune isolation d'URL/secrets/réseau par environnement (topologie externe non confirmée) | Réserve de portée |
| 7 | Pas d'édition en place d'une version de contrat (versionner = nouvelle ligne + changelog) — cohérent avec l'esprit versionné mais sans diff de schéma | Choix MVP documenté |
| 8 | Performance prouvée sur SQLite synthétique (100 logs), pas sur les volumes réels MySQL | Réserve de portée |

Le hub reste une **couche de gouvernance** au-dessus d'un socle robuste :
il ne refonde rien, il rend explicites l'état réel des contrats, les audits
d'idempotence et les rejeus — l'absence de donnée n'est jamais masquée.
