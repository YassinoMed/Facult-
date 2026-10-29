# LOOP 14 — Vérification indépendante · Simulateur de scénarios

**Date d'exécution :** 27/10/2026
**Mode :** audit + tests négatifs + correctif minimal. Lecture seule sur les données officielles.
**Rapport préexistant conservé :** `docs/engineering/LOOP-14-SIMULATEUR-SCENARIOS.md` (non modifié, non écrasé).
**Périmètre :** `app/Modules/SimulateurScenarios/` + `resources`/routes du module.

## 1. Verdict

**`PASS_WITH_RESERVATIONS`.**

Trois défauts de cloisonnement ont été **prouvés par des tests qui échouaient**, puis
corrigés au minimum, puis re-testés :

- deux contournements du périmètre facultaire (D14-1, D14-3) ;
- un agrégat inter-facultés exposé à un `admin_faculte` (D14-2).

Les 3 tests négatifs passent (13 assertions) et la suite existante du lot reste verte
(16/16, 54 assertions). Une réserve de performance non corrigée subsiste (§7, R-14/1).

> **Cycle de complément du 2026-10-27 :** R-14/1 (plafond inopérant) et R-14/6
> (aucune interface) sont traitées ; R-14/2 est documentée. La suite de vérification
> passe à **6 tests / 34 assertions** et le module à **22/22 (88 assertions)**.
> Restent ouvertes R-14/3 (année non rattachée à la faculté), R-14/4 (MySQL/navigateur
> non exécutés) et R-14/5 (charge réelle non mesurée). Détail en §7 et §7bis.

## 2. Audit préalable — ce qui existe et n'a pas été dupliqué

| Actif | Emplacement | Réutilisation |
|---|---|---|
| Résolveur de périmètre | `Support/FacultePerimetre.php` → `User::peutAccederFaculte()` / `User::faculteRestreinteId()` | Aucune règle réécrite |
| DTO borné | `Support/CriteresScenario.php` (bornes `delta_effectifs` −0,5/+2,0 ; `delta_salles` ±20 ; `delta_charges` −0,5/+1,0) | Inchangé |
| Moteur lecture seule | `Services/ServiceScenarios.php` : `calculer()`, `comparer():68`, `ecarts():91` | Seul l'agrégat `hors_chaine_programme` a été scopé |
| Permission | `statistiques.view` (registre existant) via middleware de route + contrôle explicite | Aucune permission inventée |
| Verrou module | `module:simulateur-scenarios` (`config/modules.php:114`) | Inchangé |

Aucune table, aucune migration, aucun écran : le simulateur est un moteur de calcul
exposé par deux endpoints POST (`executer`, `comparer`), sans persistance.

## 3. Défauts prouvés

### D14-1 — `comparer()` : le périmètre facultaire était réécrivable par les sous-tableaux

**Cause.** `CriteresScenario::depuisTableau()` lit `faculte_id` dans le tableau reçu.
Le contrôleur fusionnait avec l'opérateur `+`, qui **ne remplace jamais une clé
existante** :

```php
$criteresA = CriteresScenario::depuisTableau($donnees['scenario_a'] + ['faculte_id' => $perimetre]);
```

Un `admin_faculte` de la faculté A pouvait donc envoyer
`{"scenario_a":{"faculte_id": <B>}, "scenario_b":{"faculte_id": <B>}}` et obtenir les
effectifs de la faculté B — le résolveur `FacultePerimetre` n'étant appliqué qu'au
paramètre de premier niveau, absent ici.

**Preuve.** Test `test_un_admin_faculte_ne_peut_pas_forcer_une_autre_faculte_dans_les_sous_scenarios` :
échec initial `Failed asserting that 2 is identical to 1` sur
`perimetre.faculte_id` — la réponse annonçait la faculté B (id 2) pour un
administrateur de la faculté A (id 1).

### D14-2 — `hors_chaine_programme` : agrégat non scopé par faculté

