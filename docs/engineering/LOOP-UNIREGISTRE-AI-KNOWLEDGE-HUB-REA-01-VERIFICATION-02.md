# LOOP AI KNOWLEDGE HUB (REA-01) — Vérification indépendante 02

> Date : 23/10/2026 · Complément de `LOOP-UNIREGISTRE-AI-KNOWLEDGE-HUB-REA-01.md` (programme
> principal) et `-CONTRE-AUDIT-01` / `-INDEPENDENT-AUDIT-01` (agents parallèles). Rôle : **vérifier
> dans le code actuel** les affirmations des rapports, corriger un défaut de véracité confirmé, et
> signer la non-régression croisée. Session parallèle — co-éditions détectées et respectées.

## A. Verdict (périmètre vérifié)

**`PASS_WITH_RESERVATIONS`** — l'architecture réelle a été auditée de façon indépendante ; le
module `AssistantIa` (actif) couvre les 14 lots avec des implémentations réelles et testées
(130 tests dédiés verts) ; un défaut de véracité (message d'approbation prétendant une exécution
inexistante) est corrigé ; la non-régression croisée de 297 tests passe. Réserves : recherche
« hybride » **sans embeddings** (lexicale enrichie), chaîne d'exécution post-approbation restant
dans les services métier humains (par conception mais libellé corrigé), bascule fournisseur
`distant` possible via config sans garde dure, deux piles IA coexistantes (AssistantIa actif /
AiAssistant désactivé) à trancher.

## B. État réel vérifié (LOT 0 — confronté au code, pas aux rapports)

| Lot | État vérifié | Preuves (code actuel) |
|---|---|---|
| 0 Audit | 39 fichiers AssistantIa + config + 21 suites (16 Feature + 5 Unit) | `app/Modules/AssistantIa/**`, `tests/**/AssistantIa/` |
| 1 Registre sources | Table `ai_sources_connaissances` + `RegistreSourcesConnaissances` (idempotent, anti-traversal, suspension) + UI admin | migrations 2026_10_23_14*, `RegistreSourcesConnaissances.php` |
| 2 Code UniRegistre | `ConnecteurCodeUniRegistre` : incrémental sha256, détection secrets bloquante, exclusions strictes, extraction symboles classes/méthodes/routes, permission dédiée | `ConnecteurCodeUniRegistre.php:16-47,103-155,186-204` |
| 3 Doc métier | Frontmatter statut/domaine/version + avertissements obsolete/brouillon | `ConnecteurDocumentationMetier.php:46-138` |
| 4 Web contrôlé | `ConnecteurWebControle` : whitelist 7 domaines, HTTPS-only, anti-SSRF DNS/IP, sans redirection, 150 Ko/5 s, encapsulation anti-injection | `ConnecteurWebControle.php:17-130,162-177` |
| 5 Recherche | Lexicale **enrichie** (exclusion obsolètes par défaut + `inclure_obsoletes`, filtre `domaine`, bonus symbole exact, bonus validé / pénalité brouillon, plafond 40 candidats) | `MoteurRechercheDocumentaire.php:40-110` |
| 6 Ollama local | `FournisseurLocal` (chat OpenAI-compatible, tool_calls, diagnostics RAM/disque, offline 503 fail-soft, **pas de fallback cloud**) ; défaut = fournisseur `faux` | `FournisseurLocal.php`, `config/assistant-ia.php:16,36-42` |
| 7 Orchestration | 10 intentions (Routeur), RAG filtré par intention, boucle tool-calling ≤ 5, purge sortie | `OrchestrateurAgentIa.php:39-229`, `RouteurIntentionsIa.php` |
| 8 Outils | 11 outils, registre à re-vérification à chaque exécution, mapping statique (aucun code arbitraire) | `RegistreOutils.php:110-139`, `Tools/**` |
| 9 Approbation | Verrou pessimiste, permissions re-vérifiées, 4-yeux (bypass super_admin journalisé comme alerte), expiration, anti-rejeu, intégrité sha256 ; **l'assistant ne s'auto-exécute jamais** (libellé corrigé ici) | `WorkflowApprobationService.php:60-130` |
| 10 Interface | `index` (chat Alpine, jauge quota, badge fournisseur) + `sources` admin ; CSRF OK ; anti-IDOR `pourUtilisateur()` | `AssistantIaController.php:127,165,219`, vues |
| 11 Observabilité | `JournalisationIa` (masquage Bearer/sk-/CIN/email), `ObservabiliteIaService` (agrégats sans PII) | `JournalisationIa.php:12-74` |
| 12 Benchmark | `BenchmarkValidationKnowledgeHubTest` (16 tests, scénarios routes/services/cycle/pièces/prompt-injection) | tests/Feature/AssistantIa/ |
| 13 Non-régression | **297 tests / 1406 assertions — PASS** (campagne croisée de cette session, §F) | journalisé |
| 14 Documentation | `docs/architecture/UNIREGISTRE-AI-KNOWLEDGE-HUB.md` + rapports LOOP présents | docs/ |

