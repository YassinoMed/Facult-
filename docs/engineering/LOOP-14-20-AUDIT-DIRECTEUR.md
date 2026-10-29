# LOOP 14–20 — Audit directeur (extensions universitaires UniRegistre)

**Date :** 2026-10-24 · **Mode :** lecture seule — aucune écriture, aucun commit, aucune opération destructive.
**Méthode :** inventaire de `app/` (modules, modèles, services), sondes SELECT sur la base locale `inscription_etudiants` (APP_ENV=production ⇒ lecture seule strictement), lecture des rapports existants.
**Consigne :** auditer avant de proposer ; coexistence avec le module concurrent `AssistantIa` (jamais touché, ni code ni migrations) ; consommer l'existant avant toute création.

## 0. Cartographie des actifs existants

### Actifs transverses confirmés

| Actif | Emplacement vérifié | Utile pour |
|---|---|---|
| Référentiel LMD | `app/Modules/Referentiel` : Maquette, MaquetteUe, MaquetteUeEcue, Matiere, Semestre, TypeDiplome | 14, 20 |
| Maquettes versionnées, comparaison, activation simulée | `MaquetteService`, `MaquetteComparaisonService`, `MaquetteActivationSimulationService`, `MaquetteImportDefinition` (DataExchange), module `Versioning` | 20 |
| Simulation d'affectation persistée (pattern) | `AssignmentSimulation` : parametres, hash_entrees, seed, version, statuts (brouillon → simulee → confirmee / expiree / annulee) ; docblock : « objet technique, jamais une affectation réelle » | 14 (réutiliser le PATTERN, périmètre distinct) |
| Simulation de parcours individuel | `SimulationParcoursService` + interface, `app/Modules/ParcoursAcademique` | 14 (à ne PAS assimiler au simulateur institutionnel) |
| Affectation en groupes | `AffectationGroupe`, `AffectationProposal`, `AssignmentItem`, `AffectationAutomatiqueService` | 14 |
| Emploi du temps et évaluation | `app/Modules/Schedule`, `app/Modules/Evaluation`, `PresenceEmploiDuTempsService` (Attendance) | 14, 15 |
| Lieux physiques | Modèle `Batiment` ; modèle `Salle` NON confirmé à ce stade | 14 |
| Assiduité / émargement | `app/Modules/Attendance` | 15 |
| Résultats et historique | `Note`, `InscriptionAcademique`, `InscriptionPedagogique`, `HistoriqueAcademique`, `SituationEtudiant` | 15, 16 |
| Rendez-vous scolaires | `RendezVousScolariteController` (étudiant), `RendezVousScolariteService` | 15 |
| Notifications unifiées | `AppNotification`, `NotificationPreference`, `NotificationTemplate`, `CampagneCourriel` | 15, 16, 17, 19 |
| Qualité (socle) | `AnomalieQualite`, `QualitePolicy`, `AgregateurQualite`, `Admin/QualiteController`, `Admin/SystemeController` | 16 |
| Coffre documentaire | `DocumentAcademique`, `DocumentVerification`, `DocumentTemplate`, `DocumentTemplateVersion`, `DocumentSignatory`, `DocumentBatch`, `DocumentEvent` ; `app/Modules/StudentDossiers` (stockage, antivirus) | 16, 17, 19 |
| Data Mesh / observabilité | `app/DataMesh/Observabilite/ServiceObservabilite`, `Admin/DataMeshController` (lecture seule, périmètre facultaire forcé, permission `statistiques.view`) | 14, 16, 18 |
| Intégrations existantes | `WebhookVerifier` (`app/Services/Io`), `WebhookController` (`Api/V1`), `IntegrationLog` (écriture seule, payload_hash), `DataExchange`, `ImportJob` / `ExportJob` / `ImportJobRow` / `ImportReport` | 18 |
| Gouvernance / incidents | `app/Modules/Sentinel` : `SentinelIncident`, `SentinelRegle`, `Services/Checks`, `SentinelRegistry`, `SentinelService` | 19 |
| Sécurité des comptes | MFA, `VerrouillagePin`, `DatabaseBackup`, `AttributionRoleService`, permissions par rôles | 19 |

