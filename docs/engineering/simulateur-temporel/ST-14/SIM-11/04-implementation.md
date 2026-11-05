# SIM-11 — 04 · Implémentation

**Date :** 2026-11-05  
**Moteur :** `ServiceSimulationTemporelle` — version `1.8` **inchangée**  
**Compatibilité :** `analyse_nouvelle` (confirmée par l'audit)

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `sim11()`, `schemaSim11()`, `validerReglesSim11()`, branche `validerParametres`, ajout à la liste des définitions ; retrait de SIM-11 de `planifies()` (**12 disponibles / 33 planifiés**). |
| `tests/Feature/SimulationTemporelleSim11Test.php` | Créé | 7 tests / 88 assertions — références hors moteur (axe `abandon`, sens `hausse`), neutralité structurelle, refus de définition, idempotence, rétrocompatibilité, analyses OAT/MC, sécurité et lecture seule. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | **22 tests / 555 assertions** : compteurs 12/33, index 11, assertions de schéma SIM-11, test des règles SIM-11, refus du planifié suivant (SIM-12). |

**Aucune modification de :**

| Élément | Constat |
|---|---|
| `Services/ServiceSimulationTemporelle.php` | Aucune édition pendant SIM-11 ; `VERSION_MOTEUR = '1.8'` (livrée par SIM-09). Le diff cumulé contre `HEAD` provient de SIM-07 → SIM-09. |
| Tables / migrations | Aucune. |
| Routes / contrôleurs / vues | Aucune. |
| Permissions / politiques | Aucune. |
| Services, modèles, jobs | Aucun. |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail catalogue

### D.2.1 Schéma (`schemaSim11`)

```php
$schema = self::schemaAvecTauxRequis('SIM-11');
$schema['taux_modifie'] = [           // requis, trois taux, [0,1]
    'passage' => ..., 'redoublement' => ..., 'abandon' => ...,
];
$schema['axe_variation'] = [          // requis, épinglé
    'valeurs' => ['abandon'],
];
$schema['sens_variation'] = [         // requis, épinglé
    'valeurs' => ['hausse'],
];
```

Gel, choc, cible, creux et les alias `taux_ameliore` / `taux_degrade` ne
figurent pas au schéma : toute présence est refusée structurellement
(« paramètre inconnu »).

### D.2.2 Règles (`validerReglesSim11`)

| Ordre | Contrôle | Message |
|---|---|---|
| 1 | `passage + redoublement + abandon ≤ 1` (jeu modifié, tolérance `1.0001`) | « (modifié) > 1 : incohérent » |
| 2 | `taux_modifie.abandon > taux.abandon` (strict) | « doit être strictement supérieur à l'abandon de référence » |

Les bornes `[0,1]`, la complétude du jeu, l'axe, le sens et la présence du
jeu de référence sont couverts par la validation générique du catalogue
(`validerParametres`), pas dupliqués dans la règle métier.

### D.2.3 Définition (`sim11`)

| Attribut | Valeur |
|---|---|
| Code / nom | `SIM-11` — Hausse de l'abandon |
| Catégorie / lot | `reussite_flux` / A |
| Version / formules | `1` / `1.0` |
| Statut | `disponible` |
| Compatibilité | `analyse_nouvelle` |
| Dépendances | `['SOCLE-EFFECTIFS']` |
| Périmètre | `faculte_ou_global` |
| Indicateurs | `hausse_abandon`, totaux comparés, écarts, sortants, effet cumulé, champs moteur |

`planifies()` ne contient plus SIM-11 : **12 disponibles / 33 planifiés**
(45 entrées au total).

---

## D.3 Réutilisation stricte du moteur v1.8

| Capacité | Origine | Usage SIM-11 |
|---|---|---|
| Détection du descripteur générique | `aVariationGenerique()` (SIM-09) | Détecte le trio `taux_modifie` / `axe_variation` / `sens_variation` |
| Validation de forme | Gardes génériques v1.8 | Jeu complet, bornes, somme, axe/sens ∈ vocabulaire, référence |
| Direction `hausse` | Garde générique v1.8 | `taux_modifie[axe]` strictement supérieur à la référence |
| Double trajectoire | `simulerVariante()` + témoin en mémoire | Principale = jeu modifié ; témoin = jeu de référence |
| Comparaison neutre | `analyserReussite()` | 5 champs neutres + `hausse_abandon` |
| Analyses OAT / MC | `criteresAnalyses['taux'] = $reussiteTaux` | Calculées autour du jeu **modifié** |
| Exposition des champs | `champsSpecifiques()` | `hausse_abandon` déjà publié par SIM-09 |

Le contrat d'axe (`abandon` + `hausse`) est **exclusivement** porté par le
schéma catalogue SIM-11. Le moteur partagé reste générique : il accepte les
trois axes et les deux sens, et refuse explicitement si la direction déclarée
n'est pas respectée. Cette séparation est assumée et tracée en réserve
`SIM11-R6`.

---

## D.4 Ordre des gardes (inchangé)

| Ordre | Bloc | Comportement sur une entrée SIM-11 valide |
|---|---|---|
| 1 | SIM-07 (`taux_ameliore`) | Non déclenché (clé absente) |
| 2 | SIM-08 (`taux_degrade`) | Non déclenché (clé absente) |
| 3 | Générique | Valide le trio, la direction `hausse`, les incompatibilités |
| 4 | Commun | Somme du jeu de référence, bornes, périmètre |

L'ordre est celui livré par SIM-09 ; SIM-11 n'y touche pas. L'exclusion
mutuelle est prouvée par les tests de refus (alias SIM-07/08 simultanés).

---

## D.5 Compatibilité et versionnement

| Point | Décision |
|---|---|
| Version moteur | **`1.8` inchangée** — aucun comportement nouveau |
| Version scénario | `1`, formules `1.0` |
| Hashs d'entrée | Inchangés pour les scénarios existants (même version moteur) |
| Exécutions historiques | Lisibles, jamais réécrites |
| SIM-01 → SIM-10 | Suites inchangées, 0 échec |
| SIM-12 | Le descripteur générique est prêt (sens `baisse`) ; contrat à créer |

Aucun incrément n'est justifié : ajouter un contrat catalogue ne change ni
la formule, ni les hashs. La décision d'audit (ne pas incrémenter par
anticipation) est respectée ; la Phase D n'a imposé aucun retour dessus.

