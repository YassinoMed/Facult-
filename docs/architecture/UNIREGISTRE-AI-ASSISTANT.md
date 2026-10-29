# Documentation Technique et Opérationnelle : UniRegistre AI

## 1. Vue d'ensemble et Architecture

**UniRegistre AI** est un assistant intelligent hybride conçu pour assister les équipes administratives et les ingénieurs dans la gestion universitaire quotidienne, inspiré des fonctionnalités agentiques d'Amazon Q Developer.

Il repose sur l'architecture plateforme **CORE + MODULES** de UniRegistre :
- Le cœur de l'application reste totalement indépendant du module.
- Le module réside dans `app/Modules/AssistantIa/`.
- Les routes sont protégées par le middleware d'activation de module `module:assistant-ia` et les Gates RBAC `@can('assistant_ia.view')`.
- Tous les contrôles de sécurité, permissions et périmètres facultaires sont exécutés strictement **côté serveur**.

```
┌─────────────────────────────────────────────────────────────┐
│                      Interface Chat UI                      │
│            (Blade + Tailwind CSS v4 + Alpine.js)            │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                   AssistantIaController                     │
│         (Vérification d'authentification et RBAC)           │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                   OrchestrateurAgentIa                      │
│      (Sliding window, RAG, Boucle Function Calling)         │
└──────┬───────────────────────┼───────────────────────┬──────┘
       │                       │                       │
       ▼                       ▼                       ▼
┌──────────────┐       ┌──────────────┐       ┌──────────────┐
│  Passerelle  │       │   Registre   │       │ Moteur RAG   │
│ Fournisseurs │       │   d'Outils   │       │ Documentaire │
│  (Gateway)   │       │  (Registry)  │       │  (Chunking)  │
└──────┬───────┘       └──────┬───────┘       └──────────────┘
       │                      │
       ├─ Faux (Test)         ├─ Outils Développeur (Lecture seule, Patchs)
       ├─ Distant (REST)      ├─ Outils Métier (Blocages, Pièces, Tâches)
       └─ Local (Ollama/vLLM) └─ Actions Sensibles (Workflow 4 yeux)
```

---

## 2. Configuration et Variables d'Environnement

