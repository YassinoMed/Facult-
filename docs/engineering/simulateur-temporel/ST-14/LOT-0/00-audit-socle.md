# ST-14 / LOT 0 — Audit et stabilisation du socle

**Date :** 2026-11-02
**Mode :** AUDIT_ONLY / SIMULATION / TESTING — données métier en lecture seule
**Périmètre :** module `SimulationTemporelle`, catalogue ST-14, rapports ST-10/ST-11/ST-12/ST-14
**Verdict du Lot 0 :** `PASS_WITH_RESERVATIONS` (audit, pas de développement fonctionnel)

> Ce document clôt le Lot 0 exigé par le LOOP maître. Il ne remplace ni
> `00-catalogue-scenarios.md` (contrat ST-14.00) ni les verdicts historiques :
> il les confronte au code réel et fixe les frontières du premier scénario
> fonctionnel à ouvrir.

---

## A. État Git constaté

| Élément | Constat |
|---|---|
| Branche | `main` |
| HEAD | `a8b3f0b` |
| Fichiers suivis modifiés | 66 |
| Entrées non suivies (`git status`) | 189 (622 fichiers réels) |
| Commits créés | Aucun |
| Push / branche / PR | Aucun |
| `git stash` | Vide |
| `reset --hard` / `clean` | Aucun |
| Migrations exécutées | Aucune |
| Données métier | Aucune écriture |

Les travaux ST-11, ST-12, ST-14 et les autres chantiers locaux sont préservés.
Aucun fichier non suivi n'a été écrasé ni supprimé.

---

## B. Inventaire réel du module

### B.1. Fichiers

**27 fichiers PHP** dans `app/Modules/SimulationTemporelle/` :

- 2 adaptateurs : `AdaptateurEffectifsCohortes`, `ContratAdaptateur` ;
- 1 contrôleur : `SimulationTemporelleController` ;
- 3 modèles : `ScenarioTemporel`, `ExecutionTemporelle`, `SnapshotExecution` ;
- 1 fichier de routes : `Routes/web.php` ;
- 1 service canonique : `ServiceSimulationTemporelle` ;
- 19 supports : moteur d'événements, horloge, variantes, évaluation,
  classement, explication, comparaison, capacités, sensibilité, Monte-Carlo,
  Pareto, budget, qualité, alertes, export, gouvernance, IA, catalogue.

### B.2. Routes et permissions

- **17 routes** réelles (`php artisan route:list --path=simulation-temporelle`).
- Préfixe `admin/simulation-temporelle`, middleware `auth`, `admin`,
  `secondaire.verrou`, `module:simulation-temporelle`.
- **7 permissions granulaires** : `simulation.view`, `.gerer`, `.executer`,
  `.export`, `.explication`, `.gouvernance.proposer`, `.gouvernance.decider`.
- `statistiques.view` n'autorise plus aucune écriture ni décision (correctif
  ST-11 P0-1 conservé).

### B.3. Vues

- `resources/views/modules/simulation-temporel/index.blade.php` (parcours
  mono-page : configuration, exécution, résultats, comparaison, exports).
- `resources/views/modules/simulation-temporel/pdf.blade.php` (export PDF).
- **Aucune vue dédiée par famille** : le catalogue ST-14 est exposé en JSON
  via `GET admin/simulation-temporelle/catalogue`.

### B.4. Tables techniques

Migration `2026_10_25_040000_creer_tables_simulation_temporelle.php` :
`scenarios_temporels`, `executions_temporelles`, `snapshots_executions`.
Aucune table métier n'est écrite par le module (preuve ST-11, 10 tables
avant/après).

### B.5. Tests

| Fichier | Tests | Assertions | Rôle |
|---|---|---|---|
| `SimulationTemporelleTest.php` | 52 | 312 | moteur historique ST-11/ST-12 |
| `SimulationTemporelleCatalogueTest.php` | 12 | 292 | contrat ST-14.00 + SIM-01 |
| `SimulationTemporelleSim01Test.php` | 7 | 56 | SIM-01 |
| **Total module** | **71** | **660** | **0 échec** |

Commande exécutée le 2026-11-02 :

```bash
php artisan test tests/Feature/SimulationTemporelleTest.php \
  tests/Feature/SimulationTemporelleCatalogueTest.php \
  tests/Feature/SimulationTemporelleSim01Test.php --compact
```

Résultat : `71 passed`, `660 assertions`, `6 499 ms`.

---

## C. Contrats et formules réellement implémentés

### C.1. Moteur

```text
admissions(pas n) = round(baseL1 × (1 + delta)^n)
baseL1           = effectif du premier niveau de la cohorte (reset)
```

- `delta ∈ [-0.5, +2.0]` (`BORNE_DELTA_MIN`, `BORNE_DELTA_MAX`).
- `VERSION_MOTEUR = 1.1` depuis SIM-01 (expose `admissions_par_periode`).
- Progression : `partent = round(effectif × passage)`,
  `redoublants = round(effectif × redoublement)`, plafonnement de
  conservation si `partent + redoublants > effectif`.
