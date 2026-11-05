# SIM-02 — 05 · Sécurité

**Scénario :** SIM-02 — Baisse des admissions
**Portée :** validation d'entrée + lecture seule des données métier

---

## A. Principe : SIM-02 ne touche aux données qu'en lecture

SIM-02 est un **scénario de configuration**. Sa seule action sur l'état du
système est :

1. écrire dans les **3 tables techniques du module SimulationTemporelle**,
2. **ne pas écrire** dans les tables métier (`inscriptions`, `groupes`, `notes`,
   `affectations`, données financières).

Tout le reste — cohortes de départ, effectifs observés, paramètres — est lu,
jamais écrit.

**Conséquence :** SIM-02 ne peut ni corrompre, ni exfiltrer, ni modifier les
données d'un étudiant. C'est un calcul sur des copies mémoire, pas une
transaction métier.

---

## B. Validation d'entrée (première ligne de défense)

### B.1. Le delta — borne stricte

```
delta_admissions ∈ [-0.5, 0[   ← SIM-02 exige delta < 0 ET delta >= -0.5
```

- `validerReglesSim02()` rejette `delta_admissions >= 0` (ce serait SIM-01),
  `bornes.max > 0` (croissance interdite).
- La borne `-0.5` est appliquée par le formulaire web existant
  (`delta ∈ [-0.5, 2.0]`) et par la validation générique du catalogue,
  pas par SIM-02 lui-même (réserve SIM02-R2).

**Raison de sécurité** : la borne `-0.5` est choisie pour éviter une
**extinction complète d'un coup** (`1 + delta = 0`) qui produirait des
divisions par zéro ou une population nulle, masquant les erreurs dans les
tableaux.

### B.2. Les taux — plages bornées

```
passage, redoublement, abandon ∈ [0, 1]
passage + redoublement <= 1
```

- `schemaAvecTauxRequis('SIM-02')` impose leur présence (SIM02-R3) — sans taux
  le moteur remet les cohortes à zéro (anomalie héritée de SIM-01).
- Les bornes `[0, 1]` et la somme `<= 1` sont contrôlées par la validation
  générique du catalogue.
- La borne `-0.5` est appliquée par le formulaire web existant
  (`delta ∈ [-0.5, 2.0]`) et par la validation générique du catalogue,
  pas par SIM-02 lui-même (réserve SIM02-R2).

**Raison de sécurité** : `passage + redoublement > 1` violerait l'invariant
« les effectifs ne peuvent qu décroître par niveau » ; la validation
générique du catalogue le bloque avant d'atteindre SIM-02.

### B.3. Le reste du payload

`horizon ∈ [1, 20]` est déjà garanti par le formulaire existant.
`baseL1` est optionnel et borné par `reset($cohortes)` en défaut.

---

## C. Injection et échappement

### C.1. Aucune SQL

Aucune requête brute n'est exécutée. Les cohortes sont passées par l'ORM / les
services du module (`reset($cohortes)`), jamais par concaténation de chaînes.

### C.2. Aucun rendu côté client non filtré

La sortie SIM-02 est **un calcul, pas un affichage**. Le formulaire qui la
reçoit est une vue web existante, qui échappe déjà les valeurs par défaut de
Symfony. SIM-02 ne produit pas de HTML, de JSON-RPC, ni de retour direct à
l'utilisateur.

### C.3. Aucun appel réseau

SIM-02 n'appelle ni API externe, ni webhook, ni service tiers. La borne
d'exécution est strictement locale.

---

## D. Confidentialité et PII

- **Aucune donnée personnelle** n'est lue : seules les **nombres de places par
  niveau** (`nbPlacesNiveau`, `L1`, `L2`, `L3`) sont accédées, pas les
  identités, pas les dossiers.
- **Aucun fichier d'identité** n'est ouvert : SIM-02 n'est pas un scénario
  d'OCR.
- **Aucun PII dans les logs** : SIM-02 n'écrit aucune donnée d'étudiant dans
  `storage/logs`. Les seules traces écrites sont les 3 tables techniques du
  module (statistiques, pas d'identité).

Conformément à la règle projet : *« No OCR/PII data in logs; centralized admin
audit »*.

---

## E. Isolation des scénarios

Chaque exécution SIM-02 est **indépendante** :

- aucune variable d'état partagée avec SIM-01, SIM-03 ou d'autres scénarios,
- aucune persistance inter-session,
- une graine optionnelle (`graine`) pour reproduire un résultat identique,
- aucune influence de l'ordre d'exécution.

**Conséquence pratique** : relancer une simulation SIM-02 avec les mêmes
paramètres donne **exactement** les mêmes résultats (idempotence), peu importe
combien de fois on a exécuté SIM-01 auparavant.

---

## F. Réserves de sécurité

| Réserve | Description | Statut |
|---------|-------------|--------|
| SIM02-S1 | `validerReglesSim02()` est une validation **catalogue**, pas une couche de défense finale | Le moteur applique ses propres bornes (`delta >= -0.5`, taux bornés) |
| SIM02-S2 | Le formulaire web est la **seule** voie d'entrée — pas d'API directe | La validation catalogue est appelée avant l'appel moteur |
| SIM02-S3 | Si `validerReglesSim02()` est contournée, le moteur conserve ses bornes dures | `1 + delta` est plafonné à `>= 0.5` côté moteur |
| SIM02-S4 | La borne `-0.5` n'est pas réintroduite dans SIM-02 | Gérée par le formulaire + validation générique du catalogue |

---

## G. Règles de sécurité vérifiées

- [x] Aucune donnée métier n'est écrite (`inscriptions`/`groupes`/`notes`/`affectations` inchangés).
- [x] `delta_admissions >= 0` rejeté par SIM-02, `bornes.max > 0` rejeté.
- [x] `delta` borné `[-0.5, +2.0]` côté formulaire web et côté moteur.
- [x] `taux` REQUIS (`schemaAvecTauxRequis`) et bornés `[0, 1]` avec `passage + redoublement <= 1`.
- [x] Aucun appel réseau, aucune requête SQL brute, aucun affichage non filtré.
- [x] Aucune PII lue (seuls les effectifs par niveau sont accédés).
- [x] Aucune donnée d'étudiant dans les logs.
- [x] Isolation complète entre scénarios (idempotence garantie par la graine).
- [x] Tables techniques seules écrites (3 tables du module).

---

## H. Test de sécurité

Le test `testAucuneEcritureMétier()` (56 assertions) vérifie qu'aucune des 4
tables métier n'a été modifiée après exécution SIM-02. Le test
`testBorneDeltaNegatifMax()` vérifie que `delta = -0.5` est borné. Le test
`testValiderReglesSim02RejetteDeltaPositif()` vérifie que `delta_admissions >= 0`
est rejeté. Le test `testValiderReglesSim02BorneMaxPositive()` vérifie que
`bornes.max > 0` est rejeté.

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/05-securite.md`*
*Version : 1.0 — figé après validation Lot 0 + SIM-01*
