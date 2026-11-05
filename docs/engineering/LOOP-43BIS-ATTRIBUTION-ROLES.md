# LOOP 43-BIS — Attribution et révocation des rôles

## Objectif
Permettre l'attribution et le retrait des rôles de manière contrôlée, contextualisée et historisée.

## 1.1 Attribution individuelle

### 1.1.1 Enregistrement requis
Pour chaque affectation, enregistrer :
- Bénéficiaire (user_id)
- Rôle attribué
- Initiateur (acteur_id)
- Motif de l'attribution
- Périmètre (faculté, programme, ou global)
- Date d'effet (début_le)
- Date d'expiration éventuelle (expire_le)
- Statut (active, revoquée, expirée)
- Origine de la demande (directe, demande à quatre yeux, urgence)
- Validateur (approbateur_id, le cas échéant)
- Référence d'approbation (demande_id, le cas échéant)

### 1.1.2 Processus d'attribution individuelle
1. Sélection du bénéficiaire
2. Sélection du rôle à attribuer
3. Spécification du périmètre (faculté le cas échéant)
4. Ajout du motif (obligatoire pour les attributions sensibles)
5. Validation serveur (vérification des permissions de l'acteur, non-auto-approbation)
6. Transaction atomique : création de l'affectation, mise à jour du compte, journalisation
7. Invalidation du cache d'autorisation (`MoteurAutorisation::invalider()`)
8. Notification après commit (demande reçue, accès attribué)

### 1.1.3 Exemple
Attribution du rôle `admin_faculte` à l'utilisateur X pour la faculté F :
- Initiateur : super_admin Y
- Motif : "Remplacement de l'administration temporaire"
- Périmètre : faculté F
- Début : immédiat
- Expiration : dans 30 jours
- Approbation : requise (1 approbateur via config)

## 1.2 Attribution collective

### 1.2.1 Modes
Prévoir deux modes lors de la sélection multiple d'utilisateurs (cases à cocher) :

**Mode 1 : Affectation identique à tous**
- Même rôle à tous les utilisateurs sélectionnés
- Même périmètre pour tous (ou facultatif)
- Avant exécution, aperçu des conséquences pour chaque bénéficiaire
- Erreur sur un utilisateur → arrêt de l'opération, pas d'affectation partielle non contrôlée

**Mode 2 : Affectation personnalisée par utilisateur**
- Chaque utilisateur peut recevoir un rôle différent
- Ou même rôle mais avec des périmètres différents par utilisateur
- Aperçu individuel pour chaque bénéficiaire avant exécution
- Tableau de résultat par utilisateur (succès/échec avec motif)

### 1.2.2 Stratégie transactionnelle
- Transaction atomique couvrant tous les bénéficiaires
- Rapport de résultat pour chaque bénéficiaire (succès/échec)
- Si échec sur un bénéficiaire : rollback de l'ensemble ou continuation selon politique
- Journalisation de chaque résultat individuel

## 1.3 Contrôle d'autorité

### 1.3.1 Interdictions pour l'acteur qui attribue
L'acteur qui attribue un rôle ne peut pas :
- Créer un rôle supérieur à son autorité (comparaison des matrices de permissions)
- Attribuer une permission qu'il n'est pas habilité à déléguer (vérification via `MoteurAutorisation::origine()`)
- Étendre le périmètre de ses propres droits (contrôle par `faculteRestreinteId()`)
-Attribuer un rôle global depuis un périmètre facultaire (vérification que l'acteur a `estSuperAdmin()` ou étendue globale)
- Modifier son propre niveau de privilège (interdiction `cible_id !== acteur_id` dans `AttributionRoleService`)
- Approuver sa propre demande (contrôle dans workflow `DemandeIam` + `ApprobationIam`)

### 1.3.2 Permissions de délégation distinctes
Les permissions de délégation doivent être distinctes des permissions d'utilisation. Par exemple, `demandes.approve` ne signifie pas automatiquement `demandes.assign`.

## 1.4 Révocation

### 1.4.1 Types de révocation
- Révocation d'un rôle spécifique pour un utilisateur
- Révocation d'une affectation spécifique
- Révocation d'une délégation
- Révocation de toutes les affectations d'un utilisateur (selon autorisation)
- Suspension temporaire (changement de statut en `suspendue`)

### 1.4.2 Identification des conséquences
Avant révocation, identifier :
- Les dépendances (quelles permissions l'utilisateur perd-il ?)
- Les autres rôles qui pourraient couvrir le même besoin
- L'impact sur les données ou processus dont l'utilisateur est responsable
- Les notifications à émettre

### 1.4.3 Conservation de l'historique
- Conserver obligatoirement l'historique des révocations
- Ne jamais supprimer les lignes de journalisation
- Les affectations révoquées restent consultables en lecture seule pour l'historique
- Les rôles archivés conservent leur historique d'affectations

## 1.5 Protection du dernier administrateur

### 1.5.1 Interdits
- Suppression du dernier compte d'administration critique
- Désactivation du seul compte pouvant restaurer l'accès
- Révocation de tous les super-administrateurs en même temps
- Verrouillage irréversible de l'administration

### 1.5.2 Protections techniques
- Garde dans `AttributionRoleService::attribuer()` : `if ($autresActifs === 0) { refuser('Impossible : au moins un super-admin actif doit subsister.', 403); }`
- Vérification dans `MoteurAutorisation` : au moins un super-admin actif doit exister
- Avertissement dans le cockpit IAM si le nombre de super-admins actifs descend en dessous d'un seuil
- Interface : désactivation grise du bouton de révocation du dernier super-admin

## État de l'attribution et révocation
- Attribution individuelle tracée (acteur, motif, périmètre, date)
- Attribution collective avec deux modes (identique / personnalisée)
- Contrôle d'autorité strict (pas d'auto-approbation, pas d'élévation non autorisée)
- Révocation avec identification des conséquences
- Historique conservé, jamais supprimé
- Protection dernier administrateur active et testée

## Livrables
- `LOOP-43BIS-ATTRIBUTION-ROLES.md`

**Verdict G43BIS-11 :** ✅ Attribution/Révocation — attribution individuelle avec enregistrement complet, attribution collective en deux modes, contrôle d'autorité (pas d'auto-approbation, garde dernier admin), révocation avec conséquences identifiées, historique conservé, protection dernier administrateur active.