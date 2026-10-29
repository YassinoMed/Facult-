# 02 — RÉSULTATS DE L'AUDIT (MESURE)

> **Phase** : AUDIT — lecture seule stricte. Aucune écriture en base.
> **Date** : 19/10/2026
> **Base** : MySQL `inscription_etudiants`
> **Reproductibilité** : `php artisan tinker --execute="require 'docs/audit-referentiel/mesure.php';"`
> **Sorties** : `03-ANOMALIES.csv` (75 lignes), `04-STATISTIQUES.json`

---

## 1. Méthodologie

### 1.1 Périmètre mesuré

| Table | Lignes |
|---|---:|
| `inscriptions_academiques` | 2 085 |
| `groups` | 160 |
| `matieres` | 66 |
| **Total** | **2 311** |

Référentiel de comparaison : **4 programmes**, tous rattachés à **FST**.

### 1.2 Normalisation

Deux niveaux, jamais confondus :

| Niveau | Règle | Statut |
|---|---|---|
| **Exact** | Égalité stricte après `trim` avec `programmes.code` ou `programmes.nom` | Correspondance confirmée |
| **Normalisé** | `trim` + espaces compactés + minuscules + accents repliés + ponctuation réduite | **Candidat** — jamais confirmé |
| **Partiel** | Inclusion de chaîne (`str_contains`) | **Indice** — jamais confirmé, jamais exploité automatiquement |

Une valeur n'est comptée « résolue » que si elle produit **exactement une** correspondance au même niveau. Deux correspondances ou plus ⇒ `AMBIGU`.

### 1.3 Catégories

| Code | Signification |
|---|---|
| `EXACT_CODE` | Égalité stricte avec `programmes.code` |
| `EXACT_NOM` | Égalité stricte avec `programmes.nom` |
| `CANDIDAT_NORMALISE` | Égalité après normalisation |
| `CANDIDAT_PARTIEL` | Inclusion de chaîne, non confirmée |
| `AMBIGU` | Plusieurs programmes correspondent |
| `NON_RESOLUE` | Aucune correspondance dans le périmètre |
| `VIDE` | `NULL` ou chaîne vide |
| `HORS_PERIMETRE` | La faculté de la ligne n'a **aucun** programme au référentiel |
| `INCOHERENCE_NIVEAU` | `niveau_id` appartient à un autre programme que `programme_id` |
| `DIVERGENCE_ETUDIANT` | `programme_id` de l'inscription ≠ celui de l'étudiant |

---

## 2. Indicateurs globaux

| Indicateur | Inscriptions | Groupes | Matières |
|---|---:|---:|---:|
| Lignes analysées | 2 085 | 160 | 66 |
| Valeurs `filiere` non vides | 71 | 4 | 0 |
| Valeurs distinctes non nulles | 4 | 3 | 0 |
| `programme_id` renseigné | 12 (0,58 %) | 7 (4,38 %) | n/a |
| `niveau_id` renseigné | 12 (0,58 %) | 1 (0,63 %) | n/a |
| Correspondances exactes par code | 10 | 0 | 0 |
| Correspondances exactes par libellé | 0 | 0 | 0 |
| Candidats normalisés | 0 | 0 | 0 |
| Candidats partiels (non confirmés) | 0 | 3 | 0 |
| Cas ambigus | 0 | 0 | 0 |
| Valeurs non résolues | 12 | 1 | 0 |
| Valeurs vides / nulles | 2 014 | 156 | 66 |
| Hors périmètre référentiel | 49 | 0 | 0 |
| Incohérences de niveau | **10** | 0 | 0 |
| Divergences identifiant ↔ texte | 0 | 0 | 0 |
| Programme archivé / inactif | 0 | 0 | 0 |

### 2.1 Lecture immédiate

- **Le champ `filiere` est quasi vide** : 2 236 lignes sur 2 311 (96,8 %) n'ont aucune valeur.
- **Le rattachement canonique est quasi inexistant** : 2 073 inscriptions sur 2 085 (99,4 %) et 2 047 étudiants sur 2 047 (100 %) n'ont pas de `programme_id`.
- **Le référentiel ne couvre qu'une faculté sur dix** : FST (4 programmes). Les 9 autres facultés n'ont aucun programme.
- **`matieres.filiere` est un champ mort** : 0 valeur sur 66, et 52 matières ne sont même pas rattachées à une UE.

---

## 3. Correspondances par table

