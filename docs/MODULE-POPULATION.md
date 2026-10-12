# Phase 2 — Population universitaire

## §1. Cartographie (§2 : auditer avant de créer)

```
CONSERVER : Etudiant (STATUTS, definirStatut, num INS- auto, relations),
  Teacher, Group, User (= comptes staff), InscriptionAcademique,
  DossierAcademique, StudentsImporter (CIN/email), RechercheController,
  updateStatut/bulkStatut, policies archives.*.
ÉTENDRE (additif, nullable) : etudiants (+programme_id, +niveau_id),
  teachers (+matricule, grade, département, spécialité pro, bureau,
  embauche, statut), groups (+programme, +niveau LMD, +année, +capacite),
  inscriptions (+programme, +niveau, +type, +regime).
CRÉER : grades_enseignants, fonctions, personnels (profil User),
  affectations_groupe, situations_etudiant, inscriptions_pedagogiques.
MIGRER : rien de destructif (aucune colonne existante touchée).
```

## §2. Décisions structurantes

- **Personnel = profil de User** (§43) : pas de 2e système de comptes ;
  CRUD super_admin uniquement (comptes sensibles), le compte survit
  au profil.
- **Statuts** : `Etudiant::STATUTS` conservés (tests/flux) ; transitions
  via `StudentStatusService` (matrice + motif + historique) ; suspension
  datée avec garde `estSuspendu()` (whereDate : SQLite/MySQL).
- **Inscriptions** : `InscriptionService::transiter` (en_cours →
  valide/ajourne/abandon, validée verrouillée), `reinscrire()` (nouvelle
  ligne + S1/S2, historique intact, niveau progressé), pédagogique
  adossée à la maquette **active** (refus hors maquette).
- **Affectations** : une seule active, clôture datée, capacité (forçage
  `groupes.manage` tracé `[FORCÉ]`), `groupe_id` synchronisé.
- **Permissions** : matrice existante réutilisée (archives.*, groupes.*,
  enseignants.*) + seule `personnel.manage` ajoutée (super_admin).
- **Audit** : event `PopulationChanged` (ids + contexte, sans PII).
- **Import/export** : StudentsImporter (+programme/niveau/groupe résolus
  serveur), TeacherDefinition (+matricule/grade/département/bureau),
  GroupDefinition (+programme/niveau/année/capacité) ; templates à jour.
