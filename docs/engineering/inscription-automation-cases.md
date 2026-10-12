# Registre des cas — Automatisation inscriptions/affectations

Baseline LAN (2026-10-11) : 85 tests, 358 assertions, 0 échec, 1 skip (FOR UPDATE MySQL gaté).
Légende : PASS (preuve) · PASS_WITH_WARNINGS · FAIL · BLOCKED · NOT_STARTED · NOT_EXECUTED · NOT_APPLICABLE.

## Cas §5 — situations des dossiers

| ID | Situation | Statut | Preuve |
|---|---|---|---|
| IA-01 | Dossier reçu / en traitement | PASS | File de traitement (état calculé, actions attendues) + `InscriptionFileTraitementTest` |
| IA-02 | Informations à vérifier | PASS | `VerificationEngine` verdict `A_VERIFIER` + file action `ANOMALIE_DETECTEE` |
| IA-03 | Documents manquants | PASS | `DocumentsRequisService` (états MANQUANT/REJETE/EXPIRE) + gate `exigerPourValidation` → `REGISTRATION_DOCUMENTS_INCOMPLETE` (`DocumentsRequisTest` 5) |
| IA-04 | Identité à confirmer | PASS | Divergences MENS/OCR → 4-eyes (`MensDivergenceRule`, `IdentificationFailClosedTest` 11) |
| IA-05 | MENS indisponible | PASS | Fail-closed `UNKNOWN` → saisie manuelle contrôlée (`MensInscriptionIntelligenteTest`) |
| IA-06 | Divergence MENS | PASS | Divergences A_VERIFIER/BLOQUANT sans écrasement, workflowisée (B5 LOOP, 4 tests) |
| IA-07 | Doublon potentiel | PASS | `DoublonsRule` + `doublonsPotentiels` scopé périmètre (fix 11/10, registre-étudiants) |
| IA-08 | Dossier bloqué | PASS | `BlocageInscriptionService` (codes explicites) + gate `transiter→valide` (`CentreResolutionTest` 6) |
| IA-09 | Prêt pour validation | PASS | Union KPI/liste `A_INSTRUIRE` (fix file-traitement, 11/10) |
| IA-10 | Validé / rejeté motivé | PASS | `InscriptionService::transiter` + `StudentStatusService` (motifs 255) |
| IA-11 | Correction puis resoumission | PASS | 4-eyes `proposee→approuvee→appliquee` + ouverture après rejet (`InscriptionCorrection4YeuxTest` 7, `ComplementsInscriptionLoopTest` 8) |
| IA-12 | Affecté à un groupe | PASS | `AffectationAutomatiqueApresValidationTest` Cas 1-11 + `AffectationApresDossierCompletTest` 3 |
| IA-13 | Réaffectation | PASS (admin) | `POST inscriptions/{inscription}/reaffecter` (relance mêmes règles) + propositions acceptee/modifiee |
| IA-14 | Changement de groupe (workflow §9) | **PASS** (11/10/2026, LOOP B) | `ChangementGroupeService` (simuler/changer) + route `POST etudiants/{etudiant}/affectations/changer` (`can:archives.update`, throttle 20/1, scope 403) — 12 tests : transfert+historique cloturee, idempotence demande identique, sans affectation, niveau inactif/capacité (contraintes existantes via affecter), simulation sans écriture+impact, notification post-commit, audit sans PII, HTTP autorisé/cross-faculté sans mutation/sans permission |
| IA-15 | Archivé | NOT_APPLICABLE | Module Archives séparé (existant) — hors périmètre inscription, documenté |

## Cas §6 — automatisation de la validation

