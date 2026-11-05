# ST-11 — 07 · Déterminisme et reproductibilité

## E1 · Construction de `hash_entrees` (vérifiée dans le code)

`ServiceSimulationTemporelle` (lignes 154-159) :

```php
$entrees = [
  'scenario'        => [$id, $version, $criteres, $contraintes, $objectifs],
  'annee_source'    => …,
  'effectifs'       => état initial de l'adaptateur (données de référence),
  'graine'          => …,
  'nb_variantes'    => …,
  'version_moteur'  => self::VERSION_MOTEUR,   // '1.0'
];
```

**Complet** : inclut les données de référence (effectifs de l'adaptateur) — un changement de données de référence produit un hash différent ✅. Version moteur incluse ✅.

## E2 · Sérialisation

- Hash via `hash('sha256', json_encode($entrees, JSON_SORT_KEYS | …))` — tri des clés → représentation canonique stable pour les mêmes entrées logiques.
- Nombres : floats PHP (décimales de paramètres fixes) ; cohortes entières (`(int)` à la lecture).

## E3 · Graine — absence de générateur global

- `GenerateurVariantes::pseudoAleatoire(graine, itération)` : SHA-256 de `graine:contexte:itération` → [−1,1]. **Aucun** `rand()`/`mt_rand()`/`random_int()` dans le module (vérifié par grep).
- Ordres de parcours déterministes : `MoteurEvenements` file triée (temps, séquence) ; boucles `for` indexées ; niveaux triés par l'adaptateur (`orderByRaw(ordre asc)`).
- Tri sans départage : `sort()` des totaux Monte-Carlo (valeurs scalaires — pas d'ambiguïté).

## E4 · Version du moteur

- `VERSION_MOTEUR = '1.0'` constante du service, **incluse dans le hash** et retournée dans le résultat.
- Une ancienne exécution reste identifiable (hash + version figés dans la ligne) ; le rejeu d'anciennes entrées avec un moteur modifié produirait un hash différent (idempotence par contenu, jamais par ID seul). ✅ conforme au cahier E4.

## E5 · Idempotence — scénarios testés

| Scénario | Comportement | Preuve |
|---|---|---|
| Mêmes entrées exactes | exécution **existante retournée** (`idempotente=existante`), aucun nouveau snapshot | `test_execution_reproductible_et_idempotente` |
| Graine différente | exécution **nouvelle** (hash différent) | `test_graines_differentes_resultats_differents` |
| Nouvelle version scénario | `version+1`, historique préservé (jamais écrasée) | `test_st10_lot4_duplication_et_versionnement_scenario` |
| Rejeu simultané | contrainte UNIQUE sur `hash_entrees` (SQL) | migration |
| Même clé, contenu différent | impossible — le hash **est** le contenu (SHA-256) | construction E1 |

## E6 · Reproductibilité vérifiée par tests

- Deux exécutions dans le même processus : ✅ (idempotence + valeurs identiques).
- Graine fixe → mêmes totaux, snapshots, classements : ✅ (`test_st10_lot6` : valeurs exactes 43/13/11/19 attendues et obtenues).
- Après rechargement (processus séparé) : propriété du déterminisme par hash (pas d'état global) — la même logique s'applique ; la preuve multi-processus `pcntl` n'est pas disponible localement (réserve R-06, comme pour les autres modules).
- Bit à bit vs numérique : les valeurs sont des **entiers** (personnes) et des proportions à 4 décimales fixées (`round(...,4)`) — reproductibilité numérique exacte, pas de flottant accumulé dans les totaux.

## GATE E — ✅ VALIDÉ

Résultats rattachés à `version_moteur` + `hash_entrees` complets (données de référence incluses) ; idempotence par contenu ; aucune source d'aléa global.
