# 06 — RAPPORT FINAL DE LA LOOP

> **Périmètre exécuté** : DISCOVERY → AUDIT → MESURE → ANALYSE → PLAN → **CORRECTION LOT 0 (code uniquement, non commitée)**.
> **Phases non exécutées** : réparation des données historiques (bloquée par décisions métier), VALIDATION de migration (sans objet).
> **Date** : 19/10/2026

---

## 1. État initial et périmètre analysé

| Élément | Valeur |
|---|---|
| Framework | Laravel 13.33, PHP 8.5 |
| Base | MySQL `inscription_etudiants`, 182 tables |
| Tables auditées | `inscriptions_academiques` (2 085), `groups` (160), `matieres` (66) |
| Référentiel | 4 programmes, 4 spécialités, 4 mentions, 4 domaines, 12 niveaux |
| Couverture | 1 faculté sur 10 (FST) |
| État du dépôt | Arbre de travail déjà modifié avant l'audit ; aucune de ces modifications n'a été touchée |
| Correctif LOT 0 | **Implémenté dans l'arbre de travail, non commité ; 0 donnée modifiée** |

---

## 2. Méthodologie et requêtes de mesure

- Phase DISCOVERY : inspection du schéma réel (`information_schema`), des modèles, migrations, services, imports, exports, vues, policies, seeds et tests.
- Phase AUDIT : uniquement des `SELECT`. Aucun `INSERT`, `UPDATE`, `DELETE` ou DDL.
- Normalisation documentée et déterministe (cf. `02-RESULTATS.md` §1.2).
- Script reproductible : `docs/audit-referentiel/mesure.php`.

Commande de reproduction :

```bash
php artisan tinker --execute="require 'docs/audit-referentiel/mesure.php';"
```

Sorties : `03-ANOMALIES.csv` (75 lignes), `04-STATISTIQUES.json`.

---

## 3. Statistiques avant / après (données)

**Le LOT 0 est un correctif de code, pas une migration de données.** Les compteurs
de données restent donc identiques : c'est un résultat honnête. Les 10 lignes
historiques #2074 à #2083 ne sont **pas** réparées par ce lot.

| Indicateur | Avant | Après |
|---|---:|---:|
| Inscriptions sans `programme_id` | 2 073 / 2 085 (99,4 %) | identique |
| Inscriptions sans `filiere` | 2 014 / 2 085 (96,6 %) | identique |
| Étudiants sans `programme_id` | 2 047 / 2 047 (100 %) | identique |
| Groupes sans `programme_id` | 153 / 160 (95,6 %) | identique |
| Matières avec `filiere` | 0 / 66 | identique |
| Incohérences `niveau_id` historiques | **10** | identique (non réparées) |
| Facultés couvertes par le référentiel | 1 / 10 | identique |

### Effet du correctif sur les nouveaux imports

