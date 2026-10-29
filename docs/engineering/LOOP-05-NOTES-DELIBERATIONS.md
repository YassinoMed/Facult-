# LOT 05 — CARNET DE NOTES ET DÉLIBÉRATION CONTRÔLÉE

**Date :** 24/10/2026 · **Mode :** extension minimale d'un noyau EXISTANT_VALIDÉ (audit LOOP-00 §F5)
**Verdict : PASS** (aucune réserve bloquante ; 1 réserve opéretionnelle documentée §7).

---

## 1. Objectif et périmètre

Amener la chaîne notes/délibérations aux critères d'acceptation du programme :
- notes invalides / affectations non autorisées rejetées ✅ (existant, confirmé par tests de ce lot)
- **import prévisualisé avant application** ✅ (existant confirmé — lecture obligatoire, aucune écriture)
- **corrections historisées + motif** ✅ (existant confirmé)
- **dépends d'audit réellement fonctionnelles** 🔧 (lacune CORRIGÉE dans ce lot : see §3)
- publication sous contrôles + quorum + anomalies bloquantes ✅ (existant, suite DeliberationPhase)
- **concurrence sans perte silencieuse** ✅ (lockForUpdate + statuts/FSM, suite Sécurité)

Lacunes identifiées par l'audit directeur (LOOP-00) et structurées par l'exploration du domaine :
1. **`valide_at` nul nul part** : la colonne n'existait PAS (ni modèle, ni migration, ni écrivain) → le drapeau d'audit `a_ete_modifiee_apres_validation` était systématiquement FAUX (fonctionnalité morte).
2. **Détection par match de chaîne** ('correction'/'modification' dans le motif) : les motifs réellement générés sont « Saisie (web/import) », « Saisie anonyme NNN », « Verrouillage : », « Demande de correction : », « Correction autorisée : » → **faux négatifs réels** pour les re-saisies; et aucune comparaison temporelle.
3. Politique autorisation `RegleEvaluationController::index` : **couvert par route** (`can:evaluations.view`, L63-65) — PAS un trou de sécurité (décision : aucun changement).
4. Diff POST (`notes/{note}/comparer`) : op lecture seule, CSRF + throttle — accepté tel quel (décision documentée).

## 2. État Git

- **Initial (début LOT 05) :** HEAD `38ef149` · 671 entrées non-committées · branche `feature/admin-modifier-etudiant`.
- **Final :** HEAD `38ef149` **inchangé** (aucun commit — règle du mandat) · 675 entrées (déales : mes 2 fichiers suivis modifiés + 3 fichiers non-suivis créés/persistés, ci-dessous).

## 3. Fichiers modifiés (strictement nécessaires)

| Fichier | Statut git | Nature |
|---|---|---|
| `database/migrations/2026_10_24_100000_ajouter_valide_at_aux_notes_evaluation.php` | `??` (nouveau) | Migration **addictive** nullable `evaluation_notes.valide_at` + `down()` dropColumn (re-routage documenté dans le fichier) |
| `app/Modules/Evaluation/Models/NoteEpreuve.php` | `M` (suivi) | fillable + cast `valide_at` datetime ; helper statique `existeColonneValideAt()` (mémoïsé par worker) |
| `app/Modules/Evaluation/Services/SaisieNoteService.php` | `M` (suivi) | `validerNoteVerrouillee` : horodates `valide_at = now()` **sous garde fail-soft** (colonne présente) |
| `app/Modules/Evaluation/Services/EvaluationAuditService.php` | `??` (flux antérieur, modifié) | Remplacement de la détection par chaîne par **comparaison temporelle stricte** (`ancienne_valeur` non-nulle ET `created_at > valide_at`), garde fail-soft |
| `tests/Feature/EvaluationAuditTest.php` | `??` (flux antérieur, étendu) | 3 nouveaux tests (+ helper inscription S1 dans `creerEtudiant` requis par valider via ArchiveService) |

**Règle fail-soft de securing de migration** : tant que la migration additive n'est pas appliquée dans un environnement (ne pas lancer de migration sur une base `APP_ENV=production`), la validation n'écrit PAS `valide_at` et le drapeau reste faux — **aucune erreur SQL possible**. Comportement complet une fois la migration appliquée (vérifié par les tests, qui migrerent leur base de test RefreshDatabase).

