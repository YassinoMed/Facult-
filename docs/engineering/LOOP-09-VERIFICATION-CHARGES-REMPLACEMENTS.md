# LOOP-09 — VÉRIFICATION INDÉPENDANTE DES CHARGES D'ENSEIGNEMENT ET DES REMPLACEMENTS

**Date d'exécution :** 26/10/2026
**Périmètre :** candidatures de remplacement pédagogique (dépôt, retrait, acceptation, écart), réutilisation du remplacement EDT transactionnel (`ScheduleRepairService`), priorisation des suggestions, pont EDT → charge (`chargeAvecEdt`), et **périmètre facultaire des endpoints back-office**.
**Mode :** audit statique + tests ciblés et négatifs sur SQLite `:memory:` ; aucune migration appliquée sur un environnement réel, aucun commit, aucun push.
**Verdict :** `PASS_WITH_RESERVATIONS`

> Ce rapport est **distinct** du rapport préexistant
> `docs/engineering/LOOP-09-CHARGES-REMPLACEMENTS.md` (verdict `PASS`,
> 7/7 puis 65/65). Ce dernier a été lu et **non modifié**. La présente
> vérification repart du code réel, rejoue ses preuves, puis ajoute un test
> négatif qui n'existait pas : le périmètre facultaire des nouveaux
> endpoints administratifs. Un défaut de sécurité horizontal a été prouvé,
> corrigé et rejoué.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture du rapport préexistant et de l'audit LOOP-00 §F9 ;
  inspection de `CandidatureRemplacementService`, `RemplacementCandidature`,
  `RemplacementCandidatureController`, `ScheduleRepairService`,
  `ServiceEnseignantService`, des routes et de la matrice de permissions ;
  rejeu des 5 suites canoniques ; écriture d'un test négatif de périmètre
  facultaire ; exécution avant/après correctif ; non-régression.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35,
  SQLite `:memory:` via `phpunit.xml`, `CACHE_STORE=array`,
  `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
- **Limites :** MySQL non exécuté ; migration non appliquée en local
  (`APP_ENV=production`) ; aucun test navigateur ; aucun test de charge ;
  la suite complète de l'application n'est pas revendiquée verte.
- **Point de modèle :** `teachers` n'a **pas** de colonne `faculte_id` ; le
  rattachement passe par `departement_id` → `departements.faculte_id`
  (`Teacher::faculteId()`). `matieres` n'a pas non plus de `faculte_id` ;
  la faculté d'une matière passe par `ue.programme.faculte_id`. Le contrôle
  de périmètre des candidatures est donc fondé sur le département de
  l'enseignant, pas sur la matière.

---

## 1. État initial et frontière de flux

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` (inchangé) |
| Commit / push pendant le lot | aucun |
| Migration | `2026_10_25_150000_lot09_candidatures_remplacement.php` (additive, préexistante, **non appliquée en local**) |
| Suite du lot | `tests/Feature/RemplacementCandidatureTest.php` |
| Rapport préexistant | `LOOP-09-CHARGES-REMPLACEMENTS.md`, lu, non modifié |

Le lot ne revendique que :

- `app/Modules/Schedule/Controllers/RemplacementCandidatureController.php`
  (périmètre facultaire) ;
- `tests/Feature/RemplacementCandidatureTest.php` (test négatif et cas
  positif) ;
- le présent rapport.

Le service, le modèle, la migration et les routes préexistent au lot et ne
sont pas modifiés par le correctif de périmètre.

---

## 2. Rejeu du rapport préexistant

Avant d'ajouter le test négatif, les 5 suites canoniques du rapport
préexistant ont été rejouées :

| Suite | Résultat |
|---|---|
| `RemplacementCandidatureTest` (7 tests initiaux) | **7/7 PASS, 39 assertions** |
| `SchedulePhase8ReparationRemplacementsTest` | PASS |
| `EnseignementPhase4Test` | PASS |
| `AffectationEnseignantExamenTest` | PASS |
| `SurveillanceEquilibrageTest` | PASS |
| **Total des 4 suites voisines** | **58/58 PASS, 257 assertions** |
| **Total canonique initial** | **65/65 PASS, 296 assertions** |

