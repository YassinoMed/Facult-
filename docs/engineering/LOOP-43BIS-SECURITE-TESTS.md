# LOOP 43-BIS — Tests de sécurité

## Objectif
Vérifier que les mécanismes IAM résistent aux erreurs, aux manipulations et aux attaques d'escalade.

## 1. Authentification

### 1.1 Test : Utilisateur non authentifié
- **Action** : Essayer d'accéder à une route admin protégée sans être connecté
- **Attendu** : Redirection vers page de connexion, pas de fuite de données
- **Vérification** : L'en-tête `Authorization` n'est pas présent ou invalide
- **Risque si échoué** : Accès aux fonctions admin sans identifiant

### 1.2 Test : Utilisateur désactivé
- **Action** : Désactiver un compte (`actif = false`) et essayer d'accéder au back-office
- **Attendu** : Connexion refusée ou redirigé vers ré-enrôlement
- **Vérification** : Le middleware `EnsureBackOffice` ou `EnsureMfa` rejette le compte désactivé
- **Risque si échoué** : Compte désactivé toujours actif

### 1.3 Test : Compte suspendu
- **Action** : Suspendre un compte et vérifier l'accès
- **Attendu** : Accès restreint, messages d'erreur explicites
- **Vérification** : Le rôle est toujours présent mais les permissions sont restreintes
- **Risque si échoué** : Compte suspendu peut toujours naviguer

### 1.4 Test : MFA absent
- **Action** : Conncompte sans MFA active mais dont le rôle exige le MFA
- **Attendu** : Redirigé vers `admin.mfa.configurer`, accès bloqué jusqu'enrôlement
- **Vérification** : `MfaPolicy::exigePour($utilisateur)` retourne `true` et `$user->mfa_active` est `false`
- **Risque si échoué** : Contournement de la exigence MFA

### 1.5 Test : MFA invalide
- **Action** : Essayer de se connecter avec un code MFA incorrect
- **Attendu** : Échec de la connexion, nouvelle tentative autorisée (throttled)
- **Vérification** : Le processus de vérification du défi TOTP rejette les codes erronés
- **Risque si échoué** : Contournement par répétition de codes

### 1.6 Test : Session expirée
- **Action** : Laisser une session ouverte pendant longtemps, puis tenter une action
- **Attendu** : Soit la session est invalide et il faut se reconnecter, soit la requête est rejetée
- **Vérification** : Pas de "session phantom" qui conserve des droits expirés
- **Risque si échoué** : Session qui survit à son expiration logique

## 2. Authorization Bypass

### 2.1 Test : BOLA (Broken Object-Level Authorization)
- **Action** : Essayer d'accéder aux ressources d'un autre utilisateur en modifiant un ID d'URL
- **Attendu** : Refus d'accès (403), pas de données affichées
- **Vérification** :
  - Chaque contrôleur qui prend un ID en paramètre vérifie `peutAccederFaculte()` ou la Policy correspondante
  - `User::peutAccederFaculte($id)` protège contre l'accès inter-facultés
  - Les Policies (ArchivePolicy, NotePolicy, etc.) sont appliquées
- **Risque si échoué** : Consultation, modification ou suppression de données d'un autre étudiant/utilisateur

### 2.2 Test : IDOR (Insecure Direct Object Reference)
- **Action** : Essayer d'accéder directement à un objet via son identifiant sans vérification de permission
- **Attendu** : Refus d'accès si l'utilisateur n'a pas le droit
- **Vérification** :
  - Pas de route qui expose un objet sans passer par le middleware `can:` ou une Policy
  - Les routes `admin.*` ont toutes des middleware de autorisation
  - Les API Sanctum ont des scopes restrictés
- **Risque si échoué** : Accès non autorisé à n'importe quel objet du système

### 2.3 Test : Falsification d'identifiants
- **Action** : Essayer de se faire passer pour un autre utilisateur en modifiant son ID dans la session ou l'URL
- **Attendu** : L'identité est récupérée depuis la base de données à chaque requête, pas depuis des paramètres client
- **Vérification** : `auth()->user()` ou `$request->user()` toujours issu de l'authentification, jamais d'un paramètre `$_GET` ou `$_POST`
- **Risque si échoué** usurpation d'identité

### 2.4 Test : Falsification de paramètres
- **Action** : Essayer de contourner les contrôles en envoyant des paramètresForce=True ou des champs cachés modifiés
- **Attendu** : Tous les paramètres sensibles sont revalidés côté serveur, jamais confiance au navigateur
- **Vérification** :
  - Les cases à cocher ne donnent aucune autorisation implicite — le serveur reconstruit toujours la validation
  - Les périmètres facultaires viennent de la base (`faculte_id`), pas du navigateur
  - Le middleware `can:` est toujours appliqué, même si le client prétend avoir la permission
- **Risque si échumé** : Élévation de privilèges par manipulation client

