# Angle 3 — À la page : que puis-je faire sur l'écran X ?

> Cartographie par écran, basée sur les routes réelles. Format : `URL` → ce que vous pouvez
> y faire. Les apports des modules apparaissent avec leur nom de module.

## Espace public

| Écran | Ce que je peux y faire |
|---|---|
| POST `/inscription` | Déposer une demande d'inscription nouveau ; attestation sur l'honneur obligatoire ; anti-doublon par CIN |
| POST `/inscription/verifier-cin` | Vérifier si mon CIN existe déjà ; si oui : identité pré-remplie + 2e facteur (date de naissance) |
| `/inscription/existant` (sous-pages : accueil, recherche, données, e-mail, autonome, confirmation) | M'inscrire/rejoindre avec un numéro existant, en autonomie avec authentification e-mail |

## Espace étudiant (`/etudiant/*` — accès protégé par votre session)

| Écran | Ce que je peux y faire |
|---|---|
| `/etudiant/tableau-de-bord` | Vue personnalisée : mon dossier, mes inscriptions en cours, mes échéances, raccourcis |
| `/etudiant/dossier` (+ sous-écrans) | Consulter mon dossier ; déposer/ remplacer/télécharger mes pièces (versionnées) ; consulter mes documents officiels ; générer/afficher/télécharger le dossier en PDF |
| `/etudiant/inscriptions` | Mes inscriptions académiques par année, statut visible avec explications |
| `/etudiant/notes` | Mes notes/décisions officielles (visibles seulement après publication) |
| `/etudiant/parcours` (avec `/etudiant/parcours/pdf`, simulations) | Mon parcours complet, le PDF, et une simulation de scénario sans écriture |
| `/etudiant/archives`, `/etudiant/documents`, `/etudiant/historique` | Mes archives, mes documents, mon historique académique daté |
| `/etudiant/suivi` | Suivre l'avancement de mon dossier (dépôt → vérification → décision) |
| `/etudiant/corrections` | Signaler une erreur de mon dossier à la scolarité (formulaire contrôlé) |
| `/etudiant/emploi-du-temps` (+ `/actualisations`) | Mon emploi du temps de la semaine ; actualisations incrémentales (JSON, pour l'interface) |
| `/etudiant/calendrier` | Mon agenda pédagogique |
| `/etudiant/examens` (+ convocation) | Mes examens, plan de salle, télécharger ma convocation |
| `/etudiant/absences` (+ export, justifier) | Mon taux et historique d'absences ; justifier avec pièce ; exporter |
| `/etudiant/appel/qr` | M'émarger à un cours via QR (POST « pointer ») |
| `/etudiant/annonces` | Lire les annonces ciblées pour moi |
| `/etudiant/ressources` | Télécharger les ressources pédagogiques disponibles (module Enseignement) |
| `/etudiant/demandes` (+ nouvelle, pièces, messages, annuler, soumettre) | Créer et suivre une demande administrative (9 états), échanger avec la scolarité |
| `/etudiant/paiements` (+ sous-écrans reçu) | Voir mon suivi financier ; déposer un reçu ; aperçu/téléchargement |
| `/etudiant/recepisse` | Mon récépissé d'inscription (`INS-YYYY-XXXXX`) |
| `/etudiant/rendez-vous` (+ nouveau, créneaux) | Réserver / déplacer / annuler un rendez-vous scolarité |
| `/etudiant/recherche` | Chercher dans mes contenus autorisés |
| `/etudiant/notifications` (+ lire-tout) | Lire mes notifications, tout marquer comme lu |
| `/etudiant/profil` (+ modifier, mot-de-passe) | Corriger mes coordonnées ; changer mon mot de passe |

## Espace administration (`/admin/*` — RBAC + MFA)

### Vues de pilotage
| Écran | Ce que je peux y faire |
|---|---|
| `/admin` (dashboard) | Synthèse adaptative, actions rapides, notifications, timeline |
| `/admin/ma-faculte` | Tableau de bord exclusif à `admin_faculte`, scopé à SA faculté |
| `/admin/centre-travail` (+ `{inscription}`) | Centre de travail : KPI cliquables + diagnostic explicable, décisions (valider/rejeter/corrections) |
| `/admin/inscriptions/file-traitement` | File FIFO des dossiers à traiter (action attendue calculée) |
| `/admin/cockpit`, `/admin/bi`, `/admin/population`, `/admin/qualite`, `/admin/listes` | Indicateurs, BI, statistiques de population, qualité des données, listes |

### Dossiers & inscriptions
| Écran | Ce que je peux y faire |
|---|---|
| `/admin/etudiants` (+ creer, modifier, statut, rattachement) | Registre étudiant ; créer un dossier administratif ; modifier ; statut |
| `/admin/etudiants/{id}` (fiche 360°) | Tout le dossier : identité, académique, parcours, groupes, modules, audit |
| `/admin/etudiants/{id}/verification` | Vérificateur intelligent : verdict, score/100, 10 règles, correction assistée |
| `/admin/verification/bulk` | Lancer une expertise sur une sélection |
| `/admin/etudiants/{id}/archive` (+ sensible) | GED d'archives, dossiers sensibles (accès restreint + téléchargement) |
| `/admin/etudiants/{id}/documents-center`, `…/documents-officiels`, `…/demande-complement` | Centre documentaire ; documents officiels ; demander une pièce à l'étudiant |
| `/admin/etudiants/{id}/export-dossier` | Exporter le dossier |
| `/admin/etudiants/{id}/reinitialiser-mot-de-passe` | Réinitialiser le mot de passe d'un étudiant |
| `/admin/etudiants/{id}/reinscriptions` (+ creer) | Réinscription annuelle (workflow dédié) |
| `/admin/etudiants/{id}/suspendre`, `…/transferer` | Suspension / transfert d'un dossier |
| `/admin/inscriptions/{inscription}/valider|rejeter|attestation|facturer` | Valider (garde pièces + bloquants), rejeter (motif), attestation, facture |
| `/admin/inscriptions/{inscription}/pedagogique`, `…/ecues` (+ lot), `…/corriger`, `…/reaffecter`, `…/reinscrire`, `…/progresser` | Gestion pédagogique de l'inscription (UE/ECUE, corrections, réaffectation, réinscription, progression) |
| `/admin/affectations/…` (+ masse/regulariser-inscriptions) | Affectation étudiants↔groupes, répartition automatique, régularisation de masse |
| `/admin/groupes` (CRUD, `/admin/groupes/{groupe}/etudiants`) | Groupes des facultés, effectifs, capacité |

### Évaluations & délibérations (module Evaluation)
| Écran | Ce que je peux y faire |
|---|---|
| `/admin/evaluations` (+ anomalie, examens, epreuves) | Portail évaluation : épreuves, examens, anomalies (résolution groupée, export) |
| `/admin/evaluations/epreuves/…` (compostages, emargement, convocation, correspondance, incidents, salle) | Organiser une épreuve de bout en bout |
| `…/epreuves/{epreuve}/notes` (+ anonymes, export-anonyme, import-multi 2 étapes, valider-tout, soumettre) | Saisir, charger, importer, valider les notes (flux anonymes via compostages) |
| `/admin/evaluations/deliberations/…` (membres, preparer, valider, publier, pv, releves, simuler) | Conduire une délibération jusqu'à publication + PV et relevés |
| `/admin/evaluations/affectations/…` (auto, runs, annuler) | Optimiser la répartition surveillance/épreuves (runs suivis + annulation) |
| `/admin/evaluations/documents-batch/…` | Prévisualiser → générer → télécharger des PV/relevés en lot |

### Finances, documents, référentiels
| Écran | Ce que je peux y faire |
|---|---|
| `/admin/finances/paiements-inscriptions/…` (prendre, valider, refuser, reçu aperçu/télécharger) | Vérifier des reçus d'inscription (un seul VALIDE par dossier, commentaire si écart) |
| `/admin/documents-center`, `/admin/attestations` | Documents officiels/attestations |
| `/admin/maquettes` | Référentiel LMD (UE/ECUE, coefficients, prévalidation de maquette) |
| `/admin/ged`, `/admin/archives` | GED centrale, archives |

### Système, gouvernance
| Écran | Ce que je peux y faire |
|---|---|
| `/admin/audit` | Journal immuable filtrable |
| `/admin/roles`, `/admin/administrateurs` (super_admin), `/admin/parametres` | Rôles, comptes, configuration |
| `/admin/maintenance` | Santé DB/stockage/queue/cache/sessions, purge de cache |
| `/admin/notifications`, `/admin/campagnes-courriel`, `/admin/smtp`, `/admin/personnel` | Notifications, communications |
| `/admin/recherche` | Recherche globale (7 sections scopées) |
| `/admin/donnees` (hub/import), `/admin/datamesh`, `/admin/studio` | Imports/exports de données, datamesh, outillage |

## Portail enseignant (`enseignant/*`)

| Écran | Ce que je peux y faire |
|---|---|
| `enseignant.dashboard` | Tableau de bord enseignant |
| `enseignant.appel` (+ `qr/{etudiant}`) | Listes d'appel, émargement QR |
| Notes d'épreuves habilitées | Saisie bornée selon l'épreuve (permission `notes_eval.enter`) |

## Pages d'erreur communes (Design System : 403, 404, 419, 429, 500, 503)

Chaque page d'erreur explique la cause probable (ex. 429 : trop de requêtes, 419 :
jeton CSRF expiré → recharger la page avant de renvoyer).