| Indicateur | Avant LOT 0 | Après LOT 0 |
|---|---|---|
| Import `PAR-GEST` + `L1` | `niveau_id` = `L1` de `DEMO-LGL` (id 1) | `niveau_id` = `L1` de `PAR-GEST` (id 4) |
| Import `PAR-GEST` + `L2` (niveau d'un autre programme) | accepté silencieusement | refusé (`NIVEAU_HORS_PROGRAMME`) |
| Résolution globale `L1` | premier `L1` venu (`DEMO-LGL`) | `NULL` (ambiguïté détectée) |
| Nouvelles inscriptions incohérentes après import de contrôle | 1 par ligne | **0** |

---

## 4. Fichiers modifiés et migrations ajoutées

### Fichiers créés

| Fichier | Rôle |
|---|---|
| `docs/audit-referentiel/01-CARTOGRAPHIE.md` | Cartographie complète |
| `docs/audit-referentiel/02-RESULTATS.md` | Résultats mesurés |
| `docs/audit-referentiel/03-ANOMALIES.csv` | 75 anomalies nominatives par identifiant technique |
| `docs/audit-referentiel/04-STATISTIQUES.json` | Indicateurs machine |
| `docs/audit-referentiel/05-PLAN-MIGRATION.md` | Plan par lots |
| `docs/audit-referentiel/06-RAPPORT-FINAL.md` | Ce rapport |
| `docs/audit-referentiel/mesure.php` | Script de mesure reproductible |
| `docs/REFERENTIEL-CORRESPONDANCE.md` | Matrice et décisions à signer |
| `tests/Feature/ImportNiveauProgrammeTest.php` | 10 tests de non-régression du LOT 0 |

### Migrations ajoutées

**Aucune.**

### Code applicatif modifié (LOT 0, non commité)

| Fichier | Modification |
|---|---|
| `app/Modules/Referentiel/Models/Niveau.php` | `resoudreDansProgramme()` + `resoudreCodeUnique()` |
| `app/Services/DataExchange/Definitions/InscriptionAcademiqueDefinition.php` | Résolution bornée + validation `NIVEAU_HORS_PROGRAMME` + refus explicite |
| `app/Services/DataExchange/Definitions/EtudiantDefinition.php` | Validation et persistance bornées |
| `app/Services/DataExchange/Definitions/GroupDefinition.php` | Résolution bornée + refus explicite |
| `app/Services/Io/StudentsImporter.php` | Résolution bornée (import legacy) |
| `app/Services/DataExchange/Definitions/ExamenDefinition.php` | Résolution bornée + refus explicite |
| `app/Services/DataExchange/Definitions/DeliberationDefinition.php` | En-tête borné par le parcours + refus explicite |
| `app/Services/DataExchange/Definitions/NiveauDefinition.php` | Suppression du repli global dans `trouverExistant()` |
| `app/Services/DataExchange/Definitions/SemestreDefinition.php` | Refus d'un code programme introuvable |
| `app/Services/DataExchange/Definitions/ProgressionDefinition.php` | Programme déduit de la dernière inscription ; plus de `first()` global |

> L'arbre de travail contenait déjà des modifications antérieures (dont
> `DecisionJuryDefinition.php` et des parties de `DeliberationDefinition.php`,
> `InscriptionAcademiqueDefinition.php`, `ProgressionDefinition.php`). Elles
> n'ont pas été révoquées ni écrasées ; seules les modifications LOT 0 listées
> ci-dessus sont revendiquées.

### Données modifiées

**Aucune.** Les 10 lignes historiques #2074 à #2083 restent inchangées.

---

## 5. Tests exécutés et résultats réels

### 5.1 Tests LOT 0

```bash
vendor/bin/phpunit --filter="ImportNiveauProgrammeTest" --no-coverage
```

```
tests: 10 · passed: 10 · assertions: 79 · duration: 1 373 ms
```

### 5.2 Ligne de base des quatre suites ciblées (inchangée)

```bash
vendor/bin/phpunit --filter="LmdImportPipelineTest|DataExchangeTest|AcademicExportTest|UniciteInscriptionAnnuelleTest" --no-coverage
```

```
tests: 68 · passed: 68 · assertions: 336
```

Résultat **identique au baseline** : aucune régression sur les zones concernées.

### 5.3 Filtre élargi (imports + référentiel)

```bash
vendor/bin/phpunit --filter="ImportNiveauProgrammeTest|LmdImportPipelineTest|DataExchangeTest|AcademicExportTest|UniciteInscriptionAnnuelleTest|ReinscriptionTest|ReferentielImportBoucle10Test|ReferentielNormalisationImportTest|ImportCenterPhase17Test|ReferentielPhase1Test|ReferentielLot2Test|EtudiantMatriculeNationalTest|CorrecteurImportTest|DoublonsGraduesTest" --no-coverage
```

```
tests: 159 · passed: 159 · assertions: 713
```

Aucun test n'a été désactivé, contourné ou marqué comme réussi sans exécution.
Les résultats ci-dessus sont les seuls revendiqués ; la suite complète n'est
pas présentée comme validée si elle n'a pas été exécutée intégralement.

