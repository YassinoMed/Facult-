# Data Mesh — BASELINE (Audit Approfondi et État des Lieux)

**Date d'audit** : 16 octobre 2026  
**Périmètre** : Monolithe modulaire Laravel `inscription-etudiants`  
**Méthode** : Inspection directe en lecture seule du code source, des modèles, des migrations, des services, des routes, des permissions et de la suite de tests.

---

## 1. Organisation réelle des modules et des namespaces

Le projet est structuré selon une architecture **CORE + MODULES** découplée :
- **Cœur applicatif (`app/`)** :
  - `app/Models/` (73 modèles cœur : `Etudiant`, `InscriptionAcademique`, `AffectationGroupe`, `AnneeUniversitaire`, `Faculte`, `Matiere`, `HistoriqueAcademique`, `AnomalieQualite`...).
  - `app/Services/` (56 services cœur : `InscriptionService`, `AffectationService`, `BIService`, `AuditService`, `AgregateurQualite`, `DataExchange/...`).
  - `app/Http/Controllers/` (contrôleurs scindés en `Admin/`, `Api/V1/`, `Etudiant/`, `Auth/`).
- **15 Modules Métier (`app/Modules/`)** :
  1. `Referentiel` : structure LMD (`Departement`, `Domaine`, `Mention`, `Specialite`, `Programme`, `Niveau`, `SemestreLmd`, `UniteEnseignement`, `Maquette`).
  2. `Enseignement` : charges d'enseignement, rattachement matière-enseignant-groupe (`Enseignement`, `EnseignementGroupe`, `SousGroupe`).
  3. `Evaluation` : examens, épreuves, anonymat/compostage, délibérations, surveillances (`Examen`, `Epreuve`, `NoteEpreuve`, `Surveillance`, `DecisionDeliberation`).
  4. `Schedule` : emplois du temps, groupes/classes, enseignants, salles (`Group`, `Teacher`, `Room`, `Schedule`, `ScheduleSlot`).
  5. `Attendance` : assiduité, pointages, relevés d'absence.
  6. `StudentDossiers` : documents étudiants numérisés, vérification pièces, OCR.
  7. `StudentArchive` : registre historique longitudinal, consultations, données sensibles (médical, disciplinaire).
  8. `OnlineRequests` : demandes en ligne, pièces jointes, workflow de validation.
  9. `ParcoursAcademique` : progression de l'étudiant, crédits capitalisés, passerelles.
  10. `Finances` : tarification, facturation, paiements, remboursements.
  11. `Announcements` : annonces institutionnelles, ciblages, campagnes.
  12. `Calendar` : calendrier universitaire, événements académiques.
  13. `Tasks` : gestion de tâches et rappels administratifs.
  14. `Sentinel` : détection d'intrusions, règles de sécurité et incidents système.
  15. `Versioning` : versionnage des composants, releases, déploiements, audits de version.

**Mécanisme de registre et d'activation** :
- `config/modules.php` définit les bascules d'activation (`MODULE_REFERENTIEL`, `MODULE_SCHEDULE`, `MODULE_EVALUATION`, etc.).
- `app/Providers/ModuleServiceProvider.php` et `app/Support/ModuleRegistry.php` chargent dynamiquement les routes, migrations et vues de chaque module activé.
- `app/Http/Middleware/EnsureModuleEnabled.php` intercepte les routes sous middleware `module:nom` et renvoie une réponse 404 si le module est désactivé.
- **Règle de séparation** (AGENTS.md) : un module n'ajoute pas de routes dans `routes/web.php` et n'ajoute pas de colonnes obligatoires dans les tables centrales sans précaution.

---

## 2. Modèles Eloquent, migrations et dépendances transversales

L'audit des migrations (`database/migrations/`, 160 fichiers) révèle un modèle relationnel riche mais comportant des **couplages directs entre tables de domaines distincts** via clés étrangères relationnelles :