| ID | Cas | Statut | Preuve |
|---|---|---|---|
| IA-20 | Normalisation CIN | PASS | `NormalisationService` (whereRaw indexé, garde `IDENTITY_CONFLICT`, fix LOOP affectation intelligente) |
| IA-21 | Doublons par CIN normalisée | PASS | Garde création publique + moteur affectation (`AffectationIntelligenteLoopTest` 6) |
| IA-22 | OCR vs déclaré (incohérence, pas décision) | PASS | Pré-remplissage à confiance + alertes à vérifier (`PREFILL-CIN`, confiance par champ) — jamais décision |
| IA-23 | Similarité nom ≠ preuve d'identité | PASS | `DoublonsRule` : CIN doublon = bloquant ; homonymes = éligibles (jamais fusion auto, `TeacherHomonymeImportTest` pattern identique) |
| IA-24 | Résultat structuré §6 (façade unique) | **PASS** (11/10/2026, LOOP A) | `InscriptionEligibilityService::evaluer` : {eligible, intervention_humaine (toujours vrai), verdict ELIGIBLE/AVERTISSEMENT/BLOCAGE, contrôles ×3 (période/pièces/anomalies), blocages, avertissements, documents, actions_proposees, sources traçables} — agrégation PURE (zéro nouvelle règle, zéro écriture prouvé par test) ; 8 tests RED→GREEN + câblage lecture seule `VerificationController::show` (section « Synthèse d'éligibilité ») ; validation 44/44 + régression 27/27, Pint vert |

## Cas §7 — file de traitement

| ID | Cas | Statut | Preuve |
|---|---|---|---|
| IA-30 | État, motif, prochaine action, rôle | PASS | Actions attendues ×5 + KPIs + file (actions/motifs normalisés) — `FileTraitement*Test` 33 |
| IA-31 | Priorité métier | PASS (FIFO ancienneté) | Tri FIFO + souffrance 48h/7j ; pas de priorisation configurable (aucune règle métier existante — documenté registre Phase 3) |
| IA-32 | Tentatives / reprise / isolation | **PASS** (11/10/2026, LOOP C) | Catégorisation dérivée de la file : 9 catégories (`CATEGORIES_FILE`) raffinées par **codes de règles en échec persistés au run** (migration additive `regles_echouees`, repli documenté verdict-seul pour runs antérieurs) — `FileTraitementCategoriesTest` 15/15 (1 test/catégorie, replis, pureté, sans PII, filtre HTTP scopant + invalide refusé) ; `TECHNICAL_RETRY` = NOT_APPLICABLE justifié (aucune donnée par étudiant — erreurs techniques vivent dans lots/jobs) ; attributs calculés `syncOriginal` = jamais de fuite dans une sauvegarde (défaut réel prouvé par test puis corrigé) |
| IA-33 | Filtrage, recherche, tri, pagination | PASS | 12 filtres bornés + tri + pagination préférences (fix 11/10) + scope serveur |
| IA-34 | Pas de PII dans journaux techniques | PASS | `AuditService` sans PII (aperçu tronqué, longueurs) — audit §33 |

## Cas §8 — affectation initiale

| ID | Cas | Statut | Preuve |
|---|---|---|---|
| IA-40 | 10 conditions obligatoires §8 | PASS | `AffectationEligibilityService` 5 axes + `controler` 10 contraintes + `tenter` éligibilité (Cas 1-11) |
| IA-41 | Niveau strict (jamais L1→L2 pour capacité) | PASS | Niveau exact officiel, jamais inventé (`AffectationMatureLoopTest`, moteur v2) |
| IA-42 | Capacité sous concurrence | PASS | Verrous ordonnés étudiant→groupe + `AffectationConcurrenceTest` (double tenter = EXISTING, transfert = 1 active) |
| IA-43 | Idempotence double affectation | PASS | EXISTING + lockForUpdate (fix LOOP 14) |
| IA-44 | Affectation impossible explicite | PASS | `NO_COMPATIBLE_GROUP` → audit `affectation.auto_non_assignee` + notif `affectation.en_attente` + file « Sans groupe » — jamais de solution inventée |
| IA-45 | Proposition justifiée (§8 stratégie 1-8) | PASS | `AssignmentSimulationService` : contraints-first, alternatives scorées, raisons, planCapacite, confirmation re-validée (obsolescence) |

## Cas §9 — changement de groupe (18 cas)

| ID | Cas | Statut |
|---|---|---|
| IA-50 | Décision administration (changer) | PASS | `ChangementGroupeService::changer` + contrôleur HTTP (12 tests) |
| IA-51 | Destination complète (capacité 0) | PASS | Refus GROUP_FULL, état intact (test) |
| IA-52 | Destination inactive | PASS | Refus GROUP_INACTIVE (test) |
| IA-53 | Niveau incompatible (L1→L2) | PASS | Refus LEVEL_MISMATCH, groupe inchangé (test) |
| IA-54 | Autre faculté (cible ou étudiant) | PASS | 403 scope, zéro mutation (test HTTP) |
| IA-55 | Déjà affecté au groupe cible (demande identique) | PASS | Refus explicite idempotent, aucun doublon d'historique (test) |
| IA-56 | Étudiant sans affectation active | PASS | Refus motivé (test) |
| IA-57 | Échec pendant l'application | PASS | Transaction `affecter` : refus = état intact prouvé (test capacité) |
| IA-58 | Concurrence/simultanés | PASS_WITH_WARNINGS | Verrous ordonnés `affecter` (préexistants) ; course FOR UPDATE MySQL réelle = preuve gatée existante (`AffectationConcurrenceTest`) |
| IA-59 | Historique du changement | PASS | Ancienne cloturee jamais supprimée + trace `affectation.changee` (ids groupes, sans PII) |
| IA-60 | Simulation sans écriture | PASS | `simuler` : compteurs inchangés + impact capacités + raisons codées (tests ×2) |
| IA-61 | Masse | NOT_APPLICABLE | Moteur de lots existant (`MassAssignmentService`/`AffectationMasseLot`) couvre le bulk — pas de duplication |
| IA-62 | Demande étudiant (canal) | BLOCKED (décision métier) | Canal à arbitrer : type OnlineRequests vs admin-only — documenté `inscription-group-assignment.md` §3 |
| IA-63 | Après début des cours / période de gel | NOT_APPLICABLE (décision métier) | Aucune règle existante dans le dépôt (aucune date de début de cours sur les groupes, aucun concept de gel) — à arbitrer si besoin métier |

## Cas §14 — métriques de supervision (LOOP D, 11/10/2026)

| ID | Cas | Statut | Preuve |
|---|---|---|---|
| IA-90 | Taux d'affectations automatiques réussies | **PASS** | `kpis().affectations_auto` — audits `auto_assignee`/`auto_non_assignee`, 1 requête groupée, scope faculté prouvé, taux null si vide (`FileTraitementMetriquesTest` 3/3) |
| IA-91 | Volume changements de groupe | **PASS** | `kpis().changements_groupe.effectues` — audits `affectation.changee` (LOOP B) |
| IA-92 | Taux de changements nécessitant une intervention | **NOT_APPLICABLE** | Par design : tout changement de groupe EST une décision humaine (§9 workflow) ; les refus ne s'auditent pas — aucune écriture |
| IA-93 | Âge des dossiers en attente | PASS (préexistant) | Souffrance 48h/7j + délai moyen (KPIs file, non dupliqués) |
| IA-94 | p50/p95/p99, débit, mémoire, jobs | **NOT_EXECUTED** | Pas d'environnement de charge dans ce contexte ; volumétrie file mesurée (300 dossiers = 14 ms/23 requêtes) — benchmarks dédiés si besoin métier |

## Cas §10-§11 — simulation et idempotence

| ID | Cas | Statut | Preuve |
|---|---|---|---|
| IA-70 | Simulation sans écriture + recalcul à la confirmation | PASS | `AssignmentSimulationService` (pureté testée) + `AssignmentConfirmationService` (re-validation, obsolescence, idempotence) |
| IA-71 | Deux workers / deux affectations concurrentes | PASS | Verrous ordonnés + preuve séquentielle (skip MySQL documenté — course réelle = test gaté futur) |
| IA-72 | Notification non répétée | PASS | Dédup par clé (`NotificationService::notifierEtudiant(?cle)`) — pattern prouvé (LOOP 11/12) |

## Cas §12 — sécurité

| ID | Cas | Statut | Preuve |
|---|---|---|---|
| IA-80 | Permissions/isolation/CSRF/throttle/MENS/documents/4-eyes/PII | PASS | Suites sécurité transversales (44) + `ArchiveAuthorizationTest` 32 + `SeparationProfilsTest` 17 + audits du jour ; auteur vs approbateur distingués (4-eyes `proposerInscription`/`CorrectionService`) |
