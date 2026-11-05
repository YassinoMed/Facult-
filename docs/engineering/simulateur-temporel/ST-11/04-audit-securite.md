# ST-11 — 04 · Audit de sécurité approfondi (Phase B)

> **Cohérence inter-documents (2026-11-02).** Les constats de ce rapport sont réconciliés avec `15-plan-corrections.md` (tableau « Compléments post-verdict ») et `18-verdict-ST11.md` (annexe Phase B/C). Correspondance principale : **B1 ↔ ST11-ANO-01** (correction P0-1 déjà appliquée dans le WIP) ; réserves B2–B6 = **ST11-SEC-R1..R10**. Verdict global inchangé : `PASS_WITH_RESERVATIONS`, aucune anomalie critique ou élevée.

**Date :** 2026-11-02 · **Mode :** AUDIT_ONLY + TESTING · **Périmètre :** `SimulationTemporelle`
**Sources :** routes (`Routes/web.php`), contrôleur (408 l.), services délégués, catalogue `app/Support/Permissions.php`, `AppServiceProvider` (Gate), vendor `ThrottleRequests`, suite de tests.

---

## 1. B1 — Permissions : rectification de l'audit de Phase A (preuve)

> **Rectification importante.** La matrice de `02-audit-routes-permissions.md` (Phase A) indiquait `statistiques.view` sur les 15 routes. Cette lecture reflétait l'état **commité** (`6a08e62 version final`). Le **working tree (WIP)** contient une correction **P0-1 complète** (permissions dédiées), non commitée. Preuve : `git diff HEAD` sur 3 fichiers (`Routes/web.php` +45/−, contrôleur 32 lignes, `Permissions.php` +62).

### 1.1 État réel (working tree)

| Famille | Permission dédiée | Routes |
|---|---|---|
| Consultation | `simulation.view` | index, `scenarios.index`, `options.niveaux`, `options.groupes`, `audit-qualite`, `executions.index`, `executions.detail` |
| Gestion | `simulation.gerer` | `scenarios.creer`, `scenarios.dupliquer`, `scenarios.nouvelle-version` |
| Exécution | `simulation.executer` | `scenarios/{id}/executer` |
| Export | `simulation.export` | `export/csv`, `export/pdf` |
| Explication | `simulation.explication` | `expliquer-ia` |
| Gouvernance | `simulation.gouvernance.proposer` | `gouvernance/proposer` |
| Gouvernance | `simulation.gouvernance.decider` | `gouvernance/decider` |

