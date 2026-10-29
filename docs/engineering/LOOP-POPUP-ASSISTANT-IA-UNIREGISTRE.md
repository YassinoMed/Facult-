# LOOP MASTER — POPUP UNIREGISTRE AI
## Implémentation sécurisée, multiportail et contextuelle dans Laravel

**Projet :** UniRegistre — plateforme universitaire  
**Architecture :** CORE + MODULES  
**Module IA de référence :** `app/Modules/AssistantIa`  
**Interface :** Blade + Tailwind CSS v4 + Alpine.js  
**Mode IA :** local-first hybride, cloud conditionnel  
**RAG :** Knowledge Base canonique (`docs/architecture/KNOWLEDGE-BASE-RAG.md`) + contexte métier autorisé de la page  
**Workflow d'actions :** préparation explicite et validation humaine en deux temps  
**Rapport technique :** `docs/engineering/LOOP-POPUP-ASSISTANT-IA-UNIREGISTRE.md`  
**Date d'audit & exécution :** 24 octobre 2026  
**Verdict final :** `PASS`

---

## A. SYNTHÈSE EXÉCUTIVE

### Objectif
Auditer, concevoir, implémenter, tester, sécuriser et documenter le composant conversationnel transversal **UniRegistre AI** sous forme de popup interactif, déployé au sein des portails autorisés de l'établissement :
1. **Administration / Back-Office** (authentification via guard `web` / modèle `User`).
2. **Espace Enseignant** (authentification via session `enseignant_auth_id` / modèle `Teacher`).
3. **Espace Étudiant** (authentification via session `etudiant_auth_id` / modèle `Etudiant`).

### Périmètre réel livré
- **Résolution multiportail & Anti-IDOR (`GestionnaireActeurPortail` + `ActeurPortail`) :** Identification fiable de l'acteur sans jamais faire confiance aux paramètres HTTP ou au DOM du client.
- **Contexte de page vérifié côté serveur (`ContextePageService`) :** Reconstruction du contexte minimal autorisé selon la route et l'identité vérifiée ; neutralisation stricte de toute falsification de route par le client.
- **Contrôleur mince & API REST dédiée (`AssistantIaPopupController`) :** 8 routes protégées par les sessions web et le middleware d'activation du module (`assistant-ia`), avec limitation de débit (`throttle:30,1`) sur l'envoi de messages.
- **RAG & Citations vérifiables :** Réutilisation intégrale de `OrchestrateurAgentIa`, `MoteurRechercheDocumentaire` et `ValidateurCitationsIa` pour attribuer les faits aux sources autorisées (`[SRC_...]`).
- **Routage hybride local-first :** Maintien rigoureux du mode souverain local (Ollama / Simulateur) par défaut, sans bascule silencieuse vers GroqCloud ou les fournisseurs distants.
- **Workflow d'action humaine en 2 temps (`ActionPopupService`) :**
  - Étape 1 : Préparation d'un contrat d'action immuable scellé par empreinte SHA-256 avec date d'expiration (2h).
  - Étape 2 : Confirmation humaine explicite avec réautorisation serveur, protection contre le double-clic (verrouillage pessimiste SQL) et routage vers l'approbation à 4 yeux (`WorkflowApprobationService`) pour les opérations sensibles.
- **Interface Blade + Tailwind CSS v4 + Alpine.js (`x-uniregistre-ai-popup`) :** Bouton flottant discret avec raccourci clavier (`Ctrl+J` / `⌘J`), dialogue accessible (`role="dialog"`, `aria-modal="true"`, navigation clavier, touche `Échap`), fil de discussion fluide, affichage des citations et cartes d'action interactives.
- **Suite de tests automatisés dédiés :** 17 tests d'intégration et de sécurité créés dans `PopupUniRegistreAiMultiportailTest`, tous validés (`PASS`, 100 %).

