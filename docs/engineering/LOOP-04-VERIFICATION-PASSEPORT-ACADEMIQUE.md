# LOOP-04 — VÉRIFICATION INDÉPENDANTE DU PASSEPORT ACADÉMIQUE

**Date d'exécution :** 26/10/2026
**Périmètre :** simulation de parcours LMD (`SimulationParcoursService`), scénario `validation_matiere`, FSM des dispenses (`AmenagementParcoursService`), isolation facultaire, registre canonique PACA-08.
**Mode :** audit statique + tests ciblés et négatifs sur SQLite `:memory:` ; aucune migration, aucun commit, aucun push, aucune donnée réelle modifiée.
**Verdict :** `PASS_WITH_RESERVATIONS`

> Ce rapport est **distinct** du rapport préexistant
> `docs/engineering/LOOP-04-PASSEPORT-ACADEMIQUE.md` (verdict `PASS`, réserves
> R-04/1…6). Ce dernier a été lu et **non modifié**. La présente vérification
> ne reprend pas ses conclusions : elle repart du code réel, prouve les
> défauts candidats, applique le correctif minimal, puis réexécute.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture du rapport préexistant et de l'audit LOOP-00 §F4 ; inventaire du code (`SimulationParcoursService`, `AmenagementParcoursService`, contrôleurs/routes du module, modèles de simulation) ; écriture d'une suite indépendante ; exécution **avant** correctif pour les deux défauts prouvables ; correctif minimal ; réexécution ; non-régression par les suites voisines.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35, SQLite `:memory:` forcé par `phpunit.xml`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
- **Limites :** MySQL non exécuté ; aucun parcours navigateur/E2E ; aucune vérification en production ; la suite complète n'est pas revendiquée verte (échecs préexistants hors périmètre). Un contrôle lu n'est pas un contrôle exécuté ; tout non-exécuté serait `NOT_EXECUTED`, jamais `PASS`.

---

