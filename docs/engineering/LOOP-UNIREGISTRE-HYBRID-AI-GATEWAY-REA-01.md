# RAPPORT OFFICIEL D'INGÉNIERIE ET DE VALIDATION SÉCURITÉ
# LOOP 17 — UNIREGISTRE HYBRID AI GATEWAY — LOCAL FIRST, REMOTE BY EXPLICIT CONSENT
# Référence : LOOP-UNIREGISTRE-HYBRID-AI-GATEWAY-REA-01

**Date d'exécution :** 23 octobre 2026  
**Projet :** UniRegistre (Plateforme universitaire d'inscriptions et de scolarité — Laravel 11 / PHP 8.2+)  
**Module cible :** Assistant IA & Passerelle Hybride Souveraine (`app/Modules/AssistantIa/`)  
**Type d'intervention :** Conception, renforcement architectural, implémentation de la passerelle hybride et tests adversariaux  
**Rapports antécédents consultés :** `LOOP-UNIREGISTRE-AI-ASSISTANT-INDEPENDENT-AUDIT-AUTO-PASS-02.md`  
**Verdict Global :** **`PASS`**

---

## 1. Résumé Exécutif

La mission **LOOP 17** a fait évoluer le Knowledge Hub existant d'UniRegistre vers une architecture IA souveraine **Local-First**, au sein de laquelle **Ollama est le moteur par défaut et souverain**, et où les fournisseurs distants (ex: OpenAI, vLLM distant) sont strictement inaccessibles sans une **autorisation explicite, cryptographiquement prouvée et conditionnée à la sensibilité des données**.

Conformément aux exigences de souveraineté numérique de l'enseignement supérieur :
1. **Zéro fuite automatique (Fail-Soft Souverain) :** Si le serveur Ollama local est indisponible ou surchargé, la passerelle refuse la requête avec une erreur explicite d'indisponibilité locale. **Aucun basculement (fallback) implicite vers le cloud n'est déclenché.**
2. **Consentement cryptographique à la requête :** Un simple indicateur client (ex: `allow_remote=true`) est systématiquement rejeté. Le recours au cloud requiert un jeton HMAC-SHA256 signé par la clé d'application (`app.key`), scellant l'empreinte de la requête, l'utilisateur, le fournisseur distant et les catégories de données, avec une validité maximale de 5 minutes.
3. **Minimisation et sanctuarisation des données :** Toute requête contenant des secrets, des identifiants personnels (CIN, INE, emails, téléphones) ou des données académiques et financières sensibles (notes, délibérations, paiements, relevés) est **irrévocablement bloquée en local**. Même avec un consentement utilisateur, le transfert distant est formellement interdit (`REFUSE`).
4. **Sanctuarisation des outils de mutation :** Les outils ayant un impact en écriture ou sensibles (`preparer_action`, etc.) sont épurés des schémas d'outils envoyés aux fournisseurs distants. Seul le fournisseur local souverain peut manipuler des propositions d'actions, soumises au workflow d'approbation humaine à quatre yeux.
5. **Observabilité sans PII :** Les journaux et métriques d'observabilité enregistrent la décision de routage, la durée, le volume en caractères et l'identifiant de corrélation, mais **ne consignent jamais les invites brutes (prompts) ni les données à caractère personnel**.

L'ensemble des **14 scénarios de sécurité** exigés par le cahier des charges a été implémenté et validé dans la suite de tests `tests/Feature/AssistantIa/SecuritePasserelleHybrideTest.php`. Les 14 tests passent sans erreur (69 assertions). La suite complète `AssistantIa` compte désormais **160 tests réussis (675 assertions)** sans aucune régression.

---

## 2. Contraintes Absolues et Respect de l'Environnement

| Contrainte | Statut | Preuve / Justification |
|---|:---:|---|
| Réutilisation stricte de `app/Modules/AssistantIa` | **RESPECTÉ** | Aucune duplication fonctionnelle ; extension des classes et interfaces existantes (`FournisseurLocal`, `FournisseurRestDistant`, `FiltreSecuriteIa`, `PasserelleFournisseurs`). |
| Préservation des modifications locales existantes | **RESPECTÉ** | Aucun `git reset`, `git checkout`, `git clean`, `git stash`, ni changement de branche. |
| Aucune commande destructive de base de données | **RESPECTÉ** | Aucun `migrate:fresh`, `db:wipe` ou `truncate`. Exécution des tests sur SQLite isolé en mémoire (`:memory:`). |
| Non-modification du fichier `.env` | **RESPECTÉ** | Fichier `.env` préservé intact sans altération de secrets. |
| Aucun appel réseau externe en test | **RESPECTÉ** | Isolation complète via `Http::fake()` déterministe simulant les contrats Ollama et OpenAI/REST. |
| Données synthétiques exclusivement | **RESPECTÉ** | Noms d'étudiants factices (`Etudiant Test`), identifiants synthétiques (`INE123456789`). |
| Absence d'exposition de secrets | **RESPECTÉ** | Clés API et jetons HMAC masqués ou hachés SHA-256 dans les journaux et traces. |
| Formatage et style Pint / Git diff | **RESPECTÉ** | `./vendor/bin/pint --test` : 0 violation. `git diff --check` : 0 anomalie d'espacement. |

---

## 3. Matrice d'Audit Préalable des Composants (LOT 0)

| Composant existant | Responsabilité actuelle | Décision LOOP 17 | Justification technique |
|---|---|:---:|---|
| `PasserelleFournisseurs` | Résolution et instanciation du pilote de génération/embedding | **Étendue** | Intégration de `PolitiqueRoutageIa` pour décider du pilote avant toute exécution et interdiction du fallback silencieux. |
| `FournisseurLocal` | Pilote Ollama / LLM HTTP local | **Corrigé & Étendu** | Validation regex du modèle, séparation du modèle de génération et d'embedding (`bge-m3`), contrôle de taille d'entrée (100k) et réponse (512k), traçabilité, fail-soft hermétique. |
| `FournisseurRestDistant` | Pilote distant compatible OpenAI / vLLM | **Étendu & Renforcé** | HTTPS obligatoire (`forcerHttps`), filtrage strict des outils en écriture (`filtrerOutilsPourDistant`), gestion des erreurs sans fuite. |
| `FiltreSecuriteIa` | Détection d'injections de prompt et filtrage | **Étendu** | Ajout de la classification de sensibilité (`evaluerSensibiliteDonnees`), détection de données académiques et minimisation de texte (`minimiserTextePourDistant`). |
| `JournalisationIa` | Audit et traces des conversations | **Étendue** | Ajout de `journaliserRoutage()` consignant décisions, volumes et consentements sans PII. |
| `RegistreOutils` | Catalogue et exécution des 8 outils IA | **Étendu** | Filtrage contextuel selon le fournisseur (`local` vs `distant`), interdiction formelle d'exécuter un outil sensible depuis le distant. |
| `WorkflowApprobationService` | Validation 4 yeux des actions sensibles | **Inchangé** | Conservé intact avec son verrou pessimiste `lockForUpdate` et isolation facultaire. |
| `IndexeurDocumentaire` | Ingestion et indexation RAG sécurisée | **Inchangé** | Déjà protégé contre le Path Traversal et injections via le LOOP 15/16. |
| `MoteurRechercheDocumentaire` | Recherche textuelle dans la base vectorielle/SQL | **Inchangé** | Échappement des caractères réservés SQL préservé. |

---

## 4. Réalisations Détaillées par Lot

### LOT 1 — Politique Centrale de Routage (`PolitiqueRoutageIa`)
- **Fichiers créés / modifiés :**
  - `app/Modules/AssistantIa/DTO/DecisionRoutageIa.php`
  - `app/Modules/AssistantIa/Services/PolitiqueRoutageIa.php`
- **Résultats formels définis :** `LOCAL`, `DISTANT_AUTORISE`, `CONSENTEMENT_REQUIS`, `REFUSE`, `INDISPONIBLE`.
- **Règles décisionnelles implémentées :**
  - Si données sensibles détectées (secrets, PII, notes, paiements) : routage **`LOCAL`** forcé. Si l'utilisateur demandait explicitement le distant, le résultat est **`REFUSE`** pour motif de sécurité.
  - Si l'utilisateur demande le distant sans jeton de consentement valide : résultat **`CONSENTEMENT_REQUIS`**.
  - Si le fournisseur distant n'est pas activé dans la configuration ou si l'utilisateur n'a pas la permission `assistant_ia.fournisseur_distant` : résultat **`REFUSE`**.
  - Si le fournisseur local est indisponible et aucun mode distant valide n'est configuré : résultat **`INDISPONIBLE`** sans basculement cloud.

### LOT 2 — Fournisseur Local Ollama (`FournisseurLocal`)
- **Fichier modifié :** `app/Modules/AssistantIa/Drivers/FournisseurLocal.php`
- **Renforcements :**
  - Validation regex stricte du nom de modèle (`validerNomModele()`) interdisant toute injection de commande ou path traversal.
  - Découplage de la configuration du modèle de génération (`llama3.1:8b`) et du modèle d'embedding (`bge-m3`).
  - Délais stricts : connexion 2.0s, réponse 25.0s.
  - Plafond de volume d'entrée à 100 000 caractères et plafond de réponse HTTP à 512 Ko.
  - En cas d'erreur de connexion (daemon Ollama éteint), capture contrôlée et levée d'une `AssistantIaException` explicite. **Aucune redirection vers le distant.**

### LOT 3 — Adaptateur Distant Sécurisé (`FournisseurRestDistant`)
- **Fichier modifié :** `app/Modules/AssistantIa/Drivers/FournisseurRestDistant.php`
- **Renforcements :**
  - Contrôle d'autorisation préalable du fournisseur via `config('assistant_ia.fournisseurs_distants_autorises')`.
  - Endpoint distant soumis à l'obligation stricte du protocole HTTPS (`forcerHttps = true`), empêchant toute transmission d'identifiants ou de données en clair sur le réseau (exception tolérée uniquement en environnement local pour mocks).
  - Épuration préventive des outils (`filtrerOutilsPourDistant()`) : tous les outils marqués comme sensibles (`estSensible() === true`) ou effectuant des mutations sont systématiquement retirés des métadonnées envoyées au LLM distant.
  - Erreurs distantes (401, 429, 500) converties en exceptions applicatives contrôlées avec masquage des détails internes.

### LOT 4 — Classification et Minimisation des Données (`FiltreSecuriteIa`)
- **Fichier modifié :** `app/Modules/AssistantIa/Services/FiltreSecuriteIa.php`
- **Nouvelles capacités :**
  - `evaluerSensibiliteDonnees($texte, $sourcesDocuments)` : identifie de façon déterministe la présence de secrets, de PII étudiants, de données académiques/financières protégées et de documents RAG à diffusion interne restreinte.
  - `minimiserTextePourDistant($texte)` : filtre les chemins de fichiers internes (`/Users/...`, `/var/www/...`), masque les identifiants et neutralise les métadonnées techniques avant transmission externe.
  - En cas de détection de données académiques ou de secrets, `PolitiqueRoutageIa` refuse catégoriquement l'envoi vers tout fournisseur distant.

### LOT 5 — Consentement Explicite Cryptographique (`GestionnaireConsentementIa`)
- **Fichiers créés :**
  - `app/Modules/AssistantIa/Services/GestionnaireConsentementIa.php`
  - `app/Modules/AssistantIa/Exceptions/ConsentementRequisException.php`
- **Architecture de sécurité :**
  - L'autorisation du distant ne repose jamais sur un simple booléen client (`allow_remote=true`), facilement falsifiable par injection ou rejeu.
  - Une demande vers le distant déclenche une exception `ConsentementRequisException` (HTTP 428 Precondition Required) fournissant un challenge contenant :
    - Fournisseur et modèle cibles.
    - Empreinte cryptographique de la requête (`sha256($message)`).
    - Catégories de données autorisées.
    - Horodatage d'expiration (TTL de 300 secondes).
    - Signature cryptographique HMAC-SHA256 basée sur la clé secrète de l'application (`app.key`).
  - Toute modification de l'invite, changement d'utilisateur ou dépassement de délai invalide instantanément le jeton.
  - Aucune donnée sensible n'est enregistrée pour vérifier le consentement (seule l'empreinte de hachage est vérifiée).

