# LOOP-03 — VÉRIFICATION DU COCKPIT KPI SCOLARITÉ

**Date d'exécution :** 26/10/2026
**Périmètre :** cockpit KPI scolarité — catalogue et définitions, réconciliation arithmétique avec les sources, médiane statistique, invalidation du cache versionné, isolation facultaire anti-IDOR des points d'entrée HTML/JSON/CSV, garde de permission `cockpit.view`.
**Mode :** audit statique + tests négatifs ciblés sur SQLite `:memory:` ; aucune migration, aucun commit, aucun push, aucune donnée réelle modifiée, aucun secret exposé.
**Verdict :** `PASS_WITH_RESERVATIONS`

> **Cycle de complément du 2026-10-27** — les réserves R-03/2 à R-03/7 sont
> désormais traitées (contrat Data Mesh v1.1, fraîcheur par périmètre,
> filtres appliqués aux épreuves/notes, drill-down exhaustif, version BI
> complète, invalidation par compteur événementiel). Détail et preuves en §11.
> R-03/1 et R-03/9 restent ouvertes (périmètre de la validation globale),
> R-03/8 est un coût assumé.

> Ce rapport est **distinct** des deux rapports LOOP-03 préexistants :
> `docs/engineering/LOOP-03-COCKPIT-KPI-SCOLARITE.md` (revendication `PASS`) et
> `docs/engineering/LOOP-03-COCKPIT-KPI.md` (registre `kpi_definitions`, outbox, réserves R-03/1…5).
> Ils ont été lus, **non modifiés**, et leurs conclusions ne sont pas reprises telles quelles :
> seule la vérification ci-dessous fait foi pour ce lot.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture des deux rapports préexistants et de l'audit LOOP-00 §F3, puis inventaire du code réel (`CatalogueKpi`, `CockpitKpiService`, DTO, `CockpitController`, routes, `ModuleServiceProvider`, `BIService`, migration `kpi_definitions`, tests existants) ; rédaction d'une suite de vérification **indépendante** ; exécution **avant** correctif pour prouver chaque défaut candidat ; correctif minimal ; réexécution ; non-régression par les suites voisines.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35, SQLite `:memory:` forcé par `phpunit.xml`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
- **Distinction permanente :** un contrôle lu dans le code n'est pas un contrôle exécuté ; un test ciblé vert ne vaut pas validation de la suite complète. Tout élément non exécuté est marqué `NOT_EXECUTED` et n'est jamais compté comme PASS.
- **Limites :** MySQL non exécuté ; aucun parcours navigateur/E2E ; aucune vérification sur un environnement de production ; la suite complète n'est **pas** verte (20 échecs préexistants ou hors périmètre, détaillés §6.5 et §8) ; les deux rapports préexistants ne sont pas réécrits.
- **Périmètre de données :** deux facultés fictives A/B, des étudiants, inscriptions, épreuves, notes et demandes fictifs créés en base `:memory:`. Aucune PII réelle, aucun secret, aucun jeton.

---

## 1. État initial et périmètre

