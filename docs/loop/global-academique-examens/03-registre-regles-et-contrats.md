# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 03 · Registre des règles et contrats

**Phase B — GATE B** · Date : 2026-11-02 · Sources : code lu + référentiels versionnés + campagnes exécutées.
**Règle :** chaque règle listée a une source identifiée ; les règles non démontrées sont des DÉCISIONS OUVERTES (jamais des hypothèses codées).

---

## B1 — Catalogue des règles académiques

| Domaine | Règle | Source de vérité (réelle) | Version / Statut | Service consommateur |
|---|---|---|---|---|
| Régimes | LMD Licence — capitalisation UE ≥ 10/20 (Art. 8), compensation (Art. 9), CC exclusif (Art. 11), seuil compétence (Art. 13), rachat jury (Art. 16) | `config/acquis_reglementation.php` `regimes.LMD_LICENCE` (références JORT : Décret 2022-631 ; Arrêté 07/07/2023) | `2022.1`-family / CONFIRMÉ | `ReglementationAcquisService::resoudreRegime` → `CalculAcquisPedagogiquesService` |
| Régimes | Cycle Ingénieur — seuil éliminatoire 8.00, 1 redoublement (Décret 95-2602 mod. 2009-643) | `regimes.CYCLE_INGENIEUR` | CONFIRMÉ | idem |
| Régimes | Mastère LMD (Décrets 2008-3123 / 2012-1227) | `regimes.MASTER_LMD` | CONFIRMÉ | idem |
| Formations | Fiches formation (9 entrées : `LIC_SANTE`, `LIC_EDUCATION`, `LIC_INFO_STANDARD` (ABSENCE_CONFIRMEE), `ING_INFO_ENIT`, `ING_NON_CONFIGURE`, `MASTER_RECHERCHE_FST`, `FORMATION_A_VALIDER`, `FORMATION_EXPIREE`, `FORMATION_INCONNUE`) | `matrice_formations` + `statut_loop39` | par fiche (`REGLE_CONFIRMEE`/`ABSENCE_CONFIRMEE`/`REGLE_A_VALIDER`/`REGLE_EXPIREE`/`NON_CONFIGURE`) | `evaluerStatutRegle`, `determinerSeuilFormation`, `evaluerCouvertureReglementaire` (restaurée `02` CORR-01) |
| Redoublement | Limites par régime (cycle/niveau, durée max) | `limites_redoublement` | CONFIRMÉ | `ControleDroitsRedoublementService` |
| Seuils | Matrice seuils par formation | `matrice_seuils` | CONFIRMÉ | `determinerSeuilCompetence` |
| Gouvernance des règles | Rédaction → soumission → approbation → publication → retrait (permissions `regles.*` distinctes) | `GouvernanceReglesService` + `RegleAcademiqueVersion` + migration `2026_10_31_100000_creer_regles_academiques_versions` (LOOP 44, WIP) | WIP documenté (`docs/engineering/LOOP-44-*`) | Services de gouvernance règles |
| Couverture | Niveaux `FORMATION` / `NATIONALE` / `AUCUNE` ; automatisme uniquement pour FORMATION confirmée (règle OU absence confirmée) | `evaluerCouvertureReglementaire` (contrat LOOP-41 §ÉCAR-41-02) | Restauré 02/11 | `PilotAcademiqueService` |
| Incertitude | Gate G40 : aucune décision défavorable automatique ; revue humaine obligatoire ; la validation humaine EST la revue | Doctrine LOOP-40 (`LOOP-40-AUDIT-REGLEMENTAIRE.md`) + `02` CORR-02/03 | Appliqué (non bloquant, journalisé par motif) | `ValidationApprobationPlanService`, `PlanRedoublementService` |
| Décisions jury | Décision officielle = `DecisionDeliberation.publie=true` ; délibérations verrouillées immuables | Modèles + industrialisation | Verrouillé | `CalculAcquisPedagogiquesService`, pilotage |

**Décisions ouvertes liées (héritage, non ré-inventées)** : D-11/D-12 (industrialisation), D-01…D-05 (EX-LIFECYCLE), RES-01…RES-03 (`02`).

## B2 — Règles opérationnelles (planification / salles / regroupements)