### 3.1 `inscriptions_academiques` — 71 valeurs non vides

| Catégorie | Lignes | % des valeurs non vides | Valeurs concernées |
|---|---:|---:|---|
| `EXACT_CODE` (dont 10 incohérentes en niveau) | 10 | 14,1 % | `PAR-GEST` (6), `PAR-INFO` (3), `PAR-ECO` (1) |
| `NON_RESOLUE` | 12 | 16,9 % | `Sciences & Technologies` (FST) |
| `HORS_PERIMETRE` | 49 | 69,0 % | `Sciences & Technologies` (FDEG, FMP, FSIT, FLSH, FSE, FMD) |
| **Total** | **71** | 100 % | |

**Aucune correspondance exacte n'est saine** : les 10 correspondances par code présentent toutes une incohérence de niveau (cf. §4).

Valeurs distinctes (4) :

| Valeur | Lignes | Nature réelle |
|---|---:|---|
| `NULL` | 2 014 | Absence de donnée |
| `Sciences & Technologies` | 61 | **Libellé de domaine**, pas un code programme |
| `PAR-GEST` | 6 | Code programme |
| `PAR-INFO` | 3 | Code programme |
| `PAR-ECO` | 1 | Code programme |

Le champ mélange donc **deux niveaux hiérarchiques** : un domaine (`Sciences & Technologies`) et des codes de programme (`PAR-*`).

### 3.2 `groups` — 4 valeurs non vides

| Catégorie | Lignes | Valeurs |
|---|---:|---|
| `CANDIDAT_PARTIEL` | 3 | `Informatique` ×2 → `PAR-INFO` (« Informatique de gestion ») ; `Génie Logiciel` → `DEMO-LGL` |
| `NON_RESOLUE` | 1 | `MIAGE` |

Aucune correspondance exacte. Les 3 candidats partiels **ne doivent pas** être convertis automatiquement : `Informatique` peut désigner la mention, la spécialité ou le programme selon l'établissement.

### 3.3 `matieres` — 0 valeur

| Indicateur | Valeur |
|---|---:|
| `filiere` non vide | 0 / 66 |
| `ue_id` renseigné | 14 / 66 (21,2 %) |
| Programme atteignable via UE | 14 / 66 |

Le champ est structurellement mort. Sa suppression ne peut être décidée qu'après validation métier (cf. plan, lot 4).

---

## 4. Anomalie critique démontrée : `niveau_id` hors programme

**10 inscriptions** ont un `niveau_id` appartenant à un autre programme que leur `programme_id`.

| Inscription | Programme | `niveau` texte | `niveau_id` → programme |
|---:|---|---|---|
| 2074 | `PAR-GEST` | L1 | `L1` → `DEMO-LGL` |
| 2075 | `PAR-GEST` | L1 | `L1` → `DEMO-LGL` |
| 2076 | `PAR-GEST` | L1 | `L1` → `DEMO-LGL` |
| 2077 | `PAR-GEST` | L1 | `L1` → `DEMO-LGL` |
| 2078 | `PAR-INFO` | L1 | `L1` → `DEMO-LGL` |
| 2079 | `PAR-INFO` | L1 | `L1` → `DEMO-LGL` |
| 2080 | `PAR-ECO` | L1 | `L1` → `DEMO-LGL` |
| 2081 | `PAR-GEST` | L2 | `L2` → `DEMO-LGL` |
| 2082 | `PAR-GEST` | L2 | `L2` → `DEMO-LGL` |
| 2083 | `PAR-INFO` | L2 | `L2` → `DEMO-LGL` |

**Cause racine** : `app/Services/DataExchange/Definitions/InscriptionAcademiqueDefinition.php:217`

```php
$niveauObj = ! empty($ligne['niveau']) ? Niveau::where('code', trim($ligne['niveau']))->first() : null;
```

`niveaux.code` n'est unique que par programme. Le référentiel contient quatre `L1` (ids 1, 4, 5, 6) ; `first()` retourne systématiquement l'id 1, celui de `DEMO-LGL`.

**Preuve d'exécution** :

```
L1 -> niveau#1 (DEMO-LGL)
L2 -> niveau#2 (DEMO-LGL)
L3 -> niveau#3 (DEMO-LGL)
```

Le comparatif `GroupDefinition::resoudreNiveau()` (`GroupDefinition.php:146-150`) applique correctement `->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId))`. L'import d'inscriptions n'a pas reçu le même correctif.

