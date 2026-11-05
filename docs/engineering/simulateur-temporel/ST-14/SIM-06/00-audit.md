# SIM-06 — 00 · Audit

**Date :** 2026-11-03  
**Scénario :** SIM-06 — Récupération démographique  
**Famille :** 1 — Démographie et admissions  
**Lot :** A (Admissions et démographie)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS et SIM-01 à SIM-05 disponibles ; SIM-06 à CR-06 planifiés (6 disponibles / 39 planifiés). |
| **Moteur** | `ServiceSimulationTemporelle` — version `1.4` ; trajectoire de fond composée (`delta`), gel (SIM-03), choc ponctuel (SIM-04), cible de croissance (SIM-05). |
| **Profils temporels connus** | (1) croissance/décroissance composée continue ; (2) interruption plate de N années (gel) ; (3) choc additif ponctuel ; (4) comparaison à une cible composée. **Aucun profil en creux avec retour progressif** n'existe. |
| **Compatibilité déclarée** | `extension_temporelle` (paramètre agissant sur la forme temporelle des admissions). |
| **Adaptateur** | `AdaptateurEffectifsCohortes::etatInitial` — lecture seule, sans PII. |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **103 tests / 968 assertions** à 100 % au vert (vérifié avant ouverture). |

---

## B. État initial de SIM-06 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version / formules / schéma | `null` |
| Disponibilité | `false` |
| Compatibilité | `extension_temporelle` |
| Dépendances | `[]` |
| Objectif déclaré | « Simuler une baisse suivie d'un retour progressif au niveau de référence. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

La « récupération démographique » décrit une **dépression temporaire des
admissions** suivie d'un **retour progressif au niveau de référence** :

- baisse de la démographie du bassin de recrutement (natalité, migration) ;
- suspension temporaire d'un partenariat ou d'une filière ;
- effet différé d'une crise, avec plan de reconquête ;
- sortie de zone d'attraction puis rétablissement.

Trois éléments doivent être maîtrisés et saisis :

1. **Profondeur** du creux, relative à la trajectoire de référence ;
2. **Durée de la baisse** (temps pour atteindre le creux) ;
3. **Durée de la récupération** (temps pour revenir à la référence).

Le scénario doit permettre de vérifier que le retour est **effectivement
observé dans l'horizon** simulé.

### C.2 Écart par rapport au moteur v1.4

| Besoin SIM-06 | Capacité existante | Écart |
|---|---|---|
| Creux **multiplicatif** relatif à la trajectoire de fond | Aucune | Facteur de creux à introduire |
| Rampe de baisse progressive | Gel = interruption **plate** (facteur 0) | Forme progressive inexistante |
| Retour progressif **exact** à la référence | Aucun | Rampe de récupération, atteinte au pas `d + r` |
| Profondeur et durées saisies | Aucune | Trois paramètres requis |
| Trajectoire de référence restituée | Non | Champs de comparaison (référence, écarts, creux, cumul) |

---

## D. Options d'implémentation examinées

### Option 1 — Réutiliser le gel (SIM-03) en deux temps

- **Rejet :** le gel est une interruption **plate** (admissions = 0) ; il ne
  produit ni creux progressif ni retour progressif, et sa combinaison avec
  une reprise reste un plateau suivi d'un saut, pas une rampe.

### Option 2 — Deux taux composés (taux de baisse puis taux de reprise)

- **Rejet :** deux taux composés ne garantissent **pas** le retour exact au
  niveau de référence (il faudrait que $(1+\tau_b)^d \times (1+\tau_r)^r = 1$,
  contrainte non exprimable simplement) ; le nombre de paramètres augmente
  (deux taux + deux durées) sans garantir l'objectif du scénario.

### Option 3 — Creux multiplicatif sur la trajectoire de référence, rampes linéaires (retenue)

- **Trois paramètres saisis :** `profondeur_baisse` ∈ ]0 ; 1],
  `duree_baisse_annees` ≥ 1, `duree_recuperation_annees` ≥ 1.
- **Formule :** la trajectoire de référence reste
  $A_{ref}(p) = \text{round}(baseL1 \times (1+\delta)^p)$ ; un facteur
  linéaire $f(p)$ descend de 1 à $1 - profondeur$, puis remonte de
  $1 - profondeur$ à 1 exactement au pas $d + r$.
- **Garantie :** le creux est atteint au pas $d$ et le retour à la référence
  au pas $d + r$, **exactement**, sans dépendre des arrondis de taux.
- **Règle d'observabilité :** $d + r \le H$ — le retour doit être visible
  dans l'horizon, sinon le scénario ne démontre pas la récupération.
- Aucune nouvelle table, aucun nouveau service.

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `extension_temporelle` | **`extension_temporelle` (confirmée)** | Le scénario agit sur la forme temporelle des admissions via des paramètres datés (durées, profondeur). Aucune identité de cohorte, aucune capacité datée, aucune analyse nouvelle au sens de SIM-05 : la capacité reste dans le moteur unique. |

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM06-A1` | Régression SOCLE / SIM-01→05 si le creux s'applique par défaut | HAUTE | Paramètres strictement optionnels (`null` par défaut) ; aucun changement de la branche existante. |
| `SIM06-A2` | Profondeur inventée ou appliquée par défaut | HAUTE | `profondeur_baisse` requis, jamais de valeur par défaut. |
| `SIM06-A3` | Retour jamais observable (creux hors horizon) | MOYEN | Règle `duree_baisse + duree_recuperation ≤ horizon_annees` (catalogue + moteur). |
| `SIM06-A4` | Profondeur 0 (creux nul déguisé) ou > 1 (admissions négatives) | MOYEN | Profondeur ∈ ]0 ; 1] vérifiée dans les deux validateurs ; creux total = 1 autorisé, jamais moins de 0 admission. |
| `SIM06-A5` | Trio de paramètres incomplet | MOYEN | Indissociables : les trois clés sont requises ensemble (schéma + moteur). |
| `SIM06-A6` | Combinaison avec gel / choc / cible | MOYEN | Refus explicite : gel → SIM-03, choc → SIM-04, cible → SIM-05. |
| `SIM06-A7` | Forme de la rampe imposée (linéaire) | FAIBLE | Choix minimal documenté ; une autre forme exigerait un paramètre de profil (réserve `SIM06-R?`). |
| `SIM06-A8` | Arrondis à deux étages (référence, puis produit) | FAIBLE | Documenté et testé ; cas limites choisis hors frontière .5. |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `extension_temporelle`.
- **Incrément moteur :** passage à la version `1.5` (`VERSION_MOTEUR = '1.5'`).
- **Périmètre :** trois paramètres, quatre champs de mesures
  (`admissions_reference_par_periode`, `ecarts_recuperation_par_periode`,
  `ecart_recuperation_cumule`, `creux_recuperation`), aucune table.
- **Gate A :** validé. Le besoin, les options, les bornes et la
  non-régression sont maîtrisés. Le sous-LOOP passe en Phase B.
