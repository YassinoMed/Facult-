# SIM-01 — 00 · Audit préalable

**Campagne :** ST-14 — scénarios avancés
**Scénario :** SIM-01 — Croissance des admissions
**Phase :** A — Audit
**Date :** 2026-11-02
**Mode :** AUDIT_ONLY / SIMULATION / TESTING — données métier en lecture seule

---

## A.1 Objet de l'audit

Vérifier, dans le code réel, ce que le moteur `ServiceSimulationTemporelle`
sait déjà faire pour une **croissance des admissions**, ce qu'il expose
réellement dans son résultat public, et ce qui manque pour que SIM-01 soit un
scénario exploitable sans dupliquer le moteur ni inventer de formule.

Question directrice : *SIM-01 est-il une simple configuration du moteur
existant, ou exige-t-il une extension ?*

---

## A.2 Sources examinées

| Fichier | Rôle constaté |
|---|---|
| `Services/ServiceSimulationTemporelle.php` | Moteur autoritaire : validation, simulation, snapshots, hash, exécution |
| `Adaptateurs/AdaptateurEffectifsCohortes.php` | Lecture seule des effectifs validés, agrégés par niveau |
| `Support/HorlogeSimulee.php` / `Support/MoteurEvenements.php` | Temps virtuel et événements annuels |
| `Support/EstimateurCapacitesGroupes.php` | Groupes théoriques, saturation, places manquantes |
| `Support/ComparateurProjections.php` | Écarts annuels, écart à la cible |
| `Support/GestionnaireAlertesSeuils.php` | Alertes de variation et de saturation |
| `Support/CatalogueScenarios.php` | Contrat de définition ST-14 (métadonnées) |
| `Http/Controllers/SimulationTemporelleController.php` | Exécution, permissions, périmètre facultaire |
| `Routes/web.php` (module) | Routes et middlewares |
| `tests/Feature/SimulationTemporelleTest.php` | 52 tests historiques du moteur |
| `docs/engineering/simulateur-temporel/ST-11/06-audit-mathematique.md` | Formules et conventions de conservation |

---

## A.3 Sémantique réelle du moteur (constatée dans le code)

| Élément | Comportement réel |
|---|---|
| Admissions | `admissions(pas n) = round(baseL1 × (1 + delta)^n)` |
| `baseL1` | `reset($cohortesDepart)` — **premier niveau de la cohorte**, pas nécessairement L1 |
| Delta | Global, composé, identique pour tous les niveaux ; bornes `[-0.5, +2.0]` |
| Taux | `passage`, `redoublement`, `abandon` globaux et identiques pour tous les niveaux |
| Absence de taux | Les trois taux valent 0 : tout l'effectif sort en « autres issues », seules les nouvelles admissions restent |
| Horizon | Entier de 1 à 6 (`PLAFOND_HORIZON`) |
| Variantes | 1 à 20 ; variante 0 = delta saisi, variantes suivantes = générateur déterministe |
| Snapshots | Pas 0 (état initial) à horizon ; chaque snapshot porte `cohortes`, `total`, `admissions` |
| Persistance | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions` uniquement |

---

## A.4 Sortie publique avant le LOOP

Avant modification, chaque variante exposait :

- `total_final`
- `totaux` (pas 0..horizon)
- `variations_annuelles`
- `score`, `contributions`, `violations`

Les admissions par période existaient **dans les snapshots persistés**, mais
n'étaient **pas exposées dans `mesures`** ni dans le `classement`. Un appelant
devait reconstruire la trajectoire à partir des snapshots ou de la formule, ce
qui violait le principe « un seul format de résultat ».

---

## A.5 Écart réel et nature de SIM-01

| Besoin SIM-01 | État constaté | Nature |
|---|---|---|
| Delta de croissance composé | Présent | Configuration |
| Taux explicites | Présents mais optionnels | Configuration + règle de contrat |
| Admissions par période dans le résultat | **Absentes du résultat public** | Exposition additive |
| Effectifs par période | Présents (`totaux`) | Configuration |
| Écart à la cible | Présent (`cible_total`) | Configuration |
| Capacités et groupes requis | Présents | Configuration |
| Alertes de variation / saturation | Présentes | Configuration |

**Conclusion :** SIM-01 est une **configuration** du moteur existant, complétée
par une **exposition additive** de données déjà calculées. Aucune formule
nouvelle, aucun service parallèle, aucune table nouvelle.

---

## A.6 Permissions, routes et persistance

| Contrôle | Constat |
|---|---|
| Catalogue | `GET admin/simulation-temporelle/catalogue`, `can:simulation.view` |
| Exécution | `POST admin/simulation-temporelle/scenarios/{id}/executer`, `can:simulation.executer` + `throttle:10,1,st-exec` |
| Périmètre | Scénario facultaire hors périmètre → 403 avant tout calcul |
| Erreurs métier | `DomainException` → HTTP 422 avec message explicite |
| Écritures métier | Aucune : l'adaptateur est en lecture stricte |
| Écritures techniques | 1 exécution + (horizon + 1) snapshots, avec hash et version moteur |

---

## A.7 Anomalies et réserves détectées

| ID | Constat | Gravité | Traitement SIM-01 |
|---|---|---|---|
| SIM01-R1 | Sans `taux`, les cohortes existantes sont vidées (tout en « autres issues ») alors que l'avertissement parle de cohortes « gelées » | **MOYEN** | Les taux sont **requis** par le schéma SIM-01 ; anomalie du socle documentée, non corrigée dans ce LOOP |
| SIM01-R2 | Seuils d'alerte par défaut 15 % / 95 % : valeurs du moteur, non validées institutionnellement | MOYEN | Documentés comme hypothèses, jamais présentés comme seuils officiels |
| SIM01-R3 | `baseL1` = premier niveau de la cohorte ; avec `niveau_id`, la base devient ce niveau | INFO | Hypothèse explicite dans la fiche et les tests |
| SIM01-R4 | Taux globaux, non différenciés par niveau | INFO | Hors périmètre (extension par niveau, LOT B) |
| SIM01-R5 | Capacité par défaut 35 si aucun groupe réel | MOYEN | Les tests SIM-01 utilisent un groupe réel de capacité 15 ; le défaut reste signalé par `capacite_groupe_hypothese = true` |
| SIM01-R6 | La règle « croissance uniquement » n'est portée que par le catalogue ; un scénario libre créé hors catalogue peut avoir un delta négatif | **MOYEN** | Règle de contrat testée au niveau catalogue ; le moteur reste générique |
| SIM01-R7 | Aucun écran dédié SIM-01 | FAIBLE | Le formulaire existant suffit ; la Phase H documente l'usage |

---

## A.8 Décisions d'audit

1. **Ne pas modifier les formules** : la trajectoire d'admissions est reprise
   des snapshots, sans recalcul.
2. **Incrémenter la version moteur** de `1.0` à `1.1`, car le résultat public
   change de forme (nouveau champ). Le hash d'entrées inclut la version : les
   exécutions antérieures ne sont pas confondues avec les nouvelles.
3. **Exiger les taux** dans le schéma SIM-01 : sans eux, les effectifs projetés
   ne représentent aucune progression et les indicateurs de capacité seraient
   trompeurs.
4. **Ne pas corriger l'anomalie R1** dans ce LOOP : elle touche le socle et
   mérite un LOOP dédié avec mesure d'impact.

---

## A.9 Verdict de phase

**Audit : PASS.** SIM-01 est réalisable sans duplication, avec une exposition
additive et une règle de contrat stricte. Les réserves sont documentées et
n'empêchent pas la validation mathématique indépendante.