| Règle | Source / constat | Statut |
|---|---|---|
| Capacité SALLE réelle = `rooms.capacite` ; capacité inconnue/nulle ⇒ conflit **bloquant** (jamais convertie silencieusement) | `ConflitEpreuveService::detecter` (pur, sans écriture) | CONFIRMÉ, testé (`ExamCapaciteSalleNonConfirmeeTest`) |
| Ratio d'espacement capacité utile (D-04 V2) = `config/modules.php` `capacite.ratio_espacement` (env `EXAM_CAPACITE_RATIO`, défaut 0.50) | WIP D-04 V2 + tests dédiés | Configurable — **valeur institutionnelle = réserve ST-10** |
| Conflits détectés : horaire, salle (principale + secondaires), groupe, surveillant, disponibilités | `ConflitEpreuveService` (types typés, gravités) | CONFIRMÉ |
| Correspondance académique des regroupements : faculté/programme/niveau/filière **explicites** — divergence ⇒ refus, jamais rapprochement silencieux | `GroupeVirtuelExamenService::perimetreAcademique` | CONFIRMÉ (53 tests verts 01/11) |
| Éligibilité à une épreuve : décision centralisée, jamais déduite d'une appartenance à un groupe | `ServiceEligibiliteExamen` (`REGLE_*` + fail-closed rattrapage) | CONFIRMÉ |
| Groupes virtuels : remplacement avec historique (`remplace`), **zéro écriture** `etudiants`/`groups`/`inscriptions_*` | En-tête `GroupeVirtuelExamenService` + tests | CONFIRMÉ |
| Verrous : session (`verrouillee_at`), épreuve (`locked`), examen (états critiques + journal) | Modèles + services | CONFIRMÉ (EX-LIFECYCLE) |

## B3 — Gouvernance (circuit et séparation)

- **Planning d'examens** : `soumis → certifié → approuvé (2 personnes distinctes) → publié` ; `refus` motivé ; `escalade` bloquante ; gel institutionnel + dérogation motivée ; empreinte planning certifiée (toute divergence post-certification ⇒ nouvelle version). Source : `ExamGouvernanceValidationService` (append-only `PlanningEvenement`), config `separation_pouvoirs_par_role=true` + rôles dédiés (`certificateur_qualite`, `direction_etudes`, `autorite_publication`).
- **Cycle de vie examen** : matrice + garde critique ; publication OFFICIELLE uniquement via le circuit ; journal `evaluation_examen_evenements` append-only. Source : `ExamenService` + `02-architecture-cible` (EX-LIFECYCLE `02`).
- **Plan de redoublement (4 yeux)** : clauses sensibles (dispenses/équivalences, changement de parcours) ⇒ 2 approbateurs distincts, auto-approbation interdite ; incertitude réglementaire ⇒ avertissement non bloquant (`02` CORR-02).

## B4 — Contrats interservices (interfaces réelles constatées)

| Contrat | Fournisseur | Sortie (clés réelles) | Consommateurs |
|---|---|---|---|
| **A — Calcul académique** | `CalculAcquisPedagogiquesService::analyserAcquis` / `PlanRedoublementService::simulerPlan` | `total_credits_acquis`, `elements_conserves[]`, `elements_a_reprendre[]`, `anomalies[]`, **`revue_humaine[]`** (nouveau), `droits_redoublement{statut_droit,statut_canonique,revue_humaine_requise,motif}`, `deliberation_publiee`, par UE : `statut`, `credits_acquis`, `moyenne`, `motif` | Pilotage, API acquis, outils IA, campagnes masse, validation 4 yeux |
| **B — Éligibilité** | `ServiceEligibiliteExamen::evaluer` | `DecisionEligibiliteExamen` : `decision` (eligible/non_eligible/anomalie), `source`, `regle`, `motif`, `donneesCompletes`, `evalueLe`, ids inscriptions | Groupes virtuels, génération candidats, préparation examens |
| **C — Planification** | `ExamScheduleEngineService` | scénarios (`genererScenariosPlanning`), audit `{conforme, conflits_bloquants[]}` ; application transactionnelle verrouillée (`appliquerScenario`) ; **une simulation n'écrit jamais le planning officiel** | Gouvernance (soumission/publication), cockpit |
| **D — Gouvernance** | `ExamGouvernanceValidationService` | `PlanningValidation{statut∈{soumis,certifie,approuve,rejete,escalade,publie}, empreinte_planning, acteurs}` + `PlanningEvenement` (append-only) ; publication ⇒ scellage SHA-256 (`ExamPublicationOrchestratorService`) | Publication, historique/audit, menu publication |