### LOT 6 — Sécurité des Outils et Workflow d'Approbation (`RegistreOutils`)
- **Fichiers modifiés :**
  - `app/Modules/AssistantIa/Services/RegistreOutils.php`
  - `app/Modules/AssistantIa/Services/WorkflowApprobationService.php`
- **Garanties :**
  - `obtenirDefinitionsPourIa($utilisateur, $mode, $fournisseur)` : n'expose que les outils de lecture autorisés au fournisseur distant. Les outils de préparation d'actions de scolarité sont réservés au mode souverain local.
  - `executerOutil($nom, $arguments, $utilisateur, $fournisseur)` : vérification serveur interdisant l'exécution d'un outil sensible lorsque l'appel provient d'un fournisseur distant.
  - L'exécution de toute action métier reste subordonnée à l'approbation explicite d'un gestionnaire de scolarité via le workflow 4 yeux sous verrouillage transactionnel `lockForUpdate`.

### LOT 7 — Observabilité Sécurisée (`JournalisationIa` & `ObservabiliteIaService`)
- **Fichiers modifiés :**
  - `app/Modules/AssistantIa/Services/JournalisationIa.php`
  - `app/Modules/AssistantIa/Services/ObservabiliteIaService.php`
- **Garanties :**
  - Méthode `journaliserRoutage()` consignant : ID de corrélation, fournisseur choisi, modèle, motif de routage, décision de la politique, statut du consentement, durée et catégorie d'erreur.
  - **Volume mesuré en nombre de caractères uniquement** : aucun extrait du prompt ou de la réponse n'est enregistré dans les métadonnées de routage.
  - Métriques d'agrégation `metriquesPasserelleHybride()` pour surveiller le taux de rétention locale (Local-First ratio) et les motifs de refus.

