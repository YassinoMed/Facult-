# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 00 · Audit global

**Projet :** UniRegistre · **Nature :** Audit Phase A (lecture seule) · **Date :** 2026-11-01
**Modes :** AUDIT_ONLY / TESTING · **Aucune donnée officielle modifiée.**

> Ce document ne duplique volontairement PAS les livrables existants : il les référence.
> Corpus à réutiliser : `docs/engineering/examens-advanced/` (20 documents EA-EXAMENS 01-06),
> `docs/loop/examens-cycle-vie/` (13 documents EX-LIFECYCLE), `docs/loop/industrialisation-evaluation/`
> (19 documents NOTES/ÉVALUATION), `docs/engineering/LOOP-37→46` (Acquis/Redoublement/Réglementaire),
> `docs/evaluation/` (convocations, rattrapage, types).

## 1. Périmètre audité

| Domaine | Composants principaux | Livrables de référence |
|---|---|---|
| Acquis & crédits | `app/Services/Acquis/` (24 services), `CalculAcquisPedagogiquesService` (moteur) | LOOP-37/38/39/40/40C/41/42/46 |
| Notes & résultats | `SaisieNoteService`, `NoteEpreuve`, délibérations | `industrialisation-evaluation/` (18) |
| Éligibilité | `ServiceEligibiliteExamen` (central, fail-closed) | `examens-cycle-vie/`, `EligibiliteExamenTest` |
| Planification / salles / capacité | `ExamScheduleEngineService`, `ConflitEpreuveService`, `ExamRoomAssignmentService`, `ExamDecisionCapacitePermutationService` (D-04 V2) | EA-EXAMENS 02/03, D-04 V2 |
| Groupes virtuels | `GroupeVirtuelExamenService`, `ConsultationExportGroupeVirtuelService` | EA-EXAMENS (groupes), LOOP-GLOBAL groupes virtuels |
| Gouvernance & publication | `ExamGouvernanceValidationService` (append-only), `ExamPublicationOrchestratorService` | `examens-cycle-vie/` (EX-LIFECYCLE) |
| Cycle de vie examen | `ExamenService` (+ `ExamenEvenement` append-only) | EX-LIFECYCLE |

## 2. État du dépôt (protection du WIP)

- Branche `main`, commit `a8b3f0b` ; **237 entrées** `git status --porcelain` (héritage LOOP 32→46 + IAM + EX-LIFECYCLE + industrialisation + ST-11) — **intégralement préservées**.
- **Sessions concurrentes détectées** (à ne pas écraser) :
  - `industrialisation-evaluation/` — livrée le 01/11 16:23→21:36 (verdict PASS_WITH_RESERVATIONS, 257 tests).
  - ST-11/ST-12 Simulation temporelle — **active pendant cet audit** (P0-1 permissions `simulation.*` + routes + contrôleur + tests, édités 22:30→22:49). Cet audit n'a touché aucun de ces fichiers.
- Fichiers acquis : répertoire `app/Services/Acquis/` **non suivi (WIP)** — ne pas écraser.

## 3. Routes recensées (état réel mesuré)