### Absences nettes (rien à consommer)

| Concept | Constat |
|---|---|
| Alumni / insertion professionnelle | Aucun modèle, service ou table. Aucune entité « diplôme délivré » (seul `TypeDiplome` existe : catalogue de types). |
| Compétences / objectifs pédagogiques | Aucun modèle. Catalogue à créer de façon additive (Lot 20). |
| Catalogue versionné de référentiels qualité | Absent. `AnomalieQualite` couvre les anomalies, pas les référentiels d'accréditation. |
| Simulateur institutionnel de scénarios | Absent. Les simulations existantes portent sur les groupes (technique) ou le parcours individuel (un étudiant). |
| Modèle `Salle` | Non confirmé (seul `Batiment` trouvé). À vérifier dans le LOOP-00 du Lot 14. |
| Données budgétaires | Aucune source identifiée. Les coûts resteront explicitement indisponibles si la situation ne change pas. |

## 1. Analyse par lot

### Lot 14 — Simulateur de scénarios universitaires
- **Présent :** pattern de simulation persistée sans écriture officielle (`AssignmentSimulation`) ; Data Mesh lecture seule ; services de calcul (`AcademicCalculationService`, `AcademicProgressionService`) ; emploi du temps ; affectation de groupes déjà simulée dans son périmètre technique.
- **Écart :** simulateur institutionnel multi-domaines (effectifs, programmes, salles, créneaux, charges) ; comparaison versionnée de scénarios ; périmètre facultaire.
- **Dépendances :** référentiel LMD OK ; inscriptions validées OK ; groupes OK ; salles et créneaux À VÉRIFIER (modèle `Salle` non confirmé).
- **Verdict de pré-audit :** **prêt à lancer** après son LOOP-00 spécifique (salles/créneaux). Les indicateurs de coûts resteront hors périmètre (source absente) — réserve explicite, pas un blocage.

### Lot 15 — Prévention du décrochage
- **Présent :** Attendance (émargement), résultats et historique fiables, rendez-vous scolaires, notifications unifiées avec préférences, permissions par rôles.
- **Écart :** catalogue versionné de règles approuvées et explicables ; dossier d'accompagnement séparé des résultats officiels ; flux humain (examen, correction, clôture) ; rétention des alertes.
- **Verdict de pré-audit :** **prêt à lancer**. Règle d'or : donnée absente ≠ signal négatif ; aucune alerte ne modifie notes ou statut d'inscription ; décision toujours humaine.

### Lot 16 — Assurance qualité et accréditation
- **Présent :** socle anomalies (`AnomalieQualite` + policy + agrégateur) ; coffre documentaire très complet (versions, signatures, vérification) ; journaux existants.
- **Écart :** catalogue versionné référentiels/critères ; chaîne critère relié à preuve, constat et plan d'action ; workflow d'approbation des rapports ; export sous permissions.
- **Verdict de pré-audit :** **prêt à lancer**. Interdits : inventer des critères d'accréditation ; présenter un rapport incomplet comme conforme.

### Lot 17 — Portail alumni
- **Présent :** authentification et récupération de compte, coffre, notifications et campagnes.
- **Manquants bloquants :** aucune source de diplomation officielle (seul `TypeDiplome`) ; aucune règle confirmée pour les consentements alumni.
- **Verdict de pré-audit :** **dépendance non confirmée** — en attente de son audit dédié. Retardé volontairement : fabriquer une règle de diplomation ou de consentement serait interdit par le cahier des charges.

### Lot 18 — Hub d'intégration
- **Présent (robuste) :** `WebhookVerifier` (HMAC) + contrôleur webhook API v1 ; `IntegrationLog` immuable en écriture seule avec payload_hash ; pipeline d'imports/exports ; observabilité Data Mesh (graphe, flux, qualité, outbox).
- **Écart :** contrats versionnés formels par connecteur ; idempotence à auditer connecteur par connecteur ; retries bornés et rejeu autorisé-audité ; séparation des environnements de test.
- **Verdict de pré-audit :** **prêt à lancer**. Aucune refonte de l'existant : extension additive.