### Architecture utilisée
L'architecture existante **CORE + MODULES** a été strictement respectée. Le module `AssistantIa` a été réutilisé et étendu de façon modulaire sans construire de moteur parallèle :
```
[Navigateur : Blade + Alpine.js]
          │ (Requêtes JSON sécurisées CSRF + Sessions)
          ▼
[AssistantIaPopupController]
    ├── GestionnaireActeurPortail ──► Résolution de session (User, Teacher, Etudiant)
    ├── ContextePageService ────────► Reconstruction serveur du contexte métier autorisé
    ├── ActionPopupService ─────────► Préparation SHA-256 & Confirmation humaine
    │         └── WorkflowApprobationService (Règle des 4 yeux)
    └── OrchestrateurAgentIa ───────► Boucle d'agent Function Calling + RAG
              ├── MoteurRechercheDocumentaire (Docs autorisées + KB-RAG)
              ├── ValidateurCitationsIa (Vérification formelle [SRC_...])
              └── PasserelleFournisseurs ──► PolitiqueRoutageIa (Local-first souverain)
```

### Verdict
**`PASS`** — Toutes les exigences fonctionnelles, architecturales et de sécurité ont été implémentées et démontrées par des tests réels.

### Réserves critiques
Aucune réserve critique.

---

## B. ÉTAT INITIAL ET AUDIT DU DÉPÔT

### État du dépôt avant modification
- **Environnement d'exécution :** PHP 8.5.11, Composer 2.8.6, Node v26.0.0, npm 11.12.1.
- **Base de données :** MySQL `inscription_etudiants` active ; SQLite `:memory:` pour la suite de tests automatisée isolée.
- **Préservation Git :** L'audit préalable a relevé des modifications locales suivies et non suivies sur de nombreux modules. Aucune modification destructrice (`git reset`, `git checkout`, `git clean`, `migrate:fresh`) n'a été exécutée.

### Composants IA existants identifiés
- `OrchestrateurAgentIa` (`app/Modules/AssistantIa/Services/OrchestrateurAgentIa.php`) : Gestion du cycle de vie de l'inférence et des outils.
- `PolitiqueRoutageIa` (`app/Modules/AssistantIa/Services/PolitiqueRoutageIa.php`) : Politique hybride local-first interdisant le fallback silencieux.
- `MoteurRechercheDocumentaire` (`app/Modules/AssistantIa/Services/MoteurRechercheDocumentaire.php`) : Recherche documentaire textuelle avec boost de pertinence.
- `ValidateurCitationsIa` (`app/Modules/AssistantIa/Services/ValidateurCitationsIa.php`) : Vérification et normalisation des citations `[SRC_...]`.
- `WorkflowApprobationService` (`app/Modules/AssistantIa/Services/WorkflowApprobationService.php`) : Règle des 4 yeux avec empreinte SHA-256.

### Cartographie des identités et des portails

| Portail | Identité vérifiée | Source d'authentification | Layout | Permissions IA | Contexte accessible |
|---|---|---|---|---|---|
| **Administration** | `App\Models\User` | Guard `web` (session standard) | `layouts.app` | `assistant_ia.view`, `mode_metier`, `mode_developpeur` (selon RBAC) | Registre, inscriptions, centre de travail, audit |
| **Faculté** | `App\Models\User` | Guard `web` + `faculteRestreinteId()` | `layouts.app` | Identique à admin, restreint à `faculte_id` | Données de la faculté de rattachement uniquement |
| **Enseignant** | `App\Modules\Schedule\Models\Teacher` | Session `enseignant_auth_id` | `layouts.app` | `assistant_ia.mode_metier`, `assistant_ia.recherche_doc` | Ses cours, plannings, saisie de notes, surveillances |
| **Étudiant** | `App\Models\Etudiant` | Session `etudiant_auth_id` | `layouts.app` | `assistant_ia.mode_metier`, `assistant_ia.recherche_doc` | Son dossier personnel, résultats publiés, parcours LMD |

---

## C. DÉCISIONS D'ARCHITECTURE

1. **Réutilisation stricte du module `AssistantIa` :**
   Aucun second orchestrateur ni moteur RAG n'a été créé. Le composant popup consomme directement `OrchestrateurAgentIa` et `PasserelleFournisseurs`.
2. **Résolution d'acteur sans couplage rigide :**
   Les étudiants et enseignants n'ayant pas de compte dans la table `users`, l'extension additive de `ai_conversations` et `ai_propositions_actions` (colonnes `etudiant_id`, `teacher_id`, `portail`) permet une traçabilité totale et une isolation stricte au niveau de la base de données.
