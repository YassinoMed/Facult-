# LOOP-UNIREGISTRE-CENTRE-TRAVAIL-PRÉIMPLÉMENTATION-02
## Rapport d'Audit Complémentaire et de Pré-implémentation

- **Date :** 23 octobre 2026
- **Projet :** UniRegistre — Plateforme Universitaire Intégrée
- **Programme :** Centre de travail administratif intelligent
- **Référence :** `LOOP-UNIREGISTRE-CENTRE-TRAVAIL-PRÉIMPLÉMENTATION-02`
- **Auteurs :** Équipe d'Ingénierie & Architecture Logicielle UniRegistre
- **Verdict :** **`PASS`** — Autorisation formelle de démarrage du sous-lot A

---

## 1. CONSTATS CONFIRMÉS SUR LE DÉPÔT

L'audit complémentaire approfondi mené sur le code source actif et la base de données confirme les faits techniques et métier suivants :

1. **Relations et cardinalités des inscriptions :**
   - Un étudiant (`Etudiant`) possède un dossier longitudinal unique.
   - Un étudiant peut posséder **plusieurs inscriptions académiques historiques** (`inscriptions_academiques`), exactement **une par année universitaire**.
   - La contrainte SQL unique `uniq_inscription_etudiant_annee` sur les colonnes `(etudiant_id, annee_universitaire_id)` interdit strictement les inscriptions multiples au cours d'une même année.
   - Chaque inscription annuelle possède son propre identifiant (`id`), son propre cycle de vie (`statut` : `en_cours`, `valide`, `ajourne`, `abandon`), ses pièces justificatives spécifiques (`InscriptionDocument`) et son bilan d'éligibilité.
2. **Infrastructure existante des tâches (`app/Modules/Tasks`) :**
   - La table `tasks` et son modèle `App\Modules\Tasks\Models\Task` disposent de toutes les colonnes nécessaires : `cle_unique` (string 120 unique), `titre`, `description`, `source`, `statut`, `priorite`, `echeance_a`, `lien`, `faculte_id`, `assigne_a`.
   - `TaskService` fournit les mécanismes natifs de synchronisation idempotente (`synchroniser()`), de transition validée sous transaction (`transiter()`), d'assignation tracée (`assigner()`) et de clôture automatique (`cloturerResolues()`).
   - `TaskGenerationService` synchronise actuellement 6 sources (`demande_sla`, `justification`, `deliberation`, `epreuve_salle`, `sans_groupe`, `facture_impayee`), mais **n'intègre pas encore les inscriptions académiques**.
3. **Façade d'évaluation canonique préexistante :**
   - [InscriptionEligibilityService](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/InscriptionEligibilityService.php) existe déjà et fournit une évaluation 100 % dry-run agrégeant les périodes d'inscription, les pièces obligatoires GED, les 11 règles du moteur d'anomalies et l'identité MENS.
4. **Machine à états unique :**
   - [InscriptionService::transiter](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/InscriptionService.php) est le point de passage transactionnel unique pour la validation (`valide`) ou l'ajournement (`ajourne`). Il verrouille l'enregistrement via `lockForUpdate()`, refuse la validation si des blocages subsistent, trace l'audit et émet [InscriptionValidee](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Events/InscriptionValidee.php) après commit.

---

## 2. CHEMINS ET MÉTHODES RÉELLEMENT EXAMINÉS

| Fichier examiné | Méthodes / Sections clés | Responsabilité observée |
| :--- | :--- | :--- |
| `app/Models/InscriptionAcademique.php` | Relations `etudiant()`, `anneeUniversitaire()`, `documentsInscription()` | Modélisation de l'inscription annuelle unique par an |
| `database/migrations/2026_09_24_100005_create_inscriptions_academiques_table.php` | `uniq_inscription_etudiant_annee` | Contrainte d'unicité `(etudiant_id, annee_universitaire_id)` |
| `database/migrations/2026_09_30_000006_create_tasks_tables.php` | Définition de `tasks` et `cle_unique (120)` | Structure du registre de tâches transverse |
| `app/Modules/Tasks/Services/TaskService.php` | `synchroniser()`, `transiter()`, `assigner()` | Gestion idempotente des tâches et traçabilité |
| `app/Modules/Tasks/Services/TaskGenerationService.php` | `generer()`, `synchroniserSansGroupe()`, etc. | Génération périodique des tâches par émetteur |
| `app/Services/InscriptionEligibilityService.php` | `evaluer(InscriptionAcademique $inscription)` | Synthèse explicable sans effet de bord |
| `app/Services/InscriptionService.php` | `transiter()`, `verifierUniciteAnnuelle()` | Machine à états transactionnelle verrouillée |
| `app/Services/Verification/CorrectionService.php` | `proposer()`, `approuver()`, `rejeter()` | Workflow 4 yeux avec règle anti-auto-approbation |
| `app/Policies/InscriptionAcademiquePolicy.php` | `view()`, `update()`, `create()` | Contrôle `archives.update` + `peutAccederFaculte` |
| `app/Policies/TaskPolicy.php` | `view()`, `update()`, `assign()`, `complete()` | Contrôle RBAC des tâches et périmètre facultaire |
| `app/Support/Permissions.php` | Catalogue `TOUTES` et `MATRICE` | Matrice de droits par rôle |

