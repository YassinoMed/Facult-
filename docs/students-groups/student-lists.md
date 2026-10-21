# Listes étudiants (§9-10, §18-22, §27)

Moteur : `ListeEtudiantsService::requete` (scopée faculté serveur) + `jeuDonnees`.

## Filtres (intersection stricte)

`annee_universitaire_id, programme_id, niveau, semestre, groupe_id,
sous_groupe_id, ue_id, matiere_id, enseignant_id, statut, sans_groupe, search`
+ `tri nom/prenom/num_inscription/groupe/niveau/programme, sens`.

`EtudiantController::index`, `sansGroupe`, `GroupeController::etudiants` et
`ListesController` utilisent la même méthode — aucune query dupliquée.

## Vues prédéfinies

Tous, actifs, sans-groupe (`admin.etudiants.sans-groupe`), par groupe
(`admin.groupes.etudiants`), par programme/parcours/niveau/semestre, par
UE/ECUE (`ue_id/matiere_id`), par enseignant (`enseignant_id` via
`Enseignement`), redoublants/anomalies via qualité (relations existantes
uniquement).

## Colonnes configurables

Catalogue `students` : `matricule, cin, nom, prenom, email, telephone, groupe,
sous_groupe, programme, filiere, niveau, faculte, annee, moyenne, credits,
decision, statut` (+ `numero`).intersection avec le choix utilisateur ;
`sans exports.personal` → `email/telephone` retirés, CIN masquée `BK***456`.
Fiche étudiant = dossier 360 existant (`FicheEtudiantService`), jamais dupliqué.

## Vues sauvegardées (Phase 2 §2.12-2.13)

Deux mécanismes existants réutilisés, aucun nouveau :

- Profils `ExportTemplate type=listes` (filtres + tri + colonnes +
  regroupement + orientation + titre + format) — bouton « Enregistrer le
  profil » sur `admin.listes.preview`, chips de réapplication.
- Vues tableau `VueSauvegardee` (`tableau=etudiants`, whitelistée) —
  `<details> Enregistrer la vue` sur `admin.etudiants.index` + chips.
- Pas de partage complexe : propriété par utilisateur (404 sinon), super-admin
  hors périmètre pour les téléchargements uniquement.

## Actions rapides

`x-export-actions` (PDF/Excel/CSV/Imprimer, même query string) sur registre,
preview, fiche groupe ; liens PDF regroupés (par groupe / par sous-groupe) ;
`Mes exports` (historique différés) dans preview.

## Action « Modifier » (registre)

Chaque ligne du registre (`admin.etudiants.index`) propose l'action
« Modifier » (`admin.etudiants.modifier`) ; la fiche
(`admin.etudiants.show`) expose la même action. Elle ouvre le formulaire de
correction de la fiche : **identité, contact, rattachement LMD et inscription
courante** — `archives.update` + périmètre de faculté
(`EtudiantPolicy::update`), validation serveur, journalisation dans
`historique_academique` + `situations_etudiant`, motif de modification
obligatoire.

Hors périmètre de ce formulaire (workflows dédiés, jamais dupliqués) : statut
du dossier, groupe, mot de passe et année d'inscription.