## 4. Architecture réutilisée (zéro duplication)

- FSM `NoteEpreuve::TRANSITIONS` + F-01 (validated modifié ⇒ repasse `entered`) — inchangés.
- `SaisieNoteService::valider/validerNoteVerrouillee` (le seul écrivaine `statut='validated'`, confirmé par grep) — un seul point d'insertion d'horodatage.
- Délibérations : `DeliberationService` (quorum, anomalies bloquantes, scrollages) — **aucune modification**.
- Autoring/import : `NoteEpreuveController` récueil :
  - `importMulti` (preview + token `EvaluationChanged`) → `importMultiConfirmer` (token obligatoire, verrou posé entre-temps ⇒ refus propre, rien d'écrit ; volumineux ⇒ filtrage) — **existant confirmé, non touché**.
- Permissions :Routes epreuves/notes/délibérations déjà couvertes `can:notes_eval.*|deliberations.*|evaluations.*` + Policies `EvaluationPolicy/NotePolicy` — **aucune nouvelle permission**.

## 5. Commandes réellement exécutées et résultats

| Commande | Résultat |
|---|---|
| `php artisan migrate` | **REFUSÉ volontairement** — `APP_ENV=production` (contrainte B7 du contrat obligatorio : ne jamais lancer de migration sur une base étiquetée production). La migration sera appliquée par les déplicoitures habituels du projet. |
| `vendor/bin/pint --dirty` | **PASSED** |
| `php artisan test --compact --filter="EvaluationAuditTest|NotesEvaluationsSecuriteTest|DeliberationPhase6Test|DeliberationPhase14Test|CheckConstraintsNotesTest"` | **56/56 PASSED · 191 assertions · 7,7 s** |
| `php artisan test --compact --filter="EvaluationTest|ExamensFeatureCompleteTest|ExamensInvariantsValidationTest|ExamensInvariantsSallesTest|FaculteScopeEcritureTest|NotesImportMultiTest|EvaluationCompostageTest|CompostageAnonymeTest"` | **146/146 PASSED · 480 assertions · 9,9 s** |
| Lecture des traces `[PERF-IMPORT]` (suite NotesImportMultiTest) | volume 100 : 0,07 s · volume 1000 : **0,74 s** (FILE+JOB) — aucune régression ; coût ajouté du lot = un contrôle Schema mémoïsé (O(1) par worker) |

- Tests échoués : aucun. Tests ignorés/quarantaine : aucun identifié. Baseline FIXE du cycle antérieur (EXAMENS-04) 78/78 parvenu à re-éval活的 **146/146** (couverture élargie).

## 6. Sécurité, UX, performance

- **Sécurité** : couverture route/Policies identique pré/midpost-fix ; **aucune nouvelle surface HTTP** ; isolation faculté régressée `FaculteScopeEcritureTest` PASSED + test d'isolement du service d'audit (DomainException « Accès refusé »).
- **UX** : pas d'impact UI (le drapeau n'est pas rendu aujourd'hui — liste de contrôle UX non modifiée).
- **Performance** : mesures implicites de la suite (import 1000 planches 0,74 s) inchangées ; ajout des équilibres équilibré O(1) mémoïsé.

## 7. Risques résiduels

- **Réserve 1 (op et deploiement) :** la migration `2026_10_24_100000_*` doit être appliquée dans l'environnement où le module evaluation est actif ; sans elle, l'ancien comportement fail-soft est garanti (drapeau statique faux) mais l'horodatage est absent. La stratégie de retour arrière (`migrate:rollback --step=1` ⇒ dropColumn) ne doit être exécutée QUE sur l'environnement dev dédié.
- Dette non traitée (hors périmètre strict) : pagination mémoire des anomalies (reportée SAY §F-a2), diff GET des historiques (préférence UX mineure).

## 8. Verdict et conditions du lot suivant

**PASS.** Conditions de poursuite :
- LOT 02 (notifications — next auto-pass) n'a aucune dépendance bloquante vers ce lot.
- La réserve 7 doit être clôturée à la mise en migration de l'environnement dev/stage par l'équipe (instructions déjà dans le fichier de migration).