### LOT 8 — Interface Utilisateur & Contrôleur Web
- **Fichiers modifiés :**
  - `app/Modules/AssistantIa/Controllers/AssistantIaController.php`
  - `app/Modules/AssistantIa/Routes/web.php`
  - `resources/views/modules/assistant-ia/index.blade.php`
- **Expérience utilisateur :**
  - Badge de souveraineté bien visible : **"Mode Souverain Local (Ollama)"**.
  - Sélecteur de mode local/distant avec indication de la disponibilité en temps réel.
  - Si l'utilisateur choisit le mode distant, interception du code HTTP 428 (`ConsentementRequisException`) et ouverture automatique d'un **modal d'autorisation explicite**.
  - Le modal détaille le fournisseur distant, le modèle, la liste des garanties de confidentialité et le caractère irréversible de l'autorisation pour la requête en cours.
  - Message clair et bloquant si le service Ollama local est indisponible, sans redirection furtive vers le cloud.

---

## 5. Validation par les Tests de Sécurité (LOT 9)

La classe de test `tests/Feature/AssistantIa/SecuritePasserelleHybrideTest.php` a été conçue pour valider rigoureusement les 14 scénarios imposés par le cahier des charges :

| Scénario | Intitulé du Test | Statut | Assertions | Résultat Observé |
|:---:|---|:---:|:---:|---|
| **1** | Requête normale routée vers le local | **PASS** | 4 | Routage par défaut vers `FournisseurLocal` avec statut `LOCAL`. |
| **2** | Requête sensible jamais envoyée au distant | **PASS** | 5 | Prompt contenant des notes d'étudiants refusé pour transfert distant (`REFUSE`). |
| **3** | Fournisseur local indisponible sans fallback | **PASS** | 3 | Ollama hors-ligne lève une exception d'indisponibilité sans solliciter le distant. |
| **4** | Fournisseur distant interdit si non configuré | **PASS** | 3 | Demande vers un fournisseur non autorisé rejetée avec `REFUSE`. |
| **5** | Consentement absent, refusé, expiré ou falsifié | **PASS** | 6 | Rejet systématique en cas de token manquant, corrompu ou expiré (TTL > 300s). |
| **6** | Tentative de contournement via paramètre client | **PASS** | 3 | Le paramètre `allow_remote=true` sans HMAC valide est ignoré et refusé (`CONSENTEMENT_REQUIS`). |
| **7** | Utilisateur sans permission d'accès distant | **PASS** | 3 | Agent sans permission `assistant_ia.fournisseur_distant` bloqué (`REFUSE`). |
| **8** | Documents RAG restreints protégés | **PASS** | 4 | Contexte RAG contenant un document interne sensible interdit au distant. |
| **9** | Secrets détectés avant transmission | **PASS** | 4 | Présence d'un token d'API détectée ; transfert distant formellement bloqué. |
| **10** | Erreur du fournisseur distant contrôlée | **PASS** | 3 | Échec HTTP distant absorbé de façon étanche sans fuite vers un autre service. |
| **11** | Paramètres altérés et rejeu de requête bloqués | **PASS** | 3 | Modification d'un seul caractère de l'invite invalide le jeton HMAC. |
| **12** | Actions sensibles soumises à approbation humaine | **PASS** | 4 | Outil `preparer_action` absent du catalogue distant et soumis au workflow 4 yeux. |
| **13** | Journaux de routage sans données confidentielles | **PASS** | 12 | Les logs contiennent les métadonnées de routage mais aucun PII ni contenu d'invite. |
| **14** | Isolation des requêtes concurrentes | **PASS** | 12 | Plusieurs requêtes simultanées aux politiques distinctes ne se parasitent pas. |

