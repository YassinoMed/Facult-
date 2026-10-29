# Angle 2 — Guides pas-à-pas (par tâche)

> Chaque procédure liste : **prérequis** → **étapes** → **résultat attendu**. Les chemins
> d'URL sont ceux du code (vérifiés). Un bouton pas nommé ici exactement est marqué
> **À CONFIRMER**.

## A. Candidat — s'inscrire

### A1. Nouveau bachelier (aucun dossier existant)
1. Ouvrir **POST `/inscription`** depuis le portail public (l'écran vous demande identity,
   confirmations sur l'honneur incluse).
2. Utiliser le champ de vérification CIN (**POST `/inscription/verifier-cin`**) **avant** de
   tout saisir : si un dossier existe déjà, votre identité est pré-remplie (second facteur =
   date de naissance) et l'anti-doublon vous oriente vers la bonne voie (A2).
3. Renseigner le formulaire, confirmer l'attestation sur l'honneur (**obligatoire**),
   envoyer.
4. **Résultat** : un dossier en `en_attente` avec votre numéro officiel
   `INS-YYYY-XXXXX` et votre récépissé accessible via votre espace étudiant
   (`/etudiant/recepisse`). En cas de rejeu involontaire < 60 s le système est idempotent :
   vous ne créez pas de doublon.
- **Erreurs fréquentes** : CIN erroné (8 chiffres), e-mail existant, période fermée —
  voir angle 5.

### A2. Inscription « existant » (j'ai déjà un numéro)
Parcours `/inscription/existant` :
1. Accueil → recherche (**POST `…/recherche`**) ;
2. Données + authentification par e-mail institutionnel (**`…/email`, `…/email/confirmer`** —
   anti-rejeu) ;
3. Formulaire autonome (« `…/autonome` », puis confirmation « `…/autonome/confirmer` ») ;
4. Confirmation visuelle.
- **Résultat** : mise à jour du dossier avec traces d'audit ; rien de nouveau n'est créé si
  un dossier existe (doublon interdit par contrainte unique).

## B. Étudiant — les tâches courantes

### B1. Première connexion et mot de passe
1. `/etudiant/connexion` : saisir **e-mail OU CIN** + mot de passe (max 2 facteurs si implémenté).
2. Mot de passe oublié : `/etudiant/mot-de-passe/oublie` → lien envoyé →
   `/etudiant/mot-de-passe/reinitialiser/{token}`. Le lien est à usage unique et expire.
3. Changement en session : `/etudiant/profil` > changement de mot de passe
   (**PATCH `/etudiant/profil/mot-de-passe`**).

### B2. Déposer/actualiser une pièce justificative (Mon dossier)
1. `/etudiant/dossier` → zone documents.
2. Envoyer le fichier (**POST `/etudiant/dossier/documents`**) — la validation serveur
   vérifie extension + vrai type MIME + taille ; les pièces de qualité insuffisante déclenchent une
   ressaisie manuelle (jamais d'échec brutal : OCR fail-soft).
3. Versionner : un remplacement (**`replace`**) crée une **nouvelle version** ; les versions
   précédentes restent téléchargeables.

### B3. Consulter vos notes / résultats
1. `/etudiant/notes` — liste officielle mise à jour **après publication d'une délibération**
   (avant : rien n'est visible, c'est normal).
2. `/etudiant/parcours` (+ `/etudiant/parcours/pdf` pour le document) — synthèse officielle
   par semestre/année ; « `/etudiant/parcours/simulations` » = simulation personnelle **sans
   aucune écriture** sur votre dossier.

### B4. Payer / déposer un reçu
1. `/etudiant/paiements` → votre dossier d'inscription.
2. Suites possibles : votre dossier porte l'indicateur de paiement fourni par
   l'administration ; si le paiement se fait sur un site externe, déposez le
   reçu (**POST `/etudiant/paiements/inscriptions/{inscription}/recus`**) — statuts visibles
   et le téléchargement du reçu (aperçu/téléchargement).
3. **Important** : un reçu refusé nécessite un re-dépôt corrigé ; le paiement seul ne valide
   jamais votre inscription (voir README rappels).

### B5. Absences et émargement QR
1. `/etudiant/absences` — taux, historique ; **justifier** une absence avec pièce
   (**POST `/etudiant/absences/{presence}/justifier`**).
2. Présence par QR : ouvrir `/etudiant/appel/qr` **à l'heure** de l'appel, l'enseignant
   affiche le QR ; vous pointez via POST `/etudiant/appel/qr` (bouton « pointer ») en scannant le QR affiché.

### B6. Demande administrative (attestation, niveau supérieur, etc.)
1. `/etudiant/demandes/nouvelle` → choisir le type de demande.
2. Brouillon : completer les champs + pièces, **soumettre** (POST
   `/etudiant/demandes/{demande}/soumettre`). Une fois soumise, la demande suit un
   workflow de 9 états (module OnlineRequests) : vous pouvez **commenter** (messages),
   **ajouter une pièce**, **annuler** (avant traitement terminal), télécharger le PDF final.
3. Suivi : `/etudiant/demandes` + **messages** intégrés (POST `…/messages`), l'agent
   peut vous répondre dedans.

### B7. Rendez-vous scolairité
1. `/etudiant/rendez-vous/nouveau` → créneaux proposés (**`…/creneaux`**) → réserver
   (POST `/etudiant/rendez-vous`) ; on peut **déplacer** (sans limite métier explicite ici) ou
   **annuler**.

### B8. Convocations d'examen
1. `/etudiant/examens` — liste validée par la scolarité ; télécharger la convocation
   (**GET `/etudiant/examens/{candidat}/convocation`**). Sans convocation : voir également
   l'écran anomalies du côté admin.

## C. Administration — flux de référence

### C1. File de traitement (quotidien d'un agent)
1. `/admin/inscriptions/file-traitement` — file FIFO des dossiers `en_attente` avec
   action attendue déterminée par le système ; on peut **commenter** un dossier.
2. Ouvrir la fiche du dossier avec le vérificateur intelligent :
   `/admin/etudiants/{id}/verification` — verdict **CONFORME | À_VÉRIFIER | BLOQUANT** +
   score /100 + 10 règles R1-R10 expliquées. Utiliser **« Relancer l'analyse »** (bouton
   documenté) pour rejouer le moteur.
3. Sur la fiche 360° (`admin.etudiants.show`) : bannière d'alerte du verdict, accès direct
   au rapport, **Correction assistée** (exige `archives.update` + motif obligatoire,
   transaction, audit `correction_dossier`, re-vérification immédiate).
4. Décisions finales (depuis la fiche d'inscription) :
   - **Valider** (POST `/admin/inscriptions/{inscription}/valider`) : exige pièces
     obligatoires complètes et **verdict non-BLOQUANT** ; garantit l'unicité annuelle.
     Traçable ; déclenche l'affectation automatique (fail-soft) — vérifier ensuite le groupe.
   - **Rejeter** (POST `…/rejeter`) : motif obligatoire ; le dossier reste consultable.
   - **Demande de complément** : POST `/admin/etudiants/{etudiant}/demande-complement` —
     l'étudiant reçoit la liste des pièces attendues.
5. **Unicité annuelle** : une validation qui échoue « déjà inscrit cette année » est un
   garde-fou, pas un bug — voir régularisations.

### C2. Affecter / changer de groupe
1. Fiche étudiant > affectations ; **prévisualiser** les contrôleurs de capacité
   (`…/affectations/previsualiser`) **avant** d'écrire ;
2. Affecter/retirer ; en masse : `/admin/affectations/…` et
   **POST `/admin/affectations/masse/regulariser-inscriptions`** pour réconcilier
   inscriptions sans groupe ; l'application automatique est **idempotente** (re-runner
   sans doublon) et n'est jamais bloquante.

### C3. Saisie des notes (module Évaluation)
1. Ouvrir l'épreuve : `/admin/evaluations/epreuves` → fiche épreuve.
2. Saisie nominative ou **anonyme** : POST `…/notes/anonymes` avec les
   **compostages `CMP-######`** ; export anonyme (**GET `…/export-anonyme`**) pour fournir
   des listes sans identité ; import anonyme possible.
3. Import multi matières en **2 étapes anti-écrasement** : POST `…/notes/import-multi`
   (aperçu des conflits et écarts) puis `/confirmer` (ou `/annuler`). Le rapport détaillé
   visible après.
4. Validation collective : POST `…/notes/valider-tout` — les erreurs partielles sont
   collectées et signalées ligne par ligne, le reste s'applique.
5. **Verrouiller** une note (POST `…/verrouiller`), correction en 2 étapes si verrouillée :
   demande puis autorisation. Toutes les écritures sont bornées (`0..note_max`) **côté
   serveur** — le `max` du champ n'est qu'une aide —, limitées à **2 décimales** et refusées
   si le barème (`note_max`) est absent ou ≤ 0. Un `etudiant_id` fourni côté client n'est
   jamais utilisé dans la voie anonyme : seul le compostage résolu serveur fait foi. Les
   pages et exports de notes interdisent le cache navigateur et l'indexation ;
   la synchronisation vers la note officielle est unique.
6. Incidents surveillés : `/admin/evaluations/epreuves/{epreuve}/incidents` — consigner
   sans toucher aux notes.

### C4. Délibérer (cycle jury)
1. **Créer/préparer** : `/admin/evaluations/deliberations` → POST créer, puis
   POST `…/{deliberation}/preparer` — calculs + anomalies journalisés (run hashé,
   delete+insert idempotent : rejouer est sûr).
2. **Membres du jury** : POST `…/membres` — quorum = président + ≥ 2 présents en séance.
3. **Valider** : POST `…/valider` — exige : quorum, zéro anomalie bloquante, zéro
   `à examiner`. Un compteur live vous montre le quorum en temps réel ; confirmation
   demandée avant l'action définitive.
4. **Publier** : POST `…/publier` — à partir de là, les décisions sont visibles côté étudiant et
   API ; chaque étudiant est notifié après la transaction.
5. **Documents** : PV (**GET `…/pv`**), relevés de notes (**POST `…/releves`**),
   documents par lot (`admin.evaluations.documents-batch` : prévisualiser → générer →
   télécharger).
6. Après correction de note sous délibération verrouillée : les résultats sont marqués
   invalides (date `resultats_invalides_le`) — **re-préparer** la délibération, obligatoire
   avant une nouvelle publication.

### C5. Finances — les reçus
1. `/admin/finances/paiements-inscriptions` — liste des dossiers avec paiements.
2. **Prendre en charge** (POST `…/{dossier}/prendre`) → vérifier le reçu déposé →
   **Valider** ou **Refuser** (commentaire obligatoire si écart de montant),
   un seul numéro de reçu VALIDE par dossier.
3. Séparation stricte : la **présence/admissibilité du paiement n'influe pas** sur la
   validation d'inscription (dossier académique) ; le module Finances est indépendant.

### C6. Centre de travail (vue unifiée)
1. `/admin/centre-travail` — cartes KPI **cliquables** (chaque carte mène à la liste
   exacte filtrée correspondante ; pas de « compte » fantôme).
2. Fiche diagnostic : `/admin/centre-travail/{inscription}` — explication ligne par ligne
   de ce qui bloque ou n'empêche pas la validation (dry-run : **aucune écriture ici**) ;
   décisions possibles : valider, rejeter, trancher les corrections proposées
   (POST `…/corrections/{correction}/decider`).
3. Centre **lecture/décision uniquement** : il ne calcule pas de notifications
   automatiques de lui-même.

### C7. Super admin — comptes et maintenance
1. `/admin/administrateurs` : créer/éditer des comptes back-office, rattacher une faculté,
   activer/désactiver (il est impossible de se désactiver soi-même).
2. `/admin/roles`, `/admin/parametres`, `/admin/maintenance` (purge cache avec quota).

---

## Bonnes pratiques transverses

- **Motif systématique** : toute correction ou rejet exige un motif explicite ; c'est ce qui
  sauve l'audit.
- **Simulation > écriture** : quand un chemin propose « simuler/prévisualiser »
  (affectations, délibérations, documents-batch), faites-le ; l'application réelle est
  ensuite idempotente.
- **Re-vérifier** : après toute décision, consultez le score du vérificateur ; une correction
  mal orientée fait remonter le verdict à BLOQUANT, ce qui bloque la validation.