**Aucun contrat ne permet au consommateur de court-circuiter le propriétaire de la décision** (éligibilité ❌ réimplémentée par la planification ; publication ❌ hors circuit — 5 chemins analysés, EX-LIFECYCLE `02`).

## B5 — Chaîne globale (dépendances logiques)

```mermaid
graph LR
  R[Règles/maquette<br/>config + versions] --> N[Notes]
  N --> ECUE[Calcul ECUE]
  ECUE --> UE[Calcul UE / crédits]
  UE --> DELIB[Décision délibération<br/>publie]
  DELIB --> ACQ[Acquis / plan redoublement]
  ACQ --> ELG[Éligibilité épreuve<br/>ServiceEligibiliteExamen]
  ELG --> PLN[Planification / groupes virtuels]
  PLN --> CAP[Capacités & conflits]
  CAP --> DOSSIER[Dossier planning]
  DOSSIER --> GOV[Gouvernance 4 yeux]
  GOV --> PUB[Publication officielle]
```

(Chaîne de dépendances, pas une séquence obligatoire : le pilotage, les exports et les campagnes consomment des sous-chaînes.)

## B6 — Matrice de traçabilité (extrait consolidé)

| ID | Règle | Service propriétaire | Test (preuve exécutée) | Statut |
|---|---|---|---|---|
| ACQ-01 | Capitalisation UE/ECUE selon régime (fail-closed) | `CalculAcquisPedagogiquesService` | `tests/Feature/Acquis/` **201/201** (02/11) | ✅ |
| ACQ-02 | Couverture réglementaire FORMATION/NATIONALE/AUCUNE | `ReglementationAcquisService` (restaurée) | `PiloteAcademiqueLoop42Test` 20/20 | ✅ |
| ACQ-03 | Incertitude ⇒ revue humaine non bloquante (G40) | `ValidationApprobationPlanService` + `PlanRedoublementService` | `DecisionAcademiqueWorkflowTest` 14/14 ; campagne reprenable 1/1 | ✅ |
| ACQ-04 | Limites de redoublement (droits épuisés ⇒ blocage) | `ControleDroitsRedoublementService` | `CertificationLoop39RegimesAcademiquesTest` phase 4 | ✅ préservé |
| ELG-01 | Éligibilité fail-closed, décision explicable | `ServiceEligibiliteExamen` | `EligibiliteExamenTest` (+ transverses 69/69) | ✅ |
| PLN-01 | Conflits bloquants, moteur pur | `ConflitEpreuveService` | `ExamensInvariantsSallesTest` etc. | ✅ |
| CAP-01 | Capacité inconnue ⇒ blocage ; ratio D-04 configurable | `ConflitEpreuveService` + config | `ExamCapaciteSalleNonConfirmeeTest`, `ExamDecisionCapacitePermutationTest` | ✅ (valeur ratio = réserve) |
| GV-01 | Regroupement sans réaffectation | `GroupeVirtuelExamenService` | 53/53 (01/11) | ✅ |
| GOV-01 | Publication = circuit 4 yeux + empreinte | `ExamGouvernanceValidationService` | 28/28 + 20/20 + 9/9 | ✅ |
| LIF-01 | Transitions examen verrouillées + journal append-only | `ExamenService` | `ExamenCycleVieTest` 19/19 | ✅ |

## Décisions métier en attente (rappel — aucune n'est contournée par le code)

D-01 (réouverture), D-02 (motif), D-03 (notifications), D-04 (propagation annulation), D-05 (complétude `completed`) — EX-LIFECYCLE ; D-11/D-12 — industrialisation ; ratios capacité/budgétaires — ST-10 ; RES-01…03 — `02`.

## GATE B — Vérification

| Critère | Statut |
|---|---|
| Chaque règle a une source identifiable | ✅ B1/B2 (config versionnée, doctrine LOOP-40/41/44, modèles) |
| Ambiguïtés consignées, non codées en hypothèses | ✅ décisions ouvertes listées |
| Contrats interservices documentés (A–D) | ✅ B4 |
| Chaîne globale représentée | ✅ B5 |
| Traçabilité règle→test→preuve | ✅ B6 |

**GATE B : FRANCHI.**
