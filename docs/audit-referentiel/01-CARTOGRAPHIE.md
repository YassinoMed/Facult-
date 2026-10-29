# 01 — CARTOGRAPHIE DU RÉFÉRENTIEL ACADÉMIQUE

> **Phase** : DISCOVERY — lecture seule, aucune modification de code ni de données.
> **Date** : 19/10/2026
> **Base mesurée** : MySQL `inscription_etudiants` (182 tables).
> **État du dépôt** : arbre de travail déjà modifié avant l'audit (nombreux fichiers non commités). Les références `fichier:ligne` portent sur l'état courant du répertoire de travail, pas sur `HEAD`.
> **Preuve d'exécution** : `php artisan tinker --execute="require 'docs/audit-referentiel/mesure.php';"`

---

## 1. Périmètre

| Objet | Rôle dans le périmètre |
|---|---|
| `programmes` | Référentiel des programmes / parcours (4 lignes) |
| `specialites` | Spécialités rattachées aux mentions (4 lignes) |
| `inscriptions_academiques.filiere` | Valeur historique textuelle (2 085 lignes) |
| `groups.filiere` | Rattachement historique des groupes (160 lignes) |
| `matieres.filiere` | Rattachement historique des matières (66 lignes) |
| `AcademicExportEngine` | Exports et synthèses « filière » |

Le reste de la chaîne (`facultes`, `departements`, `domaines`, `mentions`, `niveaux`, `semestres_lmd`, `unites_enseignement`) est cartographié comme dépendance.

---

## 2. Schéma réel (mesuré, pas supposé)

### 2.1 Référentiel

```
facultes (10)
  └── departements (4)        faculte_id NOT NULL
  └── domaines (4)            faculte_id NOT NULL
        └── mentions (4)      domaine_id NOT NULL
              └── specialites (4)  mention_id NOT NULL
programmes (4)                faculte_id NOT NULL
                              departement_id NULL
                              specialite_id NULL
                              type_diplome_id NULL
  └── niveaux (12)            programme_id NOT NULL
        └── semestres_lmd (24)  niveau_id NOT NULL
              └── unites_enseignement (7)  semestre_id + programme_id NOT NULL
                    └── matieres (66)      ue_id NULL (52/66 non rattachées)
```

Contraintes d'unicité :

| Table | Contrainte |
|---|---|
| `programmes` | `UNIQUE(faculte_id, code)` |
| `specialites` | `UNIQUE(mention_id, code)` |
| `departements` | `UNIQUE(faculte_id, code)` |
| `domaines` | `UNIQUE(faculte_id, code)` |
| `mentions` | `UNIQUE(domaine_id, code)` |
| `niveaux` | `UNIQUE(programme_id, code)` |

Point capital : **`niveaux.code` n'est unique que par programme**. Un `L1` existe 4 fois (un par programme). Toute résolution `Niveau::where('code', 'L1')` non bornée par `programme_id` est ambiguë.

Aucune contrainte SQL n'impose que `specialite_id` ou `departement_id` d'un programme appartiennent à la même faculté ; la règle n'existe qu'en application (`app/Modules/Referentiel/Controllers/ReferentielController.php:593`).

### 2.2 Données historiques

| Table | Colonne `filiere` | Colonne de rattachement canonique | Volume |
|---|---|---|---|
| `inscriptions_academiques` | `varchar(150) NULL` | `programme_id` nullable, `niveau_id` nullable | 2 085 |
| `groups` | `varchar(150) NULL` | `programme_id` nullable, `niveau_id` nullable | 160 |
| `matieres` | `varchar(150) NULL` | `ue_id` nullable (pas de `programme_id`) | 66 |
| `etudiants` | **absente** | `programme_id` nullable, `niveau_id` nullable | 2 047 |

`matieres` n'a **aucune** clé étrangère vers `programmes` : la seule voie est `matieres.ue_id → unites_enseignement.programme_id`, et elle est vide dans 52 cas sur 66.

---

## 3. Modèles Eloquent

| Modèle | Table | Relations pertinentes |
|---|---|---|
| `App\Modules\Referentiel\Models\Programme` | `programmes` | `faculte`, `departement`, `specialite`, `typeDiplome`, `niveaux`, `unitesEnseignement`, `maquettes` |
| `App\Modules\Referentiel\Models\Specialite` | `specialites` | `mention`, `programmes` |
| `App\Modules\Referentiel\Models\Mention` | `mentions` | `domaine`, `specialites` |
| `App\Modules\Referentiel\Models\Niveau` | `niveaux` | `programme` |
| `App\Models\InscriptionAcademique` | `inscriptions_academiques` | `programmeRef` / `programme` (alias), `niveauRef` / `niveauRelation` (alias) |
| `App\Modules\Schedule\Models\Group` | `groups` | `programme`, `niveauRef`, `faculte`, `anneeUniversitaire` |
| `App\Models\Matiere` | `matieres` | `ue` (→ `programme`), pas de `programme` direct |
| `App\Models\Etudiant` | `etudiants` | `programmeRef` / `programme` (alias), `niveauRef` |

Deux constats :