- Route + contrôleur : les 15 routes portent le middleware `can:simulation.*` **et** le contrôleur re-vérifie le même gate (double barrière cohérente — plus aucun `statistiques.view` dans le module : `git diff` le remplace sur toutes les méthodes).
- Catalogue : les 7 permissions sont déclarées dans `Permissions::TOUTES` (l.228-234) avec commentaire P0-1 explicite (« la consultation n'autorise plus aucune écriture ni décision »).
- Attribution : **aucun rôle existant n'est élargi automatiquement** ; seul `super_admin => self::TOUTES` (l.412) les détient. Commentaire de matrice : « Attribution aux rôles : décision institutionnelle ».
- Résolution Gate : `Gate::define($permission, fn ($user) => $user->hasPermission($permission))` (`AppServiceProvider` l.210) → toute permission du catalogue est une ability canonique ; `can:simulation.gerer` est valide sans code ad hoc.

### 1.2 Tests de non-régression déjà présents (et verts)

| Test | Ce qu'il prouve |
|---|---|
| `st11_sec_011_consultation_refuse_partout` | un rôle détenant `statistiques.view` mais aucune permission `simulation.*` est refusé partout |
| `st11_sec_012_agent_sans_habilitation_ne_decide_pas` | `agent_scolarite` (avec `statistiques.view`) ne peut pas décider en gouvernance → 403 |
| `st11_sec_013_permissions_dediees_non_attribuees_aux_anciens_roles` | les 12 rôles historiques ne reçoivent **aucune** permission `simulation.*` ; seul `super_admin` les a |

Exécution mesurée : `php artisan test --filter="st11"` → **4 tests PASS, 74 assertions** (dont `st11_math_014`).

### 1.3 Décision restante (Phase N — institutionnelle)

Quels rôles (hors `super_admin`) doivent recevoir `simulation.view` / `.gerer` / `.executer` / `.export` / `.explication` / `.gouvernance.proposer` / `.gouvernance.decider` ? **Aucune attribution automatique n'est acceptable** ; l'état actuel (super_admin seul) est sûr mais restrictif. À trancher par la gouvernance, sans nouvelle migration (mécanisme catalogue existant).

**Verdict B1 : conforme au principe de moindre privilège. Écart P0-1 déjà corrigé dans le WIP.**

---

## 2. B2 — Isolation facultaire

### 2.1 Mécanisme canonique

`FacultePerimetre::resoudre(User, ?int)` (`app/Modules/SimulateurScenarios/Support/FacultePerimetre.php` l.26-33) :
- faculté demandée hors périmètre → `AuthorizationException` (403) ;
- retour = `faculteRestreinteId() ?? demandée` → un admin restreint est **forcé** sur sa faculté même en forgeant `?faculte_id=`.
`User::peutAccederFaculte()` (l.214-219) : `restreinte === null || restreinte === faculteId`.

### 2.2 Matrice des contrôles (relevés dans le code courant)

| Surface | Contrôle | Emplacement |
|---|---|---|
| `scenarios.index` | filtre `where('faculte_id', restreinte)` | contrôleur |
| `scenarios.creer` | `peutAccederFaculte(faculte_id)` → 403 | contrôleur |
| `scenarios.dupliquer` / `nouvelle-version` | 403 si scénario hors périmètre | contrôleur |
| `executer` | 403 si scénario hors périmètre | contrôleur |
| `executions.index` | `whereHas('scenario', faculte)` | contrôleur |
| `executions.detail` | 403 (scénario) — snapshots chargés **après** contrôle, même exécution | contrôleur |
| `export.csv` / `export.pdf` | 403 (scénario) | contrôleur |
| `options.niveaux` / `options.groupes` | `FacultePerimetre::resoudre` (403 hors périmètre) | contrôleur |
| `audit-qualite` | `FacultePerimetre::resoudre` | contrôleur |
| `expliquer-ia` | `peutAccederFaculte` **avant** toute lecture d'explication | `ExplicateurIaSimulation` l.41-43 |
| `gouvernance.proposer` | `peutAccederFaculte` avant enregistrement | `GouvernancePlanificationService` l.37-39 |
| `gouvernance.decider` | `peutAccederFaculte` avant décision | idem l.112-114 |

Aucune divulgation de contenu avant contrôle : les `findOrFail` ne renvoient que des métadonnées d'existence, le payload n'est servi qu'après vérification.

### 2.3 Réserves B2

| ID | Constat | Gravité |
|---|---|---|
| ST11-SEC-R1 | **Canal 403/404** : sur une exécution d'une autre faculté, `findOrFail` passe puis 403 → un acteur restreint peut distinguer un ID existant d'un ID inexistant (fuite d'existence, pas de contenu). Convention dépôt : 404 partout pour les ressources hors périmètre. | FAIBLE |
| ST11-SEC-R2 | `executer` valide `annee_universitaire_id` (`exists`) mais ne la rattache ni à la faculté ni au statut actif (EC-05) → exécution possible sur une année hors périmètre temporel de la faculté (pertinence des données, pas confidentialité). | MOYEN |
| ST11-SEC-R3 | Scénarios `faculte_id = null` : invisibles des admins restreints (`where faculte_id = restreinte` exclut NULL), visibles des admins globaux. Sémantique « transversal » à confirmer (EC-06). | MOYEN (décision) |

---

## 3. B3 — Protection des snapshots

| Contrôle | Résultat | Preuve |
|---|---|---|
| Chemins d'écriture | **1 seul** : `ServiceSimulationTemporelle::executer` l.348-356 (une ligne par pas, variante référence) | grep exhaustif du module (cf. 05 §4) |
| Routes d'écriture/lecture | aucune route ne crée/modifie/supprime un snapshot ; lecture uniquement via `executions.detail` (même contrôle facultaire que l'exécution) | `Routes/web.php` |
| Modification par service | aucun `update`/`delete` sur `SnapshotExecution` | grep module |
| Réaffectation | FK `execution_temporelle_id` + `cascadeOnDelete` ; pas de colonne `scenario_id` mutable | migration `2026_10_25_040000` |
| PII | `etat` = `{cohortes: {code→entier}, total, admissions}` — **aucun nom/CIN** | test `aucune_ecriture_metier_et_snapshots_sans_pii` (PASS) |
| Fuite en export | CSV/PDF n'incluent **pas** les snapshots JSON | `ExporteurSimulation` + `pdf.blade.php` |
| Immuabilité | **garantie par construction (aucun chemin d'écriture), pas par un trigger DB** | analyse |

**Réserve ST11-SEC-R4 (INFO)** : immuabilité non contrainte au niveau base (pas de trigger/observer read-only). Acceptable dans l'application actuelle (aucun chemin d'écriture), à durcir si un jour une API d'administration expose ces tables.

---

## 4. B4 — Protection des exports

### 4.1 CSV (`ExporteurSimulation::exporterCsv` + `ecrireLigneCsv`)

| Contrôle | État |
|---|---|
| Injection de formule | neutralisation `'` préfixe si premier caractère ∈ `= + - @`, **sauf** nombres purs (`/^-?\d+(?:[.,]\d+)?%?$/`) — les écarts négatifs restent lisibles (test `st10_export_csv_preserve_negatifs_et_neutralise_formules` PASS) |
| Encodage | BOM UTF-8 (`\xEF\xBB\xBF`), `Content-Type: text/csv; charset=UTF-8` |
| Délimiteur | `;` (convention FR), `fputcsv` (guillemets/échappement standards) |
| Valeurs nulles | `'N/A'` pour cible/écart cible |
| Nom de fichier | assaini `[^a-zA-Z0-9_\-] → _` |
| Cache | `no-store, no-cache` |
| PII | aucune (scénario, agrégats, empreintes) |

**Réserve ST11-SEC-R5 (FAIBLE)** : les préfixes de contrôle (`\t`, `\r`, espace insécable) ne sont pas neutralisés (seul le premier octet `= + - @` l'est). Exploitabilité quasi nulle ici (valeurs issues d'agrégats numériques + code/libellé saisis par un admin habilité), mais la parade est triviale à compléter (trim des contrôles avant test).

**Réserve ST11-SEC-R6 (FAIBLE/PRÉSENTATION)** : l'en-tête CSV affiche « **RAPPORT OFFICIEL** » (l.35) alors que le document est une simulation — la ligne d'avertissement « Simulation d'aide à la décision — aucune décision réelle » (l.44) rétablit le sens, mais le libellé doit être corrigé en Phase N (risque d'interprétation institutionnelle).

