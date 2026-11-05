# SIM-08 — 09 · Verdict final

**Scénario :** SIM-08 — Dégradation de la réussite  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-04  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :**  
SIM-08 est le **miroir** de SIM-07 sur le moteur unique
(`ServiceSimulationTemporelle` v1.7) : `taux_degrade` déclaré, trajectoire
témoin parallèle **en mémoire**, `analyserReussite()` **généralisé** (clé de
variation + magnitude), exclusion mutuelle hausse/dégradation. Les cas de
référence sont calculés hors moteur et concordent exactement : dégradé
`[60,43,36]` + sortants `[15,10]` vs référence `[60,37,28]` + sortants
`[21,12]`, écarts `[+6,+8]`, effet `−8` ; baisse absorbée par le « reste » :
`[60,37]` vs `[60,43]`, effet `−6` — les deux origines **déclarées**
produisent des effets opposés, tous deux assertés. La rétrocompatibilité
(aucune clé sans jeu dégradé, SIM-07 strictement inchangé), la cohérence des
analyses OAT/MC autour des taux dégradés, la sécurité (403 ×2, isolation) et
la lecture seule sont prouvées. La suite du module passe de 120 à
**128 tests / 1279 assertions**, sans échec, Pint propre du premier coup.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| **Audit** | ✅ PASS | `00-audit.md` — symétrie SIM-07, options, reclassement | R1→R3 |
| **Spécification** | ✅ PASS | `01-specification.md` — contrat, règles R1→R9, cas non applicables | — |
| **Formules** | ✅ PASS | `02-formules.md` — 3 cas calculés hors moteur | — |
| **Conception** | ✅ PASS | `03-architecture.md` — miroir, exclusion mutuelle par ordre, zéro duplication | — |
| **Implémentation** | ✅ PASS | `04-implementation.md` — v1.7, `CatalogueScenarios::sim08` | — |
| **Validation math.** | ✅ PASS | `07-resultats.md` — concordance exacte, miroir du cas A SIM-07 | — |
| **Sécurité** | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, étanchéité, zéro écriture, exclusion mutuelle | SIM08-SEC-1/2 |
| **Tests** | ✅ PASS | **7/7 SIM-08**, **19/19 catalogue**, **128/128 module** | — |
| **Non-régression** | ✅ PASS | 120 tests antérieurs inchangés (52 + 7×6 + 8 + 18) | — |
| **UX** | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — lecture des signes, pièges | R2, R4 |
| **Documentation** | ✅ PASS | dossier `ST-14/SIM-08/` complet (00 à 09) | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-08 | **7 / 7** (premier essai) |
| Assertions SIM-08 | **73** |
| Tests catalogue | **19 / 19** |
| Assertions catalogue | **450** |
| Suite module Simulation Temporelle | **128 / 128** |
| Assertions module | **1279** |
| Suite historique ST-11/ST-12 | **52 / 52** (312 assertions) |
| Pint `--test` | **OK** (0 erreur, premier passage) |
| Écritures métier | **0** |
| Version moteur | `1.7` |

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| `SIM08-R1` | Moteur passé en v1.7 : les exécutions v1.6 restent lisibles mais ne sont pas retournées par l'idempotence (hash d'entrées versionné) | INFO | Versionnement normal, traçabilité conservée. |
| `SIM08-R2` | Le résultat mesure l'effet **dans le modèle déclaré** : la conversion d'une crise réelle en taux dégradés reste une expertise humaine | MOYEN | Documenté ; aucune vraisemblance revendiquée. |
| `SIM08-R3` | Le résultat dépend entièrement de l'origine **déclarée** de la baisse (redoublement↑ vs reste) : effets opposés possibles | INFO | Force du contrat (aucune compensation inventée) ; documenté. |
| `SIM08-R4` | « Sortants » = dernier niveau du **périmètre** | INFO | Définition explicite ; réserve héritée. |
| `SIM08-R5` | Taux globaux : pas de dégradation différenciée par niveau | INFO | LOT B (extension par niveau). |
| `SIM08-R6` | La création HTTP n'appelle pas le catalogue ; la protection moteur s'active aux deux niveaux | INFO | Héritée ; garde de forme à l'exécution. |
| `SIM08-R7` | Capacité estimée au pas final, pas au pic intermédiaire | MOYEN | Héritée de `SIM04-R3` / `SIM07-R7` ; Famille 4 (SIM-20). |
| `SIM08-R8` | Doublement du calcul par variante (trajectoire témoin) | FAIBLE | Variantes ≤ 20, horizon ≤ 6 ; coût borné. |
| `SIM08-R9` | L'exclusion mutuelle repose sur **l'ordre** des blocs de validation (SIM-07 avant SIM-08) | INFO | Ordre explicite et commenté ; aucune branche morte ; testé. |

---

## J.5 Décision et suites autorisées

- **Clôture de SIM-08 :** prononcée sous le verdict **PASS_WITH_RESERVATIONS**.
- **Catalogue :** 9 disponibles / 36 planifiés (45 entrées), SIM-08
  `analyse_nouvelle` confirmée.
- **Famille 2 :** SIM-07 et SIM-08 clôturés ; SIM-09 → SIM-13 restants.
- **Prochain scénario fonctionnel autorisé :** `SIM-09 — Hausse du
  redoublement` (LOT A, sous-LOOP ST-14.09) — axe **redoublement** ;
  compatibilité préliminaire `configuration`, reclassement à confirmer par
  son audit ; la machinerie double-taux de SIM-07/08 est candidate à
  réutilisation, la décision appartient à l'audit (aucune extension par
  anticipation).
- **Interdits maintenus :** aucune origine de baisse inventée, aucune
  écriture métier, aucun commit / push / branche / migration destructive.