## C. Défaut confirmé et corrigé (véracité — LOT 0.16/9)

**CT-IA-01** : `WorkflowApprobationService::approuverAction()` retournait « Action approuvée et
**exécutée** avec succès » alors qu'**aucun exécuteur métier n'existe** — la proposition marquée
`completed` est un mandat, l'exécution effective relève des écrans/services métier humains.
Corrigé : message et commentaire désormais explicites (« Exécution métier à réaliser hors
assistant »), commenté « l'assistant ne s'auto-exécute JAMAIS ». Aucun test n'assertait l'ancien
libellé (grep négatif). Suites d'approbation rejouées : **45/45 PASS**.

## D. Corrections de cette session

| Fichier | Modification |
|---|---|
| `app/Modules/AssistantIa/Services/WorkflowApprobationService.php` | Libellés honnêtes d'approbation (Pint PASS) |
| `docs/engineering/LOOP-UNIREGISTRE-AI-KNOWLEDGE-HUB-REA-01-VERIFICATION-02.md` | Ce rapport |

Aucune migration, aucune dépendance, aucune route, aucun commit. WIP 557+ entrées préservé ;
co-éditions concurrentes en temps réel détectées (fichiers de tests réécrits pendant la session) —
rejeux effectués à état stable.

## E. Anomalie transitoire documentée

`AuditIndependantKnowledgeHubTest::test_lot_e_isolation_facultaire_anti_bola` a échoué **une fois**
pendant la campagne (fichier réécrit par l'agent concurrent pendant l'exécution), puis **16/16
PASS** au rejeu à état stable. Aucune régression : l'échec était un artefact de co-édition, pas un
défaut de code.

## F. Tests exécutés (commandes et résultats réels)

| Commande | Résultat |
|---|---|
| `php artisan test --compact --filter="AssistantIa"` | **130 / 130 PASS, 529 assertions** |
| `php artisan test --compact --filter="SecuriteApprobationAvanceeTest\|AgentsEtApprobationsTest\|AuditSecuriteIndependantTest\|AiAssistantChatTest"` | **45 / 45 PASS, 146 assertions** (après correction libellé) |
| `php artisan test --filter="AuditIndependantKnowledgeHubTest"` | **16 / 16 PASS, 77 assertions** (rejeu post-co-édition) |
| Campagne croisée : AssistantIa + Notes/Évaluations + UX + Centre de travail + thème + inscription (16 suites) | **297 / 297 PASS, 1406 assertions** |
| `vendor/bin/pint app/Modules/AssistantIa/Services/WorkflowApprobationService.php` | PASS |

## G. Limites et risques résiduels (classés)

- **P2** — Recherche « hybride » = lexicale enrichie ; **aucun embedding** (`bge-m3` recommandé dans
  le diagnostic Ollama mais non implémenté) — le cahier exige une évaluation avant d'ajouter un
  vector store : à arbitrer avec des mesures.
- **P2** — Bascule fournisseur `distant` possible via config sans garde dure (aucune variable
  `ASSISTANT_IA_*` dans `.env.example`) : recommandation = flag `interdire_distant` + documentation
  des variables (non fait ici : fichier co-édité chaud, tests concurrents dépendants).
- **P2** — Deux piles IA coexistantes (`AssistantIa` actif / `AiAssistant` désactivé) : tables,
  workflows et tests dupliqués — décision d'architecture requise.
- **P3** — `politique_actualisation='sur_modification'` non honorée (aucun hook) ; purge mémoire 90 j
  jamais planifiée ; `resultats_outils` persistés sans masquage PII (sortie finale seule masquée).

## H. Garantie de préservation

Aucun commit, push, reset, clean, stash, migration destructive ni modification de `.env`.
Changements inventoriés : 1 fichier PHP corrigé (libellés) + ce rapport. L'arbre préexistant
(557+ entrées, travaux de 3 programmes parallèles) est intact.
