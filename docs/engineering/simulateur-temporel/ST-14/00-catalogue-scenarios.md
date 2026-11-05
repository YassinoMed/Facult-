# ST-14.00 — Catalogue commun et contrat des scénarios avancés

**Campagne :** ST-14 — scénarios avancés du simulateur temporel
**LOOP :** fondateur ST-14.00
**Date :** 2026-11-02
**Mode :** AUDIT_ONLY / SIMULATION / TESTING — données métier en lecture seule
**Verdict :** ✅ **PASS_WITH_RESERVATIONS**

> **Note d'évolution (2026-11-02) :** ce document décrit l'état **à la clôture
> de ST-14.00** (1 scénario disponible, 10 tests catalogue / 277 assertions).
> Le LOOP SIM-01 a ensuite ajouté une deuxième définition disponible et deux
> tests catalogue. Voir `registre-global.md` pour l'état courant.

---

## A. Objectif du LOOP

Doter la campagne ST-14 d'une structure commune **avant** tout développement de
scénario : un catalogue de métadonnées, un format d'entrée unique, un format de
résultat unique, un hash de définition complet et des règles de compatibilité
avec le moteur existant.

**GATE ST-14.00 :** aucun scénario suivant ne développe son propre format
d'entrée ni son propre système de résultat.

---

## B. Audit initial (Phase A) — capacités réellement disponibles

### B.1 Services examinés

| Fichier | Rôle constaté |
|---|---|
| `Services/ServiceSimulationTemporelle.php` | Moteur autoritaire : validation, exécution, snapshots, hash |
| `Adaptateurs/AdaptateurEffectifsCohortes.php` | Lecture seule des effectifs réels par niveau |
| `Support/MoteurEvenements.php` | Événements simulés datés (support existant) |
| `Support/HorlogeSimulee.php` | Temps virtuel injecté, `P1Y`, sans `now()` |
| `Support/GenerateurVariantes.php` | Variantes déterministes `sha256(graine:i)` |
| `Support/EvaluateurScenarios.php` | Contraintes dures `plafond_total` / `plancher_total`, objectifs `cible_total` / `stabilite` |
| `Support/AnalyseurSensibilite.php` | Sensibilité OAT univariée |
| `Support/SimulateurMonteCarlo.php` | Itérations contrôlées par graine |
| `Support/EstimateurCapacitesGroupes.php` | Groupes théoriques requis, saturation |
| `Support/OptimiseurPareto.php` | Frontière non dominée |
| `Support/ProjecteurBudgetaire.php` | Coûts fixes / variables / par groupe (hypothétiques) |
| `Support/ClasseurScenarios.php`, `ExplicateurScenarios.php` | Classement et explication déterministes |

### B.2 Sémantique réelle du moteur (vérifiée dans le code)

- Admissions : `admissions = round(baseL1 × (1 + delta)^pas)`.
- `baseL1 = reset($cohortesDepart)` — **le premier niveau de la cohorte est la base des admissions**.
- Taux `passage`, `redoublement`, `abandon` **globaux**, identiques pour tous les niveaux.
- Delta global **composé**, pas de profil temporel.
- Plafonds : horizon ≤ `PLAFOND_HORIZON` (6), variantes ≤ `PLAFOND_VARIANTES` (20).
- Taux absents = progression gelée + avertissement (jamais de zéro silencieux).
- Bornes du delta : `[-0.5, +2.0]`.

### B.3 Dimensions absentes confirmées

| Dimension | État |
|---|---|
| Admissions variables dans le temps (gel, choc, reprise) | ❌ absente |
| Taux différenciés par niveau | ❌ absente |
| Cohorte identifiée logiquement | ❌ absente |
| Capacité datée / hypothétique / perte temporaire | ❌ absente |
| Répartition par groupe simulée | ❌ absente |
| Enveloppe budgétaire paramétrable | ❌ absente (`ProjecteurBudgetaire` a des constantes par défaut) |
| Sensibilité combinée, robustesse, seuils de rupture | ❌ absentes (seul OAT existe) |

**Conclusion Phase A :** le moteur couvre un delta global composé et des taux
globaux ; 44 scénarios exigent des extensions ou des analyses nouvelles. Le
catalogue doit donc distinguer honnêtement le réalisable par configuration du
réalisable après extension.

---

## C. Spécification du contrat commun (Phase B)

### C.1 Champs obligatoires de toute définition

`code`, `nom`, `categorie`, `lot`, `objectif`, `version`, `statut`,
`perimetre`, `compatibilite`, `dependances`, `schema`, `hypotheses`,
`version_formules`, `resultats_attendus`, `indicateurs`.

### C.2 Catégories