**Portée** : le défaut est dans le code, pas seulement dans les données. Toute réexécution de l'import reproduira l'anomalie.

---

## 5. Répartition par faculté

### 5.1 Inscriptions (catégories)

| Faculté | Vides | Hors périmètre | Non résolues | Incohérences niveau |
|---|---:|---:|---:|---:|
| FST | 300 | 0 | 12 | 10 |
| FDEG | 286 | 10 | 0 | 0 |
| FMP | 286 | 9 | 0 | 0 |
| FSIT | 286 | 12 | 0 | 0 |
| FLSH | 286 | 4 | 0 | 0 |
| FSE | 285 | 8 | 0 | 0 |
| FMD | 285 | 6 | 0 | 0 |
| **Total** | **2 014** | **49** | **12** | **10** |

Programmes au référentiel par faculté :

| Faculté | Programmes |
|---|---:|
| FST | 4 |
| FDEG, FMP, FLSH, FSIT, FSE, FMD, FASE, USF, FSEGS | **0** |

### 5.2 Répartition par année universitaire

| Année | Inscriptions | Sans `programme_id` | Observations |
|---|---:|---:|---|
| 2021-2022 | 3 | 3 | Jeu de démonstration |
| 2022-2023 | 6 | 6 | Jeu de démonstration |
| 2023-2024 | 11 | 11 | Jeu de démonstration |
| 2024-2025 | 16 | 16 | Jeu de démonstration |
| 2025-2026 | 25 | 25 | Jeu de démonstration |
| 2026-2027 | 2 022 | 2 012 | Données de masse |
| 2027-2028 | 2 | 0 | 2 inscriptions reliées (`DEMO-LGL`) |

**2012 des 2012 inscriptions de 2026-2027 sans `programme_id`** : la donnée de masse n'a jamais été raccordée au référentiel.

---

## 6. Divergences identifiant ↔ texte

| Contrôle | Résultat |
|---|---|
| `inscription.programme_id` ≠ `etudiant.programme_id` (les deux renseignés) | **0** |
| `inscription.filiere` ≠ `programme.code` **et** ≠ `programme.nom` | **0** |
| `group.programme_id` d'une autre faculté que `group.faculte_id` | **0** |
| `group.niveau_id` d'un autre programme que `group.programme_id` | **0** |
| `etudiant.groupe_id` d'un autre programme que `etudiant.programme_id` | **0** |

Les rares lignes reliées sont cohérentes entre elles. Le problème est l'**absence** de lien, pas la contradiction des liens existants.

---

## 7. Données exclues

Aucune ligne n'a été exclue. Trois facultés (`FASE`, `USF`, `FSEGS`) n'ont aucune inscription ; elles n'apparaissent donc pas dans les répartitions mais ne faussent aucun taux.

---

## 8. Limites de la mesure

1. La base est une base de **développement**, non de production.
2. Le référentiel local ne contient que 4 programmes de démonstration ; un référentiel de production modifierait mécaniquement les taux de correspondance.
3. Les catégories `CANDIDAT_*` sont des indices, jamais des preuves.
4. La mesure porte sur la valeur stockée, pas sur l'intention métier : `Sciences & Technologies` est peut-être un domaine, une mention ou une filière locale — seule la scolarité peut le dire.

---

## 9. Conclusion de la phase AUDIT

| Question | Réponse mesurée |
|---|---|
| Le texte `filiere` diverge-t-il du référentiel ? | Non : il est **absent** dans 96,8 % des cas. La divergence n'est pas le problème principal. |
| Le référentiel est-il utilisé ? | Presque pas : 0,6 % des inscriptions, 4,4 % des groupes, 0 % des étudiants. |
| Existe-t-il une corruption de données ? | **Oui, 10 inscriptions** avec un `niveau_id` hors programme, causées par un défaut de code reproductible. |
| Une migration automatique est-elle possible ? | **Non** : aucune correspondance non ambiguë et non nulle ne le permet. |
| Une décision métier est-elle requise ? | **Oui** : nature de « filière », statut de « spécialité », niveau du « parcours ». |

**Verdict de phase** : audit reproducible, anomalies prouvées, aucune écriture effectuée. Les corrections dépendant du vocabulaire restent **bloquées** jusqu'à validation métier ; le correctif de bornage `niveau_id` est indépendant de cette décision et peut être planifié en lot 0.