## 1. État initial et frontière de flux

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` (inchangé) |
| Commit / push pendant le lot | aucun |
| Migration | aucune |
| Rapport préexistant | `LOOP-04-PASSEPORT-ACADEMIQUE.md`, lu, non modifié |
| Suite indépendante | `tests/Feature/Loop04PasseportAcademiqueVerificationTest.php` (non suivie) |

Le lot ne revendique que :

- `app/Modules/ParcoursAcademique/Services/SimulationParcoursService.php` (correctifs R-04/4 et R-04/5) ;
- `docs/registre/registre-canonique.md` (compteur PACA-08) ;
- la suite `Loop04PasseportAcademiqueVerificationTest.php` ;
- le présent rapport.

Les fichiers non suivis `MaSimulationParcoursController.php`, `MaSimulationParcoursRequest.php`, `MaSimulationParcoursTest.php` et `PortailSimulationParcoursTest.php` préexistaient au lot ; ils n'ont pas été modifiés et ne sont pas revendiqués, mais leurs tests sont inclus dans la non-régression.

---

## 2. Contrôles confirmés par exécution

| Contrôle | Preuve | Résultat |
|---|---|---|
| Une simulation de matière recalcule la moyenne sans écrire de note officielle | `test_simulation_matiere_recalcule_sans_ecriture_officielle` | PASS — 18,0 simulé, note réelle conservée à 12,0, nombre de notes inchangé |
| Une hypothèse incomplète ne produit aucune modification | `test_simulation_matiere_hypothese_incomplete_ne_modifie_rien` | PASS — `resultat_final = impossible`, conditions non satisfaites non vides |
| Le repli `matière` est compatible avec un tableau issu de `toArray()` | `test_repli_matiere_id_est_compatible_tableau_et_non_accentue` | PASS — 12, 7 puis 0 |
| La FSM des dispenses refuse une transition non autorisée | `test_fsm_dispense_refuse_les_transitions_non_autorisees` | PASS — `proposee → annulee` refusée en 422, statut inchangé en base |

La suite préexistante `ParcoursAcademiqueTest` a également été rejouée pour vérifier que le correctif ne déplace pas le contrat : **57/57 PASS, 238 assertions**.

---

## 3. Défauts prouvés et corrigés

### D4/1 — Repli `matiere_id` inopérant après `toArray()` (R-04/4)

**Constat.** `SimulationParcoursService::simulerMatiere()` recevait des lignes converties en tableaux. Le repli `$ligne['matiere']?->id` s'appliquait alors à un **tableau** : l'opérateur `?->` émet un avertissement et ne retrouve pas l'identifiant. Conséquence : si `matiere_id` était absent de la ligne, la simulation ne matchait pas la matière et le résultat devenait silencieusement faux, sans exception.

**Correctif.** Extraction d'un helper unique `matiereIdDeLigne()` qui lit d'abord `matiere_id`, puis `data_get($ligne, 'matiere.id')`, puis `0`. Le test de réflexion couvre les trois cas (identifiant direct, relation tableau, relation absente) et un test d'intégration vérifie le recalcul réel.

### D4/2 — Identifiant accentué `$transférables` (R-04/5)

**Constat.** La variable `$transférables` est valide en PHP 8, mais elle constitue une dette de lisibilité et un risque d'incohérence avec les conventions du projet (identifiants non accentués). Aucun défaut fonctionnel n'a été observé.

**Correctif.** Renommage en `$transferables` dans le service, sans changement de comportement. Pint et la suite complète du parcours restent verts.

### D4/3 — Compteur PACA-08 obsolète (R-04/6)

**Constat.** Le registre affichait `ParcoursAcademiqueTest (22+29)` alors que la suite contient désormais **57 tests**. Le chiffre était donc faux pour un lecteur du registre.

**Correctif.** Mise à jour de la ligne PACA-08 vers `ParcoursAcademiqueTest (57)` avec la date de vérification `26/10/2026`. Le chiffre a été prouvé par exécution : `57/57 PASS, 238 assertions`.

---

## 4. Critères d'acceptation

| Critère | Statut | Preuve |
|---|---|---|
| C1 — La simulation n'écrit jamais dans les notes officielles | PASS | test d'intégration, comptage avant/après |
| C2 — Une hypothèse invalide ne produit pas de résultat trompeur | PASS | test négatif `impossible` |
| C3 — Le repli matière est correct pour un tableau | PASS | test de réflexion + test d'intégration |
| C4 — Les transitions FSM non autorisées sont refusées | PASS | test 422 + statut inchangé |
| C5 — L'isolation facultaire n'est pas contournée | PASS | suite `ParcoursAcademiqueTest` et tests de portail |
| C6 — Le registre reflète le nombre réel de tests | PASS | `57/57`, registre `(57)` |

---

## 5. Modifications appliquées

| Fichier | Nature |
|---|---|
| `app/Modules/ParcoursAcademique/Services/SimulationParcoursService.php` | helper `matiereIdDeLigne()`, appel dans `simulerMatiere()`, renommage `$transferables` |
| `docs/registre/registre-canonique.md` | PACA-08 : `(22+29)` → `(57)`, date de vérification |
| `tests/Feature/Loop04PasseportAcademiqueVerificationTest.php` | suite indépendante (4 tests) |
| `docs/engineering/LOOP-04-VERIFICATION-PASSEPORT-ACADEMIQUE.md` | présent rapport |

Aucune migration, aucun changement de route, aucune permission, aucun changement de contrat public.

---

## 6. Preuves d'exécution

### 6.1 Suite de vérification indépendante

```bash
php artisan test --compact tests/Feature/Loop04PasseportAcademiqueVerificationTest.php
```

**Résultat : 4/4 PASS, 17 assertions, 1 441 ms.**

### 6.2 Suite préexistante du domaine

```bash
php artisan test --compact tests/Feature/ParcoursAcademiqueTest.php
```

**Résultat : 57/57 PASS, 238 assertions, 2 638 ms.**

### 6.3 Non-régression élargie

```bash
php artisan test --compact --filter="ParcoursAcademiqueTest|MaSimulationParcoursTest|PortailSimulationParcoursTest|Loop04PasseportAcademiqueVerificationTest"
```

**Résultat : 99/99 PASS, 492 assertions, 6 491 ms.**

### 6.4 Qualité de code

```bash
php -l app/Modules/ParcoursAcademique/Services/SimulationParcoursService.php
vendor/bin/pint --test app/Modules/ParcoursAcademique/Services/SimulationParcoursService.php
```

**Résultat : aucune erreur de syntaxe ; Pint PASSED.**

---

## 7. Non-régression et hygiène

- Aucun commit, push, tag ni déploiement.
- Aucune migration, aucun `migrate:fresh`, `db:wipe` ou suppression de données.
- Aucun secret, jeton, mot de passe ou PII réelle dans les tests ou ce rapport.
- Les fichiers non revendiqués (contrôleur/request/tests de portail préexistants) n'ont pas été modifiés.
- Le rapport préexistant LOOP-04 n'a pas été écrasé.

---

## 8. Risques résiduels et réserves

- **R-04/1** — Les conflits de concurrence restent des réponses HTTP 409 brutes côté web ; l'ergonomie de session est un développement UI futur, hors périmètre minimal. **Non bloquant.**
- **R-04/2** — L'état `annulee` d'une réorientation reste réservé sans chemin d'écriture (`decider()` le refuse en 422). Toute ouverture future doit passer par `TRANSITIONS` + test anti-dérive. **Non bloquant.**
- **R-04/3** — Les états `eligible` / `non_eligible` ne sont attribués par aucun flux actuel ; comportement documenté, à auditer avant toute suppression. **Non bloquant.**
- **R-04/7 (nouvelle)** — La couverture FSM est prouvée sur la transition `proposee → annulee` et sur les tests préexistants, pas sur l'ensemble exhaustif des couples d'états. Une future matrice de transitions devrait être épinglée par un test paramétré. **Non bloquant.**
- Héritage du programme : réserves R-03/1…5 et R1-R4 de LOOP-00 toujours ouvertes.

---

## 9. Verdict

**`PASS_WITH_RESERVATIONS`.**

Le rapport préexistant était globalement exact sur le comportement métier, mais il laissait deux défauts de code réels (repli `matière` inopérant, capacité de lecture du registre inexacte) et un compteur obsolète. Ces trois points sont corrigés et prouvés. Les réserves restantes sont des choix de conception ou des développements UI documentés, sans impact sur les critères d'acceptation du lot. Aucun élément `NOT_EXECUTED` n'est compté comme `PASS`.
