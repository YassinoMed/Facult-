# SIM-13 — 00 · Audit

**Date :** 2026-11-05  
**Scénario :** SIM-13 — Crise académique combinée  
**Famille :** 2 — Réussite, redoublement et abandon  
**Lot :** A (Admissions, démographie et réussite)  
**Mode :** AUDIT_ONLY préalable  
**Verdict d'audit :** ⛔ **BLOCKED_BUSINESS** — Gate A non validé

---

## A. Périmètre audité

| Élément | Constat |
|---|---|
| **Catalogue** | `CatalogueScenarios` — 45 entrées : SOCLE-EFFECTIFS + SIM-01 → SIM-12 disponibles (**13 disponibles / 32 planifiés**) ; SIM-13 → CR-06 planifiés. SIM-13 est `planifie`, sans version, sans schéma, sans formules. |
| **Moteur** | `ServiceSimulationTemporelle` — version **`1.8`**. Descripteur **mono-axe** livré par SIM-09 : `taux_modifie` + `axe_variation` + `sens_variation`. Une seule clé de réussite est produite par exécution. |
| **SIM-08** | `taux_degrade` accepte déjà un jeu **complet** de taux dégradés ; seule la baisse de `passage` est imposée. La restitution se limite à `baisse_passage`. |
| **Restitution** | `champsSpecifiques()` connaît les six clés `hausse_/baisse_<axe>`, mais `analyserReussite()` n'en produit **qu'une seule** par exécution, celle de l'axe focal. |
| **Tests** | **160 tests / 1763 assertions** au vert à la clôture de SIM-12 ; aucun changement de code depuis. |
| **Tables techniques** | `scenarios_temporels`, `executions_temporelles`, `snapshots_executions`. Aucune table nouvelle identifiée. |
| **Documentation SIM-13** | Aucun dossier `SIM-13/` ni aucune spécification avant le présent audit. |
| **Données métier** | Lecture seule ; aucune écriture, aucune migration. |

**Conclusion de périmètre :** le moteur sait **calculer** un jeu de taux
complets où plusieurs paramètres changent simultanément, mais il ne sait pas
**représenter** ni **restituer** plusieurs axes de variation dans un même
contrat. La question n'est donc pas seulement technique : elle est d'abord
métier.

---

## B. État initial de SIM-13 dans le catalogue

| Attribut | Valeur constatée |
|---|---|
| Code | `SIM-13` |
| Nom | « Crise académique combinée » |
| Catégorie / lot | `reussite_flux` / A |
| Objectif | « Combiner plusieurs dégradations dans les limites du modèle. » |
| Statut | `planifie` |
| Version / formules / schéma | `null` / `null` / `null` |
| Disponibilité | `false` |
| Compatibilité déclarée | `configuration` (préliminaire, non confirmée) |
| Dépendances | `['SIM-07', 'SIM-08', 'SIM-09', 'SIM-10', 'SIM-11', 'SIM-12']` |
| Périmètre | `faculte_ou_global` |

**Source unique :** `CatalogueScenarios::planifies()` —
`app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php`, ligne 1387.

Aucun autre document du dépôt ne définit SIM-13. Les occurrences relevées
dans les dossiers SIM-07 → SIM-12 sont des **renvois** (« SIM-13 traitera les
combinaisons »), jamais une règle, un seuil, un schéma ou un résultat attendu.

---

## C. Analyse du besoin fonctionnel

### C.1 Ce que dit la seule définition disponible

La définition se réduit à une phrase :

> « Combiner plusieurs dégradations dans les limites du modèle. »

Elle ne précise pas :

- **quels axes** sont combinés (`passage`, `redoublement`, `abandon` ? les
  trois ? au moins deux ? un sous-ensemble libre ?) ;
- **quels sens** sont admissibles (une « dégradation » du passage est une
  baisse, une dégradation du redoublement ou de l'abandon est une hausse —
  mais le contrat ne le dit pas) ;
- si chaque dégradation doit être **stricte** ou si une axe peut rester
  inchangé ;
- si les dégradations sont **simultanées** à chaque période ou séquencées
  dans le temps ;