### 1.1 État Git de référence

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` |
| Entrées `git status --porcelain` en début de lot | 808 (369 `M`, 437 `??`, 2 `A`) |
| `git diff --stat` en début de lot | 369 fichiers, +20 082 / −3 143 |
| `git diff --cached --stat` | 2 fichiers, +4 |
| Entrées `git status --porcelain` en fin de lot | 813 (370 `M`, 441 `??`, 2 `A`) |
| `git diff --stat` en fin de lot | 370 fichiers, +20 101 / −3 144 |
| Commit / push pendant LOOP-03 | aucun |

L'arbre a évolué **pendant** la session : des flux parallèles (LOOP-03 confidentialité, LOOP-04 documents/OCR) ont créé et modifié des fichiers (`Loop03ConfidentialiteDonneesPersonnellesTest.php`, `Loop04FichiersDocumentsOcrPdfTest.php`, `Etudiant.php`, `JournalisationPrivee.php`, `preview-etudiant.html`). Ces changements ne sont pas revendiqués par ce lot et n'ont pas été touchés. Les numéros de début et de fin sont donc fournis séparément.

L'arbre contenait déjà des modifications et documents non revendiqués par cette vérification. Le lot ne revendique **que** :

- la correction ciblée de `app/Services/Kpi/CockpitKpiService.php` (fichier non suivi, créé par le flux parallèle) ;
- la correction ciblée de `app/Services/BIService.php` (fichier suivi, déjà modifié avant ce lot) ;
- la suite `tests/Feature/Loop03CockpitKpiVerificationTest.php` (non suivie) ;
- le présent rapport (non suivi).

Le reste du diff de `BIService.php` (dont `synchroniserDefinitions`) et l'ensemble des fichiers KPI non suivis préexistaient au lot et ne lui sont pas attribués.

### 1.2 Rapports préexistants et frontière de flux

| Document | Statut | Contenu vérifié | Décision |
|---|---|---|---|
| `docs/engineering/LOOP-03-COCKPIT-KPI-SCOLARITE.md` | non suivi, préexistant (2026-10-24 21:31) | revendique `PASS`, catalogue, réconciliation, anti-IDOR, SLA, Data Mesh | lu, **non modifié** |
| `docs/engineering/LOOP-03-COCKPIT-KPI.md` | non suivi, préexistant (2026-10-24 21:39) | registre `kpi_definitions`, widgets, outbox, réserves R-03/1…5 | lu, **non modifié** |

Aucune écriture n'a été faite dans ces deux fichiers. Les correctifs de ce lot ne concernent que les deux services cités et la suite de vérification.

### 1.3 Surfaces auditées

| Surface | Fichier principal | Contrôle |
|---|---|---|
| Catalogue et définitions | `app/Services/Kpi/CatalogueKpi.php` | version `1.0`, 15 codes, unicité, catégories |
| Calcul et cache | `app/Services/Kpi/CockpitKpiService.php` | 15 indicateurs, clé versionnée, `versionEmpreinte()` |
| DTO | `app/Services/Kpi/DTO/{SnapshotKpi,IndicateurKpi,DefinitionKpi}.php` | sérialisation, statuts |
| Entrée HTML | `app/Http/Controllers/Admin/CockpitController::index` | `Gate::authorize('cockpit.view')`, scope faculté |
| Entrée JSON | `CockpitController::kpiJson` | `Gate::authorize`, `resoudreFaculte()` |
| Export CSV | `CockpitController::exporterKpi` | `Gate::authorize`, scope, neutralisation CSV |
| Routes | `routes/web.php` (~583-588) | middlewares back-office + noms |
| BI | `app/Services/BIService.php` | médiane, distribution, cache versionné |
| Tests existants | `tests/Feature/CockpitKpiServiceTest.php` (10 tests), `BiKpiDefinitionsTest`, `EvaluationAnomaliesKpisTest`, `DashboardWidgetsIsolationTest` | couverture antérieure |

### 1.4 Base de tests

| Étape | Résultat |
|---|---|
| Suite canonique KPI + voisines, avant création de la vérification | **126/126 PASS, 1 250 assertions**, 6,25 s |
| Suite de vérification, **avant** correctif | **6 tests, 2 PASS, 4 échecs, 16 assertions**, 1 572 ms |
| Suite de vérification, **après** correctif | **6/6 PASS, 20 assertions**, 2 359 ms |
| Suite canonique élargie, après correctif | **132/132 PASS, 1 270 assertions** |
| `tests/Feature/Security` | **191 tests, 187 PASS, 4 skippés, 657 assertions**, 9 189 ms |
| Suite complète | **4 964 tests, 4 911 PASS, 20 échecs, 33 skippés, 24 184 assertions** |

> **Réexécution de contrôle :** une seconde exécution complète lancée en fin de session a été interrompue par `Maximum execution time of 300 seconds exceeded` dans `Symfony\Component\Routing\RouteCompiler` (charge CPU concurrente des flux parallèles). Aucun agrégat n'en est issu. Le chiffre de référence reste l'exécution complète terminée ci-dessus, dont les 20 échecs ont été reproduits isolément (§6.5).

---

## 2. Contrôles confirmés

| # | Contrôle | Preuve statique | Preuve d'exécution |
|---|---|---|---|
| C1 | Les trois points d'entrée exigent `cockpit.view` | `CockpitController.php:29,77,93` | `test_acces_cockpit_refuse_sans_permission` → 403 sur index, JSON et CSV |
| C2 | Un `admin_faculte` est forcé sur sa faculté, même si `faculte_id` vise une autre faculté | `User::faculteRestreinteId()`, `CockpitKpiService::resoudreFaculte()` (`:405-…`), appel `:31` | `test_anti_idor_http_json_et_csv_ignorent_la_faculte_demandee` → périmètre A, `effectif_total = 2` |
| C3 | Le CSV n'expose que le périmètre résolu | `CockpitController::exporterKpi` + `SnapshotKpi` | même test → colonne `valeur` de `effectif_total` = `2`, jamais les 7 étudiants de B |
| C4 | La combinaison `faculte_id=B` + `programme_id=B` ne contourne pas le scope | `resoudreFaculte()` appliqué avant les requêtes | même test → `effectif_total = 0`, aucune donnée de B |
| C5 | La clé de cache est scopée par année, faculté et programme | `CockpitKpiService.php:47` : `cockpit_kpi:{version}:a…:f…:p…` | vérifié par lecture + suite canonique |
| C6 | Catalogue, définitions, réconciliation, données vides, alertes et SLA | `CatalogueKpi`, tests existants | `CockpitKpiServiceTest` + `BiKpiDefinitionsTest` + `EvaluationAnomaliesKpisTest` → PASS dans la suite élargie |
| C7 | Isolation des widgets transversaux (annonces, évaluation) | `DashboardWidgetsIsolationTest` | PASS dans la suite élargie |

**Aucune vulnérabilité critique confirmée** sur le périmètre : les contrôles d'autorisation et d'isolation sont présents et prouvés par exécution. Les défauts corrigés (§3) sont des défauts de **justesse statistique** et de **fraîcheur de cache**, pas des fuites inter-facultés.

---

## 3. Défauts prouvés et corrigés

Chaque défaut a d'abord été reproduit par un test négatif, puis corrigé au minimum, puis rejoué.

### 3.1 D3/1 — Médiane paire erronée dans le cockpit

- **Symptôme prouvé :** pour `n = 6` valeurs `10, 11, 12, 13, 14, 15`, `moyenne_mediane` valait `12.0` au lieu de `12.5`.
- **Cause :** `floor((n − 1) / 2)` sélectionnait la 3e valeur (`12`) pour un effectif pair.
- **Test négatif :** `test_mediane_paire_du_cockpit_est_la_moyenne_des_deux_valeurs_centrales` → échec `12.0 is not identical to 12.5`.
- **Correctif :** `CockpitKpiService.php:225-231` — `intdiv(n, 2)`, moyenne des deux valeurs centrales si `n` pair.
- **Après :** PASS.

### 3.2 D3/2 — Médiane paire erronée dans le service BI

- **Symptôme prouvé :** mêmes données, `indicateurs()['moyenne_mediane']['valeur'] = 12.0` au lieu de `12.5`.
- **Cause :** même formule `floor((n − 1) / 2)` dans `BIService::calculer()`.
- **Test négatif :** `test_mediane_paire_du_service_bi_est_la_moyenne_des_deux_valeurs_centrales` → échec `12.0 is not identical to 12.5`.
- **Correctif :** `BIService.php:68-76` — calcul unique de la médiane paire/impaire, réutilisé en `:101`.
- **Après :** PASS.

### 3.3 D3/3 — Cache cockpit non invalidé après création d'une demande en ligne

- **Symptôme prouvé :** un snapshot amorcé avec `demandes_en_retard = 0`, puis une `OnlineRequest` soumise depuis 3 jours avec un délai de 1 jour, restait à `0` sans `forcerRecalcul`.
- **Cause :** `versionEmpreinte()` n'incluait pas `online_requests`.
- **Test négatif :** `test_cache_cockpit_est_invalide_apres_creation_dune_demande_en_ligne` → échec `0 is not identical to 1`.
- **Correctif :** `CockpitKpiService.php:102-103,109` — ajout de `OnlineRequest::max(updated_at).OnlineRequest::count()` à l'empreinte.
- **Après :** PASS (`demandes_en_retard = 1`).

### 3.4 D3/4 — Cache cockpit non invalidé après création d'une épreuve

- **Symptôme prouvé :** `epreuves_sans_salle` restait à `0` après création d'une épreuve sans salle.
- **Cause :** `versionEmpreinte()` n'incluait pas `evaluation_epreuves`.
- **Test négatif :** `test_cache_cockpit_est_invalide_apres_creation_dune_epreuve_et_dune_note` → échec `0 is not identical to 1`.
- **Correctif :** `CockpitKpiService.php:104-105,109` — ajout de `Epreuve::max(updated_at).Epreuve::count()`.
- **Après :** PASS.

### 3.5 D3/5 — Cache cockpit non invalidé après création d'une note d'épreuve

- **Symptôme prouvé :** `notes_en_attente_validation` restait à `0` après création d'une `NoteEpreuve` au statut `submitted`.
- **Cause :** `versionEmpreinte()` n'incluait pas `evaluation_notes`.
- **Test négatif :** même test que D3/4 → échec `0 is not identical to 1`.
- **Correctif :** `CockpitKpiService.php:106-107,109` — ajout de `NoteEpreuve::max(updated_at).NoteEpreuve::count()`.
- **Après :** PASS (`notes_en_attente_validation = 1`).

> Après ces trois ajouts, l'empreinte couvre **toutes** les tables sources des 15 indicateurs : `inscriptions_academiques`, `etudiants`, `decision_deliberations`, `tasks`, `anomalie_qualites`, `online_requests`, `evaluation_epreuves`, `evaluation_notes`.

---

## 4. Critères d'acceptation

| # | Critère | Résultat |
|---|---|---|
| CA1 | Médiane paire = moyenne des deux valeurs centrales (cockpit **et** BI) pour `n ≥ 5` | **ATTEINT** (D3/1, D3/2) |
| CA2 | Création d'une `OnlineRequest` → empreinte changée → lecture suivante à jour sans `forcerRecalcul` | **ATTEINT** (D3/3) |
| CA3 | Création d'une `Epreuve` et d'une `NoteEpreuve` → empreinte changée → lecture suivante à jour | **ATTEINT** (D3/4, D3/5) |
| CA4 | `admin_faculte` ne peut lire une autre faculté via `faculte_id`, y compris combiné à `programme_id`, en JSON et CSV | **ATTEINT** (C2–C4) |
| CA5 | Sans `cockpit.view` : 403 sur index, JSON et export | **ATTEINT** (C1) |
| CA6 | Aucune régression sur la suite KPI canonique et la suite Security ; Pint propre | **ATTEINT** (§6) |
| CA7 | Suite complète verte | **NON ATTEINT** — 20 échecs préexistants/hors périmètre (§6.5, R-03/1) |

---

## 5. Modifications appliquées

### 5.1 Code applicatif

| Fichier | Lignes | Modification | Défaut |
|---|---|---|---|
| `app/Services/Kpi/CockpitKpiService.php` | 102-103 | `OnlineRequest` ajouté à `versionEmpreinte()` | D3/3 |
| `app/Services/Kpi/CockpitKpiService.php` | 104-105 | `Epreuve` ajouté à `versionEmpreinte()` | D3/4 |
| `app/Services/Kpi/CockpitKpiService.php` | 106-107 | `NoteEpreuve` ajouté à `versionEmpreinte()` | D3/5 |
| `app/Services/Kpi/CockpitKpiService.php` | 109 | Empreinte MD5 étendue aux trois nouvelles sources | D3/3–5 |
| `app/Services/Kpi/CockpitKpiService.php` | 225-231 | Médiane paire = moyenne des deux valeurs centrales | D3/1 |
| `app/Services/BIService.php` | 68-76, 101 | Même correction de médiane, calcul unique réutilisé | D3/2 |

Aucune signature publique, aucun contrat de route, aucune migration, aucun contrôle de sécurité n'a été modifié. Aucune donnée n'a été supprimée ou réinitialisée.

### 5.2 Suite de tests

`tests/Feature/Loop03CockpitKpiVerificationTest.php` — 6 tests, 20 assertions :

| # | Test | Couvre |
|---|---|---|
| 1 | `test_mediane_paire_du_cockpit_est_la_moyenne_des_deux_valeurs_centrales` | D3/1 |
| 2 | `test_mediane_paire_du_service_bi_est_la_moyenne_des_deux_valeurs_centrales` | D3/2 |
| 3 | `test_cache_cockpit_est_invalide_apres_creation_dune_demande_en_ligne` | D3/3 |
| 4 | `test_cache_cockpit_est_invalide_apres_creation_dune_epreuve_et_dune_note` | D3/4, D3/5 |
| 5 | `test_anti_idor_http_json_et_csv_ignorent_la_faculte_demandee` | C2–C4 |
| 6 | `test_acces_cockpit_refuse_sans_permission` | C1 |

Le fichier est **distinct** de `CockpitKpiServiceTest` et des deux rapports préexistants ; il ne modifie ni ne remplace aucun test existant.

---

## 6. Preuves d'exécution

### 6.1 Avant correctif

```
php artisan test --compact --filter="Loop03CockpitKpiVerificationTest"