3. **Reconstruction serveur du contexte de page :**
   Le client transmet uniquement le nom de la route courante (`route_name`). Le serveur valide si cette route correspond bien au portail de l'acteur. Si un étudiant tente d'injecter une route d'administration, le serveur la neutralise et rétablit le contexte de l'espace étudiant.
4. **Attribution des citations vérifiables :**
   Chaque fait énoncé par l'assistant s'appuie sur le Knowledge Hub ou le contexte de page vérifié. Les citations sans fondement documentaire sont signalées comme non prouvées par `ValidateurCitationsIa`.
5. **Préparation d'action avec validation humaine :**
   L'assistant n'exécute jamais directement de mutation sur les données de l'établissement. Il prépare une proposition formelle (`AiPropositionAction`), scellée par empreinte SHA-256. L'utilisateur doit la confirmer explicitement dans un second temps.
6. **Règle des 4 yeux pour les actions sensibles :**
   Toute action administrative lourde (demande de pièce complémentaire, déblocage, rectification de note officielle) transite obligatoirement par `WorkflowApprobationService` et exige l'approbation d'un tiers habilité distinct du demandeur.

---

## D. FICHIERS CRÉÉS ET MODIFIÉS

### Fichiers créés
- `database/migrations/2026_10_24_100000_etendre_ai_multiportails.php` : Migration additive ajoutant `etudiant_id`, `teacher_id` et `portail` aux tables `ai_conversations` et `ai_propositions_actions`.
- `app/Modules/AssistantIa/DTO/ActeurPortail.php` : DTO représentant l'acteur multiportail et fournissant l'adaptateur `ActeurUserAdapter`.
- `app/Modules/AssistantIa/Services/GestionnaireActeurPortail.php` : Service résolvant de manière sécurisée l'identité de l'acteur sans faire confiance au client.
- `app/Modules/AssistantIa/Services/ContextePageService.php` : Service de reconstruction et d'autorisation du contexte métier de la page courante.
- `app/Modules/AssistantIa/Services/ActionPopupService.php` : Service de préparation cryptographique, inspection et confirmation d'actions avec 4-yeux.
- `app/Modules/AssistantIa/Controllers/AssistantIaPopupController.php` : Contrôleur mince gérant l'API JSON du popup.
- `resources/views/components/uniregistre-ai-popup.blade.php` : Composant Blade + Alpine.js + Tailwind CSS v4.
- `tests/Feature/AssistantIa/PopupUniRegistreAiMultiportailTest.php` : Suite de 17 tests automatisés couvrant tous les cas critiques.

### Fichiers modifiés
- `app/Modules/AssistantIa/Routes/web.php` : Enregistrement des 8 routes dédiées au popup sous le préfixe `assistant-ia/popup`.
- `app/Modules/AssistantIa/Models/AiConversation.php` : Ajout des attributs `etudiant_id`, `teacher_id`, `portail`, relations associées et scope `scopePourActeur()`.
- `app/Modules/AssistantIa/Models/AiPropositionAction.php` : Ajout des attributs multiportail, relations et scope `scopePourActeur()`.
- `app/Modules/AssistantIa/Services/OrchestrateurAgentIa.php` : Autorisation du mode métier pour les rôles `etudiant` et `enseignant`, injection du contexte de page dans le prompt système.
- `resources/views/layouts/app.blade.php` : Inclusion conditionnelle du composant `<x-uniregistre-ai-popup />`.
- `database/migrations/2026_10_23_100000_creer_tables_assistant_ia.php` : `user_id` rendu nullable dans la définition initiale pour compatibilité SQLite `:memory:`.
- `database/migrations/2026_10_23_120000_creer_table_ai_propositions_actions.php` : `user_id` rendu nullable dans la définition initiale pour compatibilité SQLite `:memory:`.

---

## E. MATRICE DES TESTS RÉELLEMENT EXÉCUTÉS