- ce que signifie exactement « **dans les limites du modèle** » : bornes de
  taux et `somme ≤ 1`, ou limites institutionnelles supplémentaires ;
- **ce qui doit être restitué** : une clé jointe, une clé par axe, une
  magnitude de « crise », un simple libellé ?
- si le véhicule est `taux_degrade` (SIM-08) ou un nouveau descripteur
  multi-axes ;
- si la compatibilité `configuration` est confirmée ou doit être reclassée.

Aucune de ces réponses ne peut être déduite des dépendances : SIM-07 → SIM-12
sont des contrats **mono-axe** ; aucun ne définit une combinaison.

### C.2 Ce que le moteur v1.8 sait réellement faire

**Ce qu'il sait calculer.** `simulerVariante()` reçoit un tableau `$taux`
complet (`passage`, `redoublement`, `abandon`) et l'applique à chaque pas.
Rien n'empêche donc un jeu de taux où **plusieurs** paramètres ont changé :
la progression, les arrondis, la conservation et les sortants sont calculés
correctement.

**Ce qu'il ne sait pas représenter.** Le descripteur générique exige un
**seul** couple axe / sens :

```php
// ServiceSimulationTemporelle.php — validation du descripteur
if (self::aVariationGenerique($criteres)) {
    // taux_modifie + axe_variation + sens_variation indissociables
    $axe = (string) $criteres['axe_variation'];   // UN axe
    $sens = (string) $criteres['sens_variation']; // UN sens
    // ...
}
```

La clé produite est unique :

```php
$reussiteCle = $reussiteGenerique['sens'].'_'.$reussiteGenerique['axe'];
$reussiteVariation = /* magnitude sur ce seul axe */;
```

`analyserReussite()` reçoit **une** `$cleVariation` et retourne **une** clé
de variation :

```php
return [
    $cleVariation => round($variationPassage, 4),
    'totaux_reference_reussite' => ...,
    'ecarts_reussite_par_periode' => ...,
    'ecart_reussite_final' => ...,
    'sortants_par_periode' => ...,
    'effet_sortants_cumules' => ...,
];
```

`champsSpecifiques()` peut exposer les six clés possibles, mais **seule celle
qui a été calculée existe** : une exécution SIM-13 ne produirait donc qu'une
seule variation, même si plusieurs taux ont bougé.

**Conséquence :** le moteur peut **simuler** une combinaison, mais il ne peut
pas **nommer ni restituer** plusieurs axes dans une même exécution sans un
changement de contrat (et probablement de version).

### C.3 Ce que SIM-08 permet déjà — et pourquoi cela ne suffit pas

SIM-08 (`taux_degrade`) exige un jeu complet et impose seulement :

- `passage` **strictement inférieur** au passage de référence ;
- `redoublement` et `abandon` **libres** (ils peuvent baisser, rester égaux
  ou augmenter) ;
- somme du jeu dégradé ≤ 1 ;
- restitution `baisse_passage` uniquement.

| Besoin supposé de SIM-13 | Capacité SIM-08 aujourd'hui | Écart |
|---|---|---|
| Saisir plusieurs taux modifiés | Oui, jeu complet | Aucun |
| Imposer plusieurs dégradations | **Non** : seule la baisse de passage est imposée | Règle absente |
| Restituer chaque axe dégradé | **Non** : seule `baisse_passage` est produite | Contrat de sortie absent |
| Nommer la combinaison | **Non** | Contrat absente |
| Distinguer SIM-13 de SIM-08 | **Non** : même entrée, même sortie | Duplication si aucune règle ajoutée |

**Conclusion :** exécuter SIM-13 via `taux_degrade` est techniquement possible
dès aujourd'hui, mais le scénario serait **indistinguable de SIM-08** du point
de vue du contrat. Ajouter une règle (« au moins deux dégradations strictes »,
par exemple) serait une **décision métier non fournie**.

### C.4 Questions à trancher avant toute implémentation