Les chiffres du rapport préexistant sont donc confirmés. Ils ne couvraient
toutefois pas le périmètre facultaire des **nouveaux** endpoints
`admin.remplacement-candidatures.*`.

---

## 3. Défaut prouvé et corrigé — D9/1

### 3.1 Constat

La matrice de permissions donne au rôle `admin_faculte` les permissions
`schedules.view` et `schedules.manage`. La convention du projet est que ce
rôle est restreint à **sa** faculté via `User::faculteRestreinteId()` et
`User::peutAccederFaculte()` — convention déjà appliquée par
`ScheduleController` (`abort_unless(..., 404)`).

Or, avant correctif :

1. `RemplacementCandidatureController::index()` retournait **toutes** les
   candidatures, sans filtre facultaire : un `admin_faculte` pouvait lire
   les noms d'enseignants, matières, messages et décisions d'une autre
   faculté.
2. `accepter()` ne vérifiait ni la faculté de l'enseignant candidat, ni
   celle du créneau ciblé : un `admin_faculte` pouvait appliquer un
   remplacement sur l'EDT d'une autre faculté.
3. `ecarter()` ne vérifiait pas la faculté de l'enseignant : un
   `admin_faculte` pouvait écarter une candidature d'une autre faculté.

**Impact.** Escalade de privilèges horizontale (IDOR/BOLA) sur un registre
de ressources humaines et sur l'emploi du temps : lecture inter-facultés et
mutation inter-facultés avec une permission pourtant limitée à la faculté de
rattachement.

### 3.2 Preuve avant correctif

Le test négatif
`test_admin_faculte_ne_voit_ni_ne_mute_les_candidatures_d_une_autre_faculte`
a été écrit puis exécuté avant le correctif. Il a échoué à l'index :

```text
Found unexpected JSON fragment: [{"id":1}]
within [[{"code":"MAT_R09C_...","enseignant":"Leila VolontaireAutre",
"id":1,"matiere":"Littérature LOT09","statut":"soumise"}]]
```

La candidature de l'autre faculté était donc bien exposée à un
`admin_faculte` restreint. Le défaut est prouvé par exécution.

**Renforcement du test.** La première version du test créait l'enseignant
de l'autre faculté **sans département** : l'exclusion aurait alors pu
provenir de l'absence de département plutôt que d'une faculté différente.
La version finale crée un `Departement` rattaché à l'autre faculté et y
affecte le titulaire et le volontaire. Le négatif porte donc réellement sur
une **autre faculté**, et le cas positif porte sur la faculté de l'admin.
C'est ce test renforcé qui est conservé.

### 3.3 Correctif appliqué

`RemplacementCandidatureController` applique désormais la convention
existante :

