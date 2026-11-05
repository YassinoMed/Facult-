# ST-11 — 16 · Matrice de tests

## Couverture ST-11 (IDs du cahier → preuves)

| ID | Scénario | Statut | Preuve |
|---|---|---|---|
| ST11-SEC-001 | Module désactivé → 404 | ✅ existant | `test_isolation_perimetre_et_module` (Config active=false, sidebar + accès) |
| ST11-SEC-002 | Non authentifié → refus | ✅ existant | middleware `auth` (groupe) |
| ST11-SEC-003 | Non-administrateur → refus | ✅ existant | middleware `admin` |
| ST11-SEC-004 | Permission manquante → refus | ✅ **nouveau** | ST11-SEC-011 (consultation 403 partout) |
| ST11-SEC-005/006/007/008 | Faculté A → objet B (scénario/exécution/export/gouvernance) | ✅ existant | `test_isolation_perimetre_et_module`, `test_options_niveaux_filtrees…` + contrôle service gouvernance |
| ST11-SEC-009 | Manipulation d'identifiant | ✅ existant | `test_groupe_hors_perimetre_rejete_meme_requete_forgee` |
| ST11-SEC-010 | Throttle | ⚠️ **config seulement** | familles `st-*` (routes) — **aucun test de dépassement 429** (réserve ST11-SEC-R8, test à ajouter Phase M) |
| ST11-SEC-011 | Consultation refusée partout | ✅ **nouveau** | test dédié |
| ST11-SEC-012 | Agent sans habilitation ne décide pas | ✅ **nouveau** | test dédié (proposition reste « soumise ») |
| ST11-SEC-013 | Permissions non attribuées aux anciens rôles | ✅ **nouveau** | test dédié (7 rôles × 7 permissions) |
| ST11-RO-001..009 | Lecture seule sur tous les chemins | ✅ élargi | `test_preuve_absolue_absence_ecriture_tables_metier` (10 tables + export CSV) ; `test_aucune_ecriture_metier_et_snapshots_sans_pii` |
| ST11-RO-010 | Erreur pendant simulation → métier intact | ✅ | chemins d'écriture = 3 tables techniques uniquement ; exceptions avant écriture |
| ST11-DET-001..008 | Hash/graine/idempotence/versions | ✅ existant | `test_execution_reproductible_et_idempotente`, `test_graines_differentes_resultats_differents`, lot 4 versionnement |
| ST11-MATH-001..013 | Formules, taux, horizon, cible, écarts | ✅ existant | lots 1-13 ST-10 (audit qualité → alertes) |
| ST11-MATH-014 | Conservation frontière d'arrondi | ✅ **nouveau** | 61+11−15=57 exact |
| ST11-STAT-001..008 | Quantiles/écart-type/constante | ✅ existant | lot 10 Monte-Carlo (conventions vérifiées) |
| ST11-GOV-001..008 | 4 yeux : auto-approbation, périmètre, rejeu | ✅ existant | lots 14 + gouvernance resoumission |

## Bilan

| Catégorie | Tests | Statut |
|---|---:|---|
| Existants préservés | 47 | ✅ (adaptés : habilitations IAM) |
| Nouveaux ST-11 | 4 | ✅ (SEC-011, SEC-012, SEC-013, MATH-014) |
| **Sous-total ST-11** | **51** | **51/51, 305 assertions** (historique, remplacé) |
| ST-12 (UX-014 : masquage décision auteur + mention budget) | +1 | ✅ |
| **Résultat définitif** | **52** | **52/52, 312 assertions** (mesuré le 2026-11-02) |

> Le verdict ST-11 (18) distingue l'état historique de clôture ST-11 (51/305) et le **résultat définitif** du dépôt (52/312, incluant ST12-UX-014).