| ID | Question | Nature | Pourquoi c'est bloquant | Ce qui serait inventé si l'on devinait |
|---|---|---|---|---|
| `SIM13-B1` | Quels axes font partie de la combinaison ? | Métier | Le contrat ne liste aucun axe | Un sous-ensemble arbitraire |
| `SIM13-B2` | Quels sens sont admissibles pour chaque axe ? | Métier | « Dégradation » n'est pas défini axe par axe | Une direction arbitraire |
| `SIM13-B3` | Combien de dégradations au minimum ? Sont-elles strictes ? | Métier | « Plusieurs » est ambigu (2 ? 3 ?) | Un seuil inventé |
| `SIM13-B4` | Les dégradations sont-elles simultanées ou séquencées ? | Métier | Le moteur applique des taux constants ; le séquencement relève d'une autre capacité | Un profil temporel inventé |
| `SIM13-B5` | Que doit restituer le scénario ? | Métier / contrat | Une clé jointe, une clé par axe ou un simple libellé ? | Un format de sortie arbitraire |
| `SIM13-B6` | « Dans les limites du modèle » : quelles limites exactement ? | Métier | Bornes de taux et somme ≤ 1, ou limites institutionnelles en plus ? | Une contrainte inventée |
| `SIM13-B7` | La compatibilité `configuration` est-elle confirmée ? | Métier / audit | SIM-08 est `analyse_nouvelle` ; une combinaison multi-axes ne peut pas être une simple configuration | Un reclassement non validé |
| `SIM13-T1` | Véhicule : `taux_degrade` (SIM-08) ou nouveau descripteur multi-axes ? | Technique / contrat | Les deux options ont des conséquences opposées | Une architecture arbitraire |
| `SIM13-T2` | Si extension : version moteur, hashs, rétrocompatibilité ? | Technique | Aucune politique n'est fixée pour un changement multi-axes | Un incrément de version et une rupture de hash non mesurés |
| `SIM13-T3` | Si extension : impact API / UI / permissions ? | Technique | Le catalogue et le contrôleur ne prévoient pas ce contrat | Une surface d'entrée et de sortie non validée |

Tant que `SIM13-B1` → `SIM13-B7` et `SIM13-T1` → `SIM13-T3` ne sont pas
tranchées par une autorité compétente, toute implémentation violerait la
règle de campagne : **ne jamais inventer règles, seuils ou distributions**.

---

## D. Options d'implémentation examinées

### Option A — Contrat catalogue sur `taux_degrade`, moteur inchangé

- **Principe :** `sim13()` publie un schéma fondé sur `taux_degrade` ;
  `validerReglesSim13()` ajoute d'éventuelles contraintes de combinaison ;
  le moteur v1.8 reste inchangé.
- **Avantages :** aucun changement moteur, aucune migration, faible risque.
- **Blocages :**
  1. sans règle supplémentaire, SIM-13 est un **doublon** de SIM-08 ;
  2. avec une règle « au moins deux dégradations », il faut décider des axes,
     des sens et de la strictness — autant de règles non fournies
     (`SIM13-B1` → `SIM13-B3`) ;
  3. la restitution reste `baisse_passage` : la combinaison n'est pas
     visible dans le résultat (`SIM13-B5`) ;
  4. la compatibilité `configuration` n'est pas confirmée (`SIM13-B7`).
- **Conclusion :** **rejetée** en l'état, sans décision métier.

### Option B — Descripteur multi-axes et moteur v1.9

- **Principe :** nouveau contrat (`axes_variation` ou équivalent), validation
  multi-axes, plusieurs clés de restitution, version moteur incrémentée.
- **Ce qu'il faudrait décider :** forme exacte du descripteur, règles de
  validation, clés produites, politique de hash et de rétrocompatibilité,
  impacts API/UI, stratégie de tests.
- **Blocages :** aucune de ces décisions n'est fournie (`SIM13-B1` →
  `SIM13-T3`). Un tel changement peut modifier les hashs et la lecture des
  exécutions existantes ; il exige une mesure d'impact complète.
- **Conclusion :** **rejetée** en l'état ; elle ne peut être ouverte que sur
  décision explicite.

