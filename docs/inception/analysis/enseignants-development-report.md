# Rapport final — Boucle master Module Enseignants (phases A–E)

- Date : 2026-10-10. Audit source : `docs/inception/analysis/enseignants-audit.md`.
- Journal : `docs/inception/analysis/enseignants-development-log.md` (toutes les commandes et décisions y sont consignées phase par phase).
- Statut : **5/5 phases VALIDÉES**. Projet déclaré terminé pour le périmètre mandaté (réserve déploiement §9).

## 1. Résumé exécutif

Toutes les anomalies de l'audit ont été traitées par preuve test-first : suppressions 500 éliminées (6 familles gardées), autorisation de la liste posée, écritures throttlées, isolation faculté appliquée (décision humaine : modèle salles), `actif/statut` unifiés (`actif` fait foi, décision humaine), import/export durcis (statuts persistés, grades explicites, conflits et périmètre refusés, parité 9/9), interface enrichie (matricule, filtres, pagination perso) et fiche sans N+1, code mort supprimé, traçabilité sans PII dans le journal central. **34 tests créés, 290 tests rejoués en validation globale, 0 échec, Pint vert, 1 migration additive.**

## 2. Statut des phases

| Phase | Statut | Preuve |
|---|---|---|
| 0 Audit et baseline | VALIDÉE | Anomalies confirmées, baseline 129/129 verts |
| A Fiabilité et sécurité | VALIDÉE | 9/9 ciblés, régressions 76+90 verts |
| B Cohérence métier | VALIDÉE (après décision humaine : isolation salles + actif fait foi) | 6/6 ciblés, régressions 67+32+8 verts |
| C Import et export | VALIDÉE | 9/9 ciblés, pipeline 60+23 + SALIMA 140 assertions verts |
| D Interface, perfs, hygiène | VALIDÉE | 5/5 ciblés, régressions 88+96 verts |
| E Traçabilité et interop | VALIDÉE | 5/5 ciblés, régressions 32+31 verts |
| Validation globale | VALIDÉE | 34+76+121+59 = **290 tests, 0 échec** |

## 3. Anomalies corrigées et preuves

- **E1 (élevée)** : `destroy()` ne gardait que les créneaux → 500 FK prouvé (surveillance, pointage, affectation, puis jury/matière relevés par la 2e main). Corrigé : garde 6 familles + refus métier. Preuve : `EnseignantFiabiliteTest` (9 tests, 500 reproduits avant, redirects après).
- **E2 (élevée)** : entité globale manipulable inter-facultés (vecteur email+mot de passe prouvé par lecture). Corrigé après décision : scope index/show/écritures + 404, `EnseignantsProvider` et dropdown Listes scopés. Preuve : `EnseignantPerimetreTest` (6 tests A/B).
- **M1** : `can:enseignants.view` sur l'index (REVUE `audit:routes-securite` résorbée pour cette route).
- **M2** : select `statut` au formulaire + synchro serveur documentée (dernier écrit gagne côté statut, chemin historique `actif` préservé — `BackOfficeTest` inchangé vert).
- **M3/M5** : `ligne()` 9/9, statuts normalisés (`ACTIF`→`actif`) persistés + `actif` synchronisé, grade format strict + création signalée, cohérence faculte_code, conflit matricule refusé, département inconnu refusé dès la preview.
- **M4** : fiche 5 requêtes `matieres` unitaires → 0, requête Blade → contrôleur, total borné ≤ 15 en test.
- **M6/M7** : provider aligné sur le périmètre ; `throttle:20,1` sur les 3 écritures (429 prouvé au 21e appel).
- **M8** : 3 contrôleurs portail morts supprimés (zéro référence prouvée par grep) + `composer dump-autoload`.

## 4. Fichiers créés ou modifiés

- Créés : `tests/Feature/Enseignant{Fiabilite,Perimetre,ImportExport,Interface,Tracabilite}Test.php` (34 tests) ; migration `2026_10_10_191552_rendre_etudiant_audit_nullable.php` ; ce rapport (le journal a été tenu à jour à chaque phase).
- Modifiés : `Admin/EnseignantController.php` (scopes, gardes, synchro, traces, filtres, pagination, eager), `routes/web.php` (Gate + throttles, aucun URI renommé), `TeacherDefinition.php` (Phase C), `EnseignantsProvider.php`, `ListesController.php` (dropdown), `AuditService.php` (`tracerReferentiel`), `HistoriqueAcademique.php` (3 constantes + libellés), `AuditController.php` (index + export scopés, helper), vues `admin/enseignants/{index,form,show}.blade.php`, `admin/audit/index.blade.php` (colonne Entité), `AGENTS.md` (contexte), `composer` classmap régénérée (généré).
- Supprimés : `Enseignant/{Surveillance,Presence,EmploiDuTemps}Controller.php`.