**Bilan de la suite ciblée :**  
`php artisan test --filter="SecuritePasserelleHybrideTest"`  
**14 tests exécutés, 14 passés, 69 assertions, durée : 1.04s.**

---

## 6. Vérifications Globales et Non-Régression (LOT 10)

### 6.1. Suite Complète Assistant IA
```bash
php artisan test --filter="AssistantIa"
```
- **Résultat :** `PASS` (Code retour 0)
- **Tests exécutés :** 160
- **Tests réussis :** 160
- **Échecs :** 0
- **Assertions validées :** 675
- **Durée totale :** 4.24s

### 6.2. Tests Transversaux de Sécurité
```bash
php artisan test --filter="Security"
```
- **Résultat :** `PASS` (Code retour 0)
- **Tests exécutés :** 257 (253 passés, 4 ignorés pour contraintes environnementales)
- **Assertions validées :** 848
- **Échecs :** 0

### 6.3. Contrôle du Style de Code et Diff
```bash
./vendor/bin/pint --test app/Modules/AssistantIa tests/Feature/AssistantIa app/Support/Permissions.php
# Résultat : Code retour 0 (Toutes les règles PSR-12 / Laravel respectées)

git diff --check
# Résultat : Code retour 0 (Aucun trailing whitespace ni conflit de fusion)
```

