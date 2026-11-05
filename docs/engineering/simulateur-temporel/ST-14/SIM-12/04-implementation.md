# SIM-12 — 04 · Implémentation

**Date :** 2026-11-05  
**Moteur :** `ServiceSimulationTemporelle` — version `1.8` **inchangée**  
**Compatibilité :** `analyse_nouvelle` (confirmée par l'audit)

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `sim12()`, `schemaSim12()`, `validerReglesSim12()`, branche `validerParametres`, ajout à la liste des définitions ; retrait de SIM-12 de `planifies()` (**13 disponibles / 32 planifiés**). |
| `tests/Feature/SimulationTemporelleSim12Test.php` | Créé | 7 tests / 88 assertions — références hors moteur (axe `abandon`, sens `baisse`), neutralité structurelle, refus de définition, idempotence, rétrocompatibilité, analyses OAT/MC, sécurité et lecture seule. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | **23 tests / 590 assertions** : compteurs 13/32, index 12, assertions de schéma SIM-12, test des règles SIM-12, refus du planifié suivant (SIM-13). |

**Aucune modification de :**

| Élément | Constat |
|---|---|
| `Services/ServiceSimulationTemporelle.php` | Aucune édition pendant SIM-12 ; `VERSION_MOTEUR = '1.8'` (livrée par SIM-09). Le diff cumulé contre `HEAD` provient de SIM-07 → SIM-09. |
| Tables / migrations | Aucune. |
| Routes / contrôleurs / vues | Aucune. |
| Permissions / politiques | Aucune. |
| Services, modèles, jobs | Aucun. |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail catalogue

### D.2.1 Schéma (`schemaSim12`)

```php
$schema = self::schemaAvecTauxRequis('SIM-12');
$schema['taux_modifie'] = [           // requis, trois taux, [0,1]
    'passage' => ..., 'redoublement' => ..., 'abandon' => ...,
];
$schema['axe_variation'] = [          // requis, épinglé
    'valeurs' => ['abandon'],
];
$schema['sens_variation'] = [         // requis, épinglé
    'valeurs' => ['baisse'],
];
```

Gel, choc, cible, creux et les alias `taux_ameliore` / `taux_degrade` ne
figurent pas au schéma : toute présence est refusée structurellement
(« paramètre inconnu »).

### D.2.2 Règles (`validerReglesSim12`)

| Ordre | Contrôle | Message |
|---|---|---|
| 1 | `passage + redoublement + abandon ≤ 1` (jeu modifié, tolérance `1.0001`) | « (modifié) > 1 : incohérent » |
| 2 | `taux_modifie.abandon < taux.abandon` (strict) | « doit être strictement inférieur à l'abandon de référence » |

Les bornes `[0,1]`, la complétude du jeu, l'axe, le sens et la présence du
jeu de référence sont couverts par la validation générique du catalogue
(`validerParametres`), pas dupliqués dans la règle métier.

### D.2.3 Définition (`sim12`)

| Attribut | Valeur |
|---|---|
| Code / nom | `SIM-12` — Réduction de l'abandon |
| Catégorie / lot | `reussite_flux` / A |
| Version / formules | `1` / `1.0` |
| Statut | `disponible` |
| Compatibilité | `analyse_nouvelle` |
| Dépendances | `['SOCLE-EFFECTIFS']` |
| Périmètre | `faculte_ou_global` |
| Indicateurs | `baisse_abandon`, totaux comparés, écarts, sortants, effet cumulé, champs moteur |

`planifies()` ne contient plus SIM-12 : **13 disponibles / 32 planifiés**
(45 entrées au total).

---

## D.3 Réutilisation stricte du moteur v1.8

| Capacité | Origine | Usage SIM-12 |
|---|---|---|
| Détection du descripteur générique | `aVariationGenerique()` (SIM-09) | Détecte le trio `taux_modifie` / `axe_variation` / `sens_variation` |
| Validation de forme | Gardes génériques v1.8 | Jeu complet, bornes, somme, axe/sens ∈ vocabulaire, référence |
| Direction `baisse` | Garde générique v1.8 | `taux_modifie[axe]` strictement inférieur à la référence |
| Double trajectoire | `simulerVariante()` + témoin en mémoire | Principale = jeu modifié ; témoin = jeu de référence |
| Comparaison neutre | `analyserReussite()` | 5 champs neutres + `baisse_abandon` |
| Analyses OAT / MC | `criteresAnalyses['taux'] = $reussiteTaux` | Calculées autour du jeu **modifié** |
| Exposition des champs | `champsSpecifiques()` | `baisse_abandon` déjà publié par SIM-09 |

Le contrat d'axe (`abandon` + `baisse`) est **exclusivement** porté par le
schéma catalogue SIM-12. Le moteur partagé reste générique : il accepte les
trois axes et les deux sens, et refuse explicitement si la direction déclarée
n'est pas respectée. Cette séparation est assumée et tracée en réserve
`SIM12-R6`.

---

## D.4 Ordre des gardes (inchangé)

| Ordre | Bloc | Comportement sur une entrée SIM-12 valide |
|---|---|---|
| 1 | SIM-07 (`taux_ameliore`) | Non déclenché (clé absente) |
| 2 | SIM-08 (`taux_degrade`) | Non déclenché (clé absente) |
| 3 | Générique | Valide le trio, la direction `baisse`, les incompatibilités |
| 4 | Commun | Somme du jeu de référence, bornes, périmètre |

L'ordre est celui livré par SIM-09 ; SIM-12 n'y touche pas. L'exclusion
mutuelle est prouvée par les tests de refus (alias SIM-07/08 simultanés).

---

## D.5 Compatibilité et versionnement

| Point | Décision |
|---|---|
| Version moteur | **`1.8` inchangée** — aucun comportement nouveau |
| Version scénario | `1`, formules `1.0` |
| Hashs d'entrée | Inchangés pour les scénarios existants (même version moteur) |
| Exécutions historiques | Lisibles, jamais réécrites |
| SIM-01 → SIM-11 | Suites inchangées, 0 échec |
| SIM-13 | Multi-axes : hors contrat mono-axe, décision d'extension à évaluer |

Aucun incrément n'est justifié : ajouter un contrat catalogue ne change ni
la formule, ni les hashs. La décision d'audit (ne pas incrémenter par
anticipation) est respectée ; la Phase D n'a imposé aucun retour dessus.

