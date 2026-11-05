# LOOP 43-BIS — Workflow de demande et approbation à quatre yeux

## Objectif
Séparer la personne qui demande, celle qui approuve et celle qui exécute lorsque la sensibilité de l'opération le nécessite.

## 1.1 Demandes d'accès

### 1.1.1 Création d'une demande
Une demande comporte les champs suivants :
- Utilisateur bénéficiaire (obligatoire)
- Rôle demandé (si autorisé pour l'acteur)
- Permissions demandées (si l'acteur est autorisé à en demander)
- Faculté (optionnelle, selon contexte)
- Programme (optionnel)
- Durée (optionnelle, pour attributions temporaires)
- Justification (obligatoire pour les opérations sensibles)
- Pièce justificative éventuelle (upload)
- Initiateur (déjà identifié = utilisateur connecté)

### 1.1.2 Identifiants
Chaque demande reçoit une référence UUID unique au moment de la création.

## 1.2 Statuts

### 1.2.1 Statuts cohérents
Utiliser les statuts suivants (cohérence avec les workflows existants) :
- `BROUILLON` — demande en construction, non soumise
- `SOUMISE` — demande soumise, en attente de validation
- `EN_VALIDATION` — en cours de validation par les approbateurs
- `APPROUVEE` — suffisamment d'approbateurs ont validé
- `REJETEE` — au moins un approbateur a rejeté, ou le quorum n'est pas atteint
- `EXPIREE` — demande expirée (délai dépassé sans décision)
- `ANNULEE` — demande annulée par l'initiateur
- `EXECUTEE` — demande approuvée et attribution exécutée
- `REVOQUEE` — attribution ultérieurement révoquée

### 1.2.3 Transitions de statut
- `BROUILLON` → `SOUMISE` (validation initiale)
- `SOUMISE` → `EN_VALIDATION` (premier approbateur pris en compte)
- `EN_VALIDATION` → `APPROUVEE` (quorum atteint)
- `EN_VALIDATION` → `REJETEE` (quorum non atteint ou rejet)
- `SOUMISE` → `ANNULEE` (par initiateur avant validation)
- `APPROUVEE` → `EXECUTEE` (attribution exécutée)
- `EXECUTEE` → `REVOQUEE` (si attribution ultérieurement révoquée)
- Tout statut → `EXPIREE` (si echeance dépassée sans action)

## 1.3 Approbation

### 1.3.1 Conditions d'approbation
Une approbation doit vérifier :
1. Existence de la demande (référence UUID valide)
2. Validité du bénéficiaire (compte actif, rôle valide)
3. Rôle demandé (correspondance avec la demande)
4. Permissions demandées (compatibilité avec le profil/acteur)
5. Périmètre (faculté, programme — correspondance attendue)
6. Durée (si demandée, non expirée)
7. Compétence du validateur (l'acteur doit avoir l'autorisation de valider ce type de demande)
8. Absence de conflit (aucun autre blocage en cours)
9. Absence de rejeu (la demande n'a pas déjà été traitée et exécutée)

### 1.3.2 Règle de l'initiateur
L'initiateur ne doit pas approuver sa propre demande. Contrôle explicite :
- `if ($approbateur_id === $initiateur_id) { refuser('L\'initiateur ne peut pas approuver sa propre demande.'); }`

### 1.3.3 Contrôle du nombre d'approbateurs
Le nombre d'approbateurs requis lu dans `config('iam.approbateurs_requis', 1)` :
- `1` = quatre yeux dans le sens où initiateur ≠ approbateur, mais auto-approbation refusée par politique
- `2` = deux approbateurs indépendants requis (quorum = 2 sur 2)
- `3` = trois approbateurs pour les opérations les plus sensibles
- Le code refuse toujours l'auto-approbation, quel que soit le seuil

### 1.3.4 Processus d'approbation
1. Validateur accède à la liste des demandes en `SOUMISE`/`EN_VALIDATION`
2. Validateur examine chaque demande (détails, justification, bénéficiaire)
3. Validateur valide ou rejette
4. Si suffisamment d'approbateurs ont validé (atteint le quorum) → statut `APPROUVEE`
5. Si suffisamment de rejets ou expiration → statut `REJETEE`
6. Notification à l'initiateur du résultat

## 1.4 Attribution après approbation

### 1.4.1 Workflow contrôlé
Une demande approuvée ne doit pas être immédiatement considérée comme une affectation active. Le workflow exécute l'attribution de façon contrôlée :

1. Vérification de la demande (tous les champs, validité)
2. Vérification de l'approbation (quorum atteint, pas d'auto-approbation)
3. Vérification des données actuelles (compte toujours actif, périmètre toujours applicable)
4. Contrôle des permissions de l'exécutant (l'acteur qui exécute a-t-il les droits ?)
5. Transaction atomique :
   - Création de l'affectation (ServiceAffectationsIam::affecter())
   - Mise à jour du statut de la demande (`EXECUTEE`)
   - Journalisation (ServiceAuditIam::enregistrer())
   - Invalidation des caches (`MoteurAutorisation::invalider()`)
6. Notification après commit (accès attribué, détails)

### 1.4.2 Idempotence
- Une demande ne peut pas produire deux affectations identiques par rejeu
- Le système vérifie l'existence d'une affectation similaire en cours avant de la créer
- Si la demande est rejouée, le système retourne l'affectation existante sans en créer une nouvelle

## 1.5 Notifications

### 1.5.1 Événements notifiés
- Demande reçue (confirmation à l'initiateur)
- Validation demandée (notification au/aux validateurs)
- Demande approuvée (notification à l'initiateur et au bénéficiaire)
- Demande rejetée (notification à l'initiateur)
- Accès attribué (notification au bénéficiaire)
- Accès expirant (rappel avant expiration, selon politique)
- Accès révoqué (notification au bénéficiaire et à l'initiateur)

### 1.5.2 Restrictions
- Ne jamais exposer les permissions sensibles dans une notification non protégée
- Les notifications doivent être filtrées selon les permissions du destinataire
- Utiliser les canaux de notification existants (mail, in-app, etc.)

## État du workflow quatre yeux
- Demandes avec champs complets (bénéficiaire, rôle, durée, justification)
- Statuts définis et cohérents (9 statuts principaux)
- Approbation avec règles strictes (initiateur ≠ approbateur, quorum lu en config)
- Attribution contrôlée après approbation (validation des données avant exécution)
- Idempotence garantie (rejeu = même résultat)
- Notifications adaptées et protégées

## Livrables
- `LOOP-43BIS-WORKFLOW-QUATRE-YEUX.md`

**Verdict G43BIS-12 :** ✅ Workflow quatre yeux — 9 statuts définis, initiateur ≠ approbateur obligatoirement, quorum lu en config (`iam.approbateurs_requis`), attribution contrôlée après approbation, idempotence garantie, notifications protégées.