### 5.4 Suite complète (tentative non concluante)

```bash
vendor/bin/phpunit --no-coverage
```

La suite complète a été lancée mais s'est interrompue sur une erreur fatale du
processus PHP dans `StudentDossierPdfTest::test_admin_voit_boutons_selon_etat`
(« Premature end of PHP process »), sans rapport avec le LOT 0 (test de rendu
PDF). **La suite complète n'est donc pas revendiquée comme validée.** Les
preuves de non-régression utilisées sont les suites ciblées §5.1 à §5.3.

### 5.5 Sous-ensemble élargi hors PDF

```bash
vendor/bin/phpunit --filter="/^(?!.*Pdf).*(Import|Export|Referentiel|Evaluation|Note|Deliberation|Examen|Progression|Groupe|Group|Etudiant|Inscription|Reinscription|DataExchange|Lmd)/" --no-coverage
```

```
tests: 1026 · passed: 1018 · failed: 2 · errors: 3 · skipped: 3 · assertions: 5372
```

Les 5 échecs sont **hors périmètre LOT 0** et proviennent de code déjà modifié
avant ce lot dans l'arbre de travail :

- 2 échecs N+1 dans `FileTraitementController` (26 requêtes au lieu de < 25) ;
- 3 erreurs de publication de planning dans `PortailEtudiantPublicationTest`
  (`ScheduleService` déjà modifié).

Aucun test des imports, du référentiel ou des inscriptions LOT 0 n'est en échec.

---

## 6. Anomalies corrigées et anomalies restantes

### Corrigées

**1 défaut de code (LOT 0)** : la résolution d'un niveau par son seul code.

- `InscriptionAcademiqueDefinition` résout désormais le niveau par `code` **et**
  `programme_id`, et refuse un niveau incompatible avec un programme connu.
- Les chemins étudiants, groupes, examens, délibérations, semestres et
  progressions ont été alignés sur le même résolveur.
- Un import de contrôle `PAR-GEST` + `L1` produit le `L1` de `PAR-GEST`
  (id 4), jamais celui de `DEMO-LGL` (id 1).
- **Les 10 lignes historiques #2074 à #2083 ne sont pas corrigées** : le
  correctif empêche la reproduction du défaut, il ne répare pas l'historique.
  Cette réparation relève du lot 2, avec procédure validée et sauvegarde.

### Restantes

| Catégorie | Nombre | Gravité | Dépend d'une décision métier ? |
|---|---:|---|---|
| `INCOHERENCE_NIVEAU` (inscriptions, historique) | 10 | **Critique** | **Non** — cause code corrigée ; données à rapprocher (lot 2) |
| `NON_RESOLUE` (inscriptions) | 12 | Moyenne | Oui (nature de « Sciences & Technologies ») |
| `NON_RESOLUE` (groupes) | 1 (`MIAGE`) | Moyenne | Oui |
| `HORS_PERIMETRE` (inscriptions) | 49 | Structurelle | Oui (périmètre du référentiel) |
| `CANDIDAT_PARTIEL` (groupes) | 3 | Faible | Oui — jamais converti automatiquement |
| `VIDE` | 2 236 | Structurelle | Partiellement |

### Anomalies hors champ `filiere`

| Anomalie | Nombre | Preuve |
|---|---:|---|
| `matieres` sans UE | 52 / 66 | `04-STATISTIQUES.json` |
| Groupes sans année universitaire | 153 / 160 | Mesure directe |
| Branche morte `$etudiant->filiere` dans le scoring d'affectation | 2 emplacements | `AffectationEngine.php:638`, `MassAssignmentService.php:379` |

---

## 7. Impacts sur les exports et les données historiques

