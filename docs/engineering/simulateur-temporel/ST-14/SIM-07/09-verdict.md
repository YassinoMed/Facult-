# SIM-07 — 09 · Verdict final

**Scénario :** SIM-07 — Amélioration de la réussite  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-04  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-07 active la classe `analyse_nouvelle` sur le moteur unique
(`ServiceSimulationTemporelle` v1.6) : **deux jeux de taux complets
déclarés** (référence et amélioré), une trajectoire témoin parallèle **non
persistée**, et la restitution des **sortants** (auparavant calculés puis
jetés). Les cas de référence sont calculés hors moteur et concordent
exactement : totaux `[60,37,28]` vs `[60,43,36]`, sortants `[21,12]` vs
`[15,10]`, écarts `[-6,-8]`, effet sortants `+8` ; hausse puisée dans le
« reste » : `[60,43]` vs `[60,37]`, effet `+6` — les deux origines
**déclarées** produisent des effets opposés, tous deux assertés sans
affaiblissement. Le reclassement `configuration → analyse_nouvelle` est
justifié par l'audit. La rétrocompatibilité (aucune clé sans jeu amélioré,
déstructuration à 2 éléments préservée), la cohérence des analyses OAT/MC
autour des taux améliorés, la sécurité (403 ×2, isolation facultaire) et la
lecture seule sont prouvées. La suite du module passe de 112 à
**120 tests / 1180 assertions**, sans échec, et Pint est propre.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — moteur v1.5 audité, options comparées, reclassement argumenté | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R9, cas non applicables | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur, effets opposés documentés | — |
| **Conception** | ✅ PASS | `03-architecture.md` — moteur unique, référence en mémoire, 3ᵉ retour interne | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — v1.6, `CatalogueScenarios::sim07`, garde double | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte sur tous les cas | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture | SIM07-SEC-1/2 |
| **Tests** | ✅ PASS | **7/7 SIM-07**, **18/18 catalogue**, **120/120 module** | — |
| **Non-régression** | ✅ PASS | 112 tests antérieurs inchangés (52 + 7×5 + 8 + 17) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — lecture des écarts, piège « écart négatif ≠ échec » | R2, R4 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-07/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-07 | **7 / 7** (premier essai) |
| Assertions SIM-07 | **70** |
| Tests catalogue | **18 / 18** |
| Assertions catalogue | **424** |
| Suite module Simulation Temporelle | **120 / 120** |
| Assertions module | **1180** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur) |
| Écritures métier | **0** |
| Version moteur | `1.6` |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM07-R1` | Moteur passé en v1.6 : les exécutions v1.5 restent lisibles mais ne sont pas retournées par l'idempotence (hash d'entrées versionné) | INFO | Versionnement normal, traçabilité conservée. |
| `SIM07-R2` | Le résultat mesure l'effet **dans le modèle déclaré** : la conversion d'une politique réelle en taux améliorés reste une expertise humaine | MOYEN | Documenté ; aucune vraisemblance revendiquée. |
| `SIM07-R3` | Le résultat dépend entièrement de l'origine **déclarée** de la hausse (redoublement↓ vs reste) : effets opposés possibles | INFO | C'est la force du contrat (aucune compensation inventée) ; documenté. |
| `SIM07-R4` | « Sortants » = dernier niveau du **périmètre** ; si `niveau_id` filtre un niveau intermédiaire, ce ne sont pas des diplômés du programme | INFO | Définition explicite ; réserve héritée du périmètre. |
| `SIM07-R5` | Taux globaux : pas d'amélioration différenciée par niveau | INFO | LOT B (extension par niveau). |
| `SIM07-R6` | La création HTTP n'appelle pas le catalogue ; la protection moteur s'active à la définition **et** à l'exécution | INFO | Héritée ; garde de forme renforcée. |
| `SIM07-R7` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée de `SIM04-R3` / `SIM06-R7` ; Famille 4 (SIM-20). |
| `SIM07-R8` | Doublement du calcul par variante (trajectoire témoin) | FAIBLE | Variantes ≤ 20, horizon ≤ 6 ; coût borné et mesuré. |
| `SIM07-R9` | SIM-08 (dégradation), déclaré `configuration`, devra probablement être reclassé `analyse_nouvelle` par symétrie | INFO | À trancher par l'audit de SIM-08. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-07 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 8 disponibles / 37 planifiés (45 entrées), SIM-07
  `analyse_nouvelle` confirmée.
- **Famille 2 :** en cours (SIM-07 clôturé ; SIM-08 → SIM-13 restants).
- **Prochain scénario fonctionnel autorisé :** `SIM-08 — Dégradation de la
  réussite` (LOT A, sous-LOOP ST-14.08) — symétrique de SIM-07 (passage
  strictement **inférieur**), compatibilité préliminaire `configuration`,
  reclassement probable à confirmer par son audit.
- **Interdits maintenus :** aucune origine de hausse inventée, aucune
  écriture métier, aucun commit / push / branche / migration destructive.
