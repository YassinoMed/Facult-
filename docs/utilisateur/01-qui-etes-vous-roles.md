# Angle 1 — Qui êtes-vous ? Que pouvez-vous faire ?

> Sources : `docs/PROFILS-ET-SCOPING.md`, `app/Support/Permissions.php`, matrice de routes
> vérifiée. Un rôle n'est **jamais** lu dans le navigateur : il vient de la base de données
> à chaque requête.

## 1. Candidat / visiteur (sans compte)

- **Espace** : pages publiques.
- **Peut** :
  - déposer une demande d'inscription de nouveau (« POST `/inscription` ») — sujet à un
    quota anti-abus (10 requêtes/minute) ;
  - vérifier si un CIN a déjà un dossier (**POST `/inscription/verifier-cin`**) : pré-remplissage
    de l'identité, second facteur = date de naissance ;
  - s'**inscrire en autonomie** si vous avez un numéro d'inscription existant : parcours
    « `/inscription/existant` » (accueil → recherche → e-mail d'authentification → formulaire →
    confirmation).
- **Ne peut pas** : voir le moindre dossier, payer, contacter un agent autrement que par un
  formulaire.

## 2. Étudiant (compte `etudiants`, session dédiée)

- **Connexion** : **`/etudiant/connexion`** — e-mail **ou CIN** + mot de passe ;
  mot de passe oublié via `/etudiant/mot-de-passe/oublie`.
- **Autorité** : **votre dossier uniquement**, résolu par votre session. Aucun ID d'étudiant
  ne circule dans l'URL : il est impossible (et testé) de consulter le dossier de quelqu'un d'autre.
- **Peut** : consulter et télécharger votre dossier académique, vos inscriptions, vos notes
  (après publication), votre parcours + PDF, vos documents, votre historique, vos absences,
  votre emploi du temps, vos ressources pédagogiques, vos finances (dépôt de reçu, suivi),
  vos convocations d'examen, vos demandes administratives, vos corrections à demander,
  prendre rendez-vous, modifier votre profil et votre mot de passe.
- **Ne peut jamais** : modifier une note, une décision ou des crédits ; supprimer son dossier ;
  accéder à `/admin` (vous y êtes un invité → renvoyé vers la page de connexion admin).
- **Espace lecture + actions ciblées** : la modification est cantonnée à vos coordonnées,
  votre mot de passe, vos pièces justificatives et vos demandes.

## 3. Agent de scolarité (`agent_scolarite`)

- **Connexion** : **`/admin/connexion`** (email + mot de passe bcrypt, quota 5/minute),
  défis MFA selon criticalité (écran `connexion-defi`) + verrouillage secondaire selon routes.
- **Périmètre** : global (toutes facultés), **consultation + saisie** — sans suppression ni
  configuration.
- **Peut** : travailler la file de traitement, valider/rejeter un dossier d'inscription,
  régulariser, saisir des notes, administrer le vérificateur intelligent (voir angle 2),
  commenter les dossiers, préparer les délibérations, affecter des groupes, dépôt et
  vérification des paiements, émission d'attestations.
- **Ne peut pas** : supprimer, gérer les référentiels/configuration, déverrouiller une note
  verrouillée (réservé `admin_scolarite` + rôle supérieur).

## 4. `admin_scolarite`

- **Périmètre** : global. **En plus** : archives/notes/documents **complets**,
  `configuration.manage`, **`notes.unlock`** (déverrouillage de note verrouillée).
- Rôle principal pour les corrections lourdes, les verrouillages/déverrouillages et la
  configuration pédagogique.

## 5. `consultation`

- **Périmètre** : global, **lecture seule** : archives, documents (vue/téléchargement),
  notes, historique. Aucune écriture. Idéal pour une direction qui supervise et produit des rapports.

## 6. `admin_faculte` — administrateur d'une faculté

- **Périmètre** : **sa faculté uniquement** (`users.faculte_id`). Tout le serveur impose le
  périmètre ; le filtre faculté envoyé par le navigateur est ignoré.
- **Peut** : tout le flux métier de sa faculté (archives, documents, notes saisie/update,
  historique, exports personnels) + son tableau de bord **`/admin/ma-faculte`**.
- **Ne peut pas** : `configuration.manage`, `notes.unlock`, suppression des archives,
  exports globale, toucher une autre faculté (→ 403/404 sans fuite d'existence).

## 7. `super_admin`

- **Périmètre** : toutes les facultés + gestion des administrateurs
  (**`/admin/administrateurs`** : création de comptes, rattachement faculté, activation).
  Le super admin **ne peut pas se désactiver lui-même**.
- Remarques : maintenance (purge de cache avec quota), `notes.unlock`, configuration.

## 8. Enseignant

- **Espace** : portail enseignant (séparé, session dédiée `enseignant_auth_id`).
- **Peut** : suivi de ses cours, listes d'appel + **QR d'émargement** (routage
  `enseignant.appel.qr`), saisie des notes d'épreuves **si** habilité (`notes_eval.enter`),
  saisie bornée et varie selon l'épreuve.
- **Ne peut pas** : consulter une adresse d'un autre enseignant, publier les résultats
  (réservé aux permissions de délibération).

---

## Si vous êtes admin : 3 pièges à connaître

1. **404 ≠ panne** : beaucoup de pages admin sont sous `module:xxx`. Module inactif = 404.
2. **403 silencieux** : une policy peut vous refuser une action sans message explicite
   visible — consultez l'angle 5.
3. **Le formulaire « refuse » parfois sans vous laisser corriger** : dans le vérificateur
   intelligent, une correction exige un **motif** ; le formulaire bloque tant que le motif
   est vide — ce n'est pas un bug, c'est la traçabilité.
