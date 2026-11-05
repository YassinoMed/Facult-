# SIM-05 — 04 · Implémentation

**Date :** 2026-11-03  
**Moteur :** `ServiceSimulationTemporelle` — version `1.4`  
**Compatibilité :** `analyse_nouvelle`

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | Modifié | Version `1.4` ; constante `BORNE_TAUX_CIBLE_MIN = 0.0` ; validation `taux_cible_croissance` (type, bornes, supériorité stricte, incompatibilité gel/choc) ; `analyserCibleCroissance()` ; `champsCibleCroissance()` ; exposition dans `classement` / `non_conformes`. |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `tout()` intègre SIM-05 ; `schemaSim05()` ; `sim05()` ; `validerReglesSim05()` ; branche `validerParametres` ; retrait de SIM-05 de `planifies()` (39 planifiés). |
| `tests/Feature/SimulationTemporelleSim05Test.php` | Créé | 7 tests — références calculées hors moteur, contrat, idempotence, sécurité, lecture seule. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | Compteurs 6 disponibles / 39 planifiés ; SIM-05 disponible (index 5) ; nouvelles assertions de schéma ; test dédié des règles SIM-05 (16 tests au total). |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail moteur (`ServiceSimulationTemporelle`)

### D.2.1 Version et constante

```php
public const VERSION_MOTEUR = '1.4';
public const BORNE_TAUX_CIBLE_MIN = 0.0;
```

La borne maximale du taux cible réutilise `BORNE_DELTA_MAX` (2.0) : une
seule référence de borne dans le moteur, pas de seuil dupliqué.

### D.2.2 Validation (`validerCriteres`)

Déclenchée par la **présence** de `taux_cible_croissance`
(`array_key_exists`) :

| Contrôle | Message |
|---|---|
| Type non numérique | `doit être un nombre` |
| Non fini | `non fini refusé` |
| Hors `[0 ; 2.0]` | `hors bornes` |
| Avec `duree_gel_annees` | `incompatible ... SIM-03` |
| Avec choc ponctuel | `incompatible ... SIM-04` |
| `delta < 0` | `utiliser SIM-02` |
| `delta ≤ cible` | `strictement supérieur` |

### D.2.3 Calcul (`analyserCibleCroissance`)

```php
private function analyserCibleCroissance(array $admissionsParPeriode, float $baseL1, float $tauxCible): array
```

- reprend `admissions_par_periode` **tel quel** (sortie du moteur) ;
- calcule `admissions_cible = round(baseL1 × (1 + tauxCible)^pas)` ;
- produit les quatre champs de cible ;
- **ne modifie rien** : ni cohortes, ni snapshots, ni totaux.

### D.2.4 Intégration dans `executer()`

- `$tauxCibleCroissance` lu une seule fois ; `$baseL1` lu depuis
  `etat_initial.cohortes` (premier niveau).
- Après `simulerVariante` : fusion `+=` des quatre champs — aucune clé
  existante ne peut être écrasée.
- `classement` et `non_conformes` exposent les champs via
  `champsCibleCroissance()` : **présents uniquement si la variante les
  porte**. Les scénarios SOCLE / SIM-01→04 conservent exactement leur forme
  antérieure.
- Sensibilité et Monte-Carlo inchangés : ils n'utilisent que `total_final`.
- **Note de version (SIM-06, v1.5) :** ce helper a été renommé
  `champsSpecifiques()` pour couvrir aussi les champs de récupération ; le
  comportement SIM-05 est strictement inchangé.

---

## D.3 Détail catalogue (`CatalogueScenarios`)

### D.3.1 Schéma

```php
public static function schemaSim05(): array
{
    $schema = self::schemaAvecTauxRequis('SIM-05');
    $schema['taux_cible_croissance'] = [
        'type' => 'number', 'requis' => true,
        'min' => 0.0, 'max' => ServiceSimulationTemporelle::BORNE_DELTA_MAX,
        'unite' => 'ratio annuel composé',
        ...
    ];
    return $schema;
}
```

Le schéma n'expose ni `duree_gel_annees` ni `pas_exceptionnel` : toute clé
inconnue est refusée par `validerTableau` (message `inconnu`). La règle de
domaine est donc appliquée **structurellement**, puis explicitement.

### D.3.2 Règles de domaine

`validerReglesSim05()` refuse, dans cet ordre :

1. `delta < 0` → orientation SIM-02 ;
2. `bornes.min < 0` → orientation SIM-02 ;
3. `delta ≤ cible` → `strictement supérieur`.

### D.3.3 Définition

`sim05()` déclare : version 1, formules 1.0, compatibilité
`analyse_nouvelle`, dépendance `SOCLE-EFFECTIFS`, statut `disponible`.
`planifies()` ne contient plus SIM-05 : **6 disponibles / 39 planifiés**.

---

## D.4 Absence de duplication

| Élément réutilisé | Origine |
|---|---|
| Formule de composition `(1 + taux)^pas` | Trajectoire réelle du moteur (même expression) |
| `baseL1` | État initial de l'adaptateur (même lecture que SIM-01→04) |
| Admissions par période | Snapshot moteur (`admissions_par_periode`) |
| Idempotence, hash, snapshots | `executer()` inchangé |
| Capacités, budget, alertes | Estimateurs existants, aucun recalcul |
| Bornes | `BORNE_TAUX_CIBLE_MIN` + `BORNE_DELTA_MAX` |

Aucun service parallèle, aucun helper dupliqué, aucune formule recopiée.

---

## D.5 Compatibilité et versionnement

- Moteur `1.3 → 1.4` : `hash_entrees` inclut la version ; une exécution
  v1.3 ne peut donc pas être confondue avec une exécution v1.4.
- Les exécutions historiques restent stockées telles quelles : jamais
  rejouées, jamais réécrites.
- Les quatre nouveaux champs sont **additifs** : aucune clé retirée,
  aucun type modifié.
