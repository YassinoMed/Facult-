# LOOP 43 — MATRICE DES FEATURE FLAGS ET DES CONFINEMENTS
## UniRegistre — Spécification technique, dimensions de restriction et comportement Fail-Closed

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** AUDITÉ ET CONFORME (GATE G43)  
**Référence contractuelle :** ADDENDUM LOOP 42-C + AUTORISATION LOOP 43 — Phase C & H  

---

## 1. Principes Fondamentaux d'Architecture

Le déploiement progressif sous UniRegistre repose sur un principe absolu de **cloisonnement multi-dimensionnel étanche** et de **sécurisation fail-closed** :

1. **Défaut Fermé (Fail-Closed)** : Tout indicateur non reconnu, non activé, ou dont la configuration est incomplète, contradictoire ou absente retourne systématiquement `false` et rejette la requête (code HTTP `404 Not Found` en interface web/API pour ne pas divulguer l'existence de la route).
2. **Plafond Environnemental Strict** : La liste des environnements autorisés au niveau global (`FEATURE_FLAGS_ENVIRONNEMENTS`) agit comme un plafond indépassable. Si l'environnement applicatif courant (`APP_ENV`) n'y figure pas (notamment en `production`), aucune fonctionnalité protégée ne peut être activée, quelle que soit la configuration propre au flag.
3. **Imperméabilité aux Paramètres Clients Falsifiés** : Les contrôles de contexte (faculté, programme, cohorte, utilisateur) s'appuient exclusivement sur l'état authentifié côté serveur (`$request->user()`, modèle Eloquent, liaisons d'itinéraires signées). Les paramètres envoyés par le client (`?faculte_id=1`, `programme_id`, etc.) sont **strictement ignorés**.
4. **Zéro Mutation en Production et Modes Sécurisés** : Le mode `MIGRATION_AUTORISEE` est formellement interdit en LOOP 43. Seuls les modes `AUDIT_ONLY` (lecture seule) et `SIMULATION` (calculs volatils en mémoire sans transaction SQL d'écriture) sont autorisés.

---

## 2. Matrice Globale des Indicateurs de Déploiement

| Indicateur | Statut par défaut | Environnements autorisés | Facultés autorisées | Programmes autorisés | Cohortes / Années | Modes autorisés | Pourcentage |
|---|:---:|---|---|---|---|---|:---:|
| `pilote_academique` | `false` (Désactivé) | `local, testing, preproduction` (Prod formellement bloquée) | `[1]` (FST uniquement) | `[21]` (Licence Info Régime Mixte) | `[6]` (2025-2026 L1) | `AUDIT_ONLY`, `SIMULATION` | `null` (Désactivé) |
| `gouvernance_regles` | `false` (Désactivé) | `local, testing, preproduction` | `[]` (Aucune) | `[]` (Aucun) | `[]` (Aucune) | Aucun | `null` |
| `recours_academique` | `false` (Désactivé) | `local, testing, preproduction` | `[]` (Aucune) | `[]` (Aucun) | `[]` (Aucune) | Aucun | `null` |
| `planification_simulation` | `false` (Désactivé) | `local, testing, preproduction` | `[]` (Aucune) | `[]` (Aucun) | `[]` (Aucune) | Aucun | `null` |
| `kpi_academique` | `false` (Désactivé) | `local, testing, preproduction` | `[]` (Aucune) | `[]` (Aucun) | `[]` (Aucune) | Aucun | `null` |

---

## 3. Détail des Dimensions de Restriction pour `pilote_academique`

### 3.1 Dimension Environnement
- **Variable de configuration** : `FEATURE_FLAGS_ENVIRONNEMENTS` (défaut : `local,testing,preproduction`).
- **Règle** : Si `app()->environment()` est `production`, l'évaluation s'interrompt immédiatement avec le motif `ENVIRONNEMENT_NON_AUTORISE`.
- **Garantie** : Aucune surcharge au niveau d'un flag ne peut surpasser ce plafond global.

### 3.2 Dimension Facultaire (`facultes`)
- **Valeur autorisée** : `[1]` (ID 1 = Faculté des Sciences de Tunis - FST).
- **Règle de filtrage** : Si le contexte utilisateur ne fournit pas de `faculte_id`, ou si celui-ci est différent de `1` (ex: FSJPS, ENSIT, FSEG), le retour est `false` avec le motif `HORS_FACULTE`.
- **Origine de la donnée** : `Auth::user()->faculte_id` vérifié dans la table `users`.

### 3.3 Dimension Programme Académique (`programmes`)
- **Valeur autorisée** : `[21]` (ID 21 = Licence Nationale en Sciences de l'Informatique - FST).
- **Règle de filtrage** : Tout rattachement à un programme de Mastère (ex: `10`), de Cycle Ingénieur (ex: `30`), ou autre licence retourne `false` avec motif `HORS_PROGRAMME`.
- **Origine de la donnée** : Liaison certifiée du dossier d'inscription de l'étudiant (`etudiants.programme_id` ou maquette active).

### 3.4 Dimension Cohorte / Année Universitaire (`annees`)
- **Valeur autorisée** : `[6]` (ID 6 = Année universitaire 2025-2026, cohorte L1 active).
- **Règle de filtrage** : Les inscriptions rattachées aux années antérieures (2024-2025, ID 5 et antérieures) ou non spécifiées sont bloquées avec le motif `HORS_COHORTE`.
- **Origine de la donnée** : `annee_universitaire_id` validé par la scolarité centrale.

### 3.5 Dimension Mode d'Exécution (`modes`)
- **Modes supportés** :
  - `AUDIT_ONLY` : Consultation analytique des données sans déclenchement de calcul d'acquis.
  - `SIMULATION` : Calcul des moyennes, crédits et décisions virtuelles en mémoire sans écriture SQL.
  - `PILOTE_CONTROLE` : **Désactivé** (requiert validation G43 préalable).
  - `MIGRATION_AUTORISEE` : **Strictement interdit et désactivé** (requiert Gate G50 et validation Rectorat).
- **Règle de filtrage** : Si une requête sollicite un mode non autorisé ou une écriture en base, l'exécution est bloquée avec le motif `MODE_NON_AUTORISE`.

### 3.6 Déploiement Partiel par Pourcentage (`pourcentage`)
- **Algorithme** : `crc32($flag . ':' . $sujet) % 100`.
- **Propriétés** : Déterministe, reproductible, sans état, uniformément réparti sur l'intervalle `[0, 99]`.
- **Règle** : Pour la LOOP 43, `pourcentage` est fixé à `null` (aucun découpage statistique appliqué tant que le périmètre n'est pas restreint par segment de cohorte).

---

## 4. Matrice des Motifs de Rejet (Fail-Closed)

Le service [`app/Services/FeatureFlagService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/FeatureFlagService.php) retourne des motifs de diagnostic explicites via la méthode `analyser()` tout en garantissant un rejet silencieux côté HTTP :

| Code Motif | Signification Métier | Comportement HTTP / API |
|---|---|---|
| `FLAG_INCONNU` | L'indicateur demandé n'est pas déclaré dans `config/feature_flags.php`. | HTTP 404 (Route non exposée) |
| `DESACTIVE` | L'indicateur est positionné à `enabled => false`. | HTTP 404 |
| `ENVIRONNEMENT_NON_AUTORISE` | L'environnement d'exécution n'est pas dans la liste autorisée. | HTTP 404 |
| `HORS_FACULTE` | L'utilisateur ou le dossier n'appartient pas à la faculté confinée (FST). | HTTP 404 / 403 Forbidden |
| `HORS_PROGRAMME` | Le programme n'est pas le Programme 21. | HTTP 404 |
| `HORS_COHORTE` | L'année universitaire n'est pas la cohorte confinée 2025-2026. | HTTP 404 |
| `MODE_NON_AUTORISE` | Tentative d'exécution d'un mode non autorisé (ex: écriture, migration). | Exception / Blocage immédiat |
| `UTILISATEUR_HORS_LISTE` | Utilisateur non présent dans la liste blanche nominative. | HTTP 404 |
| `POURCENTAGE_NON_ATTEINT` | Le seau de l'utilisateur dépasse le seuil configuré. | HTTP 404 |

---

## 5. Traçabilité et Confidentialité des Activations

Toute évaluation d'indicateur en environnement de test ou de préproduction peut faire l'objet d'une journalisation technique :
- **Absence totale de données personnelles (Zero PII)** : Seuls les identifiants techniques opaques (`user_id`, `faculte_id`, `programme_id`, `mode`) et le résultat booléen sont consignés.
- **Identifiant d'exécution unique (`correlation_id`)** : Permet de corréler les logs sans stocker d'informations identifiantes (CIN, nom, email).
- **Horodatage ISO 8601** pour l'auditabilité réglementaire.

