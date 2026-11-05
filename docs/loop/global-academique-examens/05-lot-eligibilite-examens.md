# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 05 · Lot 2 — Éligibilité aux examens

**Date :** 2026-11-03  
**Périmètre :** éligibilité unitaire et par populations, décision explicable  
**Mode :** vérification et clôture — aucune règle métier nouvelle  

---

## 1. Objectif du lot

Prouver que l'éligibilité aux examens est **fail-closed** : en cas de donnée
incomplète, incohérente ou de règle non validée, le service produit une
**anomalie explicite**, jamais une autorisation implicite.

---

## 2. Service canonique

`ServiceEligibiliteExamen::evaluer` retourne une
`DecisionEligibiliteExamen` contenant :

| Champ | Rôle |
|---|---|
| `decision` | `eligible` / `non_eligible` / `anomalie` |
| `source` | Origine de la décision |
| `regle` | Règle appliquée |
| `motif` | Explication lisible |
| `donneesCompletes` | Indique si toutes les données nécessaires étaient présentes |
| `evalueLe` | Horodatage |
| `inscriptions` | Identifiants des inscriptions évaluées |

Le service est le **propriétaire unique** de la décision. Les groupes
virtuels, la génération de candidats et la préparation d'examens le
consomment ; aucun ne réimplémente la règle.

---

## 3. Règles vérifiées

| ID | Règle | Preuve |
|---|---|---|
| ELG-01 | Inscriptions actives + cohérence programme ⇒ éligible ; sinon décision explicable | `EligibiliteExamenTest` |
| ELG-02 | Données insuffisantes ⇒ `anomalie`, jamais `eligible` | `EligibiliteExamenTest` |
| ELG-03 | Rattrapage : règle non validée ⇒ anomalie explicite | `EligibiliteExamenTest` |
| ELG-04 | Facade d'inscription et orientation alignées sur le même contrat | `InscriptionEligibiliteFacadeTest`, `InscriptionEligibiliteOrientationTest` |

---

## 4. Exécution réelle du 2026-11-03

```bash
php artisan test --filter="EligibiliteExamenTest|InscriptionEligibiliteFacadeTest|InscriptionEligibiliteOrientationTest|InscriptionEligibilityMensTest"
```

```json
{"tool":"phpunit","result":"passed","tests":32,"passed":32,"assertions":150,"duration_ms":1958}
```

- **32 / 32 tests**, **150 assertions**, 0 échec.
- Observation de performance consignée par le test : **27 requêtes SQL par
  évaluation** sur SQLite de test — à surveiller pour les campagnes de masse,
  sans être un échec fonctionnel.

---

## 5. Points de vigilance

| ID | Constat | Traitement |
|---|---|---|
| `ELG-P1` | 27 requêtes par évaluation unitaire | Optimisation possible (eager loading) ; hors périmètre de ce LOOP, aucun impact sur la justesse. |
| `ELG-P2` | Règles de rattrapage non validées institutionnellement | Maintenues en anomalie explicite ; décision métier attendue. |
| `ELG-P3` | Les routes modules ne portent pas `mfa.obligatoire` | Constat systémique `ANOM-A9`, documenté, non corrigé localement. |

---

## 6. Verdict du lot

**PASS_WITH_RESERVATIONS.** L'éligibilité est fail-closed, explicable et
consommée sans duplication. Les réserves sont des optimisations ou des
décisions métier, pas des contradictions de contrat.