---

## 7. Inventaire des Fichiers Modifiés et Créés

### Nouveaux Fichiers Développés :
1. `app/Modules/AssistantIa/DTO/DecisionRoutageIa.php` : DTO représentant le résultat immuable de l'évaluation de routage.
2. `app/Modules/AssistantIa/Services/GestionnaireConsentementIa.php` : Service cryptographique HMAC-SHA256 de délivrance et vérification des consentements.
3. `app/Modules/AssistantIa/Exceptions/ConsentementRequisException.php` : Exception HTTP 428 déclenchant la demande de consentement explicite.
4. `tests/Feature/AssistantIa/SecuritePasserelleHybrideTest.php` : Suite de validation des 14 scénarios de sécurité.

### Fichiers Existants Renforcés et Éperonnés :
1. `app/Modules/AssistantIa/Services/PolitiqueRoutageIa.php` : Matrice décisionnelle souveraine avec fail-soft et refus explicite.
2. `app/Modules/AssistantIa/Drivers/FournisseurLocal.php` : Pilote Ollama durci (regex modèle, séparation embeddings, timeouts, limites de taille).
3. `app/Modules/AssistantIa/Drivers/FournisseurRestDistant.php` : Pilote distant avec HTTPS forcé, filtrage d'outils et gestion étanche des erreurs.
4. `app/Modules/AssistantIa/Services/FiltreSecuriteIa.php` : Détection de données sensibles académiques/financières et minimisation d'invite.
5. `app/Modules/AssistantIa/Services/RegistreOutils.php` : Filtrage strict interdisant les outils de mutation aux moteurs distants.
6. `app/Modules/AssistantIa/Services/JournalisationIa.php` : Journalisation de routage étanche sans PII ni prompts.
7. `app/Modules/AssistantIa/Services/ObservabiliteIaService.php` : Métriques d'observabilité de la passerelle hybride.
8. `app/Modules/AssistantIa/Controllers/AssistantIaController.php` : Gestion du code HTTP 428, validation des autorisations distantes.
9. `app/Modules/AssistantIa/Routes/web.php` : Routes de statut et d'obtention de challenge de consentement.
10. `resources/views/modules/assistant-ia/index.blade.php` : Interface utilisateur avec modal de consentement et alertes de souveraineté.
11. `app/Support/Permissions.php` : Ajout de la permission `assistant_ia.fournisseur_distant`.