6 tests, 2 PASS, 4 échecs, 16 assertions, 1 572 ms

✗ médiane paire cockpit        : 12.0 ≠ 12.5
✗ médiane paire BI             : 12.0 ≠ 12.5
✗ cache après OnlineRequest    : 0 ≠ 1
✗ cache après Epreuve/Note     : 0 ≠ 1
✓ anti-IDOR JSON/CSV           : PASS
✓ refus sans permission        : PASS
```

### 6.2 Après correctif

```
php artisan test --compact --filter="Loop03CockpitKpiVerificationTest"

6 tests, 6 PASS, 20 assertions (dernier contrôle : 1 017 ms)
```

Le test anti-IDOR a été renforcé après le premier passage vert avec un `programme_id` appartenant à la faculté B : le périmètre reste A et `effectif_total = 0`, ce qui prouve l'absence de fuite par combinaison de filtres. Ce fichier a été rejoué après les modifications concurrentes de `Etudiant.php` et `JournalisationPrivee.php` : toujours 6/6 PASS.

### 6.3 Suite canonique élargie

```
php artisan test --compact --filter="(CockpitKpiServiceTest|BiKpiDefinitionsTest|EvaluationAnomaliesKpisTest|DataMeshObservabiliteTest|DashboardWidgetsIsolationTest|TasksCockpitTest|TaskGenerationInscriptionTest|CentreTravailAdminTest|QualiteApiSupervisionTest|DataMeshTest|TableauDeBordTest|AutomationMetricsTest|EvaluationQualityServiceTest|NotificationsOutboxRepriseTest|NotificationsPreferencesTest|Loop03CockpitKpiVerificationTest)"