### 4.2 PDF (`genererPdf` + `pdf.blade.php`)

| Contrôle | État |
|---|---|
| Accès | `simulation.export` + contrôle facultaire 403 |
| SSRF / ressources distantes | `isRemoteEnabled = false` ✅ |
| Avertissement | encadré réglementaire explicite (l.119-121) : projection, aucune modification réelle |
| Traçabilité | ID exécution, version scénario/moteur, graine, date, `hash_entrees`, `hash_resultat` |
| Auteur | **non inclus** (pas de PII) — ni section gouvernance (noms des proposeurs/décideurs exclus) |
| Pagination | `@page` avec compteur page/total (Dompdf) |
| Fichiers temporaires | aucun (rendu en mémoire, `output()` string) |
| Budget | section absente du PDF → aucune ambiguïté comptable possible dans ce document ; **si** le budget y est ajouté un jour, l'avertissement « sans valeur comptable » devra l'accompagner |

**Réserve ST11-SEC-R7 (FAIBLE/PRÉSENTATION)** : pied de page « Document **certifié conforme** par le moteur… » — formulation à risque (confusion avec une certification officielle). À remplacer par « Document généré automatiquement — projection non opposable ».

---

## 5. B5 — Throttling (mécanisme vérifié dans le vendor)

Forme utilisée : `throttle:N,M,préfixe` (forme numérique à préfixe, `ThrottleRequests` l.85-105).
- Clé de comptage = `préfixe + identifiant utilisateur` (l.98 + `resolveRequestSignature` l.224-233) → **familles isolées** (aucune collision entre `st-def`, `st-exec`, etc.), **compteur par utilisateur** (pas par faculté ni par route : une famille partage son budget entre ses routes — par conception).
- Fenêtre `M` minutes (60 s), vérification **avant** exécution puis `hit`, en-têtes `X-RateLimit-*`, dépassement → `ThrottleRequestsException` (429).
- Store = cache par défaut de l'application (compteurs atomiques côté store).