---

## D.6 Aucun écart détecté pendant l'implémentation

Contrairement à SIM-11 (où le calcul manuel du cas C avait dû être corrigé
sur deux demi-entiers), SIM-12 a passé sa suite **du premier coup** :
**7/7 · 88 assertions**. Les valeurs de référence des cas A, B et C ont été
recalculées avant écriture, arrondis demi-entiers inclus :

| Cas | Points d'arrondi sensibles | Valeur retenue |
|---|---|---|
| A | `13 × 0.3 = 3.9`, `12 × 0.3 = 3.6`, `21 × 0.3 = 6.3` | `4`, `4`, `6` |
| B | aucun flux modifié (slack) | trajectoires identiques |
| C | `13 × 0.7 = 9.1`, `11 × 0.7 = 7.7`, `20 × 0.7 = 14.0` | `9`, `8`, `14` |

Aucune assertion n'a été modifiée après le premier passage.

---

## D.7 Absence de duplication

| Élément | Réutilisation |
|---|---|
| Progression, conservation, arrondis, sortants | Moteur commun (SOCLE → SIM-11) |
| Comparaison de réussite | `analyserReussite()` (SIM-07 → SIM-12) |
| Validation de forme des taux | Boucles génériques du moteur |
| Validation de schéma | `schemaAvecTauxRequis()` + `validerParametres` |
| Exposition | `champsSpecifiques()` (liste unique) |
| Tests | Structure de suite réutilisée, **valeurs recalculées** pour `abandon` |

Aucun service, aucune table, aucune route, aucune permission nouvelle.
