# SIM-10 — 04 · Implémentation

**Date :** 2026-11-05  
**Moteur :** `ServiceSimulationTemporelle` — version `1.8` **inchangée**  
**Compatibilité :** `analyse_nouvelle` (confirmée par l'audit)

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `sim10()`, `schemaSim10()`, `validerReglesSim10()`, branche `validerParametres`, ajout à la liste des définitions ; retrait de SIM-10 de `planifies()` (**11 disponibles / 34 planifiés**). |
| `tests/Feature/SimulationTemporelleSim10Test.php` | Créé | 7 tests / 85 assertions — références hors moteur (sens `baisse`), refus de définition, idempotence, rétrocompatibilité, analyses OAT/MC, sécurité et lecture seule. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | **21 tests / 520 assertions** : compteurs 11/34, index 10, assertions de schéma SIM-10, test des règles SIM-10, refus du planifié suivant (SIM-11). |

**Aucune modification de :**

| Élément | Constat |
|---|---|
| `Services/ServiceSimulationTemporelle.php` | Aucune édition pendant SIM-10 ; `VERSION_MOTEUR = '1.8'` (livrée par SIM-09). Le diff cumulé contre `HEAD` provient de SIM-07 → SIM-09. |
| Tables / migrations | Aucune. |
| Routes / contrôleurs / vues | Aucune. |
| Permissions / politiques | Aucune. |
| Services, modèles, jobs | Aucun. |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail catalogue

### D.2.1 Schéma (`schemaSim10`)

```php
$schema = self::schemaAvecTauxRequis('SIM-10');
$schema['taux_modifie'] = [           // requis, trois taux, [0,1]
    'passage' => ..., 'redoublement' => ..., 'abandon' => ...,
];
$schema['axe_variation'] = [          // requis, épinglé
    'valeurs' => ['redoublement'],
];
$schema['sens_variation'] = [         // requis, épinglé
    'valeurs' => ['baisse'],
];
```

Gel, choc, cible, creux et les alias `taux_ameliore` / `taux_degrade` ne
figurent pas au schéma : toute présence est refusée structurellement
(« paramètre inconnu »).

### D.2.2 Règles (`validerReglesSim10`)

| Ordre | Contrôle | Message |
|---|---|---|
| 1 | `passage + redoublement + abandon ≤ 1` (jeu modifié, tolérance `1.0001`) | « (modifié) > 1 : incohérent » |
| 2 | `taux_modifie.redoublement < taux.redoublement` (strict) | « doit être strictement inférieur au redoublement de référence » |

Les bornes `[0,1]`, la complétude du jeu, l'axe, le sens et la présence du
jeu de référence sont couverts par la validation générique du catalogue
(`validerParametres`), pas dupliqués dans la règle métier.

### D.2.3 Définition (`sim10`)

| Attribut | Valeur |
|---|---|
| Code / nom | `SIM-10` — Réduction du redoublement |
| Catégorie / lot | `reussite_flux` / A |
| Version / formules | `1` / `1.0` |
| Statut | `disponible` |
| Compatibilité | `analyse_nouvelle` |
| Dépendances | `['SOCLE-EFFECTIFS']` |
| Périmètre | `faculte_ou_global` |
| Indicateurs | `baisse_redoublement`, totaux comparés, écarts, sortants, effet cumulé, champs moteur |

`planifies()` ne contient plus SIM-10 : **11 disponibles / 34 planifiés**
(45 entrées au total).

---

## D.3 Réutilisation stricte du moteur v1.8

| Capacité | Origine | Usage SIM-10 |
|---|---|---|
| Détection du descripteur générique | `aVariationGenerique()` (SIM-09) | Détecte le trio `taux_modifie` / `axe_variation` / `sens_variation` |
| Validation de forme | Gardes génériques v1.8 | Jeu complet, bornes, somme, axe/sens ∈ vocabulaire, référence |
| Direction `baisse` | Garde générique v1.8 | `taux_modifie[axe]` strictement inférieur à la référence |
| Double trajectoire | `simulerVariante()` + témoin en mémoire | Principale = jeu modifié ; témoin = jeu de référence |
| Comparaison neutre | `analyserReussite()` | 5 champs neutres + `baisse_redoublement` |
| Analyses OAT / MC | `criteresAnalyses['taux'] = $reussiteTaux` | Calculées autour du jeu **modifié** |
| Exposition des champs | `champsSpecifiques()` | `baisse_redoublement` déjà publié par SIM-09 |

Le contrat d'axe (`redoublement` + `baisse`) est **exclusivement** porté par
le schéma catalogue SIM-10. Le moteur partagé reste générique : il accepte
les trois axes et les deux sens, et le refuse explicitement si la direction
déclarée n'est pas respectée. Cette séparation est assumée et tracée en
réserve `SIM10-R6`.

---

## D.4 Ordre des gardes (inchangé)

| Ordre | Bloc | Comportement sur une entrée SIM-10 valide |
|---|---|---|
| 1 | SIM-07 (`taux_ameliore`) | Non déclenché (clé absente) |
| 2 | SIM-08 (`taux_degrade`) | Non déclenché (clé absente) |
| 3 | Générique | Valide le trio, la direction `baisse`, les incompatibilités |
| 4 | Commun | Somme du jeu de référence, bornes, périmètre |

L'ordre est celui livré par SIM-09 ; SIM-10 n'y touche pas. L'exclusion
mutuelle est prouvée par les tests de refus (alias SIM-07/08 simultanés).

---

## D.5 Compatibilité et versionnement

| Point | Décision |
|---|---|
| Version moteur | **`1.8` inchangée** — aucun comportement nouveau |
| Version scénario | `1`, formules `1.0` |
| Hashs d'entrée | Inchangés pour les scénarios existants (même version moteur) |
| Exécutions historiques | Lisibles, jamais réécrites |
| SIM-01 → SIM-09 | Suites inchangées, 0 échec |
| SIM-11 → SIM-12 | Le descripteur générique est prêt ; contrats à créer |

Aucun incrément n'est justifié : ajouter un contrat catalogue ne change ni
la formule, ni les hashs. La décision d'audit (ne pas incrémenter par
anticipation) est respectée ; aucune découverte en Phase D n'a imposé de
revenir dessus.

---

## D.6 Absence de duplication

| Élément | Réutilisation |
|---|---|
| Progression, conservation, arrondis, sortants | Moteur commun (SOCLE → SIM-09) |
| Comparaison de réussite | `analyserReussite()` (SIM-07 → SIM-10) |
| Validation de forme des taux | Boucles génériques du moteur |
| Validation de schéma | `schemaAvecTauxRequis()` + `validerParametres` |
| Exposition | `champsSpecifiques()` (liste unique) |
| Tests | Structure de suite réutilisée, **valeurs recalculées** pour `baisse` |

Aucun service, aucune table, aucune route, aucune permission nouvelle.