| Famille | Limite | Routes couvertes | Adéquation |
|---|---|---|---|
| `st-def` | 20/min | création scénario | défensif (opération admin légère) |
| `st-exec` | 10/min | exécution | cohérent avec le coût (jusqu'à 20 variantes × 6 ans) |
| `st-dup` | 20/min | duplication | OK |
| `st-ver` | 20/min | nouvelle version | OK |
| `st-ia` | 20/min | explication IA | OK (lecture locale déterministe, pas d'appel externe) |
| `st-gov` | 15/min | proposer + décider | OK (budget partagé entre les deux actes) |

**Réserve ST11-SEC-R8 (MOYEN)** : aucun test ne couvre le dépassement (ST11-SEC-010 absent) — à ajouter en Phase M (assertion 429 + en-têtes + isolation entre familles). Le comportement du 429 côté Alpine (fetch JSON) reste à vérifier.

---

## 6. B6 — Gouvernance à quatre yeux (revue ligne par ligne)

| Invariant exigé | Implémentation | Preuve |
|---|---|---|
| Demande enregistrée | proposition stockée dans `execution.resultat['gouvernance_proposition']` (table technique) | `GouvernancePlanificationService` l.51-79 |
| Non-écrasement | toute proposition existante (soumise **ou** statuée) → `DomainException` | l.43-49 ; test `st10_gouvernance_resoumission_refusee_et_trace_conservee` PASS |
| Auto-approbation interdite | `propose_par_id === decideur->id` → refus | l.107-109 |
| Autorité du décideur | permission dédiée `simulation.gouvernance.decider` + périmètre faculté | route + l.112-114 ; `st11_sec_012` PASS |
| Même faculté | `peutAccederFaculte` à la soumission **et** à la décision | l.37, l.112 |
| Liaison à l'exécution exacte | `hash_entrees_fige`, `hash_resultat_fige`, `version_scenario`, `scenario_id` figés dans la proposition | l.51-63 |
| Altération post-soumission | si `hash_resultat` de l'exécution ≠ hash figé → invalidation | l.120-123 |
| Double décision | statut ≠ `soumise` → refus | l.102-104 |
| Décision tracée | statut, décideur, date, motif + entrée d'historique append-only | l.125-135 |
| Aucune application métier | avertissement explicite + aucune écriture hors tables techniques | l.72 ; preuve lecture seule 05 |

**Réserve ST11-SEC-R9 (INFO)** : le `hash_resultat` est calculé à la création et **n'est pas recalculé** après l'ajout de la proposition dans `resultat` (l'ajout ne modifie que le JSON, pas la colonne). Le contrôle l.121 protège donc contre l'altération de la colonne `hash_resultat`, pas contre une altération directe du JSON `resultat` (qui n'est pas re-haché). Risque nul tant qu'aucun chemin d'écriture ne modifie `resultat` hors gouvernance (démontré en 05) ; à documenter comme limite du hash dans `12-audit-explication-ia.md`.

**Réserve ST11-SEC-R10 (FAIBLE)** : identifiant de proposition basé sur `time()` (l.52) — non déterministe mais purement technique (unicité locale, aucune valeur de preuve). Aucun impact.

---

## 7. Synthèse B7 et GATE B

| Anomalie | Gravité | Statut |
|---|---|---|
| ST11-SEC-R1 · canal 403/404 sur ressource hors périmètre | FAIBLE | réserve — à arbitrer Phase N |
| ST11-SEC-R2 · année d'exécution non liée à la faculté/activité | MOYEN | réserve — Phase N (validation) |
| ST11-SEC-R3 · sémantique `faculte_id = null` | MOYEN | décision métier (Phase N) |
| ST11-SEC-R4 · immuabilité snapshots non contrainte en DB | INFO | réserve documentée |
| ST11-SEC-R5 · préfixes de contrôle CSV | FAIBLE | correction Phase N |
| ST11-SEC-R6 · libellé « RAPPORT OFFICIEL » (CSV) | FAIBLE | correction Phase N |
| ST11-SEC-R7 · « certifié conforme » (PDF) | FAIBLE | correction Phase N |
| ST11-SEC-R8 · aucun test de throttle (SEC-010) | MOYEN | test Phase M |
| ST11-SEC-R9 · hash non recalculé après gouvernance | INFO | limite documentée |
| ST11-SEC-R10 · identifiant proposition basé sur `time()` | INFO | acceptable |

**Aucune anomalie CRITIQUE ou ÉLEVÉE.** La faille P0 que constituait la réutilisation de `statistiques.view` pour toutes les mutations **est déjà corrigée dans le WIP** (P0-1) et protégée par 3 tests dédiés.

### ✅ GATE B : FRANCHI — verdict provisoire `PASS_WITH_RESERVATIONS`
Les réserves sont toutes non critiques (présentation, tests manquants, décisions institutionnelles) ; aucune ne compromet la confidentialité, l'autorisation ou l'intégrité.