## 5. Migration et justification

- `2026_10_10_191552_rendre_etudiant_audit_nullable` : `historique_academique.etudiant_id` NOT NULL → nullable (re-création FK cascade à l'identique). Justification : réutiliser le journal central et son centre/export (zéro 2e table) ; additive et réversible (down supprime les seules lignes sans dossier puis restaure NOT NULL — perte limitée aux événements créés par cette fonctionnalité). Prouvée sur SQLite via `RefreshDatabase` (toutes les suites). **Non appliquée au MySQL dev partagé** (parallélisme + règle de prudence) → **à déployer via `php artisan migrate`** (§9). Rollback live non exécuté (documenté, à valider en recette).

## 6. Tests réellement exécutés, résultats et durées

| Lot | Résultat |
|---|---|
| 5 suites boucle (Fiabilite 9, Perimetre 6, ImportExport 9, Interface 5, Tracabilite 5) | 34/34 (~1–3 s) |
| Portail + BackOffice + Authorization (8 fichiers) | 76/76 (~4 s) |
| Intégrations (présences, appel, EDT, affectations ×2, listes ×3, dataset, navigation) | 121/121 (~5–7 s) |
| Pipeline + audit (DataExchange ×2, SALIMA 140 assertions, ImportCenter, AuditLog, StudentArchive) | 59/59 (~3–4 s) |
| Lots intermédiaires (67+32+8, 88+96, 28, 23, 32+31) | tous verts, 0 rouge |
| **Total validation globale** | **290/290, 0 échec, 0 skip** |
| Pint (fichiers touchés, par chemins — `--dirty` indisponible sans Git) | vert partout (4 fix cosmétiques auto) |
| `route:list` | 7 CRUD + 7 presences, noms/URIs inchangés |
| Non exécuté | suite complète (~2000 tests), parcours navigateur authentifié, charge live, rollback live |

## 7. Comparaison avant/après

- Suppression attachée : 500 FK → redirect + erreur `enseignant` ciblée, historique intact.
- Index : global sans Gate, 12 fixes, recherche 3 champs → scopé + Gate, pagination perso (5–100), recherche 4 champs + 3 filtres combinables préservés en pagination.
- Fiche : N+1 (5 requêtes `matieres`) + requête en Blade → 0 + contrôleur maigre.
- Formulaire : case `actif` déconnectée de `statut` → select unique synchronisé (création `suspendu` testée : compte fermé + login refusé).
- Import : statut perdu, grade silencieux, conflits/perimètre absents, export 7/9 → statut persisté, grade explicite, refus codés, parité 9/9, périmètre auteur + export.
- Traçabilité : zéro trace → CRUD tracés sans PII + centre scopé + colonne Entité ; imports déjà tracés (prouvé HTTP).
- Inter-facultés : lecture/écriture globales → 404 hors périmètre (transversaux partagés).

## 8. Risques résiduels

1. Migration à appliquer au déploiement (dev/prod) + rollback live non prouvé → recette requise (§9).
2. Sans Git, aucune revue de diff automatisée (atténué : journal + périmètre minimal + suites vertes).
3. Filtre `enseignant_id` de `ListeEtudiantsService` non scopé en propre (dropdown source scopé ; étudiants déjà scopés — résidu assumé).
4. Tri configurable non ajouté ; XLSX/JSON/PDF d'export non re-prouvés unitairement (framework générique) ; import volumineux non mesuré.
5. Co-édition parallèle (2e main active : Phase 0 du journal, FK jury/matières, classmap) — convergence vérifiée à chaque étape, à maintenir.

## 9. Déploiement et retour arrière

1. Sauvegarde base (procédure existante). 2. `composer dump-autoload` (déjà régénéré ici, à refaire au déploiement si vendor redéployé). 3. `php artisan migrate` (applique la migration nullable + celles des autres chantiers en attente — vérifier `migrate:status`). 4. `vendor/bin/pint --test`, tests ciblés (`Enseignant*Test`), `route:list`. 5. Vérifier centre d'audit (colonne Entité) et page enseignants (filtres). Retour : revert des commits/fichiers listés §4 + `migrate:rollback --step=1` (supprime les lignes d'audit sans dossier — perte acceptée et documentée) — **à valider en recette avant prod**.

## 10. Décisions métier et prochaines étapes

- Décisions reçues et appliquées : isolation modèle salles, `actif` fait foi. Aucune décision bloquante restante.
- Évalués puis écartés avec justification : notifications admin CRUD (aucun précédent, canal rapports existant), API v1 enseignants (aucun consommateur, serait artificielle).
- Prochaines étapes : appliquer §9 en recette, mesurer la fiche en charge réelle si besoin, étendre le pattern (gardes `destroy`, `tracerReferentiel`, scoping) aux autres référentiels si souhaité.
