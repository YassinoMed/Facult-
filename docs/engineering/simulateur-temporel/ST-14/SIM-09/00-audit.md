# SIM-09 — 00 · Audit

**Date :** 2026-11-05  
**Scénario :** SIM-09 — Hausse du redoublement  
**Famille :** 2 — Réussite, redoublement et abandon  
**Lot :** A (Admissions, démographie et réussite)  
**Mode :** AUDIT_ONLY préalable  

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS et SIM-01 à SIM-08 disponibles ; SIM-09 à CR-06 planifiés (9 disponibles / 36 planifiés). |
| **Moteur** | `ServiceSimulationTemporelle` — version `1.7` ; machinerie de **double jeu de taux** (SIM-07/08) : trajectoire témoin parallèle en mémoire, `analyserReussite()` générique (`$cleVariation` + magnitude), sortants restitués. |
| **Contrainte d'axe** | La machinerie v1.7 est **câblée sur l'axe passage** : `taux_ameliore` impose `passage` strictement supérieur, `taux_degrade` strictement inférieur. Aucun autre axe (redoublement, abandon) n'est adressable. |
| **Analyse préalable** | SIM-09→SIM-12 partagent la même besoin : comparer un jeu modifié sur UN axe (redoublement ou abandon, hausse ou baisse). SIM-13 (combinaisons) relèvera d'un contrat multi-axes distinct. |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune nouvelle table nécessaire. |
| **Tests existants** | **128 tests / 1279 assertions** à 100 % au vert (vérifié avant ouverture). |

---

## B. État initial de SIM-09 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Statut | `planifie` |
| Version / formules / schéma | `null` |
| Disponibilité | `false` |
| Compatibilité déclarée | `configuration` |
| Dépendances | `[]` |
| Objectif déclaré | « Évaluer l'accumulation liée à une hausse du redoublement. » |

---

## C. Analyse du besoin fonctionnel

### C.1 Définition métier

La « hausse du redoublement » mesure l'effet d'un **redoublement déclaré en
hausse** sur :

1. les **effectifs** : accumulation mécanique d'étudiants qui restent dans
   le niveau (le redoublant ne progresse pas) ;
2. les **sortants** : le pipeline d'entrée étant inchangé, l'effet aval est
   plus lent mais non nul ;
3. la **hausse réellement déclarée** du redoublement.

### C.2 Écart par rapport au moteur v1.7

| Besoin SIM-09 | Capacité existante (v1.7) | Écart |
|---|---|---|
| Deux jeux de taux déclarés | Oui | Réutilisés tels quels |
| Trajectoire témoin parallèle | Oui | Réutilisée telle quelle |
| Restitutions neutres (référence, écarts, sortants, effet) | Oui | Réutilisées telles quelles |
| **Axe générique** (redoublement / abandon) | Câblé sur passage uniquement | Descripteur `axe_variation` + `sens_variation` à introduire |
| Champ de variation nommé par axe | `hausse_passage` / `baisse_passage` uniquement | Généralisation : `hausse_redoublement`, `baisse_abandon`, etc. |

---

## D. Options d'implémentation examinées

### Option 1 — Réutiliser `taux_degrade` (passage en baisse) pour approximer

- **Rejet :** la validation (passage strictement inférieur) serait fausse
  pour un redoublement en hausse à passage constant ; le champ
  `baisse_passage` mentirait sur l'axe réellement étudié.

### Option 2 — Une clé dédiée par scénario (`taux_redoublement_hausse`, ...)

- **Rejet :** quatre clés supplémentaires (SIM-09→12) puis d'autres pour
  SIM-13 ; surface d'entrée qui explose, messages et schémas dupliqués.

### Option 3 — Descripteur générique `taux_modifie` + `axe_variation` + `sens_variation` (retenue)

- **Nouveau contrat générique :** jeu modifié complet + axe ∈
  {`passage`, `redoublement`, `abandon`} + sens ∈ {`hausse`, `baisse`}.