- `abandon` n'est pas soustrait explicitement : il représente le reste
  non conservé. Le total est recalculé par `array_sum`.
- `totaux` inclut le pas 0 ; `admissions_par_periode` couvre 1..horizon.
- Hash d'exécution : `hash_entrees` (scénario, version, critères, année,
  faculté, graine, variantes, version moteur) + `hash_resultat`.
- Idempotence : même `hash_entrees` + même graine → exécution existante.

### C.2. Limites et valeurs par défaut

| Élément | Valeur | Statut |
|---|---|---|
| Delta minimum | −0.5 | borne moteur, non institutionnelle |
| Delta maximum | +2.0 | borne moteur, non institutionnelle |
| Horizon | 1..6 ans | plafond moteur |
| Variantes | 1..20 | plafond moteur |
| Capacité par défaut | 35 | **hypothèse moteur**, pas une donnée |
| Seuils d'alerte | 15 % / 95 % | **défauts moteur**, non institutionnels |
| Monte-Carlo | 50 itérations | convention, pas une convergence |
| Budget | projection indicative | sans valeur comptable |

---

## D. Réconciliation des rapports

| Rapport | Verdict historique | Confrontation au code réel |
|---|---|---|
| ST-10 | 64 tests / 342 assertions | Périmètre antérieur différent ; non comparable directement. |
| ST-11 | `PASS_WITH_RESERVATIONS` | Toujours cohérent : permissions, lecture seule, déterminisme, quatre yeux. Réserves R1→R10 et RO-R1 ouvertes. |
| ST-12 | `PASS_WITH_RESERVATIONS` | UX P6/P8 corrigées ; réserve R-07 (accessibilité approfondie, vue mono-fichier) ouverte. |
| ST-14.00 | `PASS_WITH_RESERVATIONS` | Catalogue 45 entrées, route JSON, métadonnées uniquement. |
| SIM-01 | `PASS_WITH_RESERVATIONS` | 7/56, moteur 1.1, règles de croissance dans le catalogue. |

**Aucune contradiction bloquante** n'a été trouvée entre les rapports et le
code. Les écarts de comptage s'expliquent par des périmètres différents.

---

## E. Les sept familles — couverture réelle

| # | Famille | Scénarios | État réel | Preuves |
|---|---|---|---|---|
| 1 | Démographie et admissions | SIM-01 → SIM-06 | **Partielle** : SIM-01 clôturé ; SIM-02 audité ensuite ; SIM-03→06 planifiés | `SIM-01/09-verdict.md` |
| 2 | Réussite, redoublement, abandon | SIM-07 → SIM-13 | **Non commencée** ; le moteur sait appliquer des taux globaux | Tests historiques 52/312 |
| 3 | Cohortes et progression LMD | SIM-14 → SIM-19 | **Non commencée** ; aucune règle LMD versionnée | Réserve `L0-R8` |
| 4 | Capacités et infrastructures | SIM-20 → SIM-25 | **Non commencée** ; estimateur de groupes existe, capacité par défaut 35 | Réserve `L0-R7` |
| 5 | Budgets et ressources | SIM-26 → SIM-30 | **Non commencée** ; `ProjecteurBudgetaire` indicatif, aucun coût unitaire validé | Réserve `L0-R6` |
| 6 | Crise et résilience | CR-01 → CR-06 | **Non commencée** ; aucun modèle de choc temporel | Registre |
| 7 | Sensibilité et incertitude | UNC-01 → UNC-08 | **Non commencée** ; OAT, Monte-Carlo et Pareto existent mais ne sont pas contractualisés comme scénarios | Registre |

> Une capacité du moteur n'est pas un scénario validé. Les familles 2 à 7
> restent `NOT_STARTED` tant que leur contrat, leurs tests et leur verdict
> n'existent pas.

---

## F. Scénarios stratégiques A / B / C

| Scénario | Familles mobilisées | État | Dépendance bloquante |
|---|---|---|---|
| A — Pression démographique et saturation | 1, 3, 4, 5, 7 | `NOT_STARTED` | SIM-02→06, SIM-14→19, SIM-20→25, SIM-26→30, UNC |
| B — Amélioration de la réussite | 2, 3, 4, 5, 7 | `NOT_STARTED` | SIM-07→13, SIM-14→19, SIM-20→25, SIM-26→30, UNC |
| C — Crise et plan de résilience | 2, 4, 5, 6, 7 | `NOT_STARTED` | SIM-07→13, SIM-20→25, SIM-26→30, CR-01→06, UNC |

Aucun scénario transversal ne peut être déclaré opérationnel avant la
clôture individuelle de ses dépendances. Le registre ne leur attribue aucun
verdict.

---

## G. État réel de SIM-02 — Baisse des admissions

