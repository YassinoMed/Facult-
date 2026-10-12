# Rapport final — Registre Étudiants `/admin/etudiants` (LOOP 0–6)

- Date : 2026-10-11. Journal : `docs/inception/analysis/registre-etudiants-log.md`.
- Pas de Git ; aucune migration ; aucun commit/push/déploiement ; aucune donnée réelle modifiée.
- Navigateur authentifié : **non utilisé** (aucun parcours navigateur prétendu).

## 1. État initial et fichiers inspectés

`Admin/EtudiantController.php` (8 actions), vues `index/sans-groupe/show/affectation-preview`, `ListeEtudiantsService`, `StudentStatusService`, `StudentArchiveService`, `EtudiantPolicy`, `IntegrationLog`, routes `web.php:503-525`, tests BackOffice/StudentDossier/InscriptionEtudiants/Productivite. Baseline : 27/27 verts.

## 2. Anomalies confirmées (criticité)

1. **Haute — fuite PII à l'export** : `exportDossier` incluait `synthese.etudiant` (modèle complet : CIN, téléphone, date de naissance, adresse) malgré le commentaire « Sensibles exclus ». Prouvé par test (contenu JSON asserté).
2. **Moyenne — concurrence `changer()`** : double validation concurrente = 2 transitions + 2 notifications (pas de verrou) ; `updateStatut` sans throttle (double-clic).
3. **Moyenne — bulk** : IDs dupliqués traités 2× (compteur et historiques).
4. **Basse** : `paginate(25)` en dur (sans-groupe) vs préférence index ; `loadMissing` redondant (`:175` ⊂ `:165`) ; `destroy` non tracé.

## 3. Corrections appliquées

- Export : `unset($synthese['etudiant'])` — bloc curé (id/num/nom/prénom/email/faculté/statut) fait foi.
- `changer()` : `lockForUpdate` + re-contrôle sous verrou + no-op sans redispatch (ancien réel propagé aux events/notifs).
- Route `updateStatut` : `throttle:20,1`.
- `bulkStatut` : `array_unique` post-validation.
- `sans-groupe` : `paginate(PreferencesService::lire()->lignes_par_page)`.
- Fiche : suppression du 2e `loadMissing` (no-op démontré : 39 requêtes avant/après).
- `destroy` : trace `IntegrationLog etudiant.supprime` (sans PII) ; ordre fichiers-puis-DB conservé + `purger-orphelins` en compensation documentée.

## 4. Fichiers modifiés (justification)

- `EtudiantController.php` : pagination, export, bulk dedup, trace destroy (minimal, inline).
- `StudentStatusService.php` : verrou + ancien réel (cohérence events).
- `routes/web.php` : 1 throttle (cohérence écritures).
- `EtudiantsRegistreTest.php` : 18 tests (créé).
- Docs : journal + ce rapport.

## 5. Tests exécutés (résultats réels)

- Nouveaux : **18/18, 91 assertions** (pagination ×4, fiche ×1, sécu ×10, idempotence/throttle/trace ×3).
- Voisins : BackOffice 9, StudentDossier 6, InscriptionEtudiants 12, Productivite 10, ExperienceInscriptionLoop 9, VerificationBoucle 11, Phase8 6, ArchiveAuthorization 32, SeparationProfils 17.
- **Total : 130/130, 0 échec.** Pint vert. `route:list` : routes intactes.

## 6. Sécurité / IDOR

Invité redirigé (5 routes), consultation lecture OK/écritures 403 sans trace, cross-faculté show/update/export/destroy 403 sans mutation ni fuite, 404 fiche inexistante, bulk mixte/doublons/partiel-invalidé, mot de passe haché + flash unique + audit sans PII, export sans CIN/tél/DOB + audité, destroy fichiers+DB vérifié sur disque faked, throttle 429 prouvé au 21e appel.

## 7. Performances

Fiche : 39 requêtes avant/après (borne test < 60), relations verrouillées chargées, 0 N+1 nouveau. Liste : moteur central + eager inchangés. Mesures SQLite indicatives, pas de chiffres prod.

## 8. Risques résiduels et limites

- Échec stockage pendant `destroy` non testé (injection non réalisée) : fichiers d'abord (pas d'orpheline PII), DB rollback, orphelins inverses repris par `purger-orphelins` — documenté, non prouvé.
- Concurrence réelle MySQL non rejouée (verrou prouvé par code + idempotence séquentielle).
- Pas de navigateur authentifié ; pas de mesures MySQL prod.

## 9. Validation humaine requise

Aucune décision bloquante. À valider visuellement : fiche 360° inchangée, page sans-groupe paginée selon préférence.

## 10. Verdict final : **validé**

Toutes les conditions sont remplies : pagination cohérente, redondance traitée, autorisations intactes voire renforcées, 130/130 tests verts, modifications préexistantes inexistantes (pas de Git — aucun écrasement possible), rapport fidèle aux observations.
