# LOOP-05 — VÉRIFICATION INDÉPENDANTE DU CARNET DE NOTES ET DES DÉLIBÉRATIONS

**Date d'exécution :** 26/10/2026
**Périmètre :** auditabilité des notes (`valide_at`, drapeau `a_ete_modifiee_apres_validation`), rejet des notes hors bornes, convocation obligatoire, verrouillage, isolation facultaire de l'historique.
**Mode :** audit statique + tests ciblés et négatifs sur SQLite `:memory:` ; aucune migration exécutée sur un environnement réel, aucun commit, aucun push.
**Verdict :** `PASS_WITH_RESERVATIONS`

> Ce rapport est **distinct** du rapport préexistant
> `docs/engineering/LOOP-05-NOTES-DELIBERATIONS.md` (verdict `PASS`, réserve
> opérationnelle sur la migration). Il a été lu et **non modifié**. Le
> correctif `valide_at` était déjà présent dans l'arbre non commité au moment
> de la vérification ; ce rapport l'exécute et le prouve, il ne le
> réattribue pas à un nouveau lot.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture du rapport préexistant et de l'audit LOOP-00 §F5 ; inspection de la migration `2026_10_24_100000_ajouter_valide_at_aux_notes_evaluation.php`, de `NoteEpreuve`, de `SaisieNoteService` et de `EvaluationAuditService` ; écriture d'une suite indépendante ; exécution ; non-régression par les suites notes/délibérations.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35, SQLite `:memory:` via `phpunit.xml`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
- **Limites :** MySQL non exécuté ; aucun test de charge ; aucune vérification de l'application de la migration sur un environnement dev/stage/production ; la suite complète n'est pas revendiquée verte.
- **Distinction :** un test vert sur `:memory:` prouve le code, pas l'état de migration d'un environnement déployé. Ce point reste une réserve explicite.

---

## 1. État initial et frontière de flux

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` (inchangé) |
| Commit / push pendant le lot | aucun |
| Migration | `2026_10_24_100000_ajouter_valide_at_aux_notes_evaluation.php` (additive, non suivie) |
| Suite indépendante | `tests/Feature/Loop05NotesDeliberationsVerificationTest.php` (non suivie) |
| Rapport préexistant | `LOOP-05-NOTES-DELIBERATIONS.md`, lu, non modifié |

Le lot ne revendique aucune modification de code supplémentaire : la vérification a conclu que le correctif présent dans l'arbre satisfaisait les critères. Les fichiers touchés par le flux amont sont :

- `database/migrations/2026_10_24_100000_ajouter_valide_at_aux_notes_evaluation.php` ;
- `app/Modules/Evaluation/Models/NoteEpreuve.php` ;
- `app/Modules/Evaluation/Services/SaisieNoteService.php` ;
- `app/Modules/Evaluation/Services/EvaluationAuditService.php`.

---

## 2. Défaut d'origine et correctif vérifié

### D5/1 — `valide_at` absent : le drapeau d'audit était structurellement faux

**Constat.** La colonne `valide_at` n'existait pas. `EvaluationAuditService` ne pouvait donc jamais comparer la dernière validation à une modification postérieure : `a_ete_modifiee_apres_validation` était toujours `false`, y compris après une re-saisie réelle. Le contrôle d'audit était une fonctionnalité morte.

**Correctif présent dans l'arbre.**

1. Migration **strictement additive** : `dateTime('valide_at')->nullable()->after('valide_par')`, avec `down()` en `dropColumn`. Aucune donnée rétrodatée.
2. `NoteEpreuve` : `valide_at` dans `$fillable`, cast `datetime`, helper mémoïsé `existeColonneValideAt()` qui interroge `Schema::hasColumn`.
3. `SaisieNoteService::valider()` : écrit `valide_at = now()` **si la colonne existe** (fail-soft si la migration n'est pas appliquée).
4. `EvaluationAuditService::historiqueNote()` : compare la dernière modification à `valide_at` de façon temporelle, sans match de chaîne sur le motif.

**Preuve négative conservée.** Le test `test_validation_horodatee_et_modification_posterieure_signalee` utilise le motif réel `Saisie (import)`, qui ne contient ni « correction » ni « modification » : l'ancien match de chaîne aurait laissé le drapeau à `false`. Le test prouve que le drapeau passe à `true` après la re-saisie. Le test `test_revalidation_efface_le_drapeau_temporal` prouve qu'une validation ultérieure remet le drapeau à `false`.

---

## 3. Contrôles confirmés par exécution

| Contrôle | Preuve | Résultat |
|---|---|---|
| La validation horodate la note | `test_validation_horodatee_et_modification_posterieure_signalee` | PASS — `valide_at` nul avant, non nul après |
| Une modification postérieure est signalée, même avec un motif sans mot-clé | même test, motif `Saisie (import)` | PASS — `a_ete_modifiee_apres_validation = true` |
| Une revalidation efface le drapeau | `test_revalidation_efface_le_drapeau_temporal` | PASS |
| Une note hors bornes est rejetée sans écriture | `test_note_hors_bornes_est_rejetee_sans_ecriture` | PASS — 21/20 rejeté, aucune ligne créée |
| Un étudiant non convoqué est rejeté | `test_etudiant_non_convoque_est_rejete` | PASS — aucune ligne créée |
| Une note verrouillée ne peut plus être validée | `test_note_verrouillee_ne_peut_plus_etre_validee` | PASS — `DomainException` « Note verrouillée. » |
| L'historique est isolé par faculté | `test_isolation_faculte_de_l_historique` | PASS — `DomainException` « Accès refusé » |

---

## 4. Critères d'acceptation

| Critère | Statut | Preuve |
|---|---|---|
| C1 — Toute validation produit une borne temporelle exploitable | PASS | `valide_at` non nul après `valider()` |
| C2 — Une modification postérieure est détectée sans dépendre du libellé | PASS | motif `Saisie (import)` + comparaison temporelle |
| C3 — Une revalidation légitime remet le drapeau à faux | PASS | test dédié |
| C4 — Les notes invalides ou non convoquées sont rejetées avant écriture | PASS | deux tests négatifs |
| C5 — Le verrouillage est opposable | PASS | `DomainException` |
| C6 — L'historique ne fuit pas hors faculté | PASS | `DomainException` |
| C7 — Le correctif est rétrocompatible si la migration n'est pas appliquée | PASS (code) | `existeColonneValideAt()` + `Schema::hasColumn` |

---

## 5. Preuves d'exécution

### 5.1 Suite de vérification indépendante

```bash
php artisan test --compact tests/Feature/Loop05NotesDeliberationsVerificationTest.php
```

**Résultat : 6/6 PASS, 18 assertions, 1 606 ms.**

### 5.2 Non-régression notes / délibérations

```bash
php artisan test --compact --filter="DeliberationImportAssociationTest|DeliberationPhase14Test|DeliberationPhase6Test|EnseignantNotesTest|EvaluationAuditTest|NotesEvaluationsAmeliorationsTest|NotesEvaluationsSecuriteTest|NotesImportAssociationTest|NotesImportMultiTest|CompostageAnonymeTest|SaisieIdentificationStricteTest|SaisiesFormatsTest|Loop05NotesDeliberationsVerificationTest"
```

**Résultat : 155/155 PASS, 654 assertions, 12 921 ms.**

La sortie contient les mesures d'import existantes (`volume=1000`, `~0,71 s`) ; aucune régression de performance n'est observée sur ce périmètre.

### 5.3 Qualité de code

```bash
vendor/bin/pint --test app/Modules/Evaluation/Services/SaisieNoteService.php \
  app/Modules/Evaluation/Services/EvaluationAuditService.php \
  app/Modules/Evaluation/Models/NoteEpreuve.php
