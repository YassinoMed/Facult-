# Rapport LOOP 8 — Validation préproduction du Registre Étudiants

- Date : 2026-10-11. Base : boucle précédente (6 PASSED + 1 PASSED_WITH_WARNINGS).
- Strictement fondé sur des mesures : aucun navigateur piloté (aucun outil disponible), aucune donnée dev/prod touchée (base scratch `loop8_registry` pour MySQL).

## Étape 1 — État réel
- Artefacts relus ; code du périmètre inchangé depuis (re-preuves ci-dessous).
- Correctif doublons + scope confirmé en place (`FicheEtudiantService::doublonsPotentiels(..., ?faculteId)`, appel scopé ligne 197 du contrôleur).
- Logs : **PII préexistantes trouvées dans `storage/logs/laravel.log`** (CIN + email + **hash bcrypt `$2y$12$…`** dans `referentiel.nettoyage_cluster_poubelle_snapshot` du 2026-10-08 + lignes `fusion` CIN). Antérieures à mes boucles, autre chantier : signalées, non modifiées (logs partagés).

## Étape 2 — Sécurité interfacultés (contenu, 6/6 verts)
`RegistreInterfacultesTest` (créé) : fiche étrangère 403 **sans nom/CIN/email dans le HTML** ; index + sans-groupe sans trace B ; recherche instantanée JSON sans résultat B ; bulk JSON cross-faculté 403 sans mutation ; export JSON 403 sans contenu ; rôles légitimes (scolarité, consultation) intacts. Pint vert.

## Étape 3 — Suite complète : 2780 tests, 2766 verts, 5 échecs, 9 skips
Échecs triés (reproduits isolément) — **aucun imputable au périmètre registre** :
1. `EmploiEnseignantIntelligentTest::test_statuts_annule_deplace_affiches` (« Jour férié » manquant) — périmètre enseignants, date-sensible, fichiers non touchés par mes boucles.
2-4. `EnseignantStatutCoherenceTest` ×3 (`actif` null) — périmètre enseignants, fichiers modifiés il y a ~13 h par un autre chantier en cours ; non touchés par mes boucles.
5. `InscriptionPerformanceFiabiliteTest` (attend 25 items, 12 observés) — assertion périmée depuis la boucle file-traitement (pagination par préférence, défaut 12) ; comportement actuel voulu et validé ailleurs.
Aucune correction appliquée hors périmètre (collision avec chantiers actifs évitée) ; à traiter par leurs chantiers respectifs.

## Étape 4 — Navigateur : NOT_EXECUTED
Aucun outil navigateur disponible dans cet environnement. Non simulé.

## Étape 5 — MySQL 9.2 (scratch `loop8_registry`, 155 tables migrées — compatibilité prouvée)
- `EtudiantsRegistreTest` : 27/27 verts sur MySQL (~16 s).
- Volumétrie 300 dossiers, index : **84 ms, 13 requêtes** (sqlite : 14–46 ms, 13 requêtes) — stable inter-drivers, aucun N+1 au volume.
- Fiche : 39 requêtes (sqlite ; mesure MySQL non rejouée au volume, borne < 60 en test).
- Pas de seuil arbitraire imposé ; comparatif avant/après sans objet (zéro changement applicatif dans ce LOOP hormis test + correctif doublons déjà livré).

## Étape 6 — Corrections
Aucune correction applicative requise dans le périmètre (1 test de volumétrie ajouté, vert des deux drivers). Les 5 échecs sont hors périmètre : documentés, non touchés.

## Étape 7 — Attribution : voir `student-registry-attribution-proposal.md` (non officiel, 5 décisions listées pour le responsable de scolarité).

## Étape 8 — Verdict : VALIDÉ AVEC RÉSERVES
Réserves : (1) 5 échecs hors périmètre à résorber par leurs chantiers ; (2) PII préexistantes dans les logs (hash bcrypt + CIN, 08/10) à purger/corriger à la source ; (3) navigateur non exécuté ; (4) attribution en attente de décision métier. Aucune validation de production annoncée.