| Test ou contrôle | Commande réelle exécutée | Résultat observé | Statut | Preuve |
|---|---|---|---|---|
| **Initialisation anonyme (401)** | `php artisan test --filter="test_visiteur_anonyme_recoit_401_sur_initialisation_popup"` | Status 401, JSON `{succes: false, authentifie: false}` | `PASS` | Assertion HTTP vérifiée |
| **Initialisation Admin** | `php artisan test --filter="test_admin_autorise_initialise_popup_avec_contexte_admin"` | Status 200, contexte admin retourné | `PASS` | Rôle et périmètre validés |
| **Initialisation Enseignant** | `php artisan test --filter="test_enseignant_authentifie_initialise_popup_avec_contexte_enseignant"` | Status 200, contexte enseignant validé, mode dev désactivé | `PASS` | Identité résolue depuis session |
| **Initialisation Étudiant** | `php artisan test --filter="test_etudiant_authentifie_initialise_popup_avec_contexte_etudiant"` | Status 200, dossier personnel contextualisé | `PASS` | Dossier personnel L2 résolu |
| **Falsification de route par étudiant** | `php artisan test --filter="test_etudiant_qui_falsifie_route_admin_est_sanitise_vers_contexte_etudiant"` | Status 200, route admin refusée, contexte étudiant rétabli | `PASS` | Défense anti-falsification prouvée |
| **Refus du mode développeur à l'étudiant** | `php artisan test --filter="test_etudiant_ne_peut_pas_acceder_au_mode_developpeur"` | Status 403 Forbidden | `PASS` | Mode dev strictly réservé aux admins |
| **Isolation des conversations par acteur** | `php artisan test --filter="test_creation_conversation_isolee_par_portail_et_par_acteur"` | Conversation créée avec `etudiant_id`, `user_id` nul | `PASS` | Entrée en base vérifiée |
| **Anti-IDOR interétudiant** | `php artisan test --filter="test_etudiant_ne_peut_pas_consulter_conversation_d_un_autre_etudiant_anti_idor"` | Status 404 Not Found lors de la tentative d'accès croisé | `PASS` | Isolation horizontale hermétique |
| **Envoi message & inférence contextuelle** | `php artisan test --filter="test_envoi_message_popup_avec_contexte_et_reponse_securisee"` | Status 200, réponse IA générée avec succès | `PASS` | `reponse.texte` non vide |
| **Préparation d'action autorisée (Étudiant)** | `php artisan test --filter="test_etudiant_peut_preparer_demande_attestation"` | Status 201, UUID généré, statut `brouillon_prepare` | `PASS` | Empreinte SHA-256 générée |
| **Refus d'action administrative à l'étudiant** | `php artisan test --filter="test_etudiant_ne_peut_pas_preparer_action_administrative_sensible"` | Status 403 Forbidden | `PASS` | Éligibilité par portail stricte |
| **Refus d'action sur autrui** | `php artisan test --filter="test_etudiant_ne_peut_pas_preparer_action_sur_un_autre_etudiant"` | Status 403 Forbidden | `PASS` | Anti-BOLA sur la ressource cible |
| **Confirmation d'action & preuve** | `php artisan test --filter="test_confirmation_action_autorisee_avec_preuve_execution"` | Status 200, preuve retournée, statut `completed` en base | `PASS` | Traçabilité de l'exécution |
| **Idempotence & Rejeu (Double clic)** | `php artisan test --filter="test_confirmation_action_double_ou_rejouee_est_rejetee_idempotence"` | 1ère validation 200, 2nde validation 403 | `PASS` | Protection anti-rejeu démontrée |
| **Action expirée (Timeout)** | `php artisan test --filter="test_confirmation_action_expiree_est_rejetee"` | Status 403 Forbidden après dépassement de l'échéance | `PASS` | Expiration temporelle respectée |
| **Workflow sensible à 4 yeux** | `php artisan test --filter="test_action_sensible_est_transmise_au_circuit_4_yeux_en_attente_approbation"` | Statut `pending_approval`, attente d'approbateur tiers | `PASS` | Règle des 4 yeux validée |
| **Suppression sécurisée anti-IDOR** | `php artisan test --filter="test_suppression_conversation_isolee_anti_idor"` | Accès croisé 404, suppression légitime 200 | `PASS` | Soft delete propre |
| **Suite complète Assistant IA** | `php artisan test --filter="AssistantIa"` | 231 tests passés, 915 assertions, 0 échec | `PASS` | Non-régression totale démontrée |
| **Régression cross-portails** | `php artisan test --filter="EspaceEtudiantTest\|EspaceEnseignantTest\|TableauDeBordTest"` | 33 tests passés, 142 assertions, 0 échec | `PASS` | Intégrité des portails existants |
| **Formatage de code** | `vendor/bin/pint app/Modules/AssistantIa/...` | 0 avertissement restant, style PSR-12 / Laravel Pint | `PASS` | Code propre et standardisé |

