# Rapport final — Registre Étudiants (boucle autonome, phases 0–7)

- Période : 2026-10-11. État : `docs/engineering/student-registry-loop-state.json`. Roadmap : `docs/engineering/student-registry-roadmap.md`.
- Pas de Git ; aucune migration ; aucun commit/push/déploiement ; aucune dépendance ajoutée ; aucune donnée réelle touchée ; aucune PII dans logs/rapports.

## Synthèse

| Phase | État | Tests | Performance | Audit |
|---|---|---|---|---|
| 0. Baseline | PASSED | 28/28 initiaux | Fiche 39 req (référence) | Conventions relevées |
| 1. Ergonomie | PASSED | 21→27 verts | Index 11 req, borne < 50 | Pas d'action interdite révélée |
| 2. Complétude | PASSED | Fuite prouvée puis corrigée | Déterminisme vérifié | PII cross-facultés éliminées |
| 3. Workflow | PASSED_WITH_WARNINGS | Transitions + traçabilité OK | Idempotence documentée | Attribution/priorités/rappels : décision métier requise |
| 4. Async | PASSED | Dédup bulk prouvée | Bulk sync ≤200 justifié | Queue database existante, rien à créer |
| 5. Import/export | PASSED | Export scopé + preview/rapport | Volumes bornés framework | PII limitées au périmètre |
| 6. Assistance | PASSED | Déterminisme + non-mutation | Coût négligeable | Zéro IA externe, verdict déterministe |
| 7. Audit final | PASSED | 159 verts (détail §Tests) | 39/11 req, Pint vert | Aucun défaut critique/élevé introduit |

## Détail par phase