### Preuves de dépendances transversales :
1. **Admissions → Référentiel & Scolarité** (`2026_10_05_000001_create_admissions_table.php:18-27`) :
   - `etudiant_id` → `etudiants.id` (cascade).
   - `programme_id` → `programmes.id` (module Referentiel).
   - `niveau_id` → `niveaux.id` (module Referentiel).
   - `annee_universitaire_id` → `annees_universitaires.id`.
   - `consumed_inscription_id` → `inscriptions_academiques.id`.
2. **Affectations de groupes → Scolarité, Emplois du temps & Enseignement** (`2026_09_29_210249_create_population_tables.php:48-60` et `app/Models/AffectationGroupe.php:18-58`) :
   - `etudiant_id` → `etudiants.id` (cœur).
   - `groupe_id` → `groups.id` (module Schedule).
   - `sous_groupe_id` → `sous_groupes.id` (module Enseignement).
   - `annee_universitaire_id` → `annees_universitaires.id`.
3. **Groupes d'étudiants → Référentiel LMD** (`2026_09_29_210249_create_population_tables.php:105-113` et `app/Modules/Schedule/Models/Group.php:24-28`) :
   - `programme_id` → `programmes.id` (module Referentiel).
   - `niveau_id` → `niveaux.id` (module Referentiel).
   - `annee_universitaire_id` → `annees_universitaires.id`.
   - `capacite` : entier nullable définissant la jauge officielle.
4. **Inscriptions académiques → Référentiel LMD** (`app/Models/InscriptionAcademique.php:19-35`) :
   - Clés `programme_id`, `niveau_id`, `annee_universitaire_id`.
   - Unicité stricte : `(etudiant_id, annee_universitaire_id)` (`2026_09_24_100005_create_inscriptions_academiques_table.php:34`).

### Risque identifié :
En l'absence de frontière logique formelle, n'importe quel contrôleur, job ou module peut interroger directement les tables MySQL d'un autre domaine, contournant les règles d'intégrité métier, les contrôles de capacité ou les règles de minimisation PII (CIN, notes, emails). Le Data Mesh logique apporte cette frontière sous forme de contrats et de produits de données sans casser la base MySQL unique.

---

## 3. Services métier réutilisables (Sources de Vérité)

Le projet dispose d'une riche bibliothèque de services applicatifs éprouvés avec garanties transactionnelles strictes :