- **`index()`** : chargement de `teacher.departement` ; si
  `faculteRestreinteId()` n'est pas nul, `whereHas('teacher.departement',
  faculte_id = faculté restreinte)`. Les admins globaux ne sont pas filtrés.
- **`accepter()`** : chargement du créneau avec `schedule.groupe`, puis
  `verifierPerimetre($request, $candidature, $slot)`. Le contrôle vérifie
  **à la fois** la faculté de l'enseignant candidat et celle du groupe du
  créneau.
- **`ecarter()`** : `verifierPerimetre($request, $candidature)` vérifie la
  faculté de l'enseignant candidat.
- **`verifierPerimetre()`** : retour immédiat pour un admin global
  (`faculteRestreinteId() === null`) ; sinon `abort_unless(..., 404)` si la
  faculté ne correspond pas. Le contrôle est **fail-closed** : enseignant
  absent, département absent ou groupe absent ⇒ 404 pour un admin restreint.

Le correctif ne duplique aucune garde métier : `ScheduleRepairService::
remplacerEnseignant()` continue de vérifier activité, qualification,
disponibilité, chevauchement et historisation.

---

## 4. Contrôles confirmés par exécution

| Contrôle | Preuve | Résultat |
|---|---|---|
| Dépôt : qualification exigée, doublon actif refusé | `test_depot_exige_qualification_et_interdit_le_doublon` | PASS |
| Retrait réservé au propriétaire (IDOR enseignant) | `test_retrait_proprietaire_uniquement` | PASS |
| Acceptation = remplacement EDT réel + `ScheduleChange` historisé | `test_acceptation_reutilise_le_remplacement_edt_et_historise` | PASS |
| Acceptation refusée si matière divergente ou remplaçant indisponible/inactif, candidature préservée | `test_acceptation_refusee_si_matiere_ou_disponibilite_incompatibles` | PASS |
| Les candidats déclarés sont priorisés dans les suggestions | `test_suggestions_prioritisent_les_candidats_declares` | PASS |
| `chargeAvecEdt` expose le planifié sans fusionner avec le contractuel | `test_charge_avec_edt_expose_le_planifie_sans_fusionner` | PASS |
| Portail enseignant + admin : 403 sans `schedules.manage` | `test_portail_et_admin_http_avec_guards` | PASS |
| **Index filtré par faculté** | test négatif renforcé | PASS — candidature de l'autre faculté absente |
| **Acceptation inter-facultés refusée** | test négatif renforcé | PASS — 404, statut `soumise`, EDT inchangé |
| **Écart inter-facultés refusé** | test négatif renforcé | PASS — 404, statut `soumise` |
| **Cas positif dans sa faculté** | test négatif renforcé | PASS — candidature visible, acceptation appliquée |

---

## 5. Critères d'acceptation

| Critère | Statut | Preuve |
|---|---|---|
| C1 — Un `admin_faculte` ne lit que les candidatures de sa faculté | PASS | index filtré + test négatif |
| C2 — Un `admin_faculte` n'accepte que des créneaux de sa faculté | PASS | `verifierPerimetre()` + 404 |
| C3 — Un `admin_faculte` n'écarte que des candidatures de sa faculté | PASS | `verifierPerimetre()` + 404 |
| C4 — Un admin global conserve l'accès complet | PASS | retour immédiat si `faculteRestreinteId()` nul ; suite existante |
| C5 — Le remplacement EDT conserve ses gardes et son historisation | PASS | service réutilisé tel quel ; test d'acceptation |
| C6 — Les 7 contrôles préexistants ne régressent pas | PASS | 8/8, 49 assertions |
| C7 — La suite canonique reste verte | PASS | 66/66, 306 assertions |
| C8 — Aucune migration, route ou permission nouvelle | PASS | aucun fichier de ce type modifié |

---

## 6. Modifications appliquées

| Fichier | Nature |
|---|---|
| `app/Modules/Schedule/Controllers/RemplacementCandidatureController.php` | filtre `index`, `verifierPerimetre()` sur `accepter`/`ecarter`, chargement `schedule.groupe` |
| `tests/Feature/RemplacementCandidatureTest.php` | 8e test : cas positif + négatifs index/acceptation/écart, avec département réel de l'autre faculté |
| `docs/engineering/LOOP-09-VERIFICATION-CHARGES-REMPLACEMENTS.md` | présent rapport |

Aucune modification de `CandidatureRemplacementService`, de
`RemplacementCandidature`, de `ScheduleRepairService`, de la migration ou
des routes. Aucune permission nouvelle.

---

## 7. Preuves d'exécution

### 7.1 Test négatif renforcé

```bash
php artisan test --compact tests/Feature/RemplacementCandidatureTest.php \
  --filter=test_admin_faculte_ne_voit_ni_ne_mute_les_candidatures_d_une_autre_faculte
```

**Avant correctif (version initiale) : 1 échec — la candidature de l'autre
faculté était exposée par l'index.**
**Après correctif (version renforcée) : 1/1 PASS, 10 assertions, 1 231 ms.**

### 7.2 Suite du lot

```bash
php artisan test --compact tests/Feature/RemplacementCandidatureTest.php
```

**Résultat : 8/8 PASS, 49 assertions, 1 023 ms.**

### 7.3 Suite canonique (5 suites)

```bash
php artisan test --compact \
  tests/Feature/RemplacementCandidatureTest.php \
  tests/Feature/SchedulePhase8ReparationRemplacementsTest.php \
  tests/Feature/EnseignementPhase4Test.php \
  tests/Feature/AffectationEnseignantExamenTest.php \
  tests/Feature/SurveillanceEquilibrageTest.php