---

## 8. Réserves et Recommandations Opérationnelles

1. **Environnement de Production Ollama :**
   - S'assurer que le service Ollama est hébergé sur le même réseau privé ou sur la même machine que l'instance Laravel (ex: `http://127.0.0.1:11434`), sans exposition sur l'Internet public.
   - Prévoir une surveillance système dédiée (systemd / supervisor) sur le processus Ollama pour alerter immédiatement l'équipe d'exploitation en cas d'indisponibilité, la passerelle refusant délibérément tout basculement cloud automatique.
2. **Gestion de la Clé d'Application (`app.key`) :**
   - Les jetons de consentement étant signés par `app.key`, toute rotation de clé invalide les consentements émis dans les 5 minutes précédant la rotation (comportement attendu et sécurisé).
3. **Fournisseurs Distants Déclarés :**
   - Par défaut, `assistant_ia.fournisseurs_distants_autorises` ne doit contenir que des fournisseurs validés par la gouvernance informatique de l'établissement (ex: accord de traitement de données RGPD / DPA signé).

---

## 9. Verdict Final Officiel

Toutes les exigences architecturales, cryptographiques, fonctionnelles et sécuritaires de **LOOP 17** ont été rigoureusement respectées et démontrées par des tests reproductibles.

- **Politique de données et Local-First démontrée :** OUI
- **Consentement cryptographique à la requête vérifié :** OUI
- **Comportement sans fallback automatique (Fail-Soft) prouvé :** OUI
- **Sanctuarisation des outils et des journaux d'audit :** OUI
- **Taux de succès des tests ciblés et de régression :** 100% (160/160 passés)

**VERDICT OFFICIEL : `PASS`**
