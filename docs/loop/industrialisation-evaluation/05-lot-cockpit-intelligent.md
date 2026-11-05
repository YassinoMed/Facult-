# LOOP INDUSTRIALISATION ÉVALUATION — 05 · Lot cockpit de pilotage

## 1. Indicateurs existants — définitions et sources vérifiées (PHASE Y/AD)

| KPI | Source (service) | Définition | Périmètre | Statut |
|---|---|---|---|---|
| Examens par état | `ExamenAdvancedMenuController` | `Examen::where('statut', …)` + `pourFaculteId` | faculté (serveur) | ✅ vérifié (source directe) |
| Sessions open/ongoing | `TableauBordEvaluationController`, `ExamDashboardOrchestratorService` | comptages `SessionExamen` | — | ✅ |
| Notes saisies/validées | `ExamDashboardOrchestratorService` | états `NoteEpreuve` | périmètre policy | ✅ |
| Conflits planning | `ConflitEpreuveService` + audit conformité | recalcul à la demande | examen/session | ✅ (recalcul, pas cache) |
| Dossiers gouvernance | `ExamGouvernanceValidationService::derniereValidation` | statuts `PlanningValidation` | examen | ✅ |
| Historique/audit examens | `ExamHistoriqueAuditOrchestratorService` | `IntegrationLog` filtré serveur | filtres paramétrés | ✅ |

**Fraîcheur** : tous les indicateurs sont calculés à la requête (aucun cache de KPI obsolète détecté dans le module) — pas de « temps réel » prétendu.

## 2. Règles de fiabilité appliquées

- Résultats provisoires (`publie=false`) **jamais** comptés comme publiés (source : `DecisionDeliberation.publie`).
- Filtres appliqués **côté serveur** (périmètre facultaire forcé, filtre client seulement à l'intérieur du périmètre).
- Chaque KPI relié à sa source exacte — aucune statistique dérivée non documentée.

## 3. Anomalies cockpit (PHASE AB) — sources exploitables

Notes manquantes (notes sans valeur), notes en attente de validation (statuts `entered`/`submitted`), corrections à traiter (`correction_demandee`), conflits de salles (moteur de conflits), dossiers gouvernance en attente, épreuves incohérentes (audit conformité) — toutes calculables depuis les sources vérifiées ci-dessus.

## 4. Gouvernance IA (PHASE AC)

- **Aucune décision académique autonome** : l'IA ne modifie ni notes, ni acquis, ni plannings, ni approbations (module AssistantIa : lecture/assistance, permissions respectées via `FiltreSecuriteIa`).
- Tout passage à l'action resterait exécuté par les services métier existants après autorisation humaine — **non implémenté, non prévu sans validation**.

## 5. Tests KPI

| ID | Scénario | Preuve |
|---|---|---|
| KPI-001/004/005 | comptages examens/notes | suites `EvaluationAuditTest`, `ExamensFeatureCompleteTest` (cohérence sources) |
| KPI-002/009 | isolation facultaire | Policy + suites isolation (403 hors périmètre) |
| KPI-008 | publication reconnue | `DeliberationPhase6Test` (publie=true uniquement) |
| KPI-010 | fraîcheur | calcul à la requête vérifié par lecture des services |

**Verdict lot cockpit : PASS_WITH_RESERVATIONS** — indicateurs sourcés et isolés ; l'« analyse intelligente » reste P2 et soumise à validation métier (aucune implémentation non autorisée).
