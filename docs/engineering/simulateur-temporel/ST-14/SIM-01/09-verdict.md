# SIM-01 — 09 · Verdict final

**Scénario :** SIM-01 — Croissance des admissions
**Campagne :** ST-14 — scénarios avancés
**Date :** 2026-11-02
**Mode :** AUDIT_ONLY / SIMULATION / TESTING

---

## J.1 Verdict

# ✅ PASS_WITH_RESERVATIONS

**Justification :** SIM-01 est une configuration du moteur existant, complétée
par une exposition additive de la trajectoire d'admissions. Les valeurs sont
vérifiées par des calculs indépendants (pas de réutilisation de la formule du
moteur dans les assertions), la conservation est prouvée niveau par niveau,
le déterminisme et l'idempotence sont démontrés, la sécurité et la lecture
seule sont testées, et la suite historique reste intacte.

Les réserves sont des **hypothèses moteur** (taux absents, seuils d'alerte,
capacité par défaut) ou des **choix de contrat** (règle de croissance portée
par le catalogue, taux globaux, absence d'écran dédié). Aucune n'invalide le
scénario.

---

## J.2 Critères de validation

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| Audit | ✅ PASS | `00-audit.md` — services, formules, sorties, permissions | R1→R7 |
| Spécification | ✅ PASS | `01-specification.md` — entrées, sorties, cas exclus | — |
| Formules | ✅ PASS | `02-formules.md` — 4 cas calculés hors moteur | arrondis, baseL1 |
| Conception | ✅ PASS | `03-architecture.md` — contrat unique, versionné | — |
| Implémentation | ✅ PASS | `04-implementation.md` — 2 fichiers de production | — |
| Validation mathématique | ✅ PASS | `07-resultats.md` — admissions, totaux, conservation | R1, R5 |
| Sécurité | ✅ PASS_WITH_RESERVATIONS | `05-securite.md` — 403, périmètre, zéro écriture | S1→S5 |
| Tests | ✅ PASS | **7/7 SIM-01**, **12/12 catalogue**, **71/71 module** | — |
| Non-régression | ✅ PASS | 52 tests historiques inchangés | — |
| UX | ✅ PASS_WITH_RESERVATIONS | `08-guide-utilisation.md` — formulaire existant | R7 |
| Documentation | ✅ PASS | dossier `ST-14/SIM-01/` complet | — |

---

## J.3 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-01 | **7 / 7** |
| Assertions SIM-01 | **56** |
| Tests catalogue | **12 / 12** |
| Assertions catalogue | **292** |
| Suite module | **71 / 71** |
| Assertions module | **660** |
| Suite historique | **52 / 52** (312 assertions) |
| Pint `--dirty` | **OK** (aucun fichier après correctifs) |
| Écritures métier | **0** |
| Exécutions techniques | 1 par exécution testée |
| Snapshots techniques | `horizon + 1` |

Décomposition arithmétique : `52 + 12 + 7 = 71` et
`312 + 292 + 56 = 660`.

---

## J.4 Réserves

| ID | Réserve | Gravité | Suite proposée |
|---|---|---|---|
| SIM01-R1 | Sans taux, le moteur vide les cohortes existantes alors que l'avertissement parle de cohortes « gelées » | **MOYEN** | LOOP dédié de correction du socle ; SIM-01 exige les taux en attendant |
| SIM01-R2 | Seuils d'alerte 15 % / 95 % non institutionnels | MOYEN | Décision institutionnelle avant diffusion |
| SIM01-R3 | `baseL1` = premier niveau de la cohorte | INFO | Documenté ; ne pas généraliser à « L1 » |
| SIM01-R4 | Taux globaux, non différenciés par niveau | INFO | LOT B (extension par niveau) |
| SIM01-R5 | Capacité par défaut 35 si aucun groupe réel | MOYEN | `capacite_groupe_hypothese` explicite ; tests avec groupe réel |
| SIM01-R6 | Règle « croissance uniquement » portée par le catalogue, pas par le moteur | MOYEN | Profil de scénario ou validation dédiée dans un futur LOOP |
| SIM01-R7 | Aucun écran dédié SIM-01 | FAIBLE | Phase H des scénarios / UI catalogue |
| SIM01-R8 | L'incrément de version moteur invalide l'idempotence inter-versions | INFO | Comportement attendu ; documenté |

---

## J.5 Portée du verdict

**Autorise :**

- l'ouverture du LOOP **SIM-02 — Baisse des admissions** ;
- l'utilisation de SIM-01 comme référence de croissance ;
- la consultation du catalogue et l'exécution de SIM-01 dans la limite des
  permissions existantes.

**N'autorise pas :**

- l'exécution des 43 scénarios encore `planifie` ;
- une modification des données académiques réelles ;
- la présentation des seuils 15 % / 95 % comme des normes institutionnelles ;
- la correction de l'anomalie R1 sans LOOP dédié.

---

## J.6 État Git

| Élément | État |
|---|---|
| Commit | Aucun |
| Push / branche / PR | Aucun |
| WIP ST-11 / ST-12 | Préservé, non commité |
| Données métier | Aucune écriture |
| Migrations | Aucune |

---

## J.7 Conclusion

SIM-01 est **clos** avec le verdict **PASS_WITH_RESERVATIONS**. Le scénario est
disponible, documenté, testé et reproductible. La prochaine étape autorisée est
**SIM-02 — Baisse des admissions**, qui réutilisera le même moteur avec une
règle de domaine symétrique (`delta ≤ 0`, `bornes.max ≤ 0`).