```

**Résultat : 66/66 PASS, 306 assertions** (8 + 58 tests ; 49 + 257
assertions).

### 7.4 Qualité de code

```bash
php -l app/Modules/Schedule/Controllers/RemplacementCandidatureController.php
vendor/bin/pint --test app/Modules/Schedule/Controllers/RemplacementCandidatureController.php \
  tests/Feature/RemplacementCandidatureTest.php
```

**Résultat : aucune erreur de syntaxe ; Pint PASSED.**

---

## 8. Non-régression et hygiène

- Aucun commit, push, tag ni déploiement.
- Aucune migration appliquée, aucun `migrate:fresh`, `db:wipe` ou
  suppression de données.
- Les tests utilisent SQLite `:memory:` et des données synthétiques ; aucune
  donnée réelle, aucun secret, aucun jeton, aucune PII réelle.
- Le rapport préexistant `LOOP-09-CHARGES-REMPLACEMENTS.md` n'a pas été
  modifié.
- Les 7 tests préexistants restent verts ; le 8e test est additif.

---

## 9. Risques résiduels et réserves

- **R-09/5 — Migration non appliquée en local.** La migration additive
  `2026_10_25_150000_lot09_candidatures_remplacement.php` n'a pas été
  exécutée sur l'environnement local (`APP_ENV=production`). Les tests
  prouvent le code sur SQLite `:memory:`, pas l'état de la base déployée.
  Le déploiement doit appliquer la migration et vérifier la table.
  **Condition d'application, non bloquante pour le code.**
- **R-09/6 — Enseignants sans département invisibles.** Le filtre
  `whereHas('teacher.departement', ...)` exclut les enseignants dont le
  département est nul. C'est un comportement **fail-closed** souhaitable
  pour la sécurité, mais il peut masquer des candidatures légitimes tant que
  les rattachements ne sont pas complets. Un contrôle de complétude des
  départements est recommandé. **Non bloquant.**
- **R-09/7 — Énumération 422/404 sur `accepter`.** La validation
  `exists:schedule_slots,id` s'exécute avant `verifierPerimetre()` : un
  admin restreint peut distinguer un ID de créneau inexistant (422 pour un
  client JSON, 302 avec erreurs pour un formulaire web) d'un ID existant
  hors périmètre (404). Aucune donnée métier n'est exposée, mais une
  anti-énumération stricte exigerait de résoudre le créneau dans le
  périmètre avant la validation. **Faible gravité, non bloquant.**
- **R-09/8 — Périmètre calculé sur le département actuel.** La visibilité
  d'une candidature dépend du département **actuel** de l'enseignant, pas
  d'un instantané pris à la candidature. Un changement de département
  déplace donc la visibilité historique. À décider si un snapshot
  `faculte_id`/`departement_id` est requis pour l'audit. **Non bloquant.**
- **R-09/9 — Réserves héritées du rapport préexistant.** Pas d'UI de
  candidature (R-09/1), pas de contrôle automatique de charge à
  l'acceptation (R-09/2), candidature par matière et non par créneau
  (R-09/3), `charge()`/`seuils()` encore contractuels (R-09/4). **Non
  bloquantes, extensions métier à décider.**
- **R-09/10 — Pas de test MySQL.** Le comportement des contraintes et des
  transactions sur MySQL n'est pas exercé ici. **Limite de vérification.**

---

## 10. Verdict

**`PASS_WITH_RESERVATIONS`.** Les deux manques initiaux de l'audit §F9
restent livrés et les 65 tests préexistants sont confirmés. La vérification
indépendante a de plus prouvé un défaut horizontal non couvert : un
`admin_faculte` pouvait lire et muter des candidatures d'une autre faculté.
Le correctif minimal applique la convention `faculteRestreinteId()` déjà
utilisée par le module EDT, avec un test positif et trois négatifs
(index, acceptation, écart). Les 8/8 tests du lot et les 66/66 tests de la
suite canonique passent. Les réserves portent sur la migration non appliquée
en local, les enseignants sans département, l'anti-énumération 422/404 et
les extensions métier héritées ; elles ne bloquent pas le correctif de
sécurité.
