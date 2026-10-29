# 05 — PLAN DE MIGRATION PAR LOTS

> **Principe directeur** : aucune donnée historique n'est supprimée ni convertie automatiquement. Le référentiel devient la source de vérité **pour les nouvelles données** dès validation, puis les données historiques sont rapprochées lot par lot, avec preuve et retour arrière.

---

## 0. Préconditions générales

| Précondition | État au 19/10/2026 |
|---|---|
| Audit mesuré et reproductible | **Satisfait** (`02-RESULTATS.md`, `mesure.php`) |
| Sauvegarde vérifiée avant toute écriture | **À faire** au démarrage du lot concerné |
| Décision D-01 (nature de la filière) | **En attente** |
| Décision D-05 (périmètre du référentiel) | **En attente** |
| Référentiel couvrant les facultés concernées | **Non** (1 faculté sur 10) |

**Conséquence** : les lots 1 à 6 sont bloqués. Le lot 0 est **implémenté dans l'arbre de travail (non commité)** ; il ne dépend d'aucune décision métier.

---

## LOT 0 — Correctif de bornage `niveau_id` (indépendant du métier)

> **État au 19/10/2026** : **implémenté dans l'arbre de travail, non commité**. Aucune donnée historique n'a été modifiée.

### Problème démontré

`InscriptionAcademiqueDefinition.php` résolvait `niveau_id` par le seul code :

```php
Niveau::where('code', trim((string) $ligne['niveau']))->first()
```