| Clé | Libellé |
|---|---|
| `fondation` | Fondation et contrat commun |
| `admissions_demographie` | Admissions et démographie |
| `reussite_flux` | Réussite, redoublement et abandon |
| `cohortes_lmd` | Cohortes et progression LMD |
| `capacites_infrastructures` | Capacités et infrastructures |
| `budget_ressources` | Ressources et projections budgétaires |
| `crises_resilience` | Crises et résilience |
| `incertitude_analyses` | Sensibilité et incertitude |

### C.3 Statuts

| Statut | Signification |
|---|---|
| `disponible` | LOOP clôturé : schéma, version et version de formules exposés |
| `planifie` | Métadonnées seules ; `version`, `version_formules` et `schema` **null** |
| `bloque_business` | Hypothèse ou seuil institutionnel non validé |

### C.4 Compatibilités (préliminaires, à confirmer par l'audit de chaque scénario)

`moteur_existant`, `configuration`, `extension_temporelle`,
`extension_par_niveau`, `extension_cohorte`, `extension_capacite`,
`extension_repartition`, `extension_budgetaire`, `analyse_nouvelle`,
`decision_institutionnelle`.

### C.5 Séparation stricte métadonnées / exécution

Le catalogue **ne calcule pas**, **ne persiste pas** et **ne remplace pas**
`ServiceSimulationTemporelle`. Le hash de définition (catalogue) est distinct
du hash d'exécution (service). Aucune seconde base d'exécution n'est créée.

---

## D. Conception et développement (Phases C–D)

### D.1 Fichiers créés / modifiés

| Fichier | Nature |
|---|---|
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | **Créé** — catalogue, validation, hash, intégrité |
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | **Modifié** — constantes `BORNE_DELTA_MIN/MAX` extraites, réutilisées par le catalogue |
| `app/Modules/SimulationTemporelle/Http/Controllers/SimulationTemporelleController.php` | **Modifié** — méthode `catalogue()` (Gate `simulation.view`) |
| `app/Modules/SimulationTemporelle/Routes/web.php` | **Modifié** — route `GET admin/simulation-temporelle/catalogue` |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | **Créé** — 10 tests ST-14.00 |

### D.2 API du catalogue

| Méthode | Rôle |
|---|---|
| `tout()` | 45 entrées : `SOCLE-EFFECTIFS` + 44 scénarios planifiés, cache mémoire |
| `exiger($code)` | Définition ou `DomainException` si inconnu |
| `exigerDisponible($code)` | Refus si statut ≠ `disponible` |
| `estDisponible($code)` | Booléen sans exception |
| `validerParametres($code, $params)` | Validation stricte par schéma, clés inconnues refusées, aucune valeur par défaut |
| `empreinteScenario(...)` | Hash SHA-256 de définition |
| `empreinteCatalogue()` | Hash déterministe du catalogue complet |
| `verifierIntegrite()` | Codes, catégories, statuts, schémas, dépendances |
| `validerVersion($v)` | Entier ≥ 1 |
| `schemaSocle()` | Schéma du seul scénario disponible |

### D.3 Hash de définition

Inclut : version de contrat, code, version de scénario, version de formules,
paramètres **canonisés** (tri récursif des clés), faculté, graine, version
moteur. Sérialisation `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
JSON_THROW_ON_ERROR`.

### D.4 Schéma du socle (`SOCLE-EFFECTIFS`, version 1, formules 1.0)

| Paramètre | Type | Requis | Bornes | Unité |
|---|---|---|---|---|
| `delta_admissions` | number | oui | `[-0.5, 2.0]` | ratio annuel composé |
| `bornes.min` / `bornes.max` | number | oui | `[-0.5, 2.0]` | — |
| `horizon_annees` | integer | oui | `[1, 6]` | années |
| `taux.passage` | number | si `taux` | `[0, 1]` | proportion |
| `taux.redoublement` | number | si `taux` | `[0, 1]` | proportion |
| `taux.abandon` | number | si `taux` | `[0, 1]` | proportion |
| `niveau_id` | integer | non | ≥ 1 | identifiant |
| `groupes[]` | integer | non | ≥ 1 | identifiants |

Règles supplémentaires : `min ≤ max`, `delta ∈ [min, max]`,
`passage + redoublement + abandon ≤ 1`, `groupes` interdit sans `niveau_id`.

---

## E. Validation mathématique (Phase E)

Aucune formule nouvelle : le catalogue ne calcule aucun effectif. Les contrôles
portent sur les **bornes** et la **cohérence des taux**, alignés sur les
constantes du moteur (`BORNE_DELTA_MIN/MAX`, `PLAFOND_HORIZON`).

Cas vérifiés par les tests :