```

**Résultat : Pint PASSED.**

---

## 6. Non-régression et hygiène

- Aucun commit, push, tag ni déploiement.
- Aucune migration exécutée pendant la vérification ; aucune donnée réelle modifiée.
- Aucun `migrate:fresh`, `db:wipe`, `DROP` ou suppression.
- Aucun secret, jeton, mot de passe ou PII réelle dans la suite ou ce rapport.
- Le rapport préexistant LOOP-05 n'a pas été écrasé.

---

## 7. Risques résiduels et réserves

- **R-05/1 (opérationnelle, bloquante pour la valeur en production)** — La migration `2026_10_24_100000_*` doit être appliquée sur chaque environnement où le module Évaluation est actif. Sans elle, le code reste fail-soft : `valide_at` est absent et le drapeau reste `false`. Le test `:memory:` ne prouve pas l'état de migration d'un environnement déployé. **Condition de mise en service, pas défaut de code.**
- **R-05/2** — Aucun rétro-remplissage : les notes antérieures à la migration conservent `valide_at = null` et le drapeau `false` jusqu'à une revalidation explicite. C'est le comportement voulu (pas de date inventée), mais il doit être expliqué aux auditeurs.
- **R-05/3** — Le drapeau n'est pas encore affiché dans l'UI ; il est exposé par l'historique d'audit. Dette UX mineure.
- **R-05/4** — Pagination mémoire des anomalies et diff GET des historiques restent des dettes documentées, hors périmètre.
- **R-05/5** — MySQL non exécuté ; le type `dateTime` et la migration additive sont standards, mais la vérification reste SQLite.
- La stratégie de retour arrière (`migrate:rollback --step=1` → `dropColumn`) ne doit être exécutée que sur un environnement dev dédié, jamais en production sans sauvegarde.

---

## 8. Verdict

**`PASS_WITH_RESERVATIONS`.**

Le défaut structurel qui rendait l'audit des notes inopérant est corrigé à la racine et prouvé par des tests temporels négatifs. Les contrôles de rejet, de verrouillage et d'isolation facultaire sont confirmés. La seule réserve réellement bloquante pour l'exploitation est l'application de la migration sur les environnements déployés, qui relève de la mise en service et non du code vérifié ici. Aucun `NOT_EXECUTED` n'est compté comme `PASS`.
