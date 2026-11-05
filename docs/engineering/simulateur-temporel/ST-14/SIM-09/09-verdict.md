# SIM-09 — 09 · Verdict final

**Scénario :** SIM-09 — Hausse du redoublement  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-05  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-09 introduit le **descripteur d'axe générique**
(`taux_modifie` + `axe_variation` + `sens_variation`) sur le moteur unique
(`ServiceSimulationTemporelle` v1.8), tout en préservant **strictement** les
alias publiés SIM-07 (`taux_ameliore` / `hausse_passage`) et SIM-08
(`taux_degrade` / `baisse_passage`). La machinerie de comparaison
(`analyserReussite`) est réutilisée sans duplication. Les cas de référence
calculés hors moteur concordent exactement : modifié `[60,46,39]` +
sortants `[18,13]` vs référence `[60,40,31]` + sortants `[18,11]`, écarts
`[+6,+8]`, effet `+2` ; hausse absorbée par le « reste » : `[60,46]` vs
`[60,40]`, effet aval `0` à horizon 1 ; delta +10 % : admissions `[11,12]`
identiques. La rétrocompatibilité (aucune clé sans descripteur, alias
inchangés), la cohérence des analyses OAT/MC autour des taux modifiés, la
sécurité (403 ×2, isolation) et la lecture seule sont prouvées. La suite du
module passe de 128 à **136 tests / 1397 assertions**, sans échec, Pint
propre du premier coup.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — options, reclassement, cas vérifiés | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R9 | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur | — |
| **Conception** | ✅ PASS | `03-architecture.md` — normalisation, ordre des gardes, alias | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — v1.8, `CatalogueScenarios::sim09` | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture, exclusion | SIM09-SEC-1/2 |
| **Tests** | ✅ PASS | **7/7 SIM-09**, **20/20 catalogue**, **136/136 module** | — |
| **Non-régression** | ✅ PASS | 128 tests antérieurs inchangés (52 + 7×5 + 8 + 7 + 7 + 19) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — lecture des signes, effet aval | R4, R5 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-09/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-09 | **7 / 7** (premier essai) |
| Assertions SIM-09 | **83** |
| Tests catalogue | **20 / 20** |
| Assertions catalogue | **485** |
| Suite module Simulation Temporelle | **136 / 136** |
| Assertions module | **1397** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur, premier passage) |
| Écritures métier | **0** |
| Version moteur | `1.8` |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM09-R1` | Moteur passé en v1.8 : les exécutions v1.7 restent lisibles mais ne sont pas retournées par l'idempotence (hash d'entrées versionné) | INFO | Versionnement normal, traçabilité conservée. |
| `SIM09-R2` | Le résultat mesure l'effet **dans le modèle déclaré** : la conversion d'une politique réelle en taux modifiés reste une expertise humaine | MOYEN | Documenté ; aucune vraisemblance revendiquée. |
| `SIM09-R3` | Le résultat dépend de l'origine **déclarée** de la hausse (abandon↓, passage↓ ou reste) | INFO | Force du contrat (aucune compensation inventée) ; documenté. |
| `SIM09-R4` | « Sortants » = dernier niveau du **périmètre** | INFO | Définition explicite ; réserve héritée. |
| `SIM09-R5` | Taux globaux : pas de redoublement différencié par niveau | INFO | LOT B (extension par niveau). |
| `SIM09-R6` | Le moteur accepte tout axe du vocabulaire ; l'épinglage `redoublement/hausse` est porté par le schéma catalogue, non appelé par la création HTTP | MOYEN | Héritée (`SIM08-R6`) ; la validation moteur couvre la forme et la direction, le contrat d'axe reste au catalogue. |
| `SIM09-R7` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée (`SIM08-R7`) ; Famille 4 (SIM-20). |
| `SIM09-R8` | Doublement du calcul par variante (trajectoire témoin) | FAIBLE | Variantes ≤ 20, horizon ≤ 6 ; coût borné. |
| `SIM09-R9` | L'exclusion mutuelle repose sur **l'ordre** des blocs (SIM-07 → SIM-08 → générique) | INFO | Ordre explicite et commenté ; testé. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-09 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 10 disponibles / 35 planifiés (45 entrées), SIM-09
  `analyse_nouvelle` confirmée.
- **Famille 2 :** SIM-07, SIM-08 et SIM-09 clôturés ; SIM-10 → SIM-13 restants.
- **Prochain scénario fonctionnel autorisé :** `SIM-10 — Réduction du
  redoublement` (LOT A) — même descripteur générique, sens `baisse` ; le
  contrat d'axe est déjà porté par le moteur v1.8, le schéma catalogue reste
  à créer ; reclassement à confirmer par son audit.
- **Interdits maintenus :** aucune origine inventée, aucune écriture métier,
  aucun commit / push / branche / migration destructive.
