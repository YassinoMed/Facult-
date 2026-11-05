# LOOP 43-BIS — Architecture de gouvernance IAM/RBAC/ABAC

## Objectif
Définir une architecture unique permettant de gérer les rôles, les permissions et leurs périmètres, sans créer un deuxième moteur d'autorisation concurrent.

## 1.1 Principes directeurs

### 1.1.1 Réspect de l'existant
- `Permissions::TOUTES` reste la source canonique des CODES
- `AppServiceProvider` enregistre les Gates depuis `Permissions::TOUTES`
- Les Policies existantes ne sont pas remplacées
- Le middleware `can:` continue de résoudre en `Authorize:<permission>`
- `faculteRestreinteId()` dans `User` reste le mécanisme d'isolation facultaire

### 1.1.2 Pas de double source de vérité
- Une seule matrice de rôles (`Permissions::ROLES` + `Permissions::pourRole()`)
- Une seule liste de permissions centrales (`Permissions::TOUTES`)
- Le `MoteurAutorisation` est ADDITIF — il complète, il ne remplace pas
- Les permissions effectives = matrice + accès supplémentaires (profil/affectation/délégation/urgence)
- Le cache d'évaluation est invalideé à chaque mutation de gouvernance

### 1.1.3 Moindre privilège
- Refus par défaut : une permission inconnue est refusée
- Contexte facultaire requis sauf pour les rôles globaux (`super_admin`)
- Périmètre par défaut = faculté de l'utilisateur (`faculteRestreinteId()`)
- Les profils n'accordent que la version approuvée qui leur a été affectée

### 1.1.4 Absence d'escalade
- Le délégant doit détenir la permission directement (pas de chaîne de délégation)
- Le bénéficiaire d'une urgence n'est pas le déclencheur
- Un profil ne peut pas s'attribuer soi-même
- Le dernier super-admin est protégé (garde dans `AttributionRoleService`)

## 1.2 Couches de l'architecture

### 1.2.1 Couche CODE (infrastructure)
- `App\Support\Permissions` — matrice RBAC canonique
- `App\Support\CataloguePermissions` — métadonnées et hiérarchie
- `config/iam.php` — paramètres de gouvernance (durée, approbateurs, rôles réservés)
- Gates définis dans `AppServiceProvider::register()`
- Policies existantes pour chaque ressource

### 1.2.2 Couche SERVICE (moteur d'évaluation)
- `App\Support\Iam\MoteurAutorisation` — moteur unique d'autorisation
- `App\Services\Iam\ServiceAuditIam` — journal append-only
- `App\Services\Iam\ServiceProfilsIam` — cycle de vie des profils
- `App\Services\Iam\ServiceAffectationsIam` — affectations profil/utilisateur
- `App\Services\Iam\ServiceDelegationsIam` — délégations bornées
- `App\Services\Iam\ServiceUrgenceIam` — break-glass/urgence
- `App\Services\Iam\ServiceRecertificationIam` — recertification des accès
- `App\Services\Iam\CockpitIam` — tableau de bord de sécurité

### 1.2.3 Couche MODÈLE (données additifs)
- `App\Models\Iam\ProfilIam` — profils d'habilitation
- `App\Models\Iam\AffectationIam` — affectations utilisateur → profil
- `App\Models\Iam\HabilitationIam` — habilitations directes permission
- `App\Models\Iam\DelegationIam` — délégations utilisateur → permission
- `App\Models\Iam\UrgenceIam` — accès d'urgence break-glass
- `App\Models\Iam\RecertificationIam` — campagnes de recertification
- `App\Models\Iam\EvenementIam` — journal append-only
- `App\Models\Iam\VersionAutorisationIam` — compteur de version

### 1.2.4 Couche INTERFACE (admin)
- Sélections par checkbox (obligatoire UX)
- Matrices hiérarchiques Module → Ressource → Action
- Recherche et filtres sur le catalogue
- Récapitulatif avant enregistrement
- Confirmation explicite requise
- Le serveur reconstruit et valide l'intégralité — aucune confiance au navigateur

### 1.2.5 Couche CONTRÔLE (sécurité)
- `MoteurAutorisation::autorise()` — décision unifiée
- `User::hasPermission()` — fallback matrice + moteur
- `User::originePermission()` — origine de la permission
- `MoteurAutorisation::invalider()` — invalidation de cache
- `ServiceAuditIam::enregistrer()` — journalisation append-only
- Revues indépendantes pour les opérations critiques (urgences, recertification)

## 1.3 Flux d'autorisation

### 1.3.1 Évaluation standard
1. `$user->hasPermission($permission)` → fallback matrice + moteur
2. Si non trouvé en matrice → vérifier profils/affectations/délégations/urgences actives
3. Si trouvé avec portée facultaire → vérifier `faculteRestreinteId()` ou contexte explicite
4. Si non trouvé → refusé par défaut
5. Résultat explicable : `autorisé`, `refusé`, `rôle absent`, `permission absente`, `périmètre interdit`, `affectation expirée`, `compte inactif`, `MFA insuffisant`, `revue humaine nécessaire`

### 1.3.2 Flux avec MFA
1. Étape normale ci-dessus
2. Si la permission a `mfa_obligatoire` = `true` → vérifier `MfaPolicy::exigePour($user)`
3. Si MFA non active → rediriger vers `admin.mfa.configurer` (sauf routes d'enrôlement)
4. Si MFA active → poursuivre l'évaluation

### 1.3.3 Flux avec approbation (quatre yeux)
1. Étape normale ci-dessus
2. Si la permission ou l'opération nécessite approbation → vérifier le workflow `DemandeIam` + `ApprobationIam`
3. L'initiateur ne peut pas approuver sa propre demande
3. Le nombre d'approbateurs requis lu dans `config('iam.approbateurs_requis', 1)`
4. Validation finale seulement après avoir atteint le quorum

## 1.4 Exclusion des modules
- Le module `iam` est dans `modules_reserves` → ses permissions ne sont PAS administrées par l'interface de gouvernance générale
- Le module `ai` est dans `modules_federes_lecture_seule` → ses permissions s'affichent en lecture seule dans le catalogue fédéré, aucune attribution via la gouvernance IAM tant que le module n'est pas raccordé au moteur central
- Ces exclusions sont configurables via `config/iam.php`

## État de l'architecture
- Architecture CORE indépendante des modules respectée
- `MoteurAutorisation` additive, aucun Gate concurrent créé
- Toutes les permissions gouvernées par le code (`Permissions::TOUTES`)
- Isolation facultaire stricte via `faculteRestreinteId()`
- Moindre privilège, refus par défaut
- MFA intégré au flux d'autorisation
- Profils bornés, approuvés, versionnés
- Délégations sans chaîne, vérifiées à chaque évaluation
- Urgences temporelles, revue postérieure obligatoire
- Recertification périodique avec assignation de réviseurs

## Livrables
- `LOOP-43BIS-ARCHITECTURE.md`

**Verdict G43BIS-8 :** ✅ Architecture conforme — `Permissions::TOUTES` source canonique, `MoteurAutorisation` additive sans Gate concurrent, architecture CORE/modules respectée, isolation facultaire `faculteRestreinteId()` en place, MFA intégré, moindre privilège, refus par défaut, profils versionnés, délégations sans chaîne, urgences temporelles, recertification avec réviseurs.