### Option C — Laisser SIM-13 `planifie` et documenter le blocage (retenue)

- **Principe :** aucun code, aucun test, aucun changement de catalogue ; le
  dossier `SIM-13/` porte l'audit et le verdict `BLOCKED_BUSINESS`.
- **Avantages :** aucun rule inventée, aucune fausse promesse de résultat,
  aucune régression, traçabilité complète de la décision manquante.
- **Limite :** SIM-13 reste indisponible ; les utilisateurs ne peuvent pas
  l'exécuter. C'est précisément l'effet attendu d'un blocage métier.
- **Conclusion :** **retenue**.

### Option D — Importer la description de CR-02 comme spécification

La fiche de CR-02 (« Crise de réussite et de rétention ») mentionne :
« Combiner hausse du redoublement, hausse de l'abandon et variation du
passage. » Cette phrase est plus précise, mais :

- CR-02 appartient à la **famille 6** (`crises_resilience`), pas à la
  famille 2 ;
- CR-02 est `planifie` et **dépend de SIM-13** : l'utiliser comme source
  inverserait la dépendance ;
- elle ne définit ni la strictness, ni le minimum d'axes, ni la restitution.

**Conclusion :** **rejetée** ; ce serait une règle inter-scénarios inventée.

### Option E — Enchaîner SIM-07 → SIM-12 dans plusieurs exécutions

- **Principe :** demander à l'utilisateur d'exécuter plusieurs scénarios
  mono-axes et de comparer les résultats.
- **Blocage :** ce n'est pas une exécution combinée ; il n'y a ni trajectoire
  jointe, ni résultat unique, ni hash commun. Le scénario revendiqué ne
  serait pas livré.
- **Conclusion :** **rejetée**.

---

## E. Preuve d'absence de spécification

| Recherche | Résultat |
|---|---|
| `SIM-13` dans `docs/` | Une seule définition métier : la ligne de `planifies()` ; le reste sont des renvois « SIM-13 traitera les combinaisons » dans SIM-07 → SIM-12. |
| Dossier `ST-14/SIM-13/` | Inexistant avant le présent audit. |
| Décision d'architecture / ADR multi-axes | Aucune. |
| Source utilisateur / décision institutionnelle | Aucune définition écrite de la combinaison, des axes, des seuils ou des sorties. |
| Fiche CR-02 | Plus précise, mais autre scénario, autre famille, dépendant de SIM-13 ; non utilisable comme spécification. |
| `UNC-01` / `UNC-02` | Autres scénarios (sensibilité combinée, stress testing), autres familles ; ne définissent pas SIM-13. |

**Conclusion :** l'absence n'est pas un oubli de documentation ; la règle
métier n'existe tout simplement pas dans le corpus disponible.

---

## F. Risques et effets de bord

| ID | Risque | Gravité | Traitement |
|---|---|---|---|
| `SIM13-A1` | Inventer les axes, les sens ou le minimum de dégradations | **HAUTE** | Aucune implémentation ; questions `SIM13-B1` → `SIM13-B3` consignées. |
| `SIM13-A2` | Publier SIM-13 comme doublon de SIM-08 | **HAUTE** | Option A rejetée sans règle supplémentaire ; aucune duplication créée. |
| `SIM13-A3` | Restituer une seule clé et laisser croire à une analyse multi-axes | **HAUTE** | Limite du moteur documentée ; aucune clé jointe inventée. |
| `SIM13-A4` | Incrémenter le moteur en v1.9 sans mesure d'impact sur les hashs | **HAUTE** | Aucun changement moteur ; décision technique `SIM13-T2` requise. |
| `SIM13-A5` | Laisser SIM-13 `planifie` alors que la campagne le sait bloqué | MOYEN | Verdict et registre explicites ; le statut catalogue `bloque_business` existe mais son application modifierait le contrat et les compteurs, elle n'est pas faite sans autorisation. |
| `SIM13-A6` | Confondre SIM-13 avec CR-02 ou UNC-02 | MOYEN | Audit explicite : ce sont des scénarios distincts, dépendants de SIM-13. |
| `SIM13-A7` | Supposer que « limites du modèle » se réduit à `somme ≤ 1` | MOYEN | Question `SIM13-B6` ; aucune contrainte supplémentaire ajoutée. |
| `SIM13-A8` | Supposer que les dégradations sont simultanées alors qu'un séquencement est attendu | MOYEN | Question `SIM13-B4` ; aucune temporalité inventée. |
| `SIM13-A9` | Dégrader la lisibilité du catalogue en multipliant les scénarios proches | FAIBLE | Aucun scénario ajouté ; décision de contrat unique reportée. |