- delta hors bornes → refus ;
- `min > max` → refus ;
- delta hors de ses propres bornes → refus ;
- somme des taux > 1 → refus ;
- taux partiel → refus ;
- horizon 7 → refus ;
- `INF` → refus (« valeur non finie ») ;
- groupes sans niveau → refus ;
- entrée valide restituée **à l'identique**, sans clé ajoutée.

---

## F. Sécurité (Phase F)

| Contrôle | Preuve |
|---|---|
| Permission dédiée | Route sous `can:simulation.view` ; test 403 sans habilitation |
| Aucune permission générique d'écriture | Route `GET` uniquement, aucune mutation |
| Isolation facultaire | Le catalogue est un **contrat global** : même empreinte et même contenu pour un admin global et un admin facultaire ; aucun `faculte_id` exposé |
| Anti-fuite de données métier | Test : absence de `faculte_id`, `hash_resultat`, `etudiants` dans la réponse |
| Aucune écriture | Test avant/après sur 6 tables (`facultes`, `etudiants`, `inscriptions_academiques`, `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`) |
| Throttling | Non ajouté : lecture pure de métadonnées statiques, sans paramètre utilisateur |

**Réserve de sécurité :** le catalogue étant global et sans données métier,
l'isolation facultaire ne s'applique pas à son contenu — c'est un choix assumé,
documenté et testé.

---

## G. Tests (Phase G) — résultats réels

Commande : `php artisan test --filter="SimulationTemporelle"`

| Indicateur | Valeur |
|---|---|
| Tests exécutés | **62** |
| Réussis | **62** |
| Échoués | **0** |
| Incomplets | **0** |
| Ignorés | **0** |
| Assertions | **589** |
| Pint (`--dirty`) | **OK** (3 fixers appliqués puis re-test vert) |
| Non-régression | **52 tests / 312 assertions** historiques inchangés |
| Écriture métier détectée | **Non** |

Détail : 52 tests historiques + 10 tests `SimulationTemporelleCatalogueTest`
(277 assertions).

### G.1 Les 10 tests du catalogue

1. `test_catalogue_expose_45_entrees_et_une_empreinte_stable`
2. `test_catalogue_refuse_sans_permission_dediee`
3. `test_catalogue_est_independant_du_perimetre_facultaire_et_ne_divulgue_aucune_donnee_metier`
4. `test_integrite_du_catalogue_codes_versions_et_statuts`
5. `test_code_inconnu_et_scenario_planifie_refuses`
6. `test_parametres_invalides_ou_hors_bornes_refuses`
7. `test_parametres_valides_acceptes_sans_valeur_par_defaut`
8. `test_hash_de_definition_deterministe_et_complet`
9. `test_version_invalide_refusee`
10. `test_catalogue_ne_modifie_aucune_table_ni_donnee_metier`

---

## H. UX (Phase H)

Aucune interface graphique ajoutée à ce stade. La route JSON
`admin.simulation-temporelle.catalogue` expose :

- `contrat_version`
- `version_moteur`
- `empreinte`
- `categories`
- `scenarios[]` (45 entrées)

L'intégration visuelle appartient aux LOOP de scénarios, sans dupliquer le
formulaire existant.

---

## I. Limites et réserves

| ID | Réserve | Gravité | Suite |
|---|---|---|---|
| ST14-R1 | 44 scénarios `planifie` sans schéma exécutable : aucune exécution possible avant leur LOOP | ATTENDU | Chaque LOOP fournit schéma + version + formules |
| ST14-R2 | Classification de compatibilité **préliminaire** (non confirmée par audit individuel) | MOYEN | Confirmer dans `00-audit.md` de chaque scénario |
| ST14-R3 | Le catalogue n'est pas encore exposé dans l'UI Blade | FAIBLE | Phase H des scénarios |
| ST14-R4 | `CatalogueScenarios` dépend de constantes du service ; un changement futur de bornes impacte le schéma | INFO | Test d'intégrité existant + revue |
| ST14-R5 | Pas de throttling sur la route catalogue | INFO | Acceptable (lecture statique) ; à revoir si le catalogue devient volumineux |

---

## J. Verdict ST-14.00

### ✅ PASS_WITH_RESERVATIONS

**Justification :** le contrat commun existe, il est unique, testé, déterministe,
sécurisé en lecture et sans aucune écriture métier. Le GATE est respecté : un
seul format d'entrée, un seul format de résultat, un hash de définition complet,
et l'interdiction structurelle d'exécuter un scénario non clôturé
(`exigerDisponible`).

Les réserves sont attendues (scénarios non encore développés) ou non critiques
(UI différée, throttling non nécessaire). Aucune anomalie d'intégrité, de calcul
ou de fuite de données n'a été détectée.

**Portée :** ce verdict autorise l'ouverture du LOOP **SIM-01** uniquement. Il
n'autorise aucune exécution des scénarios `planifie` et aucune modification des
données académiques réelles.
