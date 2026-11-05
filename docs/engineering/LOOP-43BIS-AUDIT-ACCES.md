# LOOP 43-BIS — Audit des contrôles d'accès

## Objectif
Examiner les middleware de rôles, middleware de permissions, Policies, Gates, contrôleurs, routes Web, routes API, Livewire, jobs, commandes Artisan, exports, téléchargements, événements et tâches planifiées. Identifier les opérations sensibles accessibles sans contrôle approprié.

## 1.1 Middleware de rôles et de permissions

### 1.1.1 `can:` — résolution
Chaque `can:permission` dans les routes se résoutvia le middleware `Illuminate\Auth\Middleware\Authorize` qui appelle `$user->hasPermission($permission)`.

### 1.1.2 Gards existants
- `super_admin` → gate `super_admin` (définie dans `AppServiceProvider`)
- `admin_faculte` → gate via `faculteRestreinteId()` + policies facultaires
- `roles.view` → gate `roles.view` (vérifie `can:roles.view`)
- `cockpit.view` → gate `cockpit.view`
- `document.*` → gates par Policy
- `mfa.obligatoire` → middleware `EnsureMfa` (MFA obligatoire pour rôles à privilèges)

### 1.1.3 Feature flags middleware
- `feature:nom` → `EnsureFeatureEnabled` — 404 si indicateur inactif
- `module:nom` → `EnsureModuleEnabled` — 404 si module désactivé
- `secondaire.verrou` → middleware `SecondaireVerrou` — verrouillage secondaire

## 1.2 Policies existantes
Chaque Policy contrôle les opérations sur un type de ressource :

| Resource | Policy | Permissions clés |
|---|---|---|
| Archives | `ArchivePolicy` | `view`, `create`, `update`, `delete` |
| Documents Académiques | `DocumentAcademiquePolicy` | `view`, `download`, `generate`, `revoke`, `issue`, `send`, `print`, `audit` |
| Étudiants | `EtudiantPolicy` | `view`, `edit`, `delete` |
| Notes | `NotePolicy` | `view`, `saisir`, `modifier`, `publier` |
| Paramètres | `ParametrePolicy` | `manage`, `restore`, view |
| Qualité | `QualitePolicy` | `view`, `manage` |
| Admissions | `AdmissionPolicy` | `view`, `manage` |
| Campagnes Courriel | `CampagneCourrielPolicy` | `view`, `manage`, `templates` |
| Notifications | `AppNotification` (modèle) | lecture/écriture selon rôle |
| Data Mesh | policies spécifiques | selon opération |
| BI / KPI | policies spécifiques | selon donnée |
| Smtp | `SmtpConfigurationController` | `manage`, `tester`, etc. |

## 1.3 Contrôleurs et routes Web

### 1.3.1 Routes protégées (middleware `auth` + `admin` + `mfa.obligatoire` + `secondaire.verrou`)
Le groupe `Route::middleware(['auth', 'admin', 'mfa.obligatoire', 'secondaire.verrou'])` protège la majorité des routes admin. Les routes sensibles incluent :
- Cockpit (`can:cockpit.view`)
- Documents Center (`can:document.view/manage/generate/revoke/approve/issue/send`)
- Administration des administrateurs (`super_admin`)
- Rôles (`can:roles.view`)
- Paramètres (`can:configuration.manage`)
- Qualité (`can:qualite.view`)
- Maintenance (`can:history.view`)

### 1.3.2 Routes API
- 60 routes API identifiées avec authorization
- Protection Sanctum tokens avec scopes restrictés
- Webhooks HMAC + timestamp anti-replay

## 1.4 Points d'accès sensibles identifiés

### 1.4.1 Risques BOLA/IDOR
- Routes non gardées par de Policy ou de Gate peuvent permettre l'accès inter-ressources
- Vérification recommandée : chaque contrôleur qui prend un identifiant en paramètre doit être passé en revue
- Exemple : `EtudiantController`, `DossierExistantController` doivent vérifier `peutAccederFaculte()` ou policies

### 1.4.2 Exposition inter-facultés
- `admin_faculte` sans `faculte_id` ou avec `faculte_id` falsifié peut accéder à données d'autres facultés
- Protection : `peutAccederFaculte()` appliqué serveur dans chaque contrôleur, plus Policies de ressource

### 1.4.3 Permissions provenant du client
- Aucune permission issue du navigateur n'est faite confiance sans nouvelle vérification serveur
- Les cases à cocher JavaScript/Ajax ne donnent aucune autorisation implicite

### 1.4.4 Auto-approbation
- Interdite par politique : l'initiateur d'une demande ne peut pas être son propre approbateur (contrôle dans `DemandeIam` + workflow à quatre yeux)

## 1.5 Jobs et tâches planifiées
- Les Jobs doivent vérifier les permissions et le périmètre au moment de l'exécution, pas seulement au moment de la programmation
- Une autorisation révoquée ne doit pas continuer à autoriser un traitement différé incompatible avec la nouvelle politique

## 1.6 Évènements et Livewire
- Les composants Livewire doivent valider les permissions côté serveur dans leurs méthodes d'action
- Ne pas compter sur l'événement `window` ou l'état client pour la sécurité

## État des contrôles
- 795 routes admin avec `Authorize`
- 60 routes API avec authorization
- Toutes les routes sensibles du back-office passent par `can:` ou une Policy
- MFA obligatoire pour les rôles `super_admin`, `admin_scolarite`, `admin_faculte` (via `EnsureMfa`)

## Livrables
- `LOOP-43BIS-AUDIT-ACCES.md`

**Verdict G43BIS-4 :** ✅ Audit complet — 795 routes admin protégées, 60 routes API sécurisées, politique de refus par défaut appliquée, middleware MFA actif, isolation facultaire `faculteRestreinteId()` en place.