| Domaine | Service principal | Fichier source | Garanties observées |
|---|---|---|---|
| **Scolarité** | `InscriptionService` | `app/Services/InscriptionService.php` | Transactions DB, `lockForUpdate`, transitions validées (`en_cours` → `valide`), dispatch d'événements après commit (`$afterCommit = true`). |
| **Scolarité** | `PeriodeInscriptionService` | `app/Services/PeriodeInscriptionService.php` | Contrôle d'éligibilité temporelle (fuseau Tunis `Africa/Tunis`), validation dates inclusives. |
| **Scolarité** | `SuiviDossierService` | `app/Services/SuiviDossierService.php` | Consultation publique sécurisée, double facteur, masquage systématique des PII (`email_masque`, `cin_masquee`). |
| **Affectations** | `AffectationService` | `app/Services/AffectationService.php` | Verrouillage ordonné (`etudiant` puis `groupe`), contrôle de capacité sous verrou, réaffectation idempotente, historique dans `affectations_groupe`. |
| **Affectations** | `MassAssignmentService` | `app/Services/Affectations/MassAssignmentService.php` | Simulation sans effet de bord (`analyser()`), calcul de score déterministe (`AffectationEngine`), exécution par lots avec audit. |
| **Affectations** | `AffectationAutomatiqueService`| `app/Services/AffectationAutomatiqueService.php` | Recherche du meilleur groupe disponible pour un étudiant validé, fail-soft (ne bloque jamais la validation d'inscription). |
| **Référentiel** | Module `Referentiel` | `app/Modules/Referentiel/Models/*` | Hiérarchie LMD normalisée, maquettes pédagogiques versionnées (`Maquette`, `MaquetteUe`, `MaquetteUeEcue`). |
| **Pilotage / BI** | `BIService` | `app/Services/BIService.php` | Indicateurs agrégés (`indicateurs()`), empreinte MD5 versionnée (`version()`), cache scopé `bi:{v}:a:f:p` avec TTL 600 s. |
| **Qualité** | `AgregateurQualite` | `app/Services/AgregateurQualite.php` | Agrégation sans duplication depuis population, référentiel, pédagogie et vérification de dossier vers `anomalies_qualite`. |
| **Traçabilité** | `AuditService` | `app/Services/AuditService.php` | Écriture immuable dans `historique_academique` (IP, User-Agent, ancienne/nouvelle valeur JSON, zéro secret). |
| **Échanges** | `DataExchange` | `app/Services/DataExchange/*` | 40 définitions versionnées, interfaces de contrat d'import/export, détection de doublons, rollback d'imports. |

---

## 4. Événements, listeners, jobs et outbox

### Événements métier observés (`app/Events/`) :
- `InscriptionValidee` : dispatché après le commit de `InscriptionService::transiter()` vers `valide` (`$afterCommit = true`).
- `StudentRegistered` : soumission initiale d'un dossier étudiant.
- `PopulationChanged` : changement de situation ou d'affectation.
- `DossierDecisionPrise` : validation ou rejet de pièce/dossier.
- `PeriodeInscriptionOuverte` : ouverture administrative d'une campagne.

### Listeners observés (`app/Listeners/`) :
- `TenterAffectationAutomatique` : écoute `InscriptionValidee` pour tenter l'affectation automatique via `AffectationAutomatiqueService`. Garde try/catch empêchant tout blocage.
- `DetecterIncoherencesInscription` : écoute `StudentRegistered` et enregistre les anomalies dans `anomalies_qualite`.
- `JournaliserInscription`, `AuditPopulation`, `NotifierDecisionDossier`, etc.
- **Observation clé** : Tous les listeners actuels s'exécutent de façon **synchrone** (aucun n'implémente `ShouldQueue`). Il n'y a pas de perte inter-processus immédiate, mais aucun mécanisme n'assure la persistance découplée en cas de passage asynchrone sans Outbox.

### Analyse Transactional Outbox :
Pour garantir le découplage des domaines et la publication fiable d'événements sans risque de publication prématurée (avant commit) ni de perte d'événement (si le worker plante), l'introduction d'une table `data_mesh_outbox` avec statut (`pending`, `published`, `failed`), clé d'idempotence et retries permet d'assurer la livraison au moins une fois (*at-least-once delivery*).

---

## 5. API, routes, sécurité et contrôle d'accès

- **Volume de routes** : 415 routes web (`routes/web.php`) et 55 routes API v1 (`routes/api.php`).
- **Authentification & Autorisation API** :
  - Tokens Laravel Sanctum avec scopes restreints (`scope:students:read`, `scope:referentiel:read`, `scope:assignments:read`, `scope:data:exchange`, etc.).
  - Middleware `IdempotencyKey` sur les mutations POST/PUT.
  - Middleware `throttle:api`, `throttle:api-imports`, `throttle:api-webhooks`.
  - Signature HMAC-SHA256 + horodatage anti-rejeu sur les webhooks externes.
- **RBAC & Politiques d'accès Back-Office** :
  - 5 rôles standardisés (`Permissions::ROLES`) : `super_admin`, `admin_scolarite`, `agent_scolarite`, `consultation`, `admin_faculte`.
  - Matrice unifiée de permissions (`Permissions::TOUTES`) enregistrée dans les Gates Laravel.
  - Politiques Eloquent (`EtudiantPolicy`, `NotePolicy`, `InscriptionAcademiquePolicy`...).
  - Cloisonnement facultaire strict : `User::peutAccederFaculte(int $faculteId)` et `User::faculteRestreinteId()`. Un utilisateur `admin_faculte` ne peut ni lire ni modifier des données hors de sa faculté de rattachement.