1. `InscriptionAcademique` expose **deux noms** pour la même relation (`programmeRef()` et `programme()`), ce qui masque la distinction « référence LMD » / « libellé legacy ».
2. `Etudiant` **n'a pas d'attribut `filiere`** et n'en a jamais eu en base.

---

## 4. Carte des écritures

### 4.1 Qui écrit `filiere` ?

| Point d'écriture | Emplacement | Sémantique écrite |
|---|---|---|
| Formulaire admin inscription | `app/Http/Requests/StoreInscriptionAcademiqueRequest.php:46` | Texte libre, `max:150` |
| Formulaire admin inscription (édition) | `app/Http/Requests/UpdateInscriptionAcademiqueRequest.php:33` | Texte libre |
| Formulaire groupe | `app/Http/Controllers/Admin/GroupeController.php:267` | Texte libre |
| Formulaire matière | `StoreMatiereRequest.php:43`, `UpdateMatiereRequest.php:42` | Texte libre (jamais utilisé : 66/66 NULL) |
| Import inscriptions (DataExchange) | `InscriptionAcademiqueDefinition.php:224` | **Valeur mixte** : code importé, sinon `nom` du programme, sinon `niveau` de l'étudiant |
| Import groupes (DataExchange) | `GroupDefinition.php:206` | Valeur brute, **non résolue** |
| Import inscriptions (legacy) | `InscriptionsAcademiquesImporter.php:117` | Valeur brute, **sans** `programme_id` ni `niveau_id` |
| Réinscription | `InscriptionService.php:246` | Recopie la valeur précédente si absente |
| Archive | `ArchiveService.php:97` | Valeur transmise |
| Import matières | `SubjectsImporter.php:86` | Force `null` |
| Seeders | `ArchiveSeeder`, `ContinuiteInscriptionsSeeder`, `FsegsDatasetSeeder`, `PopulationSeeder`, `ScheduleSeeder` | Valeurs de démonstration |

### 4.2 Qui écrit `programme_id` ?

| Point d'écriture | Emplacement | Bornage faculté |
|---|---|---|
| Import étudiants | `EtudiantDefinition.php:262-274` | **Oui** (programme ET niveau bornés) |
| Import groupes | `GroupDefinition.php:120-159` | **Oui** (`resoudreProgramme` vérifie `faculte_id`) |
| Import inscriptions (DataExchange) | `InscriptionAcademiqueDefinition.php:216-226` | **Partiel** : programme non borné faculté, niveau **non borné programme** |
| Import inscriptions (legacy) | `InscriptionsAcademiquesImporter.php` | **Non** : ne renseigne pas `programme_id` |
| API v1 | `RegistrationApiController.php:138` | `exists:programmes,id` — **aucun** contrôle de faculté |
| Réinscription | `InscriptionService.php:243` | Hérite de l'étudiant / de l'inscription précédente |
| Seeders | `ReferentielSeeder`, `PopulationSeeder`, `FsegsDatasetSeeder` | Oui (construction explicite) |

### 4.3 Qui écrit `specialite_id` ?

Uniquement :

- `programmes` (CRUD référentiel, `ProgrammeDefinition.php:234`) ;
- `teachers` (`EnseignantController.php:85`) ;
- filtres de campagnes (`ResolutionDestinatairesService`) et d'affectation automatique.

**Aucune inscription, aucun groupe, aucun étudiant ne porte de `specialite_id`.** La spécialité est aujourd'hui un attribut du programme et de l'enseignant, pas une dimension de la scolarité étudiante.

---

## 5. Carte des lectures

### 5.1 Exports et rapports