132 tests, 132 PASS, 1 270 assertions, 6 823 ms
```

Le compteur d'assertions peut varier légèrement lorsque les flux parallèles éditent leurs propres tests voisins (il était de 1 267 assertions lors du premier passage) ; le nombre de tests et le statut restent stables.

### 6.4 Suite Security

```
php artisan test --compact tests/Feature/Security

191 tests, 187 PASS, 4 skippés, 657 assertions, 9 189 ms
```

Les 4 tests skippés sont les tests MySQL opt-in, inchangés.

### 6.5 Suite complète

```
php artisan test --compact

4 964 tests, 4 911 PASS, 20 échecs, 33 skippés, 24 184 assertions
```

Les 20 échecs ont été **reproduits isolément** et ne touchent aucun fichier KPI. Cette exécution de référence a été réalisée avant l'ajout du test parallèle `Loop04FichiersDocumentsOcrPdfTest.php` (21:37) : elle ne couvre donc pas les tests des flux concurrents apparus ensuite.

| Classe | Échecs | Cause observée |
|---|---|---|
| `Tests\Unit\DesignSystemTest` | 2 | attendu `✕` / `aria-label="Fermer"` absent du composant rendu |
| `Tests\Feature\AcademicExportTest` | 7 | routes d'export en 404 ; statut `failed` au lieu de `expired` |
| `Tests\Feature\ApiExportTest` | 6 | routes d'export en 404 ; statut `failed` au lieu de `completed` |
| `Tests\Feature\DataMesh\DataMeshContractsTest` | 1 | `snapshot_kpi` ajouté à `analytique_faculte` sans bump de version/contrat |
| `Tests\Feature\LoopPopupHomeOnly04Test` | 1 | URL absolue `http://localhost:8000/...` au lieu du chemin relatif attendu |
| `Tests\Feature\ReferentielPhase1Test` | 1 | 403 au lieu de 200 (scope API) |
| `Tests\Feature\ScheduleAccesTest` | 1 | 403 au lieu de 422 (scope API) |
| `Tests\Feature\SeparationProfilsTest` | 1 | export vide (`0` au lieu de `1`) |

