# SIM-01 — 04 · Implémentation réelle

**Date :** 2026-11-02
**Mode :** AUDIT_ONLY / SIMULATION / TESTING — aucune donnée métier modifiée

---

## E.1 Fichiers modifiés

| Fichier | Nature de la modification |
|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | `VERSION_MOTEUR = 1.1` ; `admissions_par_periode` dans `mesures`, `classement`, `non_conformes` |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | SIM-01 `disponible` ; `schemaSim01()` ; règles communes + règles SIM-01 ; 43 scénarios planifiés |
| `tests/Feature/SimulationTemporelleSim01Test.php` | **Créé** — 7 tests / 56 assertions |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | **Modifié** — 12 tests / 292 assertions (dont 2 dédiés SIM-01) |
| `docs/engineering/simulateur-temporel/ST-14/SIM-01/*` | **Créé** — dossier de preuves |
| `docs/engineering/simulateur-temporel/ST-14/registre-global.md` | Mis à jour |

Aucun autre fichier de production n'a été touché par SIM-01.

---

## E.2 Détail moteur

### E.2.1 Version

```php
public const VERSION_MOTEUR = '1.1';
```

Commentaire associé : exposition de la trajectoire d'admissions, aucune
formule modifiée.

### E.2.2 Trajectoire exposée

Dans `simulerVariante`, après calcul des totaux :

```php
$admissionsParPeriode = [];
foreach (array_slice($snapshots, 1, null, true) as $pas => $snapshot) {
    $admissionsParPeriode[] = [
        'pas' => (int) $pas,
        'admissions' => (int) ($snapshot['admissions'] ?? 0),
    ];
}
```

La valeur est **reprise du snapshot** : aucun recalcul, aucune formule
dupliquée. `mesures` porte le champ.

### E.2.3 Propagation

`classement` et `non_conformes` ajoutent :

```php
'admissions_par_periode' => $v['mesures']['admissions_par_periode'],
```

Les autres consommateurs ne sont pas modifiés.

---

## E.3 Détail catalogue

### E.3.1 Définition SIM-01

- `statut = disponible`
- `version = 1`, `version_formules = 1.0`
- `compatibilite = configuration`
- `dependances = ['SOCLE-EFFECTIFS']`
- `schema = schemaSim01()`

### E.3.2 Schéma

`schemaSim01()` clone `schemaSocle()` et fixe :

```php
$schema['taux']['requis'] = true;
```

Aucune autre clé n'est ajoutée ou retirée. Le schéma du socle reste permissif.

### E.3.3 Règles de validation

`validerParametres` applique :

- `validerReglesCommunes` pour le socle et SIM-01 (bornes, delta dans ses
  bornes, somme des taux, groupes sans niveau) ;
- `validerReglesSim01` en plus : `delta ≥ 0` et `bornes.min ≥ 0`, avec un
  message qui renvoie explicitement à SIM-02.

### E.3.4 Catalogue

- 45 entrées : `SOCLE-EFFECTIFS`, `SIM-01`, 43 scénarios `planifie`.
- `SIM-01` n'apparaît plus dans `planifies()`.
- L'empreinte du catalogue change (nouvelle définition disponible), ce qui est
  attendu et testé.

---

## E.4 Ce qui n'a pas été fait

| Élément | Raison |
|---|---|
| Nouvelle table / migration | Aucune donnée nouvelle à persister |
| Nouveau service / repository | Le moteur existant suffit |
| Nouvelle route | La route d'exécution existante suffit |
| Nouvelle formule | La trajectoire est déjà calculée |
| Correction de l'anomalie « taux absents » | Hors périmètre ; documentée en réserve R1 |
| Écran dédié SIM-01 | Formulaire existant ; Phase H documentaire |
| Modification des seuils d'alerte | Valeurs du moteur, non institutionnelles |

---

## E.5 Traçabilité des exigences

| Exigence | Implémentation | Test |
|---|---|---|
| Admissions par période visibles | `mesures` + `classement` | `test_sim01_croissance_composee...` |
| Aucune formule dupliquée | reprise des snapshots | revue de code + tests de référence |
| Taux requis | `schemaSim01()` | `test_sim01_exige_des_taux_explicites...` |
| Croissance stricte | `validerReglesSim01` | `test_sim01_est_une_configuration_de_croissance_stricte` |
| Déterminisme | hash + graine | `test_sim01_memes_entrees_idempotentes...` |
| Lecture seule | adaptateur inchangé | `test_sim01_zero_ecriture_metier...` |
| Sécurité | gates + périmètre | `test_sim01_permissions_et_isolation_facultaire` |

---

## E.6 Verdict de phase

**Développement : PASS.** Les modifications sont minimales, additives et
alignées sur l'architecture CORE + MODULES. Aucune duplication fonctionnelle.