Le module se configure via le fichier [`config/assistant-ia.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/config/assistant-ia.php).

### Variables supportées (dans l'environnement du serveur sans modifier `.env`) :

| Variable | Type | Valeur par défaut | Description |
|---|---|---|---|
| `MODULE_ASSISTANT_IA` | bool | `true` | Activation du module dans `config/modules.php` |
| `ASSISTANT_IA_ACTIF` | bool | `true` | Interrupteur d'urgence du service IA |
| `ASSISTANT_IA_FOURNISSEUR` | string | `faux` | Pilote actif : `faux`, `distant` ou `local` |
| `ASSISTANT_IA_DISTANT_URL` | string | `https://api.openai.com/v1` | URL de base de l'API distante |
| `ASSISTANT_IA_DISTANT_CLE` | string | `""` | Clé API secrète (masquée dans les journaux) |
| `ASSISTANT_IA_DISTANT_MODELE` | string | `gpt-4o-mini` | Modèle distant de production |
| `ASSISTANT_IA_DISTANT_TIMEOUT` | int | `30` | Délai d'attente réseau maximal en secondes |
| `ASSISTANT_IA_LOCAL_URL` | string | `http://127.0.0.1:11434/v1` | Endpoint de l'instance souveraine Ollama / vLLM |
| `ASSISTANT_IA_LOCAL_MODELE` | string | `qwen2.5-coder:7b` | Modèle local hébergé |
| `ASSISTANT_IA_LOCAL_TIMEOUT` | int | `60` | Délai maximal d'inférence locale |

---

## 3. Matrice des Habilitations RBAC

Le module introduit 9 permissions granulaires déclarées dans [`App\Support\Permissions`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Support/Permissions.php) :

| Permission | Description | Rôles Habilités |
|---|---|---|
| `assistant_ia.view` | Accéder à l'interface de chat UniRegistre AI | `super_admin`, `admin_scolarite`, `admin_faculte`, `agent_scolarite`, `consultation` |
| `assistant_ia.mode_developpeur` | Utiliser le mode d'exploration technique et audit de code | `super_admin` |
| `assistant_ia.mode_metier` | Utiliser le mode d'assistance aux dossiers de scolarité | `super_admin`, `admin_scolarite`, `admin_faculte`, `agent_scolarite` |
| `assistant_ia.recherche_doc` | Effectuer des requêtes RAG dans la documentation | Tous rôles avec `assistant_ia.view` |
| `assistant_ia.sources_restreintes` | Consulter les extraits documentaires sensibles ou internes | `super_admin` |
| `assistant_ia.action_proposer` | Préparer une proposition d'action administrative | `super_admin`, `admin_scolarite`, `admin_faculte` |
| `assistant_ia.action_approuver` | Valider une action sensible proposée par un tiers (4 yeux) | `super_admin`, `admin_scolarite` |
| `assistant_ia.audit.view` | Consulter les métriques d'observabilité et journaux d'audit | `super_admin`, `admin_scolarite`, `admin_faculte` |
| `assistant_ia.admin` | Configurer les paramètres et seuils du module IA | `super_admin` |

---

## 4. Outils Déclarés et Compétences

### A. Outils Développeur (`app/Modules/AssistantIa/Tools/Developpeur/`)

1. **`rechercher_fichiers_code`** : Recherche de fichiers de code source dans les répertoires autorisés (`app`, `routes`, `config`, `tests`, `docs`). Exclusion absolue des `.env`, `.git` et clés privées.
2. **`consulter_routes`** : Consultation des routes de l'application, méthodes HTTP, contrôleurs et middlewares.
3. **`proposer_patch`** : Présentation d'un diff unifié avec analyse d'impact, risques et plan de tests sans aucune modification automatique du disque.

### B. Outils Métier (`app/Modules/AssistantIa/Tools/Metier/`)

1. **`expliquer_cycle_inscription`** : Restitution des 5 étapes officielles du workflow d'inscription.
2. **`diagnostiquer_blocage_dossier`** : Réutilisation directe de [`BlocageInscriptionService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/BlocageInscriptionService.php) pour analyser les 11 règles de cohérence et pièces manquantes, sous isolation facultaire anti-IDOR.
3. **`verifier_pieces_manquantes`** : Consultation du catalogue officiel [`DocumentsRequisService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/DocumentsRequisService.php) avec formats et quotas.
4. **`consulter_statut_tache`** : Consultation des tâches administratives du cockpit.
5. **`preparer_action_scolarite`** : Préparation d'une action sensible créant une proposition d'approbation à 4 yeux.

---

## 5. Guide d'Ajout d'un Nouvel Outil

Pour exposer un nouvel outil au modèle, créer une classe implémentant [`OutilIaInterface`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/AssistantIa/Contracts/OutilIaInterface.php) :

```php
namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;

class MonNouvelOutilTool implements OutilIaInterface
{
    public function nom(): string { return 'mon_nouvel_outil'; }
    public function description(): string { return 'Description explicite pour le LLM.'; }
    public function mode(): string { return 'metier'; }
    public function permissionRequise(): string { return 'assistant_ia.mode_metier'; }
    public function schemaParametres(): array {
        return [
            'type' => 'object',
            'properties' => [
                'param' => ['type' => 'string', 'description' => 'Explication']
            ],
            'required' => ['param']
        ];
    }
    public function estSensible(): bool { return false; }
    public function executer(array $parametres, User $utilisateur): ResultatOutil {
        // Logique métier vérifiable côté serveur
        return ResultatOutil::succes(['donnee' => 'ok'], "Succès");
    }
}
```

Ensuite, enregistrer l'outil dans [`ModuleServiceProvider`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Providers/ModuleServiceProvider.php) au niveau du singleton `RegistreOutils`.

---

## 6. Procédure de Test et Exécution

Tous les tests du module sont exécutés sur base SQLite isolée en mémoire (`:memory:`) sans toucher à la production :

```bash
# Tests d'architecture et fondations (Lot 1)
php artisan test --filter=ArchitectureFondationsTest

# Tests de la passerelle des fournisseurs (Lot 2)
php artisan test --filter=PasserelleFournisseursTest

# Tests de sécurité RBAC et injections (Lot 3)
php artisan test --filter=SecuriteAutorisationsTest

# Tests de persistance et chat (Lots 4 et 5)
php artisan test --filter=ChatEtPersistanceTest

# Tests RAG et indexation documentaire (Lot 6)
php artisan test --filter=RagEtIndexationTest

# Tests agents et workflow d'approbation (Lots 7, 8 et 9)
php artisan test --filter=AgentsEtApprobationsTest

# Tests mémoire et observabilité (Lots 10 et 11)
php artisan test --filter=MemoireEtObservabiliteTest

# Tests de sécurité adversariaux et évaluation qualité (Lots 12 et 13)
php artisan test --filter=SecuriteIaEtEvaluationQualiteTest
```

---

## 7. Procédure de Désactivation d'Urgence

Si le module doit être désactivé immédiatement :
1. Définir `MODULE_ASSISTANT_IA=false` dans l'environnement.
2. Toutes les routes du module répondent instantanément `HTTP 404` via le middleware de module.
3. Les liens de navigation du back-office sont masqués automatiquement.
4. Aucun service cœur de UniRegistre n'est altéré.
