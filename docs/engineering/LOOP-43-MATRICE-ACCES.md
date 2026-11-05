# LOOP 43 — MATRICE DES ACCÈS ET CLOISONNEMENT SERVEUR
## UniRegistre — Contrôle d'accès RBAC, isolation interfaculté et imperméabilité aux manipulations clientes

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** AUDITÉ ET SÉCURISÉ (GATE G43)  
**Référence contractuelle :** ADDENDUM LOOP 42-C + AUTORISATION LOOP 43 — Phase D & H  

---

## 1. Principes de Contrôle d'Accès Côté Serveur

Le cloisonnement d'UniRegistre en LOOP 43 applique une défense en profondeur stricte articulée autour des garanties suivantes :

1. **Autorisation Native Côté Serveur** : Aucun contrôle d'affichage ou masquage de menu côté front-end (Blade, Vue, JavaScript) ne constitue une barrière de sécurité. Toute requête HTTP entrante passe obligatoirement par les middlewares d'authentification (`auth:sanctum` ou `auth:web`), de rôle/portée (`admin`, `scope`), et de contrôle d'indicateur (`feature:pilote_academique`).
2. **Ignorance Totale des Paramètres d'URL et de Corps de Requête** : Le serveur n'utilise jamais les paramètres clients (ex. `?faculte_id=1`, `?programme_id=21`, `{"faculte_id": 1}`) pour établir la légitimité d'accès d'un acteur. Seules les données de session sécurisée (`$request->user()->faculte_id`) et les vérifications d'intégrité en base de données sont opérantes.
3. **Isolation Interfacultaire Étanche** : Un gestionnaire ou doyen rattaché à une autre faculté (ex. Faculté de Droit `faculte_id=2` ou Faculté de Médecine `faculte_id=3`) ne peut en aucun cas accéder aux données, rapports, calculs ou flux d'étudiants de la FST (`faculte_id=1`).
4. **Cloisonnement Vertical des Étudiants** : Un étudiant authentifié ne peut consulter que son propre relevé ou ses propres acquis via son identifiant de session. Tout accès à l'identifiant d'un tiers via modification de l'URL est rejeté avec un code HTTP 403 Forbidden.

---

## 2. Matrice d'Accès par Rôle et Composant

| Composant / Route | Rôle Anonyme | Rôle Étudiant (FST) | Rôle Étudiant (Autre Faculté) | Gestionnaire Scolarité (Autre Faculté) | Gestionnaire Scolarité (FST) | Administrateur Central / Doyen |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| **Navigation & Menus Pilote** | Masqué | Masqué | Masqué | Masqué | Visible (si flag actif) | Visible (si flag actif) |
| **Accès Direct Route Web (`/pilote-academique/...`)** | 401 Non authentifié | 404 (Hors scope) | 404 (Hors scope) | 404 (Hors faculté) | 200 (si flag actif & FST) | 200 (si flag actif & FST) |
| **API Endpoints (`/api/v1/acquis/...`)** | 401 Non authentifié | 404 / 403 (Hors droits) | 404 (Hors faculté) | 404 (Hors faculté) | 200 (si flag actif & Token valide) | 200 (si flag actif & Token valide) |
| **Simulation Virtuelle (`AcquisService::simuler`)** | Refusé | Refusé (Action admin) | Refusé | Refusé | 200 (Mémoire uniquement) | 200 (Mémoire uniquement) |
| **Mutation Base de Données (`AcquisService::migrer`)** | **BLOQUÉ** | **BLOQUÉ** | **BLOQUÉ** | **BLOQUÉ** | **BLOQUÉ** | **BLOQUÉ (Interdit LOOP 43)** |
| **Jobs et Tâches Asynchrones** | Sans effet | Sans effet | Sans effet | Sans effet | Sans effet (si flag inactif) | Exécution restreinte (en mémoire) |
| **Rapports et PV d'Évaluation** | Refusé | Refusé | Refusé | Refusé | Lecture seule FST | Lecture seule FST |
| **Pièces Justificatives Numérisées** | Refusé | Consultation propre dossier | Refusé | Refusé | Consultation dossiers FST | Consultation dossiers FST |

---

## 3. Vecteurs d'Attaque Testés et Contre-Mesures Implémentées

### 3.1 Tentative de Falsification du `faculte_id` via Requête HTTP
- **Scénario d'attaque** : Un utilisateur authentifié de la Faculté de Médecine (`faculte_id = 3`) forge une requête HTTP `GET /api/v1/pilote/rapport?faculte_id=1` pour tenter de bénéficier du périmètre pilote FST.
- **Défense appliquée** : Dans le middleware [`EnsureFeatureEnabled.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Middleware/EnsureFeatureEnabled.php), le contexte est extrait directement depuis l'entité utilisateur authentifiée :
  ```php
  $contexte = [
      'faculte_id' => $user?->faculte_id ?? null,
      'programme_id' => $user?->programme_id ?? null,
      'user_id' => $user?->id ?? null,
  ];
  ```
  Le paramètre falsifié de l'URL est totalement ignoré.
- **Résultat** : Requête interceptée et rejetée avec `HTTP 404 Not Found`.

### 3.2 Tentative d'Inclusion d'une Cohorte Non Autorisée
- **Scénario d'attaque** : Sollicitation d'un traitement pour un étudiant inscrit en 2024-2025 (Cohorte 5) ou 2023-2024.
- **Défense appliquée** : Vérification stricte de `annee_universitaire_id`. Si l'année n'appartient pas à la liste blanche (`[6]`), le `FeatureFlagService` retourne `false` avec motif `HORS_COHORTE`.
- **Résultat** : Traitement rejeté côté serveur.

### 3.3 Tentative d'Exécution en Environnement de Production
- **Scénario d'attaque** : Appel de la fonctionnalité sur une instance où `APP_ENV=production`.
- **Défense appliquée** : Le plafond environnemental global exclut formellement `production`. La méthode `estActif()` court-circuite toute validation et renvoie `false` immédiatement.
- **Résultat** : Accès refusé sans consultation de base ni chargement d'étudiants.

### 3.4 Tentative de Mutation SQL Déguisée en Mode Simulation
- **Scénario d'attaque** : Soumission d'une requête de calcul sollicitant l'écriture ou la création de lignes dans `notes`, `evaluations`, ou `acquis`.
- **Défense appliquée** : 
  - La méthode de simulation n'ouvre aucune transaction SQL d'écriture.
  - Le mode `MIGRATION_AUTORISEE` est rejeté par `MODES_AUTORISES_LOOP43 = ['AUDIT_ONLY', 'SIMULATION']`.
  - Un écouteur d'événements (`DB::listen`) valide qu'aucune instruction `INSERT`, `UPDATE` ou `DELETE` n'est émise.
- **Résultat** : 0 mutation en base.

---

## 4. Cloisonnement des Données et Respect de la Confidentialité (PII)

- **Alias REC** : Les résultats de calculs ou rapports d'erreurs ne manipulent que des alias anonymisés (`REC-01` à `REC-05`) ou des identifiants techniques non réidentifiables.
- **Logs Anonymisés** : La méthode `FeatureFlagService::journaliserActivation()` n'enregistre ni le nom de l'étudiant, ni son CIN, ni son adresse IP, ni ses notes détaillées.
- **Sanctum API Scopes** : Les tokens API de test sont limités au scope `acquis:lecture_seule`. Tout token tentant un scope d'écriture (`acquis:ecriture`) est rejeté dès la négociation de sécurité.