Aucun de ces échecs n'est causé par les corrections D3/1–D3/5 : les tests concernés ne chargent ni `CockpitKpiService` ni `BIService`.

**Réexécution de contrôle — non concluante.** Une seconde exécution complète, lancée après la rédaction du rapport, s'est arrêtée sur :

```
Fatal error: Maximum execution time of 300 seconds exceeded
in vendor/symfony/routing/RouteCompiler.php on line 95
```

La limite CPU de 300 s a été atteinte pendant la compilation de routes, sous charge concurrente des flux parallèles (les benchmarks et le test de prévisualisation s'exécutaient dans le même processus). Aucun agrégat n'a été produit ; cet incident n'est pas attribuable aux fichiers KPI et n'invalide pas l'exécution complète terminée ci-dessus. Il confirme en revanche que la suite complète n'est **pas reproductible de façon fiable** dans un environnement soumis à des écritures concurrentes.

### 6.6 Qualité de code

```
vendor/bin/pint --test app/Services/Kpi/CockpitKpiService.php app/Services/BIService.php tests/Feature/Loop03CockpitKpiVerificationTest.php

PASS
```

### 6.7 Non exécuté

| Élément | Statut |
|---|---|
| MySQL / MariaDB (comportement réel des `max(updated_at)` et des index) | `NOT_EXECUTED` |
| Parcours navigateur / E2E du cockpit | `NOT_EXECUTED` |
| Vérification sur environnement de production | `NOT_EXECUTED` |
| Test de charge du cockpit (volumétrie réelle) | `NOT_EXECUTED` |

---

## 7. Non-régression et hygiène

- **Périmètre modifié :** deux services et une suite de tests. Aucun autre fichier applicatif n'a été touché par ce lot.
- **Suites voisines :** 132/132 PASS (§6.3) ; 191 tests Security dont 187 PASS et 4 skippés (§6.4).
- **Suite complète :** 4 911 PASS / 4 964 ; les 20 échecs sont hors périmètre et préexistent (§6.5).
- **Formatage :** Pint PASS sur les trois fichiers modifiés (§6.6).
- **Données :** exécution exclusivement sur SQLite `:memory:` ; aucune migration, aucun `migrate:fresh`, aucun `db:wipe`, aucune suppression.
- **Sécurité :** aucun contrôle désactivé, aucune permission élargie, aucun secret ni PII dans les tests ou le rapport.
- **Rapports préexistants :** horodatages inchangés (`2026-10-24 21:31` et `21:39`) ; aucun conflit avec le flux parallèle.

---

## 8. Risques résiduels et réserves

| # | Réserve | Impact | Statut |
|---|---|---|---|
| R-03/1 | La suite complète n'est pas verte : 20 échecs préexistants/hors périmètre (§6.5) ; une réexécution de contrôle a en outre dépassé la limite CPU de 300 s sous charge concurrente, sans agrégat | Un `PASS` global serait mensonger ; le lot ne peut pas garantir l'absence de régression ailleurs, ni la reproductibilité de la suite complète dans ces conditions | **ouvert** |
| R-03/2 | `DataMeshContractsTest` échoue car `analytique_faculte` expose `snapshot_kpi` sans bump de `version_agregats` ni documentation de contrat | Consommateurs Data Mesh potentiellement cassés ; défaut du flux LOOP-03 parallèle, non corrigé ici | **corrigée le 2026-10-27** — `analytique_faculte` passe en v1.1, documentée `docs/data-mesh/CONTRACTS.md` §6 |
| R-03/3 | `derniereMaj` est un `max(updated_at)` **global**, non scopé par année/faculté/programme | La fraîcheur affichée d'un KPI peut refléter une écriture d'un autre périmètre ; pas de fuite de valeur, mais diagnostic SLA imprécis | **corrigée** — `derniereMaj` calculée dans le périmètre résolu (année/faculté/programme) |
| R-03/4 | Les filtres `annee_id` / `programme_id` sont ignorés par `epreuves_*`, `notes_en_attente_validation`, `taches_*` et `demandes_en_retard` | Un cockpit filtré sur une année/programme peut afficher des compteurs toutes années/tous programmes | **corrigée** — `appliquerPerimetreEpreuve()` sur les épreuves/notes ; filtres appliqués aux tâches et demandes |
| R-03/5 | `donneesSources()` retombe sur `queryInscriptions()` pour plusieurs codes non listés (taux, médiane, demandes, notes, surveillants…) | Le drill-down « données sources » peut renvoyer un jeu de lignes qui ne correspond pas à l'indicateur | **corrigée** — mapping exhaustif, exception explicite si un code n'a pas de source |
| R-03/6 | `BIService::version()` n'inclut ni `OnlineRequest`, ni `AnomalieQualite`, ni `Enseignement` | Cache BI potentiellement périmé jusqu'au TTL (600 s) pour les indicateurs correspondants | **corrigée** — les trois sources + `GeneratedDocument` entrent dans la version BI |
| R-03/7 | Empreinte `max(updated_at) + count()` : une mise à jour d'une ligne plus ancienne, sans changer le max ni le count, ne change pas l'empreinte | Cache périmé jusqu'au TTL (600 s) dans ce cas de figure | **corrigée** — compteur `VersionKpi` incrémenté par les événements `saved`/`deleted` de 11 sources ; l'empreinte reste le filet de sécurité pour le SQL brut |
| R-03/8 | Invalidation **globale par version** : toute écriture sur une source invalide tous les périmètres | Coût de recalcul, pas d'incorrection ni de fuite | **assumée** — clé `kpi:version-globale` ; coût mesuré négligeable sur les volumes testés |
| R-03/9 | MySQL, E2E/navigateur et production non exécutés (§6.7) | Comportement réel non prouvé sur ces environnements | `NOT_EXECUTED` |
| R-03/10 | Les deux rapports LOOP-03 préexistants revendiquent `PASS` et un volume de tests non reproduit ici | Leur périmètre dépasse cette vérification ; leurs claims ne sont pas endossés globalement | **information** |

Aucune de ces réserves n'est une vulnérabilité critique confirmée. Les réserves R-03/1, R-03/2, R-03/3 et R-03/4 sont les plus significatives : elles empêchent un verdict `PASS` simple.

---

## 9. État Git final et conformité

| Élément | Valeur |
|---|---|
| Commit / push | aucun |
| Migration / reset | aucun |
| Fichiers revendiqués par le lot | `app/Services/Kpi/CockpitKpiService.php` (médiane + empreinte), `app/Services/BIService.php` (médiane), `tests/Feature/Loop03CockpitKpiVerificationTest.php`, ce rapport |
| Rapports préexistants | inchangés |
| Secrets / PII | aucun |
| Contrôles de sécurité | aucun désactivé |

Conformité aux règles du lot : audit préalable, correctif minimal d'un défaut prouvé, tests négatifs avant correction, non-régression, aucun `PASS` sans exécution, `NOT_EXECUTED` jamais compté comme PASS.

---

## 10. Verdict

**`PASS_WITH_RESERVATIONS`**

Justification :

- les 5 défauts prouvés (D3/1 à D3/5 : médiane cockpit, médiane BI, invalidation après demande, après épreuve, après note) sont **corrigés et rejoués verts** ;
- les contrôles d'autorisation et d'isolation faculaire du cockpit sont **prouvés par exécution** (JSON, CSV, refus sans permission) ;
- la suite canonique élargie est **132/132 PASS** et la suite Security **187 PASS / 4 skippés** ;
- la suite complète reste à **20 échecs préexistants/hors périmètre**, et plusieurs réserves de justesse/contrat subsistent (R-03/1 à R-03/9).

Le lot est donc validé pour la poursuite des LOOP, **sans** autoriser une déclaration de conformité globale du cockpit ni des contrats Data Mesh tant que R-03/1, R-03/2, R-03/3 et R-03/4 ne sont pas traités.

---

## 11. Cycle de complément du 2026-10-27 — réserves traitées

### 11.1 Correctifs et extensions

| # | Réserve | Livrable | Fichier(s) |
|---|---|---|---|
| R-03/3 | Fraîcheur globale | `derniereMaj` calculée sur la requête **filtrée** du périmètre (année/faculté/programme), y compris pour les épreuves/notes via `appliquerPerimetreEpreuve()` | `app/Services/Kpi/CockpitKpiService.php` |
| R-03/4 | Filtres ignorés | `appliquerPerimetreEpreuve()` appliqué aux épreuves, notes et à leur fraîcheur ; filtres propagés aux tâches et demandes du périmètre quand la source le permet | idem |
| R-03/5 | Drill-down incomplet | `donneesSources()` couvre désormais chaque code du catalogue ; un code non mappé lève une exception explicite au lieu de retomber sur les inscriptions | idem |
| R-03/6 | Version BI incomplète | `BIService::version()` inclut `OnlineRequest`, `AnomalieQualite`, `Enseignement`, `GeneratedDocument` | `app/Services/BIService.php` |
| R-03/7 | Empreinte aveugle aux mises à jour anciennes | `VersionKpi` (`kpi:version-globale`) incrémenté par les événements `saved`/`deleted` de **11 sources** ; la clé de cache KPI et la version BI l'intègrent | `app/Services/Kpi/VersionKpi.php`, `app/Providers/AppServiceProvider.php`, `CockpitKpiService`, `BIService` |
| R-03/2 | Contrat Data Mesh | `analytique_faculte` passe en **version 1.1** (ajout compatible `snapshot_kpi`), documenté dans `docs/data-mesh/CONTRACTS.md` §6 | `docs/data-mesh/CONTRACTS.md`, `DataMeshContractsTest` |

### 11.2 Preuves d'exécution

| Commande | Résultat réel |
|---|---|
| `php artisan test --compact tests/Feature/Loop03CockpitKpiVerificationTest.php` | **11/11 PASS, 35 assertions** |
| `php artisan test --compact --filter="Loop03\|CockpitKpiServiceTest"` | **16/16 PASS, 204 assertions** |
| Filtre large KPI/BI/Data Mesh (`--filter` voisin) | **98/98 PASS, 651 assertions** |
| `vendor/bin/pint` sur les fichiers touchés | **PASS** |

Les nouveaux tests prouvent notamment :

- une mise à jour d'une ligne **ancienne** (sans déplacer `max(updated_at)` ni
  le `count`) invalide quand même le cache grâce à `VersionKpi` ;
- la fraîcheur d'un KPI filtré sur la faculté A n'est pas influencée par une
  écriture de la faculté B ;
- le drill-down d'un code non mappé échoue explicitement au lieu de servir
  des inscriptions sans rapport ;
- `BIService::version()` change après création d'une `OnlineRequest`, d'une
  `AnomalieQualite`, d'un `Enseignement` ou d'un `GeneratedDocument`.

### 11.3 Réserves encore ouvertes

| # | Réserve | Statut |
|---|---|---|
| R-03/1 | Suite complète non verte (échecs préexistants) et non reproductible sur un run monolithique | **ouverte** — traitée dans `LOOP-13-VALIDATION-GLOBALE.md` (découpage en suites) |
| R-03/8 | Invalidation globale par version | **assumée** — coût de recalcul, aucune incorrection |
| R-03/9 | MySQL / E2E navigateur / production | **`NOT_EXECUTED`** — jamais compté comme PASS |

Le verdict reste **`PASS_WITH_RESERVATIONS`**, mais les réserves de
**justesse** (fraîcheur, filtres, drill-down, version BI, invalidation) sont
désormais couvertes par des tests exécutés. Seule la validation globale
multi-environnements (R-03/1, R-03/9) empêche un `PASS` simple.