**Cause.** `ServiceScenarios::effectifsHorsChainFacultaire()` filtrait par année mais
**pas par faculté**, alors que l'axe `effectifs` est scopé par
`programme.faculte_id`. Conséquence : les inscriptions sans programme des autres
facultés étaient comptées dans le compteur « hors chaîne programme » d'un
`admin_faculte`.

**Preuve.** Test `test_le_compteur_hors_chaine_programme_reste_dans_le_perimetre_facultaire` :
échec initial `Failed asserting that 2 is identical to 1` — 2 inscriptions
orphelines (faculté A + faculté B) annoncées au lieu de 1 (faculté A seule).

### D14-3 — `executer()` : `faculte_id: null` explicite levait le verrou facultaire

**Cause.** Même famille de bug, chemin différent :

```php
$criteres = CriteresScenario::depuisTableau($donnees + ['faculte_id' => $perimetre]);
```

`validate()` conserve la clé `faculte_id` lorsqu'elle est envoyée **explicitement à
null** (`nullable`). L'opérateur `+` laisse alors la valeur `null` gagner, et
`CriteresScenario::depuisTableau()` utilise `isset()` — qui traite `null` comme
absent. Le DTO reçoit donc `faculteId = null` = **périmètre transversal**, alors que
`FacultePerimetre::resoudre()` avait correctement résolu la faculté de l'administrateur.
`axeEffectifs()` n'applique le filtre facultaire que si `faculteId !== null`
(`ServiceScenarios.php:154`) : le calcul portait alors sur toutes les facultés.

**Preuve.** Test `test_un_faculte_id_null_explicite_ne_doit_pas_ouvrir_le_perimetre_transversal` :
échec initial `Failed asserting that null is identical to 1` sur
`perimetre.faculte_id` — la réponse annonçait `null` (transversal) au lieu de la
faculté 1.

## 4. Correctifs appliqués (minimum strict)

| Fichier | Changement | Effet |
|---|---|---|
| `app/Modules/SimulateurScenarios/Http/Controllers/ScenariosController.php` (`comparer`) | Fusion `array_replace($donnees['scenario_a'], $perimetreCommun])` avec `$perimetreCommun = ['faculte_id' => $perimetre, 'annee_id' => …]` | Le périmètre et l'année deviennent des décisions serveur, non réécrivables par les sous-tableaux (D14-1) |
| `app/Modules/SimulateurScenarios/Http/Controllers/ScenariosController.php` (`executer`) | `array_replace($donnees, ['faculte_id' => $perimetre])` au lieu de `+` | Une clé `faculte_id` à `null` est écrasée par la faculté résolue (D14-3) |
| `app/Modules/SimulateurScenarios/Services/ServiceScenarios.php` (`effectifsHorsChainFacultaire`) | `whereHas('etudiant', fn ($q) => $q->where('faculte_id', $criteres->faculteId))` quand `faculteId !== null` | L'agrégat reste dans le périmètre (D14-2) |

Aucune signature publique modifiée, aucune permission ajoutée, aucun comportement
d'écriture introduit. Le service reste strictement en lecture.

**Changement de sémantique assumé :** `comparer()` impose désormais **le même**
`annee_id` (celui du premier niveau) aux deux scénarios. C'est la définition même de
« comparer deux scénarios sur le MÊME périmètre » ; la suite existante (16 tests) reste
verte, mais un client qui passait une année différente par sous-scénario verra cette
valeur ignorée.

## 5. Tests exécutés (preuves)

| Commande | Résultat réel |
|---|---|
| `php artisan test --compact tests/Feature/Loop14SimulateurScenariosVerificationTest.php` (avant correctif) | **échec** : 0/2 puis 2/3 — messages cités en §3 |
| idem (après correctif D14-1/D14-2) | **2/2, 10 assertions** |
| idem (après correctif D14-3) | **3/3, 13 assertions** |
| `php artisan test --compact tests/Feature/SimulateurScenariosTest.php` | **16/16, 54 assertions** |

Couverture des 3 contrôles négatifs :

1. sous-tableaux `scenario_a`/`scenario_b` porteurs d'une autre faculté → réponse
   re-scopée sur la faculté de l'utilisateur, effectifs base = 0 ;