Or `niveaux.code` n'est unique que **par programme** (`UNIQUE(programme_id, code)`) :
quatre `L1` distincts coexistent. Le `first()` global retournait systématiquement
le niveau du premier programme (`DEMO-LGL`, ids 1 et 2), d'où 10 inscriptions
incohérentes (#2074 à #2083).

### Actions réalisées

1. **Résolveur unique dans le modèle `Niveau`** :
   - `Niveau::resoudreDansProgramme(?string $code, ?int $programmeId)` : recherche
     bornée par `programme_id`, normalisation `mb_strtoupper` + `trim` ;
   - `Niveau::resoudreCodeUnique(?string $code)` : repli **sans programme**
     uniquement si le code désigne un niveau globalement unique, sinon `null`
     (jamais de sélection arbitraire).
2. **`InscriptionAcademiqueDefinition`** :
   - validation métier `NIVEAU_HORS_PROGRAMME` au preview quand le programme
     est connu et que le niveau n'y appartient pas ;
   - `persister()` utilise le résolveur borné et refuse explicitement un niveau
     inexistant dans le programme ;
   - sans programme résolu, aucun lien LMD n'est inventé (`niveau_id = NULL`),
     le texte legacy `niveau` est conservé.
3. **Autres chemins alignés** :
   - `EtudiantDefinition` : validation + persistance bornées ;
   - `GroupDefinition` : résolution bornée, refus explicite ;
   - `StudentsImporter` (legacy) : résolution bornée ;
   - `ExamenDefinition` : refus explicite si le niveau n'appartient pas au
     programme ;
   - `DeliberationDefinition` : en-tête de délibération borné par le parcours ;
   - `NiveauDefinition::trouverExistant` : plus de repli global quand un
     programme est renseigné ;
   - `SemestreDefinition` : un code programme renseigné mais introuvable est
     désormais refusé (au lieu d'appliquer le semestre à tous les programmes) ;
   - `ProgressionDefinition` : programme déduit de la dernière inscription de
     l'étudiant ; sans programme, seul un code globalement unique est accepté.

### Chemins identifiés mais non modifiés

| Chemin | Raison |
|---|---|
| `SemestreDefinition` sans colonne `programme` | Sémantique volontaire d'application à **tous** les niveaux homonymes ; ce n'est pas une sélection d'un niveau unique. Le cas « programme renseigné mais introuvable » est désormais refusé. |
| `RegistrationApiController::reenroll` (première inscription) | N'est pas un import ; `ReinscriptionService` contrôle déjà la cohérence pour les réinscriptions. La première inscription API reste à couvrir dans un lot dédié. |
| `UpdateInscriptionAcademiqueRequest` | Ne permet pas de modifier `programme_id` / `niveau_id` ; aucune surface de création. |

### Périmètre

- Code applicatif uniquement. **Aucune écriture en base, aucune migration.**
- Les 10 lignes historiques #2074 à #2083 restent **en l'état** : le correctif
  empêche la reproduction du défaut, il ne répare pas l'historique.
- Le champ `filiere` et les exports ne sont pas modifiés.

### Tests ajoutés

`tests/Feature/ImportNiveauProgrammeTest.php` — 10 tests, 79 assertions :

| Test | Attendu |
|---|---|
| Résolveur avec deux `L1` de programmes différents | Chaque `programme_id` retourne **son** niveau ; aucun `first()` global |
| Import inscription `PAR-GEST` + `L1` | `niveau_id` = `L1` de `PAR-GEST`, jamais celui de `DEMO-LGL` |
| Import inscription `PAR-GEST` + `L2` (existant seulement dans `DEMO-LGL`) | Erreur `NIVEAU_HORS_PROGRAMME`, aucune écriture |
| Import sans programme avec deux `L1` | `niveau_id = NULL`, aucun choix arbitraire |
| Import répété | 1 seule inscription, même `niveau_id` |
| Lot mixte valide / invalide | Seule la ligne valide est écrite, avec le bon niveau |
| Import étudiant `PAR-GEST` + `L1` / `L2` | Bon niveau / refus explicite |
| Import groupe `PAR-GEST` + `L1` / `L2` | Bon niveau / refus explicite à la confirmation |

### Résultats réels

| Commande | Résultat |
|---|---|
| `--filter="ImportNiveauProgrammeTest"` | **10 tests / 79 assertions**, 10 réussis |
| `--filter="LmdImportPipelineTest|DataExchangeTest|AcademicExportTest|UniciteInscriptionAnnuelleTest"` | **68 tests / 336 assertions**, identique au baseline |
| Filtre élargi (14 classes import/référentiel) | **159 tests / 713 assertions**, 159 réussis |

### Critères d'acceptation

- [x] Le niveau est résolu par `code` **et** `programme_id`.
- [x] Aucun `first()` global sur un code de niveau dans les imports corrigés.
- [x] Un niveau incompatible avec un programme connu est refusé explicitement.
- [x] Aucun repli silencieux vers le premier niveau.
- [x] Les 68 tests ciblés existants passent toujours (336 assertions).
- [x] Transactions et rollback inchangés (aucune transaction retirée).
- [x] Autorisations et périmètre faculté inchangés.
- [ ] Les 10 lignes historiques sont réparées → **hors LOT 0**, procédure séparée (lot 2).

### Réserves

- Le correctif est **non commité** ; l'arbre de travail contenait déjà des
  modifications antérieures qui n'ont pas été touchées.
- Sans programme résolu (ex. `filiere` contenant un libellé et non un code),
  l'import ne pose pas de lien LMD (`niveau_id = NULL`) au lieu de deviner.
  C'est une abstention documentée, pas un repli.
- `ProgressionDefinition` change de comportement : un étudiant sans programme
  connu et un code de niveau ambigu est désormais refusé explicitement.
- La réparation des 10 lignes historiques reste bloquée par une procédure de
  rapprochement validée (lot 2).

### Retour arrière

Révert des fichiers de code listés. Aucune donnée modifiée, donc aucune
restauration nécessaire.

---

## LOT 1 — Construire le référentiel manquant

### Problème

9 facultés sur 10 n'ont **aucun** programme. Sans référentiel, aucun rapprochement n'est possible.

### Actions

1. La scolarité fournit, par faculté : domaine, mention, spécialité, programme, niveaux.
2. Import via `DataExchange` (`programmes`, `niveaux`, `ues`, `ecues`) — pipeline preview → confirmation.
3. Contrôles d'intégrité :
   - `specialite_id` et `departement_id` de la même faculté que le programme (règle applicative existante, à renforcer par un test) ;
   - codes uniques par faculté ;
   - niveaux cohérents (`L1..L3`, `M1..M2`) selon `type_diplome`.
4. Aucune donnée étudiante touchée.

### Critères d'acceptation

- 10/10 facultés avec au moins un programme actif.
- 0 programme dont la spécialité ou le département appartient à une autre faculté.
- Rapport d'import conservé.

### Retour arrière

Suppression des programmes créés (FK `restrict`, donc possible uniquement s'ils ne sont pas encore référencés). Sauvegarde préalable obligatoire.

---

## LOT 2 — Rapprocher les inscriptions (manuel, jamais automatique)

### Problème

2 073 inscriptions sur 2 085 sans `programme_id`. Le texte `filiere` ne permet pas de les résoudre (96,8 % vide, 69 % hors périmètre).

### Principe

Aucune correspondance par similarité. Table de correspondance explicite :

```
programme_correspondances
  faculte_id
  valeur_historique   (ex. "Sciences & Technologies")
  programme_id
  valide_par          (utilisateur)
  valide_le
  justification
  UNIQUE(faculte_id, valeur_historique)
```

### Séquence

1. **Backup** complet + empreinte SHA-256.
2. **Prévisualisation** : rapport `valeur historique → programme proposé`, sans écriture.
3. **Validation humaine** ligne à ligne des correspondances (pas de validation en masse d'une valeur ambiguë).
4. **Écriture transactionnelle** par lot de 100 lignes maximum, avec journal d'audit (`AuditService::tracer`, source `import`).
5. **Contrôle post-lot** : nombre de lignes mises à jour = nombre attendu ; 0 ligne hors périmètre.
6. **Reprise** : le lot reprend à la première ligne non traitée (idempotence par `id`).

### Critères d'acceptation

- 100 % des lignes traitées ont un `programme_id` **et** un `niveau_id` cohérents.
- 0 correspondance issue d'une similarité textuelle non validée.
- Le champ `filiere` original est **conservé**.
- Journal d'audit complet, sans PII.

### Retour arrière

Rejouer le backup, ou `UPDATE ... SET programme_id = NULL WHERE lot = X` (script fourni et testé sur base de recette).

### Blocage actuel

Impossible tant que le lot 1 n'est pas terminé et que D-01 n'est pas signée.

---

## LOT 3 — Rapprocher les groupes

### Problème

153/160 groupes sans `programme_id`, 159/160 sans `niveau_id`. 4 valeurs texte seulement.

### Actions

1. Résoudre les 4 valeurs (`Informatique` ×2, `Génie Logiciel`, `MIAGE`) avec la scolarité.
2. Renseigner `programme_id`, `niveau_id` et `annee_universitaire_id` (153 NULL).
3. Vérifier que `groups.faculte_id = programmes.faculte_id`.

### Critère d'acceptation

- 100 % des groupes actifs ont un programme et une année.
- 0 groupe dont le programme appartient à une autre faculté.

### Blocage

Dépend du lot 1 (référentiel) et de D-01.

---

## LOT 4 — Geler puis retirer `matieres.filiere`

### Problème

0 valeur sur 66. Champ mort, mais encore présent dans les formulaires et les règles de validation.

### Séquence

1. **Geler l'écriture** : retirer `filiere` des `StoreMatiereRequest` / `UpdateMatiereRequest` (phase non destructive, réversible).
2. Attendre un cycle complet (une année universitaire) pour vérifier qu'aucun usage ne réapparaît.
3. **Supprimer la colonne** uniquement après D-04 signée et sauvegarde vérifiée.

### Critère d'acceptation

- Aucune référence à `matieres.filiere` dans le code (hors migrations historiques).
- Tests verts.

### Retour arrière

Étape 1 : révert du code. Étape 3 : restauration de la colonne depuis le backup (destructive, donc jamais improvisée).

---

## LOT 5 — Exports : introduire la dimension canonique

### Problème

`AcademicExportEngine` filtre et regroupe sur `inscriptions.filiere` avec `LIKE '%valeur%'` — donc sur un champ vide à 96,8 %.

### Actions

1. Ajouter une dimension `programme` (code + libellé) issue de `programme_id`.
2. **Conserver** les en-têtes `filiere` tant que D-01 n'est pas signée.
3. Comparer ancien/nouveau résultat sur un jeu figé (golden file).
4. Documenter chaque écart.
5. Interdire le double comptage : un étudiant ne doit apparaître que dans un seul programme par année.

### Critère d'acceptation

- Exports historiques identiques tant que la source n'est pas basculée.
- Après bascule : 0 ligne en double, 0 omission, écart documenté et justifié.

### Blocage

Dépend de D-01 (signification de « filière ») et du lot 2.

---

## LOT 6 — Libellés paramétrables par établissement

### Problème

Les libellés sont en dur dans `EntitesReferentiel.php`, les vues et les exports.

### Actions

1. Créer une configuration de vocabulaire (`config/vocabulaire.php` ou table `parametres`).
2. Remplacer les chaînes dans les vues et les en-têtes par des clés.
3. Valeur par défaut = vocabulaire actuel, pour zéro régression.

### Critère d'acceptation

- Bascule d'un établissement à l'autre sans modification de code.
- Aucune vue ne contient plus « Filière » en dur.

### Blocage

Dépend de D-01 à D-03.

---

## 9. Matrice des risques

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Rapprochement automatique erroné | Élevée si non encadré | Corruption silencieuse | Interdiction de la similarité ; validation humaine |
| Référentiel construit sur un vocabulaire erroné | Moyenne | Refonte coûteuse | D-01 signée avant lot 1 |
| Exports faux après bascule | Moyenne | Incident institutionnel | Golden files + comparaison avant/après |
| Perte de données historiques | Faible | Critique | Aucune suppression ; backup + rollback testé |
| Double comptage dans les synthèses | Moyenne | Chiffres faux | Contrainte d'unicité par (étudiant, année, programme) |
| Fuite de PII dans les journaux | Faible | Critique | Audit limité aux identifiants techniques |

---

## 10. Ordre d'exécution recommandé

```
LOT 0 (implémenté, non commité — sans décision métier)
   ↓
D-01 + D-05 signées
   ↓
LOT 1 (référentiel complet)
   ↓
LOT 2 (inscriptions) → LOT 3 (groupes) → LOT 5 (exports)
   ↓
D-04 signée → LOT 4 (matières)
   ↓
D-02 + D-03 signées → LOT 6 (libellés)
```

Chaque lot est indépendant, testé, et peut être arrêté sans laisser la base dans un état incohérent.