| Point | Constat |
|---|---|
| Catalogue | `SIM-02` présent, `statut = planifie`, `version = null`, `schema = null`, `version_formules = null` |
| Disponibilité | `CatalogueScenarios::estDisponible('SIM-02') === false` |
| Règle métier | Aucune règle SIM-02 dans le code |
| Moteur | Accepte déjà `delta < 0` jusqu'à `-0.5` ; aucune formule nouvelle requise |
| Tests | Le test catalogue vérifie explicitement que SIM-02 est **non disponible** |
| Compatibilité | `configuration` (préliminaire, à confirmer par l'audit SIM-02) |
| Dépendances | Aucune (le moteur suffit) |
| Risque principal | Absence de règle de domaine : SIM-01 et le socle peuvent recevoir un delta négatif via l'API ; la règle de baisse n'existe que dans le catalogue |

**Conclusion :** SIM-02 est le premier scénario fonctionnel non clôturé. Son
ouverture est autorisée après le Gate A du sous-LOOP dédié.

---

## H. Réserves du Lot 0

| ID | Gravité | Réserve | Impact | Décision attendue |
|---|---|---|---|---|
| `L0-R1` | MOYEN | La validation du catalogue n'est pas branchée sur la création/exécution HTTP ; les règles SIM-01/SIM-02 vivent dans `CatalogueScenarios` | Un scénario au code libre peut contourner la règle de domaine | Décision d'architecture : lier un scénario à un code catalogue, ou assumer le catalogue comme référentiel documentaire |
| `L0-R2` | MOYEN | Aucune vue dédiée aux 7 familles ; le catalogue est un JSON | UX cible non atteinte | Lots UX ultérieurs |
| `L0-R3` | MOYEN | Concurrence multi-processus non démontrée : les tests tournent en SQLite `:memory:` | Preuve de concurrence absente | Runner MySQL dédié (le serveur local est joignable, credentials `.env` valides) |
| `L0-R4` | FAIBLE | Aucun tableau de bord stratégique A/B/C | Pilotage limité | Après clôture des familles |
| `L0-R5` | FAIBLE | Seuils 15 % / 95 % non institutionnels | Alertes indicatives | Direction des études |
| `L0-R6` | MOYEN | Aucun coût unitaire, devise ou enveloppe validée | Famille 5 ne peut pas produire de budget réel | Décision institutionnelle |
| `L0-R7` | MOYEN | Capacité par défaut 35 et capacité inconnue | Risque de confusion avec une capacité réelle | Contrat famille 4 |
| `L0-R8` | BLOQUANT MÉTIER | Règles LMD (seuils, crédits, passage) non versionnées | Famille 3 non calculable | Validation institutionnelle |
| `L0-R9` | MOYEN | A/B/C non intégrés | Campagne non achevable | Après familles 1→7 |
| `L0-R10` | FAIBLE | Accessibilité approfondie non auditée (héritage ST-12 R-07) | Conformité incertaine | Audit dédié |

### Réserves historiques maintenues

- **SIM01-R1** : sans `taux`, le moteur met les cohortes existantes à zéro
  alors que l'avertissement parle de cohortes « gelées ». SIM-01 et SIM-02
  exigent les taux au niveau du catalogue ; le socle reste permissif.
- **SIM01-R2** : seuils 15 % / 95 % non institutionnels.
- **SIM01-R3** : `baseL1 = reset(cohortes)` — dépend du premier niveau.
- **SIM01-R4** : taux globaux identiques pour tous les niveaux.
- **SIM01-R5** : capacité par défaut 35 si aucun groupe réel.
- **SIM01-R6** : règle de croissance portée par le catalogue uniquement.
- **SIM01-R7** : aucun écran dédié SIM-01.
- **SIM01-R8** : bump moteur 1.0→1.1, idempotence inter-versions rompue.
- **ST11-SEC-R1→R10**, **ST11-RO-R1**, **ST11-BB-01/02**, **R-06**,
  **ST12 R-07** : maintenues.

---

## I. Limites de l'audit

- Aucune donnée de production n'a été inspectée.
- Les tests du module s'exécutent en SQLite `:memory:` ; le comportement
  MySQL de concurrence n'est pas prouvé par cette exécution.
- Aucun audit navigateur / lecteur d'écran n'a été exécuté.
- Les rapports ST-10/ST-11/ST-12/ST-14 sont traités comme du contexte, pas
  comme des preuves indépendantes : seuls les tests exécutés et le code
  inspecté font foi.

---

## J. Décision du Lot 0

**Verdict : `PASS_WITH_RESERVATIONS`.**

Critères de sortie satisfaits :

- architecture et périmètre connus ;
- moteur unique identifié ;
- catalogue réconcilié avec le code ;
- tests de référence exécutés (71/660) ;
- réserves héritées et nouvelles registrées ;
- aucun développement fonctionnel lancé avant contrat.

**Prochain sous-LOOP autorisé :** `SIM-02 — Baisse des admissions`, avec
Gate A obligatoire et sans réutilisation d'un identifiant existant.
