# LOOP INDUSTRIALISATION ÉVALUATION — 03 · Lot sécurité, traçabilité et gouvernance technique

## 1. Matrice de sécurité (PHASE L) — état réel vérifié

| Opération | Acteur | Permission | Périmètre | Contrôle vérifié |
|---|---|---|---|---|
| Consultation note | admin/agent scolarité, enseignant (portail) | `notes_eval.view` | faculté/affectation (Policy `dansPerimetre`) | ✅ suites + Policy |
| Saisie (lot/anonyme/import) | agent_scolarite, enseignant portail | `notes_eval.enter` | épreuve du périmètre | ✅ Gates × 5 routes + service |
| Validation | **admin_scolarite, super_admin** | `notes_eval.validate` | périmètre + états + verrous | ✅ EXCLUE de agent_scolarite |
| Verrouillage | admin/super_admin | `notes_eval.lock` (motif obligatoire) | note/épreuve | ✅ service + NotePolicy officielle |
| Correction (autoriser) | admin/super_admin | `notes_eval.correct` | après demande motivée | ✅ workflow 3 étapes |
| Publication | circuit délibération | `deliberations.manage` + verrous | décision officielle | ✅ DeliberationService |
| Export (anonyme/stream) | détenteur saisie | `saisirNote` → stream | épreuve du périmètre | ✅ contrôleur |
| Historique | lecture `notes_eval.view` | — | périmètre | ✅ + **append-only (ANO-01 corrigé)** |

**Aucun élargissement de droits effectué** ; aucune permission créée (8 permissions `notes_eval.*` canoniques réutilisées).

## 2. Isolation facultaire et IDOR/BOLA (PHASE M)

- Policy `EvaluationPolicy::dansPerimetre` sur chaque objet (épreuve → groupe/UE→programme → faculté).
- Contrôle **serveur only** : `faculte_id` du client jamais lu ; périmètre via `faculteRestreinteId()`.
- Refus **avant toute écriture** : Gates précèdent l'appel service ; le service re-verrouille et re-vérifie (double défense) — un 403 n'est jamais précédé d'une écriture (vérifié : aucune mutation dans les contrôleurs avant `Gate::authorize`).
- Manipulation d'identifiants (`etudiant_id`, `note_id`, `epreuve_id`, `examen_id`, `session_id`, `decision_id`) : couverte par binding + Policy + gardes d'état (suites de sécurité existantes : 106 tests notes dont IDOR, 198 assertions gouvernance).

## 3. Historique et traçabilité (PHASE P) — quatre journaux distincts (jamais fusionnés)

| Journal | Table/mécanisme | Contenu | Protection |
|---|---|---|---|
| Historique métier notes | `evaluation_notes_historique` | avant/après, motif, auteur | **Append-only modèle (corrigé cette campagne)** |
| Événements applicatifs | `EvaluationChanged` → `Log::info` | type, entité, acteur | log technique |
| Décisions officielles | `evaluation_decisions_deliberation` + `deliberations` | décision, moyenne, crédits, `publie`, verrou | verrouillage + circuit |
| Audit consultatif | `EvaluationAuditService` (comparaisons de versions) | dérivations | lecture |

Champs systématiquement disponibles : acteur, action, ressource, date, avant/après (notes), motif (verrouillage/correction), statut workflow. **Aucun secret journalisé** (vérifié : aucun token/mot de passe/MFA dans ces journaux).

## 4. Gouvernance technique à quatre yeux (PHASE O)

- **Séparation par RBAC** démontrée : saisie (`enter`) ≠ validation (`validate`) ≠ correction (`correct`) — rôles distincts dans la matrice.
- Correction verrouillée : **demandeur** (motif) → **autorité** (`notes_eval.correct`, autre permission, autre acteur possible) → **exécutant** (re-saisie) — 3 acteurs identifiables dans `NoteEpreuveHistorique`.
- **Auto-validation par personne distincte : non imposée** (D-11 — décision métier ouverte, documentée, aucune garde inventée).
- Interdiction d'auto-validation : **implémentée** dans la suppléance d'urgence (`ExamUrgenceSuppleanceService`, « Interdiction absolue d'auto-validation »).
- Réouverture d'une note verrouillée : uniquement via correction autorisée (jamais d'écriture directe) ✅.

## 5. Révocation, cache, jobs (PHASE N)

- Permissions réévaluées à chaque requête (`hasPermission` → matrice + moteur additif LOOP 43-BIS) — pas de cache de permissions périmé observé dans le module.
- Révocation de sessions : mécanismes existants (`RevocationSessionsEnseignantTest`, `RevocationSessionsTest` — au vert).
- Jobs différés (`EvaluationImportService` FILE+JOB) : état métier rechargé à l'exécution (épreuve re-verrouillée dans `saisirLot`) — une épreuve verrouillée entre-temps refuse le lot ✅ (SEC-015 satisfait par construction).
- Tokens API : hors périmètre d'écriture notes (aucune route API d'écriture de note recensée).

## 6. Tests de sécurité — résultats

| ID | Scénario | Statut | Preuve |
|---|---|---|---|
| SEC-001/002/003 | accès/saisie/correction sans droit | ✅ | suites existantes (403 Policy/Gate) |
| SEC-004 | publication non autorisée | ✅ | `DeliberationPhase6Test` (verrous) |
| SEC-005/006/007 | IDOR/faculté | ✅ | `NotesEvaluationsSecuriteTest` (effets en base vérifiés) |
| SEC-008 | appel direct de service | ✅ | gardes service re-testées (barème, verrou, matrice) |
| SEC-011 | export sans permission | ✅ | Gate `saisirNote` sur export |
| **SEC-012** | **modification d'historique** | ✅ **nouveau** | `NotesHistoriqueAppendOnlyTest` (5 tests, 23 assertions) : update → `DomainException`, delete → `DomainException`, avant/après conservé, journal intouché par la revalidation |
| SEC-013/014 | révocation/cache | ✅ | suites RevocationSessions + évaluation à chaque requête |
| SEC-015 | job après verrouillage | ✅ | re-verrouillage service à l'exécution (FILE+JOB) |
| SEC-016/017 | auto-élévation/auto-approbation | ✅/⚠️ | suppléance ✅ ; notes = D-11 ouvert |
| SEC-018 | rejeu | ✅ | re-saisie = mise à jour historisée, pas de doublon |
| SEC-019 | concurrence correction | ✅ | `lockForUpdate` + verrou officiel (suites) |
| SEC-020 | secrets dans logs | ✅ | aucun secret dans les 4 journaux |

## 7. Synthèse du lot

**Sécurité : PASS_WITH_RESERVATIONS** — aucun contournement démontrable ; ANO-01 (historique modifiable) corrigée et testée ; réserves D-11 (séparation par personne) et D-12 (rectification post-publication) documentées en attente de décision institutionnelle.