2. compteur `hors_chaine_programme` → 1 (faculté A) et non 2 (A + B) ;
3. `faculte_id: null` explicite → périmètre = faculté de l'utilisateur, base = 1.

## 6. Non-régression

- Suite du module : `SimulateurScenariosTest` **16/16 PASS** (54 assertions).
- Aucune autre suite ne référence le module (`grep` sur `SimulateurScenarios` dans
  `tests/` : uniquement les deux fichiers ci-dessus).
- Aucune migration, aucun modèle, aucune donnée officielle touchée : le risque de
  régression transverse est structurellement nul.

## 7. Réserves

- **R-14/1 — plafond sans effet sur les agrégats.** → **CORRIGÉE le 2026-10-27** :
  `PLAFOND_LIGNES_PAR_AXE = 5000` ne tronque plus silencieusement un agrégat. Le
  service expose désormais `plafond_lignes` et `plafond_atteint` dans la réponse :
  quand le volume dépasse le plafond, l'avertissement est explicite, les comptes
  restent exacts et aucune ligne n'est supprimée. La sémantique des bornes est
  documentée (pas de troncature d'effectif réel).
- **R-14/2 — périmètre des effectifs par programme.** → **DOCUMENTÉE** : `base` et
  `hors_chaine_programme` sont décrits dans le contrat de réponse et dans les tests.
  Une inscription validée sans programme n'entre jamais dans `base` ; elle est
  comptée à part. Choix documenté, pas un défaut.
- **R-14/3 — `annee_id` non contrôlé par faculté.** Le filtre année est validé
  (`exists:annees_universitaires,id`) mais n'est pas rattaché à la faculté : une
  faculté peut simuler sur une année où elle n'a aucune inscription (résultat à 0,
  sans fuite d'information). **(ouverte — comportement acceptable)**
- **R-14/4 — aucune exécution MySQL ni navigateur.** Tests en SQLite `:memory:`
  uniquement ; le comportement de `whereHas` imbriqué sur MySQL est standard mais non
  rejoué ici. `NOT_EXECUTED`, pas `PASS`. **(ouverte)**
- **R-14/5 — pas de test de charge.** La tenue en charge réelle du moteur reste non
  mesurée. **(ouverte)**
- **R-14/6 — pas d'interface.** → **CORRIGÉE** : écran GET
  `admin.simulateur-scenarios.index` (formulaire comparer/exécuter), entrée de
  navigation conditionnelle au module actif, restitution des avertissements de
  plafond. Le module reste **désactivé par défaut** (`MODULE_SIMULATEUR_SCENARIOS=false`),
  ce qui est cohérent avec un moteur d'appui à la décision non encore activé.

## 7bis. Cycle de complément du 2026-10-27 — preuves

| Commande | Résultat réel |
|---|---|
| `php artisan test --compact tests/Feature/Loop14SimulateurScenariosVerificationTest.php` | **6/6 PASS, 34 assertions** |
| `php artisan test --compact tests/Feature/SimulateurScenariosTest.php` | **16/16 PASS, 54 assertions** |
| Total module (filtre `Simulateur`) | **22/22 PASS, 88 assertions** |
| `vendor/bin/pint` sur les fichiers touchés | **PASS** |

Tests ajoutés : plafond explicite (avertissement + agrégat exact), sémantique
`base`/`hors_chaine_programme`, écran GET accessible avec le module actif et
`404` avec le module désactivé.

## 8. Fichiers touchés par ce lot

| Fichier | Nature |
|---|---|
| `app/Modules/SimulateurScenarios/Http/Controllers/ScenariosController.php` | modifié (2 appels) |
| `app/Modules/SimulateurScenarios/Services/ServiceScenarios.php` | modifié (1 méthode privée) |
| `tests/Feature/Loop14SimulateurScenariosVerificationTest.php` | **créé** (3 tests négatifs) |

Aucun commit, aucun push. Les rapports `LOOP-14-SIMULATEUR-SCENARIOS.md` et
`LOOP-14-20-AUDIT-DIRECTEUR.md` sont intacts.