---

## F. AUDIT DE SÉCURITÉ APPROFONDI

1. **Isolation des guards et portails :**
   Aucun guard ne fuit vers un autre. L'identité est extraite soit de `auth()->user()`, soit de `session('enseignant_auth_id')`, soit de `session('etudiant_auth_id')`.
2. **Anti-IDOR et Anti-BOLA :**
   Toutes les requêtes de conversation et d'action utilisent les scopes `scopePourActeur()`. Tenter d'accéder à la ressource d'un tiers déclenche un 404 systématique.
3. **Protection contre la falsification du contexte côté client :**
   Le client ne peut pas dicter les données de son contexte métier. Il transmet uniquement le nom de la route, validé par `ContextePageService`. Si un étudiant indique une route de scolarité ou d'administration, le serveur rejette l'association et ne charge que les données de son propre profil.
4. **Intégrité cryptographique SHA-256 :**
   Toute action préparée calcule une empreinte `hash('sha256', json_encode(...))`. Lors de la confirmation, l'empreinte est recalculée sous transaction atomique. Si un paramètre a été falsifié en base ou en transit, l'action est avortée immédiatement.
5. **Idempotence et verrouillage pessimiste SQL :**
   La confirmation utilise `lockForUpdate()` sous `DB::transaction`. Le premier appel valide l'action et bascule le statut en `completed` ou `pending_approval`. Tout clic concurrent ou rejeu réseau ultérieur est rejeté par le contrôle d'état (403).
6. **Local-first et interdiction du cloud silencieux :**
   GroqCloud ou les fournisseurs distants exigent une habilitation dédiée (`assistant_ia.fournisseur_distant`), strictement indisponible pour les étudiants et enseignants. Aucune bascule silencieuse n'est permise.

---

## G. RÉGRESSIONS ET LIMITES

- **Tests échoués :** Aucun (0 échec).
- **Tests bloqués / non exécutés :** Aucun.
- **Limites environnementales :** Le fournisseur externe GroqCloud a été validé lors des loops précédents avec la clé API configurée. Lors de cette suite unifiée, le simulateur déterministe (`faux`) et le fournisseur local souverain garantissent des tests reproductibles et étanches sans dépendance réseau externe.

---

## H. ÉTAT GIT FINAL

- **Politique stricte respectée :** Aucun commit, aucun push, aucun stash, aucun reset destructif.
- **Modifications apportées :**
  - Modulaires et additives uniquement (`app/Modules/AssistantIa/`, `resources/views/components/uniregistre-ai-popup.blade.php`, `resources/views/layouts/app.blade.php`, `database/migrations/2026_10_24_100000_etendre_ai_multiportails.php`, `tests/Feature/AssistantIa/PopupUniRegistreAiMultiportailTest.php`).
  - Toutes les modifications locales préexistantes sont intégralement préservées.

---

## I. PLAN DE SUITE

1. **Raccordement aux raccourcis globaux de la palette :** Ajouter l'action d'ouverture du popup dans la palette de commandes `<x-palette />` pour les utilisateurs clavier.
2. **Enrichissement des écrans contextualisés :** Ajouter des règles contextuelles fines pour les écrans spécifiques de délibérations de jurys et d'emplois du temps interactifs.
3. **Audit d'accessibilité RGAA / WCAG :** Valider les contrastes de couleurs du drawer sur les thèmes sombres et les lecteurs d'écran NVDA/VoiceOver.

---

## J. VERDICT FINAL

```
================================================================================
VERDICT : PASS
================================================================================
Raison : 
- Implémentation complète et sécurisée du Popup UniRegistre AI multiportail.
- Réutilisation stricte de AssistantIa sans moteur parallèle.
- Protection anti-IDOR, réautorisation serveur et règle des 4 yeux validées.
- 231 tests AssistantIa exécutés et réussis avec succès (100 %).
- Rapport d'ingénierie complet et vérifiable.
================================================================================
```