---

## D.6 Écart détecté et corrigé pendant l'implémentation

Le premier passage de la suite SIM-11 a révélé un écart **dans le calcul
manuel** du cas C, pas dans le moteur :

| Élément | Valeur erronée initiale | Valeur recalculée |
|---|---|---|
| Total modifié au pas 2 | `35` | **`32`** |
| Écarts | `[-3, 0]` | **`[-3, -3]`** |
| Écart final | `0` | **`-3`** |

Cause : `round(4.5)` et `round(6.5)` avaient été arrondis vers le bas dans le
commentaire de référence, alors que PHP arrondit les demi-entiers à l'écart
de zéro. Le moteur était correct ; les valeurs attendues et l'audit ont été
corrigés, **sans affaiblir aucune assertion** (l'écart final nul est devenu
un écart final `-3`, plus exigeant).

---

## D.7 Absence de duplication

| Élément | Réutilisation |
|---|---|
| Progression, conservation, arrondis, sortants | Moteur commun (SOCLE → SIM-10) |
| Comparaison de réussite | `analyserReussite()` (SIM-07 → SIM-11) |
| Validation de forme des taux | Boucles génériques du moteur |
| Validation de schéma | `schemaAvecTauxRequis()` + `validerParametres` |
| Exposition | `champsSpecifiques()` (liste unique) |
| Tests | Structure de suite réutilisée, **valeurs recalculées** pour `abandon` |

Aucun service, aucune table, aucune route, aucune permission nouvelle.