---

## 3. DÉCISION SUR LA CLÉ UNIQUE DES TÂCHES

### Problématique
Si la clé unique d'une tâche d'inscription était construite avec le seul identifiant étudiant (`"inscription:{$etudiant->id}"`), deux dysfonctionnements critiques se produiraient :
1. Une réinscription d'un étudiant sur une nouvelle année universitaire écraserait ou rouvrirait la tâche historique de l'année précédente.
2. Un étudiant ayant un dossier archivé verrait son historique de tâches mélangé avec sa candidature en cours.

### Décision d'ingénierie
La clé unique doit cibler la ressource métier exacte en cours d'instruction, c'est-à-dire l'enregistrement de l'inscription annuelle :

$$\text{cle\_unique} = \mathbf{"inscription:\{" . \$inscription->id . "\}"}$$

- **Format :** Préfixe de source fixe (`inscription:`) suivi de l'identifiant primaire de la table `inscriptions_academiques`.
- **Longueur maximale :** 12 caractères de préfixe + 10 chiffres = 22 caractères (parfaitement conforme à la limite de 120 caractères de la colonne `tasks.cle_unique`).
- **Garantie :** Chaque inscription annuelle d'un même étudiant possède sa propre tâche indépendante et son propre historique d'événements.

---

## 4. ATOMICITÉ ET CONCURRENCE DE LA VALIDATION

L'articulation entre la transaction métier d'inscription et le cycle de vie de la tâche a été formellement arbitrée pour éviter tout état incohérent :

### 4.1. Ordre strict des opérations
La validation métier est la **source de vérité absolue**. Une tâche ne représente qu'un pointeur opérationnel vers cette vérité.
1. **Étape 1 :** Appel du service canonique `InscriptionService::transiter($inscription, 'valide', $userId, $motif)`.
2. **Étape 2 :** En cas de succès du commit transactionnel métier, appel de `TaskService::transiter($tache, 'completed', $userId)` pour clore la tâche.
3. **Étape 3 :** Si la clôture de la tâche échoue (ex. incident technique mineur), l'inscription reste valide (non annulée) et le mécanisme de réconciliation périodique (`cloturerResolues`) clôturera la tâche au prochain cycle.

### 4.2. Scénarios de concurrence et gestion fail-safe

| Scénario | Comportement système constaté | Traitement prévu dans le Centre de travail |
| :--- | :--- | :--- |
| **Deux agents valident simultanément** | Le 1er agent acquiert `lockForUpdate()`, valide et commit. Le 2nd agent acquiert le verrou sur l'inscription désormais `valide`, la transition `valide → valide` lève une `DomainException`. | Interception de la `DomainException` : si `$inscription->fresh()->statut === 'valide'`, retour informatif gracieux (idempotence) : *« Cette inscription a déjà été validée par un autre gestionnaire. »* (aucun crash 500). |
| **Validation échoue (blocage / pièce manquante)** | `InscriptionService::transiter` lève une `DomainException` avant la transaction SQL. Aucune écriture en base. | La tâche reste à son état actuel (`pending` ou `in_progress`), la synthèse des blocages est affichée à l'agent avec le motif précis. |
| **Rejeu d'un événement après commit** | `InscriptionValidee` est rejoué. Le listener `TenterAffectationAutomatique` appelle `AffectationAutomatiqueService::tenter()`. | Détection d'affectation existante $\rightarrow$ statut `EXISTING` sans duplication d'écriture. |
| **Échec de notification post-commit** | Le dispatch de notification intervient après commit dans `CommunicationDispatcher`. | Le fail-soft journalise l'échec dans `mail_tentatives` sans corrompre ni annuler la validation de l'inscription en base. |
| **Opération réseau / Verrous** | Aucune requête réseau ni appel externe n'est exécuté sous le verrou SQL `lockForUpdate()`. | Les opérations de notification et d'affectation automatique s'exécutent strictement après le commit transactionnel. |

---

## 5. AUDIT DES PERMISSIONS DE VALIDATION ET PÉRIMÈTRES

