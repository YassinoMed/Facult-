# SIM-03 — 04 · Implémentation

**Scénario :** SIM-03 — Gel des admissions  
**Date :** 2026-11-02  
**Mode :** AUDIT / SIMULATION / ZÉRO MUTATION MÉTIER  

---

## D.1 Fichiers modifiés et créés

| Fichier | Nature | Rôle dans SIM-03 |
|---|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | Modifié | Bump `VERSION_MOTEUR = '1.2'`, prise en charge de `duree_gel_annees` dans `validerCriteres`, transmission de `$dureeGel` à `simulerVariante`, gestion du gel ($admissions = 0$ si $pas \le dureeGel$) et reprise post-gel. |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | Enregistrement de `SIM-03` dans `definitions()`, méthode `sim03()`, méthode `schemaSim03()` avec `duree_gel_annees` requis, règle de domaine `validerReglesSim03()` ($1 \le duree \le horizon$). |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | Vérification de la disponibilité de `SIM-03`, contrôle d'intégrité (4 disponibles / 41 planifiés), test de validation du schéma et des rejets de durée. |
| `tests/Feature/SimulationTemporelleSim03Test.php` | Créé | 7 tests indépendants (gel total, reprise post-gel, conservation exacte, idempotence, capacités, sécurité, zéro écriture métier). |
| `docs/engineering/simulateur-temporel/ST-14/SIM-03/*` | Créé | Dossier documentaire complet (00 à 09). |

---

## D.2 Extraits de code de référence

### Moteur unique canonique (`ServiceSimulationTemporelle.php`)

```php
    // In simulerVariante:
    if ($dureeGel !== null && $pas <= $dureeGel) {
        $admissions = 0;
    } elseif ($dureeGel !== null) {
        $pasApresGel = $pas - $dureeGel;
        $admissions = (int) round($baseL1 * pow(1 + $delta, $pasApresGel));
    } else {
        $admissions = (int) round($baseL1 * pow(1 + $delta, $pas));
    }
```

### Règle de domaine (`CatalogueScenarios.php`)

```php
    private static function validerReglesSim03(array $parametres): void
    {
        $duree = (int) $parametres['duree_gel_annees'];
        $horizon = (int) $parametres['horizon_annees'];

        if ($duree < 1) {
            throw new \DomainException("SIM-03 (gel) : la durée du gel ({$duree}) doit être d'au moins 1 an — un gel nul relève de SIM-01 pour la stabilité.");
        }

        if ($duree > $horizon) {
            throw new \DomainException("SIM-03 (gel) : la durée du gel ({$duree}) ne peut pas dépasser l'horizon de projection ({$horizon}).");
        }
    }
```

---

## D.3 Absence de duplication et rétrocompatibilité

Le moteur demeure unique. Si `duree_gel_annees` est absent (cas des scénarios antérieurs SOCLE-EFFECTIFS, SIM-01 et SIM-02), la formule antérieure s'applique sans aucun changement.