---

## 6. Qualité des données et observabilité existantes

- **Table `anomalies_qualite`** : persistance des écarts détectés avec sévérité (`info`, `avertissement`, `erreur`, `critique`), statut de cycle de vie (`ouverte`, `acquittee`, `en_cours`, `resolue`, `ignoree`), lien vers l'entité et faculté de rattachement.
- **Audit académique (`historique_academique`)** : append-only, traçabilité des modifications, IP, user-agent et contexte utilisateur.
- **Métriques d'automatisation** : `AutomationMetricsService` mesure les taux de succès de l'affectation automatique vs non-assignée.
- **Requêtes & Performance** :
  - Chargement anticipé (`with()`) largement utilisé dans les contrôleurs critiques.
  - Requêtes par lots (`chunk(500)`) dans les simulations et vérifications.
  - Mise en cache intelligente de la BI (`BIService`) basée sur un hash des dates de mise à jour des sources (`max(updated_at)`).

---

## 7. Environnement de tests et garde anti-catastrophe

- **Configuration de test (`phpunit.xml`)** :
  - `DB_CONNECTION = sqlite`
  - `DB_DATABASE = :memory:`
  - `APP_ENV = testing`
  - `QUEUE_CONNECTION = sync`
  - `CACHE_STORE = array`
- **Garde de sécurité stricte (`tests/TestCase.php:17-22`)** :
  - Tout test échoue immédiatement si `config('database.default') !== 'sqlite'`. Cette garde empêche formellement d'exécuter des opérations destructives (`migrate:fresh`, `db:wipe`) sur une base MySQL réelle.
- **Validation initiale** :
  - Les suites existantes (ex. `AffectationAutomatiqueApresValidationTest`, 11 assertions) s'exécutent en 1.1s sur SQLite en mémoire avec succès.

---

## 8. Bilan des risques et opportunités pour le Data Mesh

| Aspect | Constat d'audit | Risque | Traitement Data Mesh |
|---|---|---|---|
| **Couplage SQL direct** | Les tables ont des FK directes entre domaines (`admissions` → `programmes`, `affectations_groupe` → `groups`). | Les consommateurs peuvent faire des requêtes non contrôlées et contourner les règles métier. | Encapsuler l'accès dans des **produits de données** en lecture seule avec contrats stricts (`ProduitDonnees`). |
| **Exposition PII** | La table `etudiants` contient CIN, email, téléphone, date de naissance. | Fuite de données personnelles vers des modules périphériques ou analytiques. | Minimisation systématique : `cin_masquee`, `email_masque`, exclusion des coordonnées personnelles dans les contrats de scolarité. |
| **Capacités de groupes** | `capacite` est `NULL` pour certains groupes historiques (signifiant capacité illimitée). | Risque d'affectation non maîtrisée ou calcul de saturation faussé. | La règle métier existante est respectée : `NULL = illimitée`. Détection dans les contrôles qualité en gravité `avertissement`. |
| **Publication d'événements** | Les événements sont synchrones post-commit sans trace d'outbox persistée. | Si un traitement devient asynchrone, risque de perte en cas de crash worker. | Mise en place d'un composant `Transactional Outbox` léger et modulaire. |
| **Données décisionnelles** | Risque que le module Pilotage crée ses propres tables parallèles d'effectifs. | Désynchronisation et seconde source de vérité divergente. | `AnalytiqueFaculte` consomme exclusivement les indicateurs contrôlés de `BIService` et les produits en amont. |

**Conclusion de l'audit** : Le socle architectural du projet est particulièrement propice à l'introduction d'un Data Mesh logique. Les services de référence existent déjà et ne doivent en aucun cas être réécrits. Le Data Mesh viendra fédérer, formaliser les contrats d'échange, garantir la qualité continue et orchestrer le flux de données en toute sécurité.
