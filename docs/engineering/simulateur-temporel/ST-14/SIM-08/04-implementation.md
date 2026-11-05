# SIM-08 — 04 · Implémentation

**Date :** 2026-11-04  
**Moteur :** `ServiceSimulationTemporelle` — version `1.7`  
**Compatibilité :** `analyse_nouvelle` (reclassée depuis `configuration`)

---

## D.1 Fichiers réellement modifiés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/SimulationTemporelle/Services/ServiceSimulationTemporelle.php` | Modifié | Version `1.7` ; garde SIM-08 dans `executer` (exclusion mutuelle ajoutée à la garde SIM-07) ; `$tauxDegrade` ; boucle généralisée (`$tauxSimule`, clé de variation + magnitude) ; `analyserReussite()` généralisé (`$cleVariation`) ; `criteresAnalyses` en `elseif` ; `champsSpecifiques()` += `baisse_passage` ; validation de définition SIM-08. |
| `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php` | Modifié | `tout()` intègre SIM-08 ; `schemaSim08()` ; `sim08()` ; `validerReglesSim08()` ; branche `validerParametres` ; retrait de SIM-08 de `planifies()` (36 planifiés). |
| `tests/Feature/SimulationTemporelleSim08Test.php` | Créé | 7 tests — miroir de SIM-07, références hors moteur, exclusion mutuelle, analyses, sécurité. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | Modifié | Compteurs 9 disponibles / 36 planifiés ; SIM-08 disponible (index 8) ; assertions de schéma ; test dédié (19 tests au total). |

**Aucune nouvelle table, aucun nouveau service, aucune nouvelle route,
aucune nouvelle permission.**

---

## D.2 Détail moteur

### D.2.1 Version et généralisation de `analyserReussite`

```php
public const VERSION_MOTEUR = '1.7';

private function analyserReussite(
    array $totauxSimules,
    array $totauxReference,
    array $sortantsSimules,
    array $sortantsReference,
    string $cleVariation,          // 'hausse_passage' | 'baisse_passage'
    float $variationPassage        // magnitude POSITIVE
): array
```

Le corps est inchangé : seuls le nom du champ de variation et sa valeur
sont paramétrés. Les cinq champs neutres (référence, écarts, écart final,
sortants, effet) sont **partagés** entre SIM-07 et SIM-08 — zéro
duplication.

### D.2.2 Gardes d'exécution

| Bloc | Contrôles |
|---|---|
| SIM-07 (exécuté en premier) | Forme du jeu amélioré, référence requise, incompatibilités gel/choc/cible/creux **et `taux_degrade`** (exclusion mutuelle) |
| SIM-08 | Forme du jeu dégradé, référence requise, incompatibilités gel/choc/cible/creux |

L'ordre chronologique des blocs rend tout contrôle inverse inutile :
`taux_ameliore` + `taux_degrade` simultanés sont rejetés par le bloc SIM-07.

### D.2.3 Boucle des variantes (généralisée)

- `$tauxSimule = $tauxAmeliore ?? $tauxDegrade ?? ($criteres['taux'] ?? null)` ;
- trajectoire témoin déclenchée si `$tauxAmeliore !== null || $tauxDegrade !== null` ;
- `$cleVariation` et `$variationPositive` dérivés de la direction ;
- fusion `analyserReussite()` dans les mesures de chaque variante.

### D.2.4 Analyses cohérentes

`criteresAnalyses['taux'] = taux_degrade` (en `elseif` après le cas
amélioré) : OAT et Monte-Carlo analysent la trajectoire **principale**.

### D.2.5 Validation de définition (`validerCriteres`)

- Bloc SIM-07 étendu : `taux_degrade` présent → refus orientant SIM-08 ;
- Bloc SIM-08 : jeu dégradé complet, bornes [0,1], somme ≤ 1,
  référence requise, `passage` **strictement inférieur**, incompatibilités
  gel (SIM-03), choc (SIM-04), cible (SIM-05), creux (SIM-06).

---

## D.3 Détail catalogue

### D.3.1 Schéma (`schemaSim08`)

`taux` requis (référence) + `taux_degrade` requis (passage/redoublement/
abandon ∈ [0,1]). Gel, choc, cible, creux et `taux_ameliore` ne figurent
pas au schéma : refus structurel (« inconnu ») avant la règle de domaine.

### D.3.2 Règles (`validerReglesSim08`)

1. somme du jeu dégradé ≤ 1 → message `> 1` ;
2. `taux_degrade.passage < taux.passage` → message `strictement inférieur`.

### D.3.3 Définition

`sim08()` : version 1, formules 1.0, compatibilité `analyse_nouvelle`,
dépendance `SOCLE-EFFECTIFS`, statut `disponible`. `planifies()` ne contient
plus SIM-08 : **9 disponibles / 36 planifiés**.

---

## D.4 Absence de duplication

| Élément réutilisé | Origine |
|---|---|
| Progression, conservation, sortants | Moteur (même fonction que SOCLE/SIM-01→07) |
| Machinerie de comparaison | `analyserReussite()` généralisé (SIM-07 + SIM-08) |
| Attributs de variante (admissions, totals) | Moteur |
| Exposition des champs | `champsSpecifiques()` (SIM-05 + SIM-06 + SIM-07 + SIM-08) |

---

## D.5 Compatibilité et versionnement

- Moteur `1.6 → 1.7` : `hash_entrees` inclut la version ; exécutions
  antérieures lisibles, jamais réécrites.
- SIM-07 strictement inchangé : `hausse_passage` toujours produit pour
  `taux_ameliore` (vérifié par sa suite, non modifiée).
- Le champ `baisse_passage` est additif (présent uniquement avec
  `taux_degrade`).