- **Aucun export n'a été modifié.** `AcademicExportEngine`, `StudentsExporter`, `GradesExporter` continuent de lire `inscriptions.filiere`.
- Les exports actuels sont donc **structurellement incomplets** : la colonne « Filière » est vide pour 96,6 % des inscriptions. Ce n'est pas une régression introduite par l'audit, c'est un état antérieur désormais mesuré.
- Aucune donnée historique n'a été supprimée, renommée ou convertie.
- Le champ `filiere` original est préservé intégralement.

---

## 8. Risques résiduels, décisions en attente et plan de reprise

### Risques résiduels

| Risque | Niveau | Commentaire |
|---|---|---|
| Réexécution de l'import d'inscriptions | **Moyen** | Le code ne reproduit plus le défaut ; les 10 lignes historiques restent à rapprocher (lot 2) |
| Import sans programme résolu | Faible | Abstention documentée : `niveau_id = NULL`, aucun mauvais lien inventé |
| `ProgressionDefinition` | Faible | Comportement changé : un étudiant sans programme connu et un code ambigu est refusé explicitement |
| Exports « Synthèse filière » | Moyen | Résultats vides ou partiels, non signalés à l'utilisateur |
| Construction du référentiel sur un vocabulaire erroné | Moyen | D-01 non signée |
| Affectation automatique | Moyen | Bonus « filière concordante » inopérant (branche morte) |

### Décisions en attente

| ID | Décision | Bloque |
|---|---|---|
| D-01 | Signification officielle de « filière » | Lots 1, 2, 3, 5, 6 |
| D-02 | La spécialité est-elle un niveau distinct ? | Lot 6 |
| D-03 | Le parcours figure-t-il sur les documents officiels ? | Lot 6 |
| D-04 | `matieres.filiere` est-il supprimable ? | Lot 4 |
| D-05 | Le référentiel doit-il couvrir les 10 facultés ? | Lot 1 |

### Plan de reprise

1. **Committer le LOT 0** après revue (code uniquement, aucune donnée, aucune migration).
2. Faire signer D-01 et D-05 (blocage principal).
3. Construire le référentiel (lot 1).
4. Rapprocher manuellement, lot par lot, avec sauvegarde et journal — y compris
   la réparation des 10 lignes historiques #2074 à #2083 (lot 2).
5. Ne jamais activer de migration destructive sans backup vérifié et script de retour arrière testé.

---

## 9. Verdict final

# `PASS_WITH_RESERVATIONS`

### Justification

**Ce qui est satisfait :**

- Audit complet, mesuré et reproductible.
- Anomalies prouvées avec identifiants et cause racine.
- **LOT 0 implémenté** : résolution du niveau par `code` + `programme_id`, refus explicite des incompatibilités, 10 tests de non-régression (79 assertions).
- **Ligne de base inchangée** : 68 tests / 336 assertions sur les quatre suites ciblées.
- Aucune donnée modifiée, aucune migration exécutée, aucun test désactivé.
- Plan de migration par lots avec préconditions, critères d'acceptation et retour arrière.

**Ce qui empêche `PASS` :**

- Les décisions métier D-01 à D-05 ne sont pas signées.
- **Les 10 lignes historiques #2074 à #2083 ne sont pas réparées** : le code ne reproduit plus le défaut, mais la donnée historique reste incohérente (lot 2).
- Le référentiel ne couvre qu'une faculté sur dix, ce qui rend tout rapprochement de masse impossible aujourd'hui.
- Le LOT 0 est **non commité** et n'a pas encore été relu en revue de code.

**Ce qui empêche `FAIL` :**

- Aucune perte ni corruption de données.
- Aucun échec de sécurité ou d'autorisation constaté.
- Aucun test essentiel en échec.
- Aucune migration risquée tentée.

### Prochaine action autorisée

1. **Revue puis commit du LOT 0** (code uniquement, aucune donnée, aucune migration).
2. Signature de `docs/REFERENTIEL-CORRESPONDANCE.md` (D-01 à D-05).
3. Lot 1 (référentiel), puis lot 2 (rapprochement des inscriptions, **y compris
   la réparation des 10 lignes historiques**).

Toute correction de données reste suspendue à la signature des décisions métier.