### Lot 19 — Centre de gouvernance et conformité
- **Présent :** module `Sentinel` (incidents + règles + checks + registre), MFA, PIN, sauvegardes, attribution des rôles, coffre, patterns de versionnage.
- **Écart :** vue unifiée des accès sensibles, changements de permissions et rétentions ; rôle d'audit distinct ; politiques de rétention explicites et approuvées ; journal des accès aux journaux.
- **Verdict de pré-audit :** **prêt à lancer** avec réserve éthique : éviter la surveillance disproportionnée ; NE PAS promettre d'immuabilité que l'infrastructure ne garantit pas.

### Lot 20 — Programmes et compétences
- **Présent :** référentiel LMD complet ; maquettes versionnées avec comparaison et activation via simulation ; imports avec définition (`MaquetteImportDefinition`) et validation.
- **Écart :** catalogue `Competence` (création additive) ; associations programme/UE/ECUE reliées aux compétences ; rapports de couverture distinguant « donnée absente » de « couverture absente » ; détection d'ambiguïtés à l'import.
- **Verdict de pré-audit :** **prêt à lancer**. Une nouvelle version ne modifie jamais silencieusement l'historique.

## 2. Verdict de sélection

Conformément au cahier : commencer par l'audit, puis sélectionner les lots dont les dépendances sont satisfaites.

| Lot | Réutilisables confirmés | État d'entrée |
|---|---|---|
| 14 | LMD, inscriptions, groupes, pattern de simulation | **À lancer** (salles/créneaux à conférer dans son LOOP-00 ; coûts exclus explicitement) |
| 15 | Attendance, historique, rendez-vous, notifications | **À lancer** |
| 16 | AnomalieQualite, coffre, journaux | **À lancer** |
| 17 | identité, coffre, notifications | **En attente** (source de diplomation + consentements à confirmer) |
| 18 | webhook vérifié, journal immuable, imports/exports, outbox observée | **À lancer** |
| 19 | Sentinel, MFA, rôles, sauvegardes | **À lancer** |
| 20 | Referentiel, maquettes versionnées, imports | **À lancer** |

**Ordre proposé :** 14 → 15 → 16 → 20 → 18 → 19 → 17. Le Lot 17 reste en réserve jusqu'à confirmation de sa source de diplomation ; sa position finale est indépendante de l'ordre des autres.

## 3. Règles transverses réaffirmées (pour toute la série et la PARTIE F)

- Les simulations n'écrivent JAMAIS dans les données officielles ; hypothèses, sources et fraîcheur restent consultables ; comparaisons à unités et périmètre identiques ; donnée manquante signalée, jamais remplacée par zéro.
- Décrochage : accompagnement humain, jamais sanction automatique ; correction et clôture tracées ; données datées et sourcées.
- Accréditation : la plateforme ORGANISE les preuves, elle ne prononce pas la conformité.
- Alumni : coordonnées privées sous consentement ; employeurs sans accès aux dossiers académiques ; agrégats protégés contre la réidentification.
- Hub : webhooks authentifiés, retries bornés, rejeu audité, environnements séparés.
- Gouvernance : accès aux journaux contrôlés, pas de fausse promesse d'immuabilité, rétentions approuvées.
- Compétences : pas de réinterprétation rétroactive ; activation via le workflow prévu.
- Un verdict passe sur une preuve exécutée ; une absence de preuve devient une réserve explicite ; NOT_EXECUTED n'est jamais présenté comme réussi.

## 4. Prochain pas

- Aucun code des lots 14–20 à ce stade : audit seul, lecture seule.
- Prochaine boucle : LOT 14 (protocole LOOP-00 → LOOP-10) dans `docs/engineering/LOOP-14-SIMULATEUR-SCENARIOS.md`, en commençant par son audit spécifique salles/créneaux (confirmation du modèle `Salle`) et la réutilisation du pattern `AssignmentSimulation` sur un périmètre institutionnel séparé.