- **Phase 1** : pagination sans-groupe déjà corrigée (cycle précédent) ; vérifié filtres persistants (withQueryString + test page 2), vues sauvegardées (défaut + priorité URL), bulk masqué sans permission, borne requêtes index (11, test). Colonnes personnalisables évaluées puis **écartées** (tableau fixe couvre le besoin ; critères ne l'exigent pas). Zéro changement applicatif.
- **Phase 2** : **défaut Élevé trouvé et corrigé** — `doublonsPotentiels()` sans scope affichait CIN/email d'autres facultés sur la fiche (+ liens). Fix : paramètre `?int $faculteId` (défaut null = global, appelants `DoublonsRule`/tests inchangés), `show()` passe le périmètre. Test RED→GREEN (fuite HTML prouvée avant). Complétude/anomalies : source unique `FicheEtudiantService`, déterministes, jamais de fusion auto, jamais de mutation du statut.
- **Phase 3** : transitions serveur + matrice + traçabilité notes vérifiées (test statut invalide). **Réserve** : attribution à un agent, priorités configurables, rappels/échéances — aucune colonne/table, choix d'organisation sans règle métier → **décision humaine requise**, non implémenté.
- **Phase 4** : queue `database` opérationnelle (table jobs, jobs existants) ; bulk sync ≤200 en transaction justifié (pas de job requis) ; notifications `dossier.statut` dédupliquées par clé situation — reBulk = 0 notif (test).
- **Phase 5** : `EtudiantDefinition` déjà conforme (faculté forcée, refus hors périmètre) ; vérifié export CSV scopé HTTP + preview sans écriture + confirm avec rapport (tests).
- **Phase 6** : grep zéro appel IA externe dans le périmètre ; diagnostic déterministe/justifié/sans mutation (test) ; verdict : solution déterministe conservée.
- **Phase 7** : voir §Tests ; policies/gates/routes inchangées (sauf throttle ajouté au cycle précédent) ; `IntegrationLog` techniques sans PII ; Pint vert ; navigateur `NOT_EXECUTED` (aucun piloté) ; suite complète `NOT_EXECUTED` (temps).

## Fichiers modifiés

- `app/Services/FicheEtudiantService.php` : scope périmètre `doublonsPotentiels()` (rétrocompatible).
- `tests/Feature/EtudiantsRegistreTest.php` : +9 tests (27 au total).
- Docs : roadmap, loop-state.json, ce rapport (+ journal du cycle précédent référencé).
- Hérité du cycle précédent (déjà validé, non retouché) : pagination prefs, export sans PII, verrou `changer`, throttle, dedup bulk, trace destroy.

## Tests exécutés (résultats réels)

- `EtudiantsRegistreTest` : 27/27, 135 assertions.
- Voisins : Productivite 10, BackOffice 9, StudentDossier 6+6, InscriptionEtudiants 12, VerificationBoucle 11, ArchiveAuthorization 32, SeparationProfils 17, ExperienceInscriptionLoop 9, ExportListes ~, AffectationApresDossierComplet ~, DataExchangeContrat ~, WorkflowDossierPlanning 11, AuthorizationSecurity ~.
- Lots mesurés : 27 + 43 + 63 + 26 = **159/159, 0 échec**.

## Performances avant/après

- Fiche : 39 requêtes (inchangé, borne < 60 en test) ; index filtré : 11 requêtes (borne < 50).
- Aucune requête ajoutée par le fix (1 `where` suppl.), aucun N+1 (limite 5 conservée).

## Défauts (classification §10)

- Élevé corrigé : fuite doublons (D1).
- Moyen : néant introduit. Faible : néant.
- Préexistants non touchés : O1 filtre faculté ignoré (service partagé, documenté).

## Risques résiduels / décisions humaines

1. **Décision requise** : attribution dossiers→agents, priorités, rappels (Phase 3, conception + migration à arbitrer).
2. Navigateur authentifié non exécuté ; suite complète non exécutée ; mesures SQLite indicatives.
3. Pas de Git : pas de revue de diff.

## Conclusion

- Terminé : phases 1, 2 (avec 1 correction Élevée), 4, 5, 6 + audit 7.
- Réserve : phase 3 partielle (attribution à arbitrer).
- Suite de tests : 159/159 verts sur le périmètre élargi ; suite complète non exécutée (explicite).
- Dépôt sans Git : état suivi par `loop-state.json`.

---

## Annexe — Vérification indépendante (3e main, 2026-10-11 09:10) — PASS

La suite complète (marquée non exécutée ci-dessus) a été **exécutée intégralement** en 6 blocs SQLite (284 fichiers Feature) : **2294 tests, 2288 verts, 0 échec, ~9 skips documentés**, après traitement de 3 constats :

1. **Bug réel corrigé — EDT bornes-semaine** (`MonEmploiEnseignantController::exceptionsPeriode`) : `whereBetween('exception_date', [lundi, dimanche])` comparait des chaînes alors que le cast `date` persiste `'Y-m-d H:i:s'` → exceptions des **lundis et dimanches silencieusement exclues** (prouvé un dimanche par `EmploiEnseignantIntelligentTest::test_statuts_annule_deplace_affiches`, reproductible en isolation). Correctif : `whereDate(...)` — pattern établi du projet (6 autres usages). Blocs 3 et 6 verts après correctif.
2. **Régression de contrat (file-traitement Phase B)** : `InscriptionPerformanceFiabiliteTest:132` attendait l'ancien 25 en dur ; réaligné sur le contrat documenté (défaut 12 via `PreferencesService`, justifié en commentaire). Non couverte par les batteries ciblées de la phase F — seul le rejeu complet l'a détectée.
3. **Fixtures `EnseignantStatutCoherenceTest`** (seule suite couvrant la synchro statut⇄actif de la phase B Enseignants) : `actif` explicite à la création (défauts DB non appliqués aux instances en mémoire — convention `EnseignantFiabiliteTest`) → 7/7 verts.

Cumul vérifié par exécution : suite complète + batteries ciblées (registre 37, voisins 36+53) + HTTP réel du registre (index/sans-groupe/show 200, pagination par préférence, section « Doublons potentiels » scopée rendue) + Pint vert sur les 4 fichiers touchés (`MonEmploiEnseignantController`, `InscriptionPerformanceFiabiliteTest`, `EnseignantStatutCoherenceTest`, journal). Les chiffres §7 (fiche 39 req, index 11 req) re-mesurés conformes. Flake connu `TesseractEngineTest::test_pipeline_complet_reel_ocr_vers_prefill` (OCR réel) vert au rejeu.

Décisions humaines toujours en attente : attribution dossiers→agents, priorités, rappels (Phase 3).