- **Validation stricte** : l'axe déclaré bouge strictement dans le sens
  déclaré ; les autres taux sont libres (origine **déclarée**, aucune
  compensation inventée) ; sommes ≤ 1.
- **Restitution** : `$cleVariation = sens_axe` (ex. `hausse_redoublement`)
  + les cinq champs neutres déjà publiés.
- **Rétrocompatibilité :** `taux_ameliore` et `taux_degrade` restent des
  **alias** exacts (axe passage) ; leurs contrats publiés, messages et
  champs sont strictement préservés ; le moteur normalise les trois formes
  vers un descripteur commun interne.
- Chaque scénario (SIM-09→12) **épingle** son axe et son sens au niveau du
  schéma catalogue (`valeurs`).

---

## E. Reclassement de compatibilité

| Avant | Après | Justification |
|---|---|---|
| `configuration` | **`analyse_nouvelle`** | Même famille de justification que SIM-07/08 : double trajectoire + restitution des sortants + descripteur d'axe — non réalisable par configuration des paramètres existants. |

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM09-A1` | Régression SOCLE / SIM-01→08 si le mode générique s'active par défaut | HAUTE | `taux_modifie` strictement optionnel ; alias et branches existantes inchangés. |
| `SIM09-A2` | Axe ou sens hors vocabulaire | MOYEN | Schéma catalogue (`valeurs`) + validation moteur (`in_array`). |
| `SIM09-A3` | Axe déclaré non modifié (ou modifié dans le mauvais sens) | MOYEN | Règle stricte `modifié[axe] ≠ référence[axe]` dans le sens déclaré (catalogue + moteur). |
| `SIM09-A4` | Somme modifiée > 1 | MOYEN | Validation stricte des deux jeux. |
| `SIM09-A5` | Combinaisons avec gel / choc / cible / creux / autres axes | MOYEN | Refus explicites (SIM-03, SIM-04, SIM-05, SIM-06, exclusion mutuelle des trois formes). |
| `SIM09-A6` | Trois formes simultanées (ameliore + degrade + générique) | MOYEN | Exclusion mutuelle par ordre des blocs (SIM-07 → SIM-08 → générique), testée. |
| `SIM09-A7` | Analyses OAT/MC sur la référence au lieu du jeu modifié | MOYEN | Normalisation du descripteur réutilisée par `criteresAnalyses`. |
| `SIM09-A8` | Effet aval (sortants) faible à horizon 1 | FAIBLE | Naturel : le pipeline aval est lent ; documenté et asserté (`effet_sortants = 0` au cas B). |

---

## G. Décision et Gate A

- **Compatibilité confirmée :** `analyse_nouvelle`.
- **Incrément moteur :** passage à la version `1.8` (`VERSION_MOTEUR = '1.8'`).
- **Périmètre :** clés génériques (`taux_modifie`, `axe_variation`,
  `sens_variation`), normalisation des trois formes, quatre nouveaux champs
  de variation possibles (`hausse_|baisse_` × `redoublement|abandon`),
  aucune table.
- **Cas de référence hors moteur (vérifiés) :**
  - Cas A : ref `{0.6,0.2,0.2}` vs mod `{0.6,0.3,0.1}`, δ=0, H=2 →
    mod `[60,46,39]` sortants `[18,13]` vs ref `[60,40,31]` sortants
    `[18,11]` ; écarts `[+6,+8]` ; effet sortants `+2`.
  - Cas B : ref `{0.6,0.2,0.1}` vs mod `{0.6,0.3,0.1}`, δ=0, H=1 →
    `[60,46]` vs `[60,40]` ; effet sortants `0`.
  - Cas C : δ=+10 % → admissions `[11,12]` ; mod `[60,47,41]` vs ref
    `[60,41,35]` ; écarts `[+6,+6]`.
- **Gate A :** validé. Le sous-LOOP passe en Phase B.