L'inspection de [InscriptionAcademiquePolicy](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Policies/InscriptionAcademiquePolicy.php), [EtudiantPolicy](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Policies/EtudiantPolicy.php), [TaskPolicy](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Policies/TaskPolicy.php) et [Permissions.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Support/Permissions.php) établit la matrice exacte des droits nécessaires :

| Opération du Centre | Gate / Permission vérifiée | Condition de périmètre organisationnel | Rôles habilités |
| :--- | :--- | :--- | :--- |
| **Accès au Centre de travail** | `tasks.view` ou `archives.view` | Aucune (filtrage de la liste selon l'agent) | Tous les rôles back-office |
| **Consultation d'une inscription** | Gate `view`, `InscriptionAcademique` (`archives.view`) | `user->peutAccederFaculte($etudiant->faculte_id)` | `super_admin`, `admin_scolarite`, `admin_faculte`, `agent_scolarite`, `consultation` |
| **Contrôle d'éligibilité (dry-run)** | Lecture seule via `evaluer()` | `user->peutAccederFaculte($etudiant->faculte_id)` | `super_admin`, `admin_scolarite`, `admin_faculte`, `agent_scolarite`, `consultation` |
| **Validation d'une inscription** | Gate `update`, `InscriptionAcademique` (`archives.update`) | `user->peutAccederFaculte($etudiant->faculte_id)` | `super_admin`, `admin_scolarite`, `admin_faculte`, `agent_scolarite` (exclus : `consultation`) |
| **Proposition de correction** | `archives.update` | `user->peutAccederFaculte($etudiant->faculte_id)` | `super_admin`, `admin_scolarite`, `admin_faculte`, `agent_scolarite` |
| **Décision correction (4 yeux)** | `admin.verification.decider` | `user->peutAccederFaculte($etudiant->faculte_id)` ET `propose_par !== user->id` | Second agent habilité `archives.update` |
| **Assignation d'une tâche** | Gate `assign`, `Task` (`tasks.assign`) | `task->faculte_id === null` OU `user->peutAccederFaculte(faculte_id)` | `super_admin`, `admin_scolarite`, `admin_faculte` (exclus : `agent_scolarite`) |
| **Consultation des pièces GED** | `student_documents.view` ou `documents.view` | `user->peutAccederFaculte($etudiant->faculte_id)` | `super_admin`, `admin_scolarite`, `admin_faculte`, `agent_scolarite`, `consultation` |

> [!IMPORTANT]
> **Confirmation :** Aucune nouvelle permission n'a besoin d'être créée. La permission `archives.update` combinée au périmètre facultaire côté serveur constitue la règle établie et éprouvée du dépôt.

---

## 6. STRATÉGIE DE PRIORISATION DES TÂCHES

Conformément à la consigne, **aucun SLA arbitraire ni score opaque n'est inventé**. La priorité d'une tâche d'inscription repose sur des critères objectifs et explicables :

1. **Urgence liée à une échéance réelle (`critical`) :**
   - Réservée aux dossiers dont la période d'inscription officielle expire dans moins de 48 heures.
2. **Blocage métier confirmé (`high`) :**
   - Dossier dont le contrôle d'éligibilité révèle au moins un blocage critique (ex. pièce rejetée, divergence MENS non résolue, décision jury requise).
3. **Ancienneté d'attente sans échéance (`high` ou `normal`) :**
   - Attente supérieure à 7 jours (`souffrance_7j`) : priorité `high` avec motif d'ancienneté.
   - Attente comprise entre 48h et 7 jours (`souffrance_48h`) : indicateur visuel d'attention (`est_en_souffrance = true`), priorité `normal`.
   - Nouveau dossier (< 48h) sans blocage : priorité `normal`.
4. **Intervention humaine requise :**
   - Indiquée par le verdict `READY_FOR_REVIEW` (dossier conforme, en attente de validation humaine formelle).

---

## 7. STRATÉGIE DU WORKFLOW DE CORRECTION À QUATRE YEUX

L'audit de [CorrectionService.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Verification/CorrectionService.php) confirme la règle suivante :
```php
// 4-eyes : l'approbateur ne peut pas être le proposeur.
abort_if((int) $verrouillee->propose_par === $decideurId, 403, 'Approbation par un second agent requise.');
```

Dans le Centre de travail administratif :
- Un agent peut initier une demande de correction sur un dossier d'inscription (`proposerPourInscription`) ou sur les données de l'étudiant (`proposer`).
- La proposition est enregistrée avec le statut `proposee` et son identifiant d'auteur (`propose_par`).
- L'interface du Centre affiche les corrections en attente :
  - Si l'agent connecté est le proposeur, le bouton d'approbation est masqué ou désactivé avec la mention explicite : *« En attente d'approbation par un second agent (règle 4 yeux) »*.
  - Si l'agent connecté est un second gestionnaire habilité de la même faculté, il peut approuver ou rejeter la correction avec motif.
  - L'application de la correction met à jour le dossier et déclenche une re-vérification automatique sans contourner le workflow canonique.

---

## 8. PREUVES ET RÉSULTATS D'EXÉCUTION DES SUITES DE TESTS

Toutes les suites de tests relatives aux briques réutilisées ont été rejouées et sont 100 % vertes :

| Suite de tests | Fichier(s) | Tests | Assertions | Durée | Statut |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **Tâches & Cockpit** | `tests/Feature/TasksCockpitTest.php` | 8 | 28 | 0.98 s | **PASS** |
| **Façade Éligibilité & MENS** | `tests/Feature/InscriptionEligibiliteFacadeTest.php`<br>`tests/Feature/InscriptionEligibilityMensTest.php` | 10 | 44 | 1.36 s | **PASS** |
| **File de Traitement (51 tests)** | `tests/Feature/FileTraitement*Test.php` | 51 | 141 | 2.46 s | **PASS** |
| **Moteur de Vérification** | `tests/Feature/VerificationBoucleTest.php`<br>`tests/Feature/VerificationFeatureTest.php` | 74 | 304 | 3.76 s | **PASS** |
| **TOTAL** | **4 sous-systèmes vérifiés** | **143** | **517** | **8.56 s** | **100% PASS** |

---

## 9. BLOCAGES MÉTIER ET RISQUES RÉSIDUELS

- **Blocages métier :** Aucun. Toutes les règles, validations et modèles nécessaires sont présents et opérationnels.
- **Risque résiduel identifié :** Risque de charge si le contrôle d'éligibilité complet était calculé de manière synchrone sur l'ensemble des 15 ou 25 lignes d'une page de file de travail.
  - **Mitigation appliquée :** La liste paginée du Centre affiche les indicateurs dérivés légers pré-calculés par `FileTraitementService`. L'évaluation complète `InscriptionEligibilityService::evaluer()` est exécutée à la demande lors de l'ouverture du tiroir de détail d'un dossier (`admin.centre-travail.show`) ou via appel asynchrone unitaire.

---

## 10. PLAN RÉVISÉ DES SOUS-LOTS (A → F)

À l'issue de cet audit approfondi, le plan d'implémentation est ajusté comme suit :

1. **Sous-lot A — Source de vérité & Extension idempotente de `TaskGenerationService` :**
   - Ajout de la méthode `synchroniserInscriptions()` dans `TaskGenerationService` avec clé `cle_unique = "inscription:{$inscription->id}"`.
   - Priorités déterministes : `critical` si période expire < 48h, `high` si blocage critique ou attente > 7j, `normal` sinon.
   - Tests unitaires et d'idempotence pour les inscriptions multiples, réinscriptions et clôtures automatiques.
2. **Sous-lot B — File de travail en lecture seule :**
   - Contrôleur `App\Http\Controllers\Admin\CentreTravailController` avec action `index()`.
   - Scoping faculté strict, filtres combinés, pagination serveur et KPIs de charge.
3. **Sous-lot C — Contrôle des inscriptions et explicabilité :**
   - Endpoint `show()` et `controler()` branchés sur `InscriptionEligibilityService::evaluer()`.
   - Affichage des 4 contrôles (Période, Documents, Anomalies, MENS) avec statuts visuels et recommandations.
4. **Sous-lot D — Actions administratives autorisées & Concurrence :**
   - Validation atomique via `InscriptionService::transiter('valide')` + clôture de la tâche `Task`.
   - Traitement gracieux de la double validation concurrente (idempotence).
   - Intégration du workflow 4 yeux pour les propositions de correction.
5. **Sous-lot E — Interface Utilisateur, Palette & Navigation :**
   - Vue Blade responsive et accessible `resources/views/admin/centre-travail/index.blade.php`.
   - Ajout du lien dans `_navigation.blade.php` et raccourci dans `x-palette`.
6. **Sous-lot F — Validation complète, non-régression et certification :**
   - Rédaction de la suite de tests complète `tests/Feature/CentreTravailAdminTest.php`.
   - Contrôle Pint et rédaction du rapport final.

---

## 11. VERDICT ET RECOMMANDATION

### Verdict : **`PASS`**

### Recommandation explicite :
**L'autorisation de démarrage du Sous-lot A est accordée sans réserve.** Toutes les ambiguïtés sur la clé unique, l'atomicité, les permissions, la priorisation et le workflow à 4 yeux sont résolues et documentées sur la base du code réel.