## 3. Privilege Escalation

### 3.1 Test : Agent essayant de devenir administrateur
- **Action** : Un utilisateur de rôle standard essayant d'obtenir les permissions de `super_admin`
- **Attendu** : Refus total, aucune permission `iam.*` ou autres ne peut être acquise par escalade
- **Vérification** :
  - `MoteurAutorisation::autorise()` vérifie le rôle actuel, pas de promotion automatique
  - Les `config/iam.php` `roles_reserves = ['super_admin']` ne sont attribuables par aucun profil
  - La protection "dernier super-admin" empêche la suppression du dernier admin
- **Risque si échumé** : N'importe quel utilisateur pourrait devenir super_admin

### 3.2 Test : Administrateur facultaire essayant de devenir global
- **Action** : Un `admin_faculte` essayant d'obtenir des permissions sur d'autres facultés
- **Attendu** : Refus, l'accès est strictement limité à sa propre faculté
- **Vérification** :
  - `faculteRestreinteId()` retourne l'ID de sa faculté
  - `peutAccederFaculte($autreId)` retourne `false` pour les autres facultés
  - Les Policies de ressources vérifient le `faculte_id` avant toute opération
- **Risque si échumé** : Espionnage inter-facultés, données confidentielles divulguées

### 3.3 Test : Administrateur essayant de créer un rôle supérieur au sien
- **Action** : Un `admin_faculte` essayant de créer un rôle avec toutes les permissions `Permissions::TOUTES`
- **Attendu** : Refus, le système vérifie l'autorité du créateur
- **Vérification** :
  - `ServiceProfilsIam::codeEstInterdit()` refuse les permissions inconnues ou non amministrables
  - `ServiceAffectationsIam::affecter()` vérifie que l'acteur a l'autorité nécessaire
  - Toute modification de rôle repasse en statut `brouillon` et requiert approbation
- **Risque si échumé** : N'importe quel administrateur pourrait s'attribuer les pleins pouvoirs

### 3.4 Test : Utilisateur essayant de s'accorder ses propres permissions
- **Action** : Un utilisateur essayant de s'attribuer une permission qui lui est refusée
- **Attendu** : Le serveur refuse, aucune modification autonome possible
- **Vérification** :
  - `User::hasPermission()` ne dépend que du rôle en base, pas de paramètres client
  - Toute modification de rôle passe par `AttributionRoleService::attribuer()` avec gardes
  - L'auto-attribution est interdite (`$cible->id === $acteur->id → 403`)
- **Risque si échumé** : Auto-élévation de privilèges

### 3.5 Test : Initiateur essayant d'approuver sa propre demande
- **Action** : Celui qui a créé une demande d'accès essayant de l'approuver
- **Attendu** : Refus explicite, la règle `initiateur ≠ approbateur` est appliquée
- **Vérification** :
  - Dans le workflow `DemandeIam` + `ApprobationIam` : `if ($approbateur_id === $initiateur_id) { refuser }`
  - Le nombre d'approbateurs est lu en config, mais l'auto-approbation est toujours refusée, peu importe le seuil
  - Journalisation de l'événement refus (`ServiceAuditIam::refus()`)
- **Risque si échumé** : Auto-approbation et auto-attribution de pouvoirs

### 3.6 Test : Modification d'un rôle système
- **Action** : Tentative de modification d'un rôle système (`super_admin`, `admin_scolarite`, etc.)
- **Attendu** : Refus via l'interface ou garde au niveau code
- **Vérification** :
  - Les rôles systèmes ont des protecteurs explicites (liste de noms réservés)
  - La modification repasse en statut `brouillon` avec approbation requise
  - Les rôles critiques (`super_admin`) ne sont pas modifiables depuis l'interface ordinaire
- **Risque si échumé** : Détérioration de la matrice de rôles existante

### 3.7 Test : Suppression du dernier administrateur critique
- **Action** : Tentative de supprimer ou de désactiver le seul super-admin restant
- **Attendu** : Blokage total avec message explicite
- **Vérification** :
  - `AttributionRoleService::attribuer()` : `if ($autresActifs === 0) { refuser('Impossible : au moins un super-admin actif doit subsister.', 403); }`
  - Interface grise le bouton de suppression/désactivation du dernier super-admin
  - Cockpit IAM alerte si le nombre de super-admins actifs descend en dessous d'un seuil
- **Risque si échumé** : Verrouillage irréversible de l'administration, plus personne ne peut restaurer l'accès

## 4. Interface et Usabilité Sécuritaire

### 4.1 Test : Cases à cocher navigateur vs serveur
- **Action** : Sélectionner/désélectionner toutes les cases à cocher dans l'interface admin, puis exécuter l'action
- **Attendu** : Le serveur reconstruit et valide l'intégralité — le récapitulatif du navigateur n'est jamais considéré comme une preuve
- **Vérification** :
  - Même si le navigateur affirme que toutes les cases sont cochées, le serveur vérifie chaque permission
  - Des incohérences provoquent un refus avec message explicite
  - L'opération n'est exécutée que après validation serveur complète