---

## G. Décision et Gate A

- **Gate A :** **NON validé.** Le sous-LOOP ne passe pas en Phase B.
- **Verdict d'audit :** ⛔ **BLOCKED_BUSINESS**.
- **Code / tests / catalogue :** aucun changement. Les compteurs restent
  **13 disponibles / 32 planifiés** (45 entrées) et la suite module reste à
  **160 tests / 1763 assertions**.
- **Statut de SIM-13 :** `planifie` (inchangé). Le statut `bloque_business`
  existe dans le catalogue, mais son application à SIM-13 n'est pas faite :
  elle modifierait le contrat du catalogue et les compteurs, et doit être
  autorisée explicitement.
- **Décisions requises avant reprise :**
  - `SIM13-B1` → `SIM13-B7` (métier) ;
  - `SIM13-T1` → `SIM13-T3` (technique / contrat).
- **Critères de reprise :** une définition écrite, validée par l'autorité
  compétente, couvrant les axes, les sens, la strictness, la temporalité, les
  limites, la restitution, le véhicule et la politique de version. Un nouvel
  audit (Gate A) devra alors confirmer la compatibilité et l'impact.
- **Scénarios indépendants :** autorisés à continuer (règle du registre :
  « un scénario `BLOCKED_BUSINESS` ne bloque pas les scénarios
  indépendants »).
- **Interdits maintenus :** aucune règle inventée, aucune écriture métier,
  aucun commit / push / branche, aucune migration destructive.

---

## H. Références vérifiées

| Référence | Constat |
|---|---|
| `CatalogueScenarios::planifies()` — `SIM-13` | Métadonnées seules : nom, catégorie, lot, objectif, compatibilité `configuration`, dépendances SIM-07→12. |
| `CatalogueScenarios::STATUT_BLOQUE` | Statut `bloque_business` prévu par le catalogue, non appliqué à SIM-13. |
| `ServiceSimulationTemporelle::VERSION_MOTEUR` | `1.8`. |
| `ServiceSimulationTemporelle::aVariationGenerique()` | Détecte le trio `taux_modifie` / `axe_variation` / `sens_variation` ; un seul axe, un seul sens. |
| `ServiceSimulationTemporelle` — bloc `$reussiteCle` | `$reussiteCle = $sens.'_'.$axe` ; une seule clé par exécution. |
| `ServiceSimulationTemporelle::analyserReussite()` | Signature à une `$cleVariation` ; retourne une seule clé de variation. |
| `ServiceSimulationTemporelle::champsSpecifiques()` | Six clés possibles, mais seules celles calculées sont exposées. |
| `ServiceSimulationTemporelle` — garde SIM-08 | `taux_degrade` complet ; `passage` strictement inférieur ; `redoublement` et `abandon` libres. |
| `tests/Feature/SimulationTemporelleCatalogueTest.php` | SIM-13 reste `planifie` et non disponible ; compteurs 13/32. |
| `docs/engineering/simulateur-temporel/ST-14/registre-global.md` | Règle : un scénario `BLOCKED_BUSINESS` ne bloque pas les scénarios indépendants. |
| `docs/engineering/simulateur-temporel/ST-14/00-catalogue-scenarios.md` | Statut `bloque_business` défini comme « hypothèse ou seuil institutionnel non validé ». |

---

**Conclusion :** SIM-13 ne peut pas être spécifié, conçu ni implémenté sans
une décision métier explicite. L'audit conclut à un **BLOCKED_BUSINESS**,
sans modifier le moteur, le catalogue, les tests ou les données.
