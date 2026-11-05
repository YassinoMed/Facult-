# LOOP 43 — DÉPLOIEMENT PROGRESSIF PAR INDICATEURS

**Date :** 31 octobre 2026
**Objet :** exposer une fonctionnalité par périmètre (faculté, programme, utilisateur, pourcentage)
sans jamais l'activer par défaut, et sans remplacer une validation métier.

---

## 1. Audit préalable

| Besoin | Existant | Décision |
|---|---|---|
| Activation de modules | `config/modules.php`, `ModuleRegistry`, `EnsureModuleEnabled` | Conservé pour les modules ; non détourné |
| Paramètres versionnés | `ParametreService`, `Parametre` | Non réutilisé : stocke des paramètres métier, pas des interrupteurs de déploiement |
| Middleware HTTP | `bootstrap/app.php` (alias `module`, `admin`, `scope`…) | Nouvel alias `feature` ajouté au même endroit |
| Audit | `AuditService` | Non appelé : évaluation en lecture seule, aucune écriture |

**Aucun système d'indicateurs n'existait.** Aucun doublon avec `ModuleRegistry` : un module est une
unité fonctionnelle activée par configuration serveur ; un indicateur est une restriction de portée
d'une fonctionnalité déjà autorisée.

---

## 2. Configuration livrée

`config/feature_flags.php`

| Clé | Défaut | Effet |
|---|---|---|
| `environnements` | `local,testing` | Plafond global : la production n'est jamais incluse implicitement |
| `flags.*.enabled` | `false` (les 5 flags) | Aucune fonctionnalité exposée |
| `flags.*.facultes/programmes/users` | `[]` | Aucune restriction, mais flag désactivé |
| `flags.*.pourcentage` | `null` | Aucun déploiement partiel |

Indicateurs déclarés, tous désactivés :

| Indicateur | Usage prévu |
|---|---|
| `pilote_academique` | LOOP 42 |
| `gouvernance_regles` | LOOP 44 |
| `recours_academique` | LOOP 45 |
| `planification_simulation` | LOOP 47 |
| `kpi_academique` | LOOP 48 |

Ce sont des **interrupteurs techniques**, pas des autorisations.

---

## 3. Service livré

`app/Services/FeatureFlagService.php`

### 3.1 Ordre d'évaluation

1. Indicateur inconnu ⇒ `FLAG_INCONNU`, faux.
2. `enabled !== true` ⇒ `DESACTIVE`, faux.
3. Environnement hors liste globale **ou** hors liste du flag ⇒ `ENVIRONNEMENT_NON_AUTORISE`, faux.
4. Faculté hors liste (ou contexte absent) ⇒ `HORS_FACULTE`, faux.
5. Programme hors liste (ou contexte absent) ⇒ `HORS_PROGRAMME`, faux.
6. Utilisateur hors liste (ou contexte absent) ⇒ `UTILISATEUR_HORS_LISTE`, faux.
7. Pourcentage sans `user_id` ⇒ `POURCENTAGE_SANS_SUJET`, faux.
8. Seau ≥ pourcentage ⇒ `POURCENTAGE_NON_ATTEINT`, faux.
9. Sinon ⇒ `ACTIF`, vrai.

Les dimensions se combinent en **ET**. Une dimension vide n'ajoute aucune restriction mais n'est pas
un joker : si elle est renseignée et que le contexte manque, le résultat est faux.

### 3.2 Pourcentage déterministe

```php
bucket($flag, $sujet) = crc32("$flag|$sujet") % 100
```

- Même sujet + même flag ⇒ même seau, sur tous les serveurs.
- Pas de `rand()`, pas de tirage par requête.
- Sans sujet stable (`user_id`), le pourcentage est refusé : pas de déploiement « au hasard ».

### 3.3 Diagnostic sans PII

`inventaire()` retourne, pour chaque flag : `enabled`, `description`, environnements, **cardinalité**
des listes de facultés / programmes / utilisateurs, et pourcentage. Les identifiants bruts ne sont
jamais exposés.

---

## 4. Middleware livré

`app/Http/Middleware/EnsureFeatureEnabled.php` — alias `feature`.

```php
Route::middleware('feature:recours_academique')->group(function () {
    // ...
});
```

- Flag inactif, inconnu ou hors portée ⇒ **404**, y compris en appel direct.
- Le 404 est volontaire : il n'annonce pas l'existence de la fonctionnalité.
- Contexte transmis : `user_id`, `faculte_id`, `environnement`. Aucune donnée nominative.

---

## 5. État réel après livraison

Exécution sur l'environnement `local` :

| Indicateur | Activé | Facultés | Programmes | Utilisateurs | Pourcentage |
|---|---|---|---:|---:|---:|---:|
| `pilote_academique` | non | 0 | 0 | 0 | — |
| `gouvernance_regles` | non | 0 | 0 | 0 | — |
| `recours_academique` | non | 0 | 0 | 0 | — |
| `planification_simulation` | non | 0 | 0 | 0 | — |
| `kpi_academique` | non | 0 | 0 | 0 | — |

Aucune route n'est protégée par `feature:` à ce stade : le middleware est disponible mais aucun
périmètre réel n'est exposé.

---

## 6. Ce que ce dispositif ne fait pas

1. Il n'autorise rien : un flag actif ne vaut pas validation métier.
2. Il ne remplace pas les permissions : `feature` restreint, `can`/`permission` autorise.
3. Il ne modifie pas les modules : `module` et `feature` sont indépendants.
4. Il ne persiste rien : pas de table, pas d'écriture, pas de cache à invalider.
5. Il ne déploie pas en production par défaut : `environnements` exclut `production`.

---

## 7. Reproductibilité

```bash
php artisan test tests/Feature/FeatureFlags/FeatureFlagLoop43Test.php --compact
vendor/bin/pint --test config/feature_flags.php app/Services/FeatureFlagService.php \
  app/Http/Middleware/EnsureFeatureEnabled.php bootstrap/app.php \
  tests/Feature/FeatureFlags/FeatureFlagLoop43Test.php
php artisan route:list   # vérifie que l'alias n'empêche pas le boot
```