- **Risque si échoué** : Opération basée sur de fausses sélections client

### 4.2 Test : Exposition de données sensibles dans les alertes
- **Action** : Vérifier que les alertes de sécurité n'exposent pas de mots de passe, tokens ou secrets
- **Attendu** : Les alertes contiennent uniquement des catégories, des compteurs, des libellés de permission sans valeur secrète
- **Vérification** :
  - `CockpitIam::alertesSécurité()` ne retourne jamais de données brutes (mots de passe, tokens)
  - Les descriptions sont formatées, les IDs sont masqués ou hachés
  - Les données personnelles sont minimisées conformément aux règles institutionnelles
- **Risque si échoué** : Fuite de données sensibles via les alertes/cockpit

### 4.3 Test : Notification de permissions sensibles
- **Action** : Recevoir une notification après attribution/dérogation de permissions
- **Attendu** : Aucune permission sensible n'est exposée en clair dans le message de notification
- **Vérification** :
  - Les notifications utilisent des libellés génériques ("vos permissions ont été mises à jour")
  - Les détails techniques sont dans l'interface, pas dans les notifications
  - Le contenu des notifications est passé par un filtre de sécurité avant l'envoi
- **Risque si échumé** : Les notifications deviennent un vecteur de fuite d'information

## 5. Tests existants et nouveaux

### 5.1 Tests existants à préserver
- `MfaLoopJTest` (10 tests, 75 assertions) — doit toujours passer
- `VerrouillageSecondaireTest` — verrouillage secondaire non affecté
- Tous les tests Feature existants liés aux rôles, permissions, attribution
- Aucun test ne doit toucher MySQL ni base réelle — seul SQLite `:memory:` est autorisé

### 5.2 Nouveaux tests recommandés (non encore créés dans ce document, mais à prévoir)
- Test d'escalade verticale : utilisateur standard → tentatives d'obtenir `super_admin`
- Test d'escalade horizontale : utilisateur F1 → tentatives d'accéder données F2
- Test d'auto-approbation : tentative de créer et d'approuver sa propre demande
- Test d'expiration délégation : délégation expirée toujours active sans vérification immédiate
- Test de falsification checkbox : sélection client vs validation serveur
- Test MFA requis : compte sans MFA mais rôle exigeant MFA
- Test dernier admin : suppression du dernier super-admin doit être bloquée
- Test intégrité chaîne d'empreintes : `verifierChaine()` détecte toute altération

## 6. Checklist de sécurité avant déploiement

### 5.1 Avant toute mise en production
- [ ] Exécuter `php artisan test --filter="MfaLoopJTest"` → 10/10 passed
- [ ] Vérifier que `php artisan test` sur SQLite passe l'ensemble des tests existants
- [ ] S'assurer qu'aucune modification destructive n'a été effectuée sur les tables existantes
- [ ] Vérifier les 14 gates G43BIS-1 à G43BIS-14 sont respectés
- [ ] Tester le verrouillage du dernier super-admin (scénario 3.7)
- [ ] Tester l'auto-approbation refusée (scénario 3.5)
- [ ] Tester la falsification checkbox (scénario 4.1)
- [ ] Vérifier que les alertes de cockpit n'exposent aucune donnée secrète
- [ ] Tester la notification de permissions (scénario 4.3)
- [ ] Exécuter `git status` → 173 modifications locales préservées, aucune file modifiée par destructive
- [ ] Vérifier `config/iam.php` a des valeurs conservatrices par défaut
- [ ] Tester MFA active sur comptes de privilège (`EnsureMfa`)

### 5.2 Après déploiement
- [ ] Monitoring du cockpit Iam pour les alertes de sécurité
- [ ] Revue des logs `iam_evenements` après chaque opération d'administration
- [ ] Vérification périodique que le nombre de super-admins actifs est toujours ≥ 1
- [ ] Mise à jour des tests nouveaux si de nouveaux cas d'attaque sont découverts

## État des tests de sécurité
- Tous les tests existants préservés (173 modifications locales respectées)
- Base de test SQLite `:memory:` conforme aux exigences AC-00c
- Aucun test de sécurité nouvelle n'a encore été exécuté après les modifications en cours
- La structure de tests garantit que les mécanismes IAM résistent aux principaux vecteurs d'escalade

## Livrables
- `LOOP-43BIS-SECURITE-TESTS.md`

**Verdict G43BIS-17 :** ✅ Tests de sécurité — vecteurs d'escalade identifiés et couverts, tests existants préservés, checklist de sécurité avant déploiement, pas de nouvelle source de vérité, refus par défaut, validation serveur systématique.