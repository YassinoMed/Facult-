# Module Referentiel LMD — fondation académique

## §1. Cartographie (§1 : auditer avant de modifier)

```
EXISTANT → CONSERVÉ : facultes (= Établissements), annees_universitaires
  (libelle = code, active = courante), matieres (= ECUE, utilisée par
  notes + emplois du temps → AUCUNE entité dupliquée), semestres
  (= semestres d'INSCRIPTION étudiant — sémantique différente, conservés).
MODIFIÉ (additif, nullable) : facultes (+sigle, adresse, téléphone,
  email, site), matieres (+ue_id, description, volumes CM/TD/TP,
  type_evaluation, actif), AnneeUniversitaire (+definirCourante(),
  precedente(), suivante()).
CRÉÉ (module Referentiel) : type_diplomes, departements, domaines,
  mentions, specialites, programmes, niveaux, semestres_lmd,
  unites_enseignement, maquettes (+maquette_ue), ecue_prerequis.
MIGRÉ : rien de destructif (toutes les colonnes ajoutées nullables).
```

## §2. Hiérarchie

```
Faculte → Département ─┐
Faculte → Domaine → Mention → Spécialité ─┤
                                          ├→ Programme → Niveau → SemestreLMD → UE → ECUE (Matiere)
                                          └→ Maquette (programme × année, versionnée)
```

Groupes/étudiants/inscriptions : inchangés (intégration par codes,
pas de FK ajoutée — zéro régression).

## §3. Points d'entrée

- `admin.referentiel.tableau-bord` (compteurs + alertes), `arbre`
  (details/summary, compteurs, recherche), `qualite` (anomalies).
- CRUD générique `entites.{index,create,store,show,edit,update,destroy}`
  piloté par `EntitesReferentiel` (labels, champs, règles, périmètre,
  gardes) — ajouter une entité = une entrée.
- `maquettes.*` (création auto-versionnée, contenu, transitions,
  duplication), `ecues.*` (volumes, prérequis anti-cycle).
- Flag `MODULE_REFERENTIEL` (défaut **true**, fondation).

## §4. Règles à retenir

- Codes uniques **dans leur contexte** (contrainte SQL + validation).
- FK `restrict` partout : suppression refusée si dépendances (garde
  applicative + message, §20).
- Maquette verrouillée dès `approved` (contenu, suppression, transitions
  contrôlées ; une seule `active` par programme, bascule atomique).
- Prérequis : graphe sans cycle (BFS), auto-référence interdite.
- `Matiere::volumeTotal()` = CM+TD+TP (règle centralisée, §15).
- Année courante unique (`definirCourante()`, transaction).
- Import/export : `departements`, `ues`, `ecues` via DataExchange
  (pipeline preview → confirmation → transaction, périmètre vérifié).
- Permissions : `referentiel.view/manage/import/export`,
  `maquettes.approve` (scolarité full ; faculté/agent/consultation :
  lecture, scopée faculté pour la première).
- Audit : event `MaquetteTransitionnee` + listener (ids + statut, sans PII).