- `php artisan route:list` : **191 routes** évaluation/examens/simulation ; **6 routes** `acquis` ; publication incluse dans évaluations.
- Groupe module Évaluation (`app/Modules/Evaluation/Routes/web.php`, 697 lignes) : `['auth','admin','secondaire.verrou','module:evaluation']` + permissions littérales `can:*` par route (aucun wildcard).
- Isolation : `EvaluationPolicy::dansPerimetre` (programme/groupe/UE→programme→faculté) + `peutAccederFaculte()` + `pourFaculteId` ; aucun `faculte_id` client utilisé pour l'autorisation.
- Constat transverse (déjà documenté EX-LIFECYCLE ANOM-A9) : les routes modules ne portent pas `mfa.obligatoire` (schéma systémique de tous les modules — point d'architecture, pas régression locale).

## 4. Sources de vérité (identifiées et vérifiées)

| Notion | Source de vérité constatée |
|---|---|
| Note | `Note` / `NoteEpreuve` + `SaisieNoteService` (barème serveur, verrouillage) |
| Maquette / UE / ECUE | Référentiel LMD (`UniteEnseignement`, `Matiere`, `SemestreLmd`) |
| Régime académique | `ReglementationAcquisService` (résolution **fail-closed**) + `config/acquis_reglementation.php` |
| Règles versionnées | `RegleAcademiqueVersion` + migration `2026_10_31_100000_creer_regles_academiques_versions` (LOOP 44, WIP) |
| Crédits / validation UE | `CalculAcquisPedagogiquesService` (Art. 8/9/10/13 : capitalisation, compensation, meilleure note, seuil compétence) |
| Éligibilité épreuve | `ServiceEligibiliteExamen` (décision explicable : `DecisionEligibiliteExamen` — décision+source+règle+motif) |
| Capacité salle | `Room.capacite` + règles `ConflitEpreuveService` (capacité inconnue → bloquant) + D-04 V2 (`config/modules.php` ratio `EXAM_CAPACITE_RATIO`, défaut 0.50) |
| Groupe pédagogique | `Group` (référentiel officiel — jamais modifié par les regroupements) |
| Groupe virtuel | `GroupeVirtuelExamen` (objet opérationnel, historique `remplace`, aucune écriture groupes/étudiants) |
| Gouvernance planning | `PlanningValidation` + `PlanningEvenement` (append-only, 4 yeux, empreinte SHA-256) |
| Cycle de vie examen | `ExamenService` (+ `evaluation_examen_evenements` append-only) |
| Publication | `ExamPublicationOrchestratorService` (permission re-vérifiée dans le service) |

## 5. Services canoniques (responsabilités confirmées)

- **Acquis** : `CalculAcquisPedagogiquesService` (moteur unique ; délègue la réglementation), `MoteurDecisionAcademiqueService`, `GestionnaireCreditsService`, `HistoriqueTentativesService` (tentatives), `EquivalenceParcoursService`, `ControlePrerequisService`, `PlanRedoublementService` + `ValidationApprobationPlanService` (4 yeux plan), `CampagneRedoublementMasseService` (permission dédiée `acquis.campagne.lancer` — LOOP 32), `PilotAcademiqueService`, `ReconstitutionHistoriqueAcquisService`, `TransitionMaquettePedagogiqueService`.
- **Éligibilité** : `ServiceEligibiliteExamen` — populations et décisions unitaires ; rattrapage → anomalie explicite (règle non validée = jamais autorisation implicite).
- **Planification** : `ExamScheduleEngineService` (scénarios + audit conformité), `ConflitEpreuveService` (**pur, sans écriture** : salle/horaire/groupe/surveillant), `ExamRoomAssignmentService`, `ExamDecisionCapacitePermutationService` (D-04 V2, WIP testé).
- **Groupes virtuels** : `GroupeVirtuelExamenService` (éligibilité par `ServiceEligibiliteExamen`, correspondance explicite, aucune réaffectation), `ConsultationExportGroupeVirtuelService` (contrôle + exports).
- **Gouvernance** : `ExamGouvernanceValidationService` (soumis→certifié→approuvé×2→publié ; refus/escalade ; personnes ET rôles distincts ; empreinte certifiée), `ExamPublicationOrchestratorService` (diagnostic + scellement SHA-256).
- **Transitions** : `ExamenService` (verrou de ligne + matrice + journal append-only + prévisualisation).

## 6. Ce qui fonctionne (prouvé)

1. **Chaîne notes→délibérations→publication** : auditée et testée (industrialisation : 257 tests, historique notes rendu append-only).
2. **Cycle de vie examen** : verrou transactionnel + journal append-only + gouvernance obligatoire pour `published` (EX-LIFECYCLE : 19 tests dédiés).
3. **Éligibilité fail-closed** : insuffisance de données ⇒ anomalie, jamais éligibilité implicite (`EligibiliteExamenTest`).
4. **Groupes virtuels** : aucune écriture sur groupes pédagogiques/étudiants (53 tests verts ce jour).
5. **Planification/capacité** : moteur de conflits pur + D-04 V2 ; capacité inconnue bloquante (69 tests verts + suites EA).
6. **Gouvernance 4 yeux** : non contournable (5 chemins analysés, EX-LIFECYCLE `02`).

## 7. Ce qui est incomplet ou incohérent (à trier — aucune correction silencieuse effectuée)

| ID | Constat | Preuve | Gravité |
|---|---|---|---|
| ACQ-RED-01 | **13 tests rouges** dans `tests/Feature/Acquis/` (201 exécutés, 188 verts) — clusters : `PilotAcademiqueLoop42Test` (9 erreurs « Undefined array key 0 » + 2 échecs périmètre/dénominateurs), `DecisionAcademiqueWorkflowTest` (attendu « Auto-approbation interdite », obtenu « LIC-ECO non répertoriée au référentiel réglementaire »), `IndustrialisationAcquisWorkflowTest` (reprise campagne : 0 ≠ 1) | Exécution 2026-11-01 (07:50→) ; fichiers/services modifiés 30-31/10, **aucune dépendance aux fichiers modifiés le 01/11** ⇒ présumés **préexistants (WIP loops 42/44)** | ÉLEVÉ — ✅ **RÉSOLU le 02/11** (`02-corrections-acq-red-01.md`) : 201/201 |
| ACQ-INT-02 | Interaction inter-lots : le durcissement réglementaire (LOOP 44 `GouvernanceReglesService` — formation « LIC-ECO » absente du référentiel ⇒ revue humaine) invalide des attentes de tests antérieurs (LOOP 22) — soit donnée référentielle à compléter, soit tests à réaligner | Message d'exception réel (ci-dessus) | MOYEN — ✅ **RÉSOLU** (`02` CORR-02) : revue humaine non bloquante |
| ACQ-PIL-03 | `PilotAcademiqueLoop42Test` : périmètre facultés et dénominateurs d'indicateurs retournent vide (0 attendu 1 / 0 ≠ 3) — contrat du pilote à réconcilier | Exécution du 01/11 | MOYEN — ✅ **RÉSOLU** (`02` CORR-01) : méthode restaurée, 20/20 |
| ACQ-DEC-04 | Ratios de capacité salles et valeurs de référence budgétaire : sources institutionnelles non fournies (ST-10 réserves : 40 places défaut, taux budgétaires) — **BLOCKED_BUSINESS** potentiel si considérés réglementaires | Réserves ST-10 §16 ; `config/modules.php` (ratio D-04) | À décider |
| ACQ-DUP-05 | Documents/LOOP antérieurs nombreux (examens-advanced, EX-LIFECYCLE, industrialisation, ST) : risque de divergence documentaire — mitigation : ce registre référence, ne duplique pas | Présent document | INFORMATION |

## 8. Ce qui est bloqué

- Aucun blocage d'environnement pour l'audit (SQLite `:memory:` conforme au dépôt ; MySQL requis uniquement pour preuves de concurrence multi-processus `pcntl` — 4 occurrences déjà documentées).
- Décisions métier attendues (héritage, non re-inventées) : D-01…D-05 EX-LIFECYCLE (réouverture, motif, notifications, propagation annulation, complétude) ; D-11/D-12 industrialisation ; réserves ST-10 (ratios).

## 9. Ce qui ne doit pas être modifié

- `ServiceEligibiliteExamen` (contrat central éligibilité) — toute évolution = nouveau consommateur, pas de réécriture.
- `CalculAcquisPedagogiquesService` : aucune règle inventée ; toute règle nouvelle passe par `config/acquis_reglementation.php` + versionnement.
- Moteurs de transitions (`ExamenService`, `EpreuveService`, `SessionExamenService`) — point d'entrée unique.
- Gouvernance append-only (`PlanningValidation`, `PlanningEvenement`, `ExamenEvenement`, `NoteEpreuveHistorique`) — immuabilité testée.
- WIP des sessions concurrentes (simulation ST-11, modules IAM, dérogations) — hors périmètre.

## 10. Améliorables sans décision métier (candidats Phase D)

1. Triage des 13 tests rouges acquis (réaligner tests vs contrats LOOP 44 OU compléter référentiel — sans toucher aux règles).
2. Alignement documentaire (matrice de traçabilité unique vers les livrables existants).
3. Vérification des scopes de lecture Pilote (ACQ-PIL-03) — si bug technique avéré, correctif borné + tests.
4. Consolidation des preuves de non-mutation (lecture seule) sur les parcours acquis/pilote.

## 11. Matrice de traçabilité (extrait — à compléter Phase B)

| ID exigence | Règle métier | Service | Route/Interface | Test | Preuve | Statut |
|---|---|---|---|---|---|---|
| ACQ-01 | Capitalisation UE ≥ seuil (Art. 8) | `CalculAcquisPedagogiquesService` | `etudiant/acquis`, API acquis | `GestionAcquisRedoublementTest` etc. | 188/201 verts | 🟡 (13 rouges à trier) |
| ELG-01 | Éligibilité = inscriptions actives + cohérence programme (fail-closed) | `ServiceEligibiliteExamen` | — (service) | `EligibiliteExamenTest` | ✅ | ✅ |
| PLN-01 | Conflits bloquants avant planification | `ConflitEpreuveService` (pur) | routes épreuves/planification | `ExamensInvariants*` | ✅ | ✅ |
| CAP-01 | Capacité inconnue ⇒ blocage | `ConflitEpreuveService` | D-04 `groupes/capacite/*` | `ExamCapaciteSalleNonConfirmeeTest` | ✅ | ✅ |
| GV-01 | Groupe virtuel sans réaffectation | `GroupeVirtuelExamenService` | `groupes-virtuels/*` | 53 tests | ✅ | ✅ |
| GOV-01 | Publication = circuit 4 yeux + empreinte | `ExamGouvernanceValidationService` | `gouvernance/*` | 28+ tests | ✅ | ✅ |
| LIF-01 | Transition examen verrouillée + journal append-only | `ExamenService` | `examens/transiter/*` | `ExamenCycleVieTest` (19) | ✅ | ✅ |

## 12. GATE A — Verdict

| Critère | Statut |
|---|---|
| Routes/services/permissions reliés | ✅ §3, §5 |
| Sources de vérité identifiées | ✅ §4 |
| WIP préservé (aucun écrasement) | ✅ §2 |
| Baseline chiffrée | ✅ `01-baseline.md` (323 tests domaine, 310 verts, 13 rouges triés) |
| Anomalies consignées sans masquage | ✅ §7 |
| Décisions métier non inventées | ✅ §8/§9 |

**GATE A : FRANCHI** (poursuite Phase B — registre des règles/contrats, puis lots). Le triage ACQ-RED-01 a été **réalisé le 02/11** : 13/13 tests reverdis, aucune assertion affaiblie, frontières métier préservées (`02-corrections-acq-red-01.md`).
