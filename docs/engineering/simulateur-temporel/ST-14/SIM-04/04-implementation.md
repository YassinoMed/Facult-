# SIM-04 — 04 · Implémentation

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Date :** 2026-11-03  
**Mode :** AUDIT / SIMULATION / ZÉRO MUTATION MÉTIER  

---

## D.1 Fichiers modifiés et créés

| Fichier | Nature | Rôle dans SIM-04 |
|---|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | Modifié | Bump `VERSION_MOTEUR = '1.3'` ; extraction de `$chocAdmissions` dans `executer` ; validation de la paire, du pas et du volume dans `validerCriteres` ; paramètre `?array $chocAdmissions` dans `simulerVariante` ; ajout additif au pas exact ; propagation dans la closure sensibilité/Monte-Carlo. |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | Enregistrement de `SIM-04` dans `definitions()`, méthode `sim04()`, `schemaSim04()` (deux paramètres requis), règle `validerReglesSim04()`, retrait de SIM-04 de `planifies()`, branche `validerParametres`. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | 5 disponibles / 40 planifiés, SIM-04 disponible (index 4), SIM-05 utilisé comme scénario planifié, nouveau test des règles SIM-04. |
| `tests/Feature/SimulationTemporelleSim04Test.php` | Créé | 7 tests indépendants : choc exact, conservation, validation, idempotence, capacité, sécurité, zéro écriture. |
| `docs/engineering/simulateur-temporel/ST-14/SIM-04/*` | Créé | Dossier documentaire complet (00 à 09). |
| `docs/engineering/simulateur-temporel/ST-14/registre-global.md` | Modifié | Section SIM-04, famille 1, tableau des 44 scénarios, totaux. |

---

## D.2 Extraits de code de référence

### Moteur unique (`ServiceSimulationTemporelle.php`)

```php
// Dans executer(), avant la boucle des variantes :
$chocAdmissions = (isset($criteres['pas_exceptionnel'], $criteres['admissions_exceptionnelles']))
    ? [
        'pas' => (int) $criteres['pas_exceptionnel'],
        'admissions' => (int) $criteres['admissions_exceptionnelles'],
    ]
    : null;
```

```php
// Dans le handler de simulerVariante(), après la trajectoire de fond :
if ($chocAdmissions !== null && $pas === (int) $chocAdmissions['pas']) {
    $admissions += (int) $chocAdmissions['admissions'];
}
```

### Validation moteur (`validerCriteres`)

```php
$pasExceptionnel = isset($criteres['pas_exceptionnel']) ? (int) $criteres['pas_exceptionnel'] : null;
$admissionsExceptionnelles = isset($criteres['admissions_exceptionnelles']) ? (int) $criteres['admissions_exceptionnelles'] : null;

if (($pasExceptionnel === null) !== ($admissionsExceptionnelles === null)) {
    throw new \DomainException('Choc exceptionnel incomplet : pas_exceptionnel et admissions_exceptionnelles sont indissociables.');
}

if ($pasExceptionnel !== null) {
    if ($pasExceptionnel < 1 || $pasExceptionnel > $horizon) {
        throw new \DomainException("Le pas exceptionnel ({$pasExceptionnel}) doit être compris entre 1 et l'horizon ({$horizon} an(s)).");
    }
    if ($admissionsExceptionnelles < 1) {
        throw new \DomainException('Le choc exceptionnel doit être strictement positif (un choc nul relève de la trajectoire de fond).');
    }
}
```

### Règle de domaine (`CatalogueScenarios.php`)

```php
private static function validerReglesSim04(array $parametres): void
{
    $pas = (int) $parametres['pas_exceptionnel'];
    $horizon = (int) $parametres['horizon_annees'];
    $admissions = (int) $parametres['admissions_exceptionnelles'];

    if ($pas < 1 || $pas > $horizon) {
        throw new \DomainException("SIM-04 (choc ponctuel) : le pas exceptionnel ({$pas}) doit être compris entre 1 et l'horizon ({$horizon}).");
    }

    if ($admissions < 1) {
        throw new \DomainException("SIM-04 (choc ponctuel) : les admissions exceptionnelles ({$admissions}) doivent être strictement positives — un choc nul relève de la trajectoire de fond.");
    }
}
```

---

## D.3 Correction de cohérence associée

La closure `$simulateurFn` utilisée par l'analyse de sensibilité OAT et le
Monte-Carlo ne recevait auparavant que `$anneeBase`. Elle recalcule une
trajectoire **sans** gel (SIM-03) et, après SIM-04, sans choc. Elle propage
désormais les deux paramètres :

```php
$simulateurFn = function ($cohortes, $delta, $horizon, $taux) use ($anneeBase, $dureeGel, $chocAdmissions) {
    [$m, $s] = $this->simulerVariante($cohortes, $delta, $horizon, $taux, $anneeBase, $dureeGel, $chocAdmissions);

    return $m['total_final'];
};
```

Cette correction aligne les analyses dérivées sur le résultat principal. Elle
ne réécrit aucune exécution persistée (lecture seule sur l'historique).

---

## D.4 Absence de duplication et rétrocompatibilité

- Aucune formule de progression dupliquée : le choc se greffe sur le calcul
  existant.
- Aucune nouvelle table, aucun nouveau service, aucun nouveau contrôleur.
- En l'absence des deux paramètres, le moteur se comporte exactement comme
  en v1.2 (prouvé par les 87 tests antérieurs : 52 historiques + 7 SIM-01 +
  7 SIM-02 + 7 SIM-03 + 14 tests catalogue d'alors).
- Les 7 tests SIM-03 restent verts sans modification.