| Fichier | Usage de `filiere` |
|---|---|
| `AcademicExportEngine.php:53` | Type d'export `filiere` → `syntheseFiliere()` |
| `AcademicExportEngine.php:344,362` | Colonne `filiere` de la synthèse par matière, alimentée par `inscriptionAcademique.filiere` |
| `AcademicExportEngine.php:405-406,439-440` | Filtre `WHERE filiere LIKE '%valeur%'` |
| `StudentsExporter.php:84,178,230` | Colonne + filtre `LIKE` |
| `GradesExporter.php:65-66` | Filtre `LIKE` |
| `ListeEtudiantsService.php:367` | `groupe.filiere` (pas l'inscription) |
| `ExportFilters.php:28,47` | `filiere` en liste blanche de filtres |
| `ExportColumnPolicy.php:21,26,27` | `filiere` déclarée dans les colonnes autorisées |
| `ExportController.php:66,80` | Alimente la liste déroulante « Filière » de l'UI |
| `app/Http/Controllers/Admin/ExportController.php:80` | Distinct des valeurs réelles (donc valeurs libres) |

### 5.2 Documents et PDF

| Fichier | Règle de repli |
|---|---|
| `StudentDossiers/Services/Pdf/OfficialDocumentService.php:220` | `programmeRef->nom ?? filiere ?? '—'` |
| `Documents/DefaultDocumentGenerator.php:225` | idem |
| `Modules/Attendance/Services/AttendanceTemplateRenderer.php:202` | `groupe.programme.nom ?? groupe.filiere ?? 'Formation universitaire'` |

### 5.3 Interfaces

- Étudiant : `dashboard.blade.php:59`, `inscriptions.blade.php:151`, `archives.blade.php:114`, `recepisse.blade.php:236`.
- Admin : `groupes/form.blade.php:30`, `groupes/show.blade.php:7`, `archives/show.blade.php:156,187`, `exports/index.blade.php:70-73`, `listes/print.blade.php:17`.
- Planning : `ScheduleExplorerController.php:46-48` construit la liste des filières depuis `groups.filiere` ; `ScheduleExplorerService.php:321` filtre par égalité stricte.

### 5.4 Affectation

`AffectationEngine.php:638-643` calcule un score de concordance :

```php
$filiereEtudiant = $etudiant->filiere ?? $etudiant->programme?->nom ?? $etudiant->programme?->code ?? null;
```

`Etudiant` n'ayant pas d'attribut `filiere`, la première branche est **toujours nulle**. Le score repose donc entièrement sur `programme`, lui-même nul pour 2 047 étudiants sur 2 047. Le bonus « filière concordante » est inopérant en pratique.

Même défaut dans `MassAssignmentService.php:379` : `$etudiant->filiere ?? ''` constitue toujours la chaîne vide dans la clé de signature.

---

## 6. Asymétries de résolution (le cœur du problème)

Quatre résolveurs différents pour la même notion :

| Résolveur | Normalisation | Bornage | Utilisé par |
|---|---|---|---|
| `Programme::resoudreParCode()` | `mb_strtoupper` + `trim` | faculté optionnelle | Méthode canonique, **non utilisée par les imports d'inscriptions** |
| `InscriptionAcademiqueDefinition::persister()` | `trim` seul (sensible à la casse) | **aucun** | Import inscriptions |
| `GroupDefinition::resoudreProgramme()` | `mb_strtoupper` + `trim` | faculté | Import groupes |
| `EtudiantDefinition` | `mb_strtoupper` + `trim` | faculté | Import étudiants |

Conséquences démontrées :

1. `PAR-GEST` importé en minuscules (`par-gest`) n'est **pas** résolu par l'import d'inscriptions, alors qu'il l'est par l'import d'étudiants et de groupes.
2. Le même en-tête CSV `filiere` signifie « code programme » pour `etudiants`, « texte libre » pour `inscriptions-academiques`, et coexiste avec une colonne `programme` distincte pour `groupes` (`GroupDefinition.php:36`).
3. `InscriptionAcademiqueDefinition.php:224` écrit dans `filiere` tantôt un code, tantôt un libellé, tantôt un code de niveau — trois natures dans une même colonne.
4. `InscriptionAcademiqueDefinition.php:217` résout `niveau_id` **sans** `programme_id` : `Niveau::where('code', 'L1')->first()` retourne toujours le niveau du premier programme créé.

---

## 7. Dépendances périphériques

| Catégorie | Références |
|---|---|
| Policies | `ReferentielPolicy` (périmètre faculté par `faculte_id` ou remontée de relation) ; `ReferentielController.php:593` (cohérence faculté spécialité/département) |
| Permissions | `referentiel.view/manage/import/export`, `maquettes.approve`, `exports.personal`, `archives.view` |
| API v1 | `GET /api/v1/referentiel/programmes`, `niveaux`, `semestres`, `ues`, `maquettes` — tous bornés faculté sauf le `programme_id` d'écriture de `RegistrationApiController` |
| Jobs / commandes | Aucun job dédié au référentiel ; `qualite:auditer` détecte des anomalies générales |
| Événements | `MaquetteTransitionnee`, `OrdreModifie` → audit ; aucun événement sur le rattachement programme |
| Seeds | `ReferentielSeeder` (4 programmes, 12 niveaux), `PopulationSeeder`, `FsegsDatasetSeeder` |
| Tests | 90 fichiers mentionnent `filiere` ; aucun test ne couvre le bornage `niveau_id`/`programme_id` de l'import d'inscriptions |

---

## 8. Synthèse de la cartographie

1. Le référentiel LMD existe, mais il ne couvre **qu'une faculté sur dix** (FST) et **4 programmes**.
2. Les données historiques vivent dans `faculte_id` + `niveau` (texte) + `groupe` ; le lien LMD est quasi inexistant.
3. `filiere` n'est pas une colonne de vérité : c'est un champ libre, majoritairement vide, alimenté par plusieurs chemins avec des sémantiques incompatibles.
4. Le défaut le plus grave n'est pas terminologique mais fonctionnel : la résolution de `niveau_id` sans bornage programme produit des rattachements faux, silencieusement.
5. `specialite_id` n'est pas une dimension de la scolarité étudiante dans le modèle actuel.

---

## 9. Réserves de la phase

- L'audit porte sur la base locale de développement, non sur une base de production.
- Les 10 anomalies de niveau sont des données de test issues d'un import réel : le défaut de code est reproductible, les lignes concernées sont des données de démonstration.
- Aucune décision métier (filière = programme ? mention ? autre ?) n'est tranchée à ce stade.
