# 🎓 UniRegistre — Manuel Complet du Projet
> **Guide Développeur (Architecture & Technique) & Guide d'Utilisation (Opérationnel & Métier)**  
> **Version :** 2.0 • **Framework :** Laravel 13 / PHP 8.3+ • **Date :** Octobre 2026

---

## 📑 Sommaire Général

- [1. Présentation Générale & Vision](#1-présentation-générale--vision)
- [PARTIE I — GUIDE DU DÉVELOPPEUR](#partie-i--guide-du-développeur)
  - [1. Prérequis & Environnement](#1-prérequis--environnement)
  - [2. Installation & Déploiement Local](#2-installation--déploiement-local)
  - [3. Architecture Technique (Core + Modules)](#3-architecture-technique-core--modules)
  - [4. Moteur d'Identité & OCR (CIN / Passeport)](#4-moteur-didentité--ocr-cin--passeport)
  - [5. Système d'Échange de Données (DataExchange)](#5-système-déchange-de-données-dataexchange)
  - [6. Commandes Artisan & Tâches Planifiées](#6-commandes-artisan--tâches-planifiées)
  - [7. API REST v1 & Intégrations](#7-api-rest-v1--intégrations)
  - [8. Sécurité, Audit & Isolation Multi-Facultés](#8-sécurité-audit--isolation-multi-facultés)
  - [9. Tests & Qualité de Code](#9-tests--qualité-de-code)
- [PARTIE II — GUIDE D'UTILISATION](#partie-ii--guide-dutilisation)
  - [1. Portail Public de Candidature & Inscription (`/`)](#1-portail-public-de-candidature--inscription-)
  - [2. Espace Étudiant Authentifié (`/etudiant`)](#2-espace-étudiant-authentifié-etudiant)
  - [3. Espace Administration & Scolarité (`/admin`)](#3-espace-administration--scolarité-admin)
    - [3.1 Connexion & Rôles](#31-connexion--rôles)
    - [3.2 Cockpit & Tableau de Bord Opérationnel](#32-cockpit--tableau-de-bord-opérationnel)
    - [3.3 Gestion des Inscriptions & Fiche 360°](#33-gestion-des-inscriptions--fiche-360)
    - [3.4 Progression Académique LMD](#34-progression-académique-lmd)
    - [3.5 Référentiel Académique LMD](#35-référentiel-académique-lmd)
    - [3.6 Enseignements & Emplois du Temps (Schedule)](#36-enseignements--emplois-du-temps-schedule)
    - [3.7 Évaluations, Notes & Délibérations](#37-évaluations-notes--délibérations)
    - [3.8 Import / Export de Données Unifié](#38-import--export-de-données-unifié)
    - [3.9 Qualité des Données & Audit (§24-25)](#39-qualité-des-données--audit-24-25)
- [ANNEXES & RÉFÉRENCE RAPIDE](#annexes--référence-rapide)
  - [A. Comptes de Démonstration (Développement)](#a-comptes-de-démonstration-développement)
  - [B. Matrice des Rôles & Permissions](#b-matrice-des-rôles--permissions)
  - [C. Variables d'Environnement Clés (`.env`)](#c-variables-denvironnement-clés-env)
  - [D. Résolution des Problèmes Fréquents (FAQ)](#d-résolution-des-problèmes-fréquents-faq)

---

## 1. Présentation Générale & Vision

**UniRegistre** est une plateforme intégrée de gestion universitaire et de registre académique d'État, conçue pour piloter l'ensemble du cycle de vie étudiant selon les normes universitaires **LMD** (Licence - Master - Doctorat) :
- **Inscription & Candidature en ligne** avec pré-remplissage dynamique et scan OCR assisté par IA (fail-soft).
- **Espace Étudiant interactif** permettant la consultation des dossiers, notes, plannings, attestations certifiées et dépôt de requêtes administratives.
- **Back-Office Scolarité de haut niveau** offrant une fiche étudiant à 360°, la gestion des programmes, maquettes de cours, services d'enseignement, emplois du temps réactifs avec détection live des conflits, jurys de délibération, journalisation d'audit inaltérable (§24-25) et isolation stricte par faculté (scoping multi-établissements).

L'application repose sur le paradigme **CORE + MODULES DÉCOUPLÉS**, garantissant une extensibilité sans altération du cœur.

---

# PARTIE I — GUIDE DU DÉVELOPPEUR

## 1. Prérequis & Environnement

Pour faire fonctionner UniRegistre en local ou sur serveur de recette, l'environnement minimal requis est :
- **PHP** : `^8.3` ou `8.4` avec extensions requises : `pdo_mysql`, `mbstring`, `openssl`, `gd`, `intl`, `xml`, `curl`, `zip`, `fileinfo`.
- **Gestionnaire de dépendances** : `Composer 2.x`.
- **Base de données relationnelle** : `MySQL 8.0+` ou `MariaDB 10.5+` (support des JSON et contraintes strictes).
- **Node.js & npm** : `Node 20+` et `npm 10+` (pour Vite, Tailwind CSS v4 et Alpine.js).
- **Moteur OCR (Optionnel pour le scan d'identité)** :
  - `Tesseract OCR` (v5+ avec paquets linguistiques `fra` et `ara`).
  - Ou `Python 3.10+` avec `RapidOCR / ONNX` pour le moteur PaddleOCR.
  - Ou un endpoint distant Colab / FastAPI (GPU). Par défaut : moteur `null` sans dépendance système.

---

## 2. Installation & Déploiement Local

### Étape 1 : Cloner le dépôt et entrer dans le dossier
```bash
git clone <url-du-depot> inscription-etudiants
cd inscription-etudiants
```

### Étape 2 : Installer les dépendances PHP et JavaScript
```bash
# Dépendances backend
composer install

# Dépendances frontend
npm install
```

### Étape 3 : Fichier d'environnement
```bash
cp .env.example .env
php artisan key:generate
```
Éditer le fichier `.env` pour configurer l'accès à votre base de données MySQL :
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inscription_etudiants
DB_USERNAME=root
DB_PASSWORD=votre_mot_de_passe
```

### Étape 4 : Base de données & Données de démonstration
Exécuter les migrations et le jeu complet de données de référence et démonstration :
```bash
php artisan migrate --seed
```
> [!NOTE]  
> Le seeder injecte 8 facultés, 25 étudiants types avec parcours, les comptes administratifs (Super Admin, Directeurs de composante, Agents), le référentiel LMD et les plannings de test.

### Étape 5 : Lier le stockage privé / public
```bash
php artisan storage:link
```

### Étape 6 : Compiler les assets et démarrer le serveur
Dans deux terminaux séparés :
```bash
# Terminal 1 — Compilation des assets à chaud
npm run dev
# ou compilation de production : npm run build

# Terminal 2 — Serveur web Laravel
php artisan serve
```
L'application est immédiatement accessible sur `http://localhost:8000`.

---

## 3. Architecture Technique (Core + Modules)

L'application respecte les principes du document d'architecture institutionnelle (`docs/ARCHITECTURE-MODULES.md`).

### Structure de l'Arborescence
```
inscription-etudiants/
├── app/
│   ├── Console/Commands/       # Commandes artisan de supervision, audit et maintenance
│   ├── Contracts/              # Interfaces publiques transverses (ex: OcrEngineInterface)
│   ├── Events/ & Listeners/    # Événements découplés (StudentRegistered, etc.)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/          # Contrôleurs du back-office scolarité
│   │   │   ├── Api/V1/         # API REST Sanctum versionnée
│   │   │   └── Etudiant/       # Contrôleurs du portail étudiant
│   │   ├── Middleware/         # Authentification, isolation faculté, module actif
│   │   └── Requests/           # FormRequests avec validation et normalisation stricte
│   ├── Models/                 # Entités Eloquent du cœur (Etudiant, Faculte, User, etc.)
│   ├── Modules/                # Domaines métier autonomes
│   │   ├── Attendance/         # Suivi des présences et justifications
│   │   ├── Enseignement/       # Services d'enseignements, ECUE, volumes horaires
│   │   ├── Evaluation/         # Sessions, examens, notes, délibérations
│   │   ├── OnlineRequests/     # Demandes & réclamations étudiantes avec SLA
│   │   ├── Population/         # Affectations de groupes, promotions, statuts
│   │   ├── Referentiel/        # Départements, programmes, semestres, maquettes
│   │   ├── Schedule/           # Emplois du temps réactifs, conflits, duplication
│   │   ├── StudentArchive/     # Dossier longitudinal 360° et timeline
│   │   └── StudentDossiers/    # GED sécurisée et pièces justificatives
│   ├── Policies/               # Autorisations RBAC et contrôle d'isolation multi-facultés
│   ├── Providers/              # ServiceProviders dont ModuleServiceProvider
│   └── Services/               # Logique applicative (Identity, Io, DataExchange, Bi, etc.)
├── config/                     # Configuration centrale (modules.php, identity.php, etc.)
├── database/                   # Migrations ordonnées, factories et seeders réalistes
├── docs/                       # Cahiers des charges exhaustifs et spécifications §1-34
├── resources/                  # Vues Blade modulaires, composants et styles Tailwind v4
└── routes/                     # web.php, api.php, console.php
```

### Le Registre Modulaire (`config/modules.php`)
Chaque module métier peut être activé ou désactivé côté serveur via les variables d'environnement (`MODULE_*`).
Si un module est inactif :
1. Le middleware `module:nom` retourne automatiquement une erreur HTTP `404 Not Found`.
2. Les liens de menu et widgets de tableau de bord correspondants ne sont pas affichés.
3. Aucune modification de schéma de base de données n'est requise.

```env
# Activation des modules métier (.env)
MODULE_REFERENTIEL=true
MODULE_ENSEIGNEMENT=true
MODULE_SCHEDULE=true
MODULE_EVALUATION=true
MODULE_ATTENDANCE=true
MODULE_ONLINE_REQUESTS=true
MODULE_STUDENT_DOSSIERS=true
MODULE_STUDENT_ARCHIVE=true
MODULE_TASKS=true
MODULE_FINANCES=false
```

---

## 4. Moteur d'Identité & OCR (CIN / Passeport)

Le parcours d'inscription intègre un pipeline de lecture automatique de pièces d'identité (CIN tunisienne et passeports internationaux).

- **Principe de Minimisation Absolue (§13 option A)** :
  Le fichier scanné ou téléversé est déposé en stockage temporaire privé (`storage/app/private`). Dès l'extraction terminée, **le fichier est irrémédiablement supprimé** dans un bloc `finally`. Aucune pièce d'identité n'est stockée de façon durable.
- **Fail-Soft par Conception** :
  Aucune défaillance OCR (binaire manquant, image floue, timeout) ne bloque l'inscription de l'étudiant. En cas d'échec de lecture, l'interface bascule en saisie manuelle sans lever d'exception utilisateur.
- **Détection MRZ Passeport** :
  Analyse de la zone de lecture optique TD3 (ICAO 9303) avec validation des checksums 7-3-1.
- **Gestion de la CIN Tunisienne** :
  Traitement des libellés bilingues arabe/français, normalisation des diacritiques et translittération standardisée des noms de filiation (« بن »).
- **Sélection du moteur via `.env`** :
  ```env
  IDENTITY_OCR_DRIVER=null       # null | tesseract | ppocr | colab | hfvlm
  IDENTITY_OCR_TIMEOUT=30
  ```

---

## 5. Système d'Échange de Données (DataExchange)

UniRegistre intègre un moteur unifié d'importation et d'exportation de données (`app/Services/Io/DataExchange/`) accessible depuis l'interface d'administration (`/admin/donnees`) et via l'API (`/api/v1/data/*`).

### Formats Supportés
- **Import** : CSV (séparateurs `,` ou `;`), XLSX (OpenSpout streaming basse mémoire), JSON.
- **Export** : CSV UTF-8 avec BOM, XLSX stylisé, PDF institutionnel certifié.

### Entités Importables & Exportables
1. `etudiants` : Dossiers étudiants, coordonnées, état d'inscription.
2. `enseignants` : Personnel professoral, département, grade, charge.
3. `notes` / `notes_epreuves` : Relevés d'évaluations et examens.
4. `affectations-groupes` : Répartition dans les groupes de TD / TP.
5. `programmes`, `niveaux`, `semestres`, `ues`, `ecues` : Architecture pédagogique LMD.
6. `schedules` : Plannings d'emplois du temps.

### Règles d'Intégrité
- **Validation préalable (Dry-Run)** : Chaque import propose un mode simulation affichant les lignes valides, les avertissements et les erreurs bloquantes avant toute écriture en base.
- **Traitement transactionnel** : En cas d'erreur fatale, l'ensemble du lot est rollback.

---

## 6. Commandes Artisan & Tâches Planifiées

L'application fournit un ensemble d'outils CLI dédiés à la supervision et à l'automatisation :

| Commande Artisan | Description Métier | Fréquence Recommandée |
|---|---|---|
| `php artisan qualite:auditer` | Analyse la base et détecte les anomalies (doublons, CIN invalides, etc.) | Hebdomadaire (`schedule`) |
| `php artisan supervision:controler` | Sonde l'état des services, bases, disques et files d'attente | Toutes les 5 minutes |
| `php artisan demandes:verifier-sla` | Calcule les dépassements de délais de traitement des requêtes | Quotidien (matin) |
| `php artisan taches:generer` | Génère les tâches cockpit automatiques (ex: étudiants sans groupe) | Quotidien |
| `php artisan documents:marquer-expires`| Détecte et étiquette les pièces justificatives arrivées à échéance | Quotidien |
| `php artisan sauvegarde:creer --verifier`| Exécute et valide une sauvegarde chiffrée de la base de données | Quotidien (nuit) |
| `php artisan audit:routes-securite` | Audite l'étanchéité des middlewares et autorisations des routes | CI / Déploiement |
| `php artisan integration:token:create` | Génère un token API Sanctum avec scopes restreints | À la demande |
| `php artisan purger:notifications` | Purge les notifications lues de plus de 90 jours | Mensuel |
| `php artisan maintenance:purger-logs` | Nettoie les fichiers de logs archivés volumineux | Mensuel |

---

## 7. API REST v1 & Intégrations

L'API d'UniRegistre est sécurisée par **Laravel Sanctum** et versionnée sous `/api/v1/`.

### Principes Clés
- **Authentification par Bearer Token** : Généré via la commande `php artisan integration:token:create "NomApplication" --scopes=referentiel:read,etudiants:read`.
- **Enveloppe de Réponse Standardisée** :
  ```json
  {
    "data": { ... },
    "message": "Opération réussie",
    "request_id": "req_65e8a91b2c4e1"
  }
  ```
- **Endpoints Clés** :
  - `GET /api/v1/ready` : Healthcheck de disponibilité système (sans authentification).
  - `GET /api/v1/referentiel/facultes` : Liste des facultés actives.
  - `GET /api/v1/referentiel/programmes` : Liste des cursus LMD.
  - `POST /api/v1/data/import/preview` : Simulation d'importation sans persistance.
  - `POST /api/v1/data/import/confirm` : Validation et écriture d'un lot d'import.
  - `POST /api/v1/schedules/import/preview|confirm` : Importation d'emplois du temps.
- **Spécification OpenAPI** : Disponible et documentée dans `docs/openapi.yaml`.

---

## 8. Sécurité, Audit & Isolation Multi-Facultés

### Isolation Stricte par Faculté (Multi-Tenant Logique)
Les administrateurs rattachés à une faculté spécifique (ex: Faculté des Sciences) ne peuvent **en aucun cas** visualiser ou modifier les dossiers, notes ou plannings d'une autre faculté.
- Le middleware `EnsureFacultyScoping` applique automatiquement les scopes Eloquent sur chaque requête.
- Les `Policies` vérifient systématiquement que `user->faculte_id === resource->faculte_id` (sauf rôle `super_admin`).

### Journalisation d'Audit (§24-25)
Chaque action sensible (création, modification de statut, saisie de note, suppression, consultation d'archive, export massif) génère un enregistrement inaltérable dans la table `audit_logs` contenant :
- `user_id`, `faculte_id`, `action`, `model_type`, `model_id`.
- `adresse_ip`, `user_agent`, `empreinte_avant`, `empreinte_apres`.
- **Exclusion stricte des données personnelles (PII)** : Aucun mot de passe, empreinte biométrique ou scan n'est jamais journalisé.

---

## 9. Tests & Qualité de Code

Le projet comporte une suite de tests unitaires et fonctionnels (Pest / PHPUnit) couvrant la sécurité, le RBAC et les parcours métiers :

```bash
# Exécution de l'ensemble des tests
php artisan test --compact

# Exécution ciblée des tests de sécurité et d'authentification
php artisan test --filter="Security|Auth|IdentityScan"

# Vérification du respect des conventions de code (Laravel Pint)
vendor/bin/pint --test
```

---

# PARTIE II — GUIDE D'UTILISATION

---

## 1. Portail Public de Candidature & Inscription (`/`)

Accessible à l'adresse racine `http://localhost:8000/`, ce portail permet aux futurs bacheliers et étudiants de soumettre leur dossier universitaire.

```
[ Étape 1 : Identité ] ➔ [ Étape 2 : Coordonnées ] ➔ [ Étape 3 : Baccalauréat ] ➔ [ Étape 4 : Affectation & Mot de passe ]
```

### Fonctionnalités du Formulaire
1. **Option Scan d'Identité Intelligent (Haut de page)** :
   - Sélectionner le type de document : **CIN Tunisienne** ou **Passeport International**.
   - Téléverser une photo nette ou un PDF du document.
   - Le système extrait automatiquement le nom, prénom, numéro de pièce et date de naissance.
   - Les champs du formulaire se pré-remplissent avec un indicateur visuel de confiance. L'étudiant vérifie et complète les informations.
2. **Recherche & Pré-remplissage par CIN** :
   - Dès la saisie du numéro de CIN, le système interroge le registre en arrière-plan.
   - Si le candidat est déjà enregistré, ses coordonnées sont automatiquement chargées pour lui éviter une double saisie.
3. **Affectation Universitaire** :
   - Sélection de la faculté parmi les établissements actifs.
   - Choix du niveau d'accès (Licence L1 à L3, Master M1 à M2, Doctorat D1 à D3).
4. **Création du Compte Étudiant** :
   - Définition d'un mot de passe sécurisé (minimum 8 caractères avec lettres et chiffres) pour accéder ultérieurement à son Espace Étudiant.
5. **Page de Confirmation & Récépissé Officiel (`/inscription/success`)** :
   - Attribution immédiate du **Numéro d'Inscription séquentiel officiel** (format : `INS-2026-00001`).
   - Bouton de copie du numéro dans le presse-papier.
   - Bouton d'impression / téléchargement direct du **Récépissé de Dépôt Officiel** en PDF.

---

## 2. Espace Étudiant Authentifié (`/etudiant`)

L'étudiant accède à son espace personnel via l'adresse `http://localhost:8000/etudiant/connexion`.

### Connexion Étudiant
- **Identifiant** : Adresse email ou numéro de CIN.
- **Mot de passe** : Celui choisi lors de la candidature.

### Rubriques de l'Espace Étudiant
- **Tableau de Bord (`/etudiant/tableau-de-bord`)** :
  - Statut en temps réel du dossier (*En attente de validation*, *Dossier validé*, *Pièces complémentaires requises*).
  - Résumé du cursus actuel, faculté de rattachement et calendrier des étapes clés.
- **Mon Dossier & Parcours (`/etudiant/inscriptions`)** :
  - Historique complet des inscriptions universitaires par année académique.
- **Relevé de Notes & Résultats (`/etudiant/notes`)** :
  - Consultation des notes d'épreuves, moyennes semestrielles et crédits validés (ECTS).
- **Mes Documents & Attestations (`/etudiant/documents`)** :
  - Téléchargement en un clic des certificats de scolarité et attestations de réussite signées numériquement.
- **Guichet des Demandes en Ligne (`/etudiant/demandes`)** :
  - Possibilité de soumettre une requête administrative (changement de groupe, demande de relevé, réclamation, aménagement de présence).
  - Suivi de l'état d'avancement et accusé de traitement par le secrétariat.
- **Mon Profil & Sécurité (`/etudiant/profil`)** :
  - Mise à jour des coordonnées de contact (téléphone, adresse) et modification du mot de passe.

---

## 3. Espace Administration & Scolarité (`/admin`)

L'accès au back-office s'effectue via `http://localhost:8000/admin/connexion`.

### 3.1 Connexion & Rôles

L'accès est strictement réservé au personnel universitaire habilité. Les fonctionnalités affichées s'adaptent selon le profil :
- **Super Administrateur (`super_admin`)** : Contrôle total sur toutes les facultés, paramétrage global, gestion des utilisateurs et audit.
- **Administrateur Scolarité (`admin_scolarite`)** : Pilotage complet des inscriptions, délibérations, maquettes et emplois du temps de sa faculté.
- **Agent de Scolarité (`agent_scolarite`)** : Traitement quotidien des dossiers étudiants, validation des pièces et saisie des présences.
- **Enseignant (`enseignant`)** : Consultation de ses emplois du temps, émargement des présences et saisie des notes de ses ECUE.
- **Consultation (`consultation`)** : Accès en lecture seule aux archives et statistiques.

---

### 3.2 Cockpit & Tableau de Bord Opérationnel

Dès la connexion, le tableau de bord présente la situation globale de l'établissement :
- **Indicateurs Clés (KPIs)** : Nombre total d'étudiants inscrits, dossiers en attente, taux de complétude, nombre de groupes formés.
- **Cockpit des Tâches Urgentes** :
  - Alertes sur les étudiants sans groupe pédagogique affecté.
  - Signalement des demandes étudiantes approchant de la rupture de délai SLA.
  - Alertes d'absences répétées non justifiées.
- **Recherche Globale Rapide (Raccourci `Ctrl + K` / `Cmd + K`)** : Permet de trouver instantanément un étudiant, un enseignant, une salle ou un cours.

---

### 3.3 Gestion des Inscriptions & Fiche 360°

Dans le menu **Étudiants** (`/admin/etudiants`) :
- **Tableau Interactif** :
  - Filtres combinables par faculté, niveau d'études (L1, L2...), statut (*En attente*, *Validé*, *Rejeté*) et année universitaire.
  - Barre de recherche instantanée (nom, CIN, numéro d'inscription, email, ville).
- **Fiche Étudiant 360° (`/admin/etudiants/{id}`)** :
  - **Synthèse** : Identité complète, coordonnées et cursus d'admission.
  - **Cycle de Vie du Dossier** : Validation ou rejet du dossier en un clic avec motif officiel notifié à l'étudiant.
  - **Timeline Historique** : Chronologie inaltérable de tous les événements de la scolarité de l'étudiant.
  - **Affectations** : Attribution de groupe de cours/TD/TP avec vérification de la capacité maximale des salles.

---

### 3.4 Progression Académique LMD

Depuis la fiche d'un étudiant ou via le module de gestion collective des inscriptions :
- **Évaluation du Passage** : Analyse automatique des crédits ECTS et des délibérations de jurys.
- **Décisions Possibles** :
  - *Admis* : Passage au niveau supérieur (L1 ➔ L2 ➔ L3).
  - *Redoublant* : Réinscription dans le même niveau avec conservation des UE acquises.
  - *Fin de cycle* : Éligibilité à la diplomation et à la candidature en Master / Doctorat.
- **Traitement par Lot** : Promotion en masse d'une cohorte après validation officielle du jury.

---

### 3.5 Référentiel Académique LMD

Dans le menu **Référentiel LMD** (`/admin/referentiel`) :
1. **Facultés & Départements** : Structuration de l'université en unités de formation et de recherche.
2. **Programmes (Parcours)** : Création des filières (ex: *Licence Informatique*, *Master Droit des Affaires*).
3. **Niveaux & Semestres** : Découpage en semestres académiques (S1 à S6).
4. **Unités d'Enseignement (UE)** : Regroupement de matières avec attribution de crédits ECTS et coefficient.
5. **Éléments Constitutifs (ECUE)** : Matières d'enseignement avec volumes horaires de Cours Magistraux (CM), Travaux Dirigés (TD) et Travaux Pratiques (TP).
6. **Ordonnancement Interactif** : Réorganisation des matières par glisser-déposer (*Drag & Drop*) avec enregistrement immédiat.

---

### 3.6 Enseignements & Emplois du Temps (Schedule)

Dans le menu **Emplois du Temps** (`/admin/emplois-du-temps`) :
- **Matrice Hebdomadaire Interactive** :
  - Visualisation des cours par groupe, par salle ou par enseignant.
- **Détection Automatique & Temps Réel des Conflits** :
  - ❌ *Conflit Enseignant* : Le même professeur programmé sur deux cours simultanés.
  - ❌ *Conflit Salle* : Une salle allouée à deux enseignements au même horaire.
  - ❌ *Conflit Capacité* : Effectif d'un groupe supérieur au nombre de places assises de la salle.
- **Cycle de Vie du Planning** :
  - Statuts : `Brouillon` ➔ `Contrôle Pré-publication` ➔ `Publié` ➔ `Verrouillé`.
  - Un planning verrouillé interdit toute altération sans déverrouillage formel tracé en audit.
- **Export & Impression** : Génération de fiches de planning hebdomadaires au format PDF optimisé pour l'affichage en campus.

---

### 3.7 Évaluations, Notes & Délibérations

Dans le menu **Évaluations** (`/admin/evaluations`) :
1. **Sessions d'Examens** : Création de la session (Normale / Rattrapage) avec dates de début et fin.
2. **Épreuves & Surveillance** :
   - Définition des horaires d'épreuves, attribution des salles et désignation des surveillants.
   - Génération automatique des listes d'émargement pour les salles d'examen.
3. **Saisie & Importation des Notes** :
   - Saisie en ligne avec calcul automatique des moyennes ou import groupé via fichier Excel/CSV.
   - Respect des barèmes, coefficients et règles d'arrondi officielles.
4. **Délibération de Jury & Verrouillage** :
   - Calcul des admissions, mentions et statuts d'UE (*Validé*, *Compensé*, *Ajourné*).
   - Validation finale en présence du jury avec verrouillage empêchant toute modification ultérieure non autorisée.

---

### 3.8 Import / Export de Données Unifié

Dans le menu **Données** (`/admin/donnees`) :
- **Importer un fichier** :
  1. Choisir le type d'entité (Étudiants, Enseignants, Notes, Groupes...).
  2. Glisser le fichier CSV ou XLSX.
  3. Lancer l'étape de **Prévisualisation (Dry-Run)** : Le système signale les éventuelles erreurs de syntaxe, doublons de matricule ou facultés inconnues sans rien écrire.
  4. Cliquer sur **Confirmer l'importation** pour insérer les enregistrements validés.
- **Exporter les données** :
  - Filtrer la population désirée (ex: Inscrits 2025-2026 de la faculté FST).
  - Choisir le format de sortie : **Excel (.xlsx)**, **CSV** ou **Liste PDF institutionnelle**.

---

### 3.9 Qualité des Données & Audit (§24-25)

Dans le menu **Qualité & Supervision** (`/admin/qualite`) :
- **Détecteur d'Anomalies** : Identification des dossiers incomplets, des CIN comportant des caractères anormaux et des discordances d'adresses.
- **Journal d'Audit Centralisé (`/admin/audit`)** :
  - Consultation de toutes les actions administratives réalisées sur la plateforme.
  - Filtres par utilisateur, adresse IP, plage de dates ou entité modifiée.
  - Export CSV légal de la piste d'audit pour les inspections académiques.

---

# ANNEXES & RÉFÉRENCE RAPIDE

## A. Comptes de Démonstration (Développement)

Ces identifiants sont initialisés par `php artisan db:seed` en environnement local :

| Profil / Rôle | Identifiant (Email) | Mot de passe | Périmètre d'Accès |
|---|---|---|---|
| **Super Administrateur** | `admin@universite.ac.ma` | `Admin@2026` | Accès universel complet (toutes facultés) |
| **Responsable Scolarité** | `scolarite@universite.ac.ma` | `Scolarite@2026` | Gestion scolarité centrale |
| **Agent de Scolarité** | `agent@universite.ac.ma` | `Agent@2026` | Saisie et instruction des dossiers |
| **Admin Faculté Sciences** | `admin.fst@universite.ac.ma` | `Faculte@2026` | Limité strictement à la Faculté FST |
| **Consultation Archives** | `consultation@universite.ac.ma` | `Consult@2026` | Lecture seule sur les archives académiques |
| **Étudiant Démo 1** | `mehdi.alami@email.com` (ou CIN: `BK712345`) | `etudiant123` | Espace Étudiant (L1 Sciences) |
| **Étudiant Démo 2** | `fz.bennani@email.com` (ou CIN: `AB890123`) | `etudiant123` | Espace Étudiant (L2 Droit) |

---

## B. Matrice des Rôles & Permissions

| Domaine Métier | Super Admin | Admin Scolarité | Agent Scolarité | Enseignant | Étudiant |
|---|:---:|:---:|:---:|:---:|:---:|
| **Inscriptions (Validation / Rejet)** | ✅ | ✅ (Faculté) | ✅ (Instruction) | ❌ | ❌ |
| **Fiche Étudiant 360°** | ✅ | ✅ (Faculté) | ✅ (Faculté) | ❌ | ❌ (Son profil seul) |
| **Référentiel LMD (Maquettes / UE)** | ✅ | ✅ (Faculté) | ❌ | ❌ | ❌ |
| **Emplois du Temps (Schedule)** | ✅ | ✅ (Faculté) | ❌ | 👁️ (Lecture) | 👁️ (Lecture) |
| **Notes & Délibérations** | ✅ | ✅ (Faculté) | ❌ | ✅ (Ses ECUE) | 👁️ (Ses notes) |
| **Imports / Exports (DataExchange)** | ✅ | ✅ (Faculté) | ❌ | ❌ | ❌ |
| **Journal d'Audit (§24-25)** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Requêtes & Réclamations** | ✅ | ✅ (Traitement) | ✅ (Traitement) | ❌ | ✅ (Dépôt) |

---

## C. Variables d'Environnement Clés (`.env`)

```ini
# Environnement & Débogage
APP_NAME=UniRegistre
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Base de données
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inscription_etudiants
DB_USERNAME=root
DB_PASSWORD=

# Modules Métier (true pour activer, false pour désactiver)
MODULE_REFERENTIEL=true
MODULE_ENSEIGNEMENT=true
MODULE_SCHEDULE=true
MODULE_EVALUATION=true
MODULE_ATTENDANCE=true
MODULE_ONLINE_REQUESTS=true
MODULE_STUDENT_DOSSIERS=true
MODULE_STUDENT_ARCHIVE=true
MODULE_TASKS=true

# Moteur OCR d'identité (null | tesseract | ppocr | colab)
IDENTITY_OCR_DRIVER=null
IDENTITY_OCR_TIMEOUT=30
```

---

## D. Résolution des Problèmes Fréquents (FAQ)

### 1. Le panneau d'administration renvoie une erreur 404 sur un module
👉 **Cause** : Le module concerné est désactivé dans votre fichier `.env`.  
👉 **Solution** : Vérifiez dans votre fichier `.env` que le module est à `true` (ex: `MODULE_SCHEDULE=true`), puis exécutez `php artisan config:clear`.

### 2. Les modifications CSS ou JavaScript ne sont pas visibles dans le navigateur
👉 **Cause** : Les assets compilés dans `public/build` ne sont pas à jour.  
👉 **Solution** : Lancez `npm run build` (ou laissez tourner `npm run dev`) et effectuez un rafraîchissement forcé du navigateur (`Ctrl + F5` ou `Cmd + Shift + R`).

### 3. Erreur de permissions lors du téléversement de documents
👉 **Cause** : Le dossier `storage/` n'a pas les droits d'écriture pour le serveur web.  
👉 **Solution** : Exécutez `chmod -R 775 storage bootstrap/cache` et vérifiez le lien symbolique avec `php artisan storage:link`.

### 4. Comment réinitialiser entièrement la base de données avec des données propres ?
👉 **Solution** : Exécutez :
```bash
php artisan migrate:fresh --seed
```

---
*UniRegistre — Plateforme Universitaire Certifiée conforme aux standards LMD et exigences de traçabilité (§24-25).*
