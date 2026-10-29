# RAPPORT OFFICIEL DU CONTRE-AUDIT INDÉPENDANT DE SÉCURITÉ
# LOOP-UNIREGISTRE-AI-ASSISTANT-INDEPENDENT-AUDIT-AUTO-PASS-02

**Date d'audit :** 23 octobre 2026  
**Projet :** UniRegistre (Plateforme universitaire d'inscriptions et de scolarité)  
**Composant audité :** Module UniRegistre AI (`app/Modules/AssistantIa/`)  
**Mandat :** Audit indépendant, approfondi, fondé sur les preuves et tests adversariaux  
**Référence précédente :** `LOOP-UNIREGISTRE-AI-ASSISTANT-FOUNDATION-AUTO-PASS-01`  
**Verdict Global :** **`PASS`**

---

## 1. Résumé Exécutif

Un contre-audit indépendant, méthodique et adversarial a été conduit sur l'ensemble des composants du module **UniRegistre AI** récemment introduit dans le dépôt.

Le rapport préliminaire LOOP-01 annonçait `PASS` avec 50 tests et 216 assertions. L'audit indépendant a confirmé que la fondation initiale était saine mais a mis en évidence **quatre défauts de sécurité et de robustesse réels** non couverts par la suite précédente :
1. **Évasion de périmètre facultaire sur les approbations et rejets d'actions (BOLA / Cross-Tenant IDOR) :** absence de validation du rattachement facultaire de l'approbateur vis-à-vis de la ressource ou de la proposition d'action.
2. **Accès persistant des comptes utilisateurs désactivés (`actif = false`) :** absence de contrôle systématique de l'activité du compte dans le contrôleur de l'assistant IA.
3. **Risque de traversée de répertoire (Path Traversal) dans l'indexeur documentaire RAG :** acceptation potentielle de segments `..` menant vers des fichiers hors répertoires documentaires (`composer.json`, etc.).
4. **Bascule silencieuse imprévisible sur fournisseur inconnu et fragilité sur les tool calls :** `PasserelleFournisseurs` basculait sur un faux modèle sans lever d'alerte, et `FournisseurRestDistant` risquait un plantage de type en présence d'arguments pré-décodés ou d'appels à nom vide.

Toutes ces failles démontrées ont été corrigées de manière chirurgicale, sans modification destructive ni régression. Une suite de tests adversariaux dédiée (`AuditSecuriteIndependantTest`) de **10 nouveaux tests (29 assertions)** a été ajoutée. La suite complète du module compte désormais **60 tests unitaires et d'intégration réussis (245 assertions)**, sans aucun échec ni avertissement, validée sur SQLite isolé en mémoire (`:memory:`).

---

## 2. Périmètre Exact et Limites de l'Audit

### Périmètre audité :
- **Architecture et DTOs :** `app/Modules/AssistantIa/DTO/*`, `Contracts/*`, `Exceptions/*`.
- **Passerelle et Pilotes IA :** `PasserelleFournisseurs`, `FauxFournisseurIa`, `FournisseurRestDistant`, `FournisseurLocal`.
- **Contrôles d'accès et Isolation :** `AssistantIaController`, `Routes/web.php`, `FiltreSecuriteIa`, RBAC (`Permissions.php`).
- **Workflow d'approbation et 4 yeux :** `WorkflowApprobationService`, `AiPropositionAction`.
- **RAG et Recherche :** `IndexeurDocumentaire`, `MoteurRechercheDocumentaire`, `AiDocumentIndex`.
- **Mémoire et Observabilité :** `GestionnaireMemoireIa`, `ObservabiliteIaService`, `JournalisationIa`.
- **Outils Développeur et Métier :** Les 8 outils enregistrés dans `RegistreOutils`.
- **Migrations de persistance :** `database/migrations/2026_10_23_*`.

### Limites explicites de l'audit :
- Aucun appel réseau réel vers OpenAI, vLLM ou Groq n'a été effectué avec des clés payantes (simulation stricte via `Http::fake()` déterministe).
- Aucune donnée réelle d'étudiants n'a été injectée ou traitée.
- Les tests ont été menés sur SQLite isolé en mémoire (`:memory:`), conformément aux règles impératives du projet.
- Aucun déploiement en production ni activation automatique des fournisseurs distants n'a été réalisé.

---

## 3. État Initial du Dépôt et Préservation Git

- **Statut Git initial :** 616 fichiers modifiés ou non suivis issus de boucles d'ingénierie parallèles.
- **Règle absolue :** Aucun `git reset`, `git clean`, `git stash`, `git checkout`, `git commit` ou `git push` n'a été exécuté.
- **Protection de l'environnement :** Le fichier `.env` existant n'a été ni lu pour des clés réelles, ni modifié.
- **Intégrité des bases :** Aucune commande `migrate:fresh` ou `db:wipe` n'a été exécutée sur la base de développement `inscription_etudiants`.

---

## 4. Matrice des Contrôles et Preuves Indépendantes

| Lot | Objet audité | Niveau de Risque initial | Constat et Preuve du Défaut | Action Corrective apportée | Preuve de Validation |
|---|---|:---:|---|---|:---:|
| **Lot 1** | Isolation des autorisations et comptes inactifs | **Élevé** | Un compte avec `$user->actif = false` et session active pouvait interroger l'assistant sans blocage 403. | Ajout de la méthode `verifierUtilisateurActif()` appelée systématiquement dans toutes les actions du contrôleur (`abort_unless(..., 403)`). | `AuditSecuriteIndependantTest::test_utilisateur_inactif_est_bloque_avec_code_403` (PASS) |
| **Lot 1 & 4** | Périmètre facultaire sur les actions (BOLA / IDOR) | **Critique** | L'administrateur de la Faculté A pouvait approuver ou rejeter une action touchant un dossier de la Faculté B. | Ajout de `faculte_id` dans `ai_propositions_actions`, transmission dans `PreparerActionScolariteTool`, et vérification stricte `FiltreSecuriteIa::verifierPerimetreFaculte` sous `lockForUpdate`. | `AuditSecuriteIndependantTest::test_approbation_action_bloque_l_acces_croise_entre_facultes_differentes` (PASS) |
| **Lot 2** | Validation des fournisseurs et bascule silencieuse | **Moyen** | Une clé inconnue passée à `PasserelleFournisseurs` basculait silencieusement sur `FauxFournisseurIa` sans alerte. | Validation stricte `in_array($cle, ['faux', 'distant', 'local'], true)` avec levée d'une `AssistantIaException` (400) et journalisation d'alerte. | `AuditSecuriteIndependantTest::test_passerelle_rejette_fournisseur_inconnu_sans_bascule_silencieuse` (PASS) |
| **Lot 2** | Schéma des réponses et tool calls distants | **Faible** | TypeError possible dans `FournisseurRestDistant` si les arguments d'outils étaient déjà décodés sous forme de tableau. | Normalisation `$args = is_array($argsJson) ? $argsJson : ...` et exclusion stricte des appels à nom vide (`$nom === ''`). | `AuditSecuriteIndependantTest::test_fournisseur_distant_gere_robustement_arguments_array_et_noms_vides` (PASS) |
| **Lot 3** | Indexation RAG et Path Traversal | **Élevé** | `IndexeurDocumentaire::estFichierAutorise()` ne rejetait pas explicitement les séquences `..` (ex: `docs/../../composer.json`). | Rejet immédiat de toute chaîne contenant `..`, `./` ou débutant par `/` après normalisation des antislashs. Purge définitive par `forceDelete()`. | `AuditSecuriteIndependantTest::test_indexeur_documentaire_bloque_path_traversal_vers_fichiers_racine` (PASS) |
| **Lot 3** | Expressions régulières d'injection de prompt | **Moyen** | Les motifs avec caractères accentués (`règles`, `précédentes`) échouaient sans le modificateur Unicode `/u`. | Ajout du modificateur `/u` sur l'ensemble des expressions régulières dans `FiltreSecuriteIa::MOTIFS_INJECTION`. | `AuditSecuriteIndependantTest::test_filtre_securite_bloque_injections_avec_balises_systeme_et_oubli_instructions` (PASS) |
| **Lot 4** | Concurrence et anti-rejeu sur les approbations | **Élevé** | Absence de verrou transactionnel pessimiste lors de l'approbation (`lockForUpdate`). Risque de double validation concurrente. | Enveloppement dans `DB::transaction()` avec rechargement de l'action via `lockForUpdate()`, validation d'état et vérification sha256. | `AuditSecuriteIndependantTest::test_workflow_approbation_verrouille_et_empeche_le_rejeu` (PASS) |
| **Lot 7** | Normalisation de chemins dans les outils développeur | **Faible** | Antislashs non convertis avant validation anti-traversal dans `InspecterFichiersCodeTool` et `ProposerPatchTool`. | Normalisation systématique `str_replace('\\', '/', trim($chemin))` avant les vérifications de sécurité. | `AuditSecuriteIndependantTest::test_outils_dev_bloquent_traversal_avec_antislash` (PASS) |

---

## 5. Détail des Fichiers Modifiés ou Créés

1. `app/Modules/AssistantIa/Controllers/AssistantIaController.php` :
   - Contrôle systématique du compte actif (`verifierUtilisateurActif`).
   - Retransmission explicite de code HTTP 403 sur violation de sécurité lors de l'approbation/rejet.
2. `app/Modules/AssistantIa/Models/AiPropositionAction.php` :
   - Prise en charge de `faculte_id` dans `$fillable`, `$casts`, relation `faculte()` et scope `scopePourFaculte`.
3. `database/migrations/2026_10_23_120000_creer_table_ai_propositions_actions.php` :
   - Ajout de la colonne `faculte_id` avec clé étrangère vers `facultes` et index composite `['faculte_id', 'statut']`.
4. `app/Modules/AssistantIa/Services/WorkflowApprobationService.php` :
   - Enveloppement transactionnel `DB::transaction()` avec `lockForUpdate()`.
   - Contrôle strict du périmètre facultaire (`FiltreSecuriteIa::verifierPerimetreFaculte`).
5. `app/Modules/AssistantIa/Tools/Metier/PreparerActionScolariteTool.php` :
   - Transmission du `faculteId` lors de la création de la proposition d'action.
6. `app/Modules/AssistantIa/Services/PasserelleFournisseurs.php` :
   - Rejet immédiat avec `AssistantIaException` (400) des fournisseurs non autorisés.
7. `app/Modules/AssistantIa/Drivers/FournisseurRestDistant.php` & `FournisseurLocal.php` :
   - Tolérance sur arguments pré-décodés et rejet des appels d'outils anonymes.
8. `app/Modules/AssistantIa/Services/IndexeurDocumentaire.php` :
   - Blocage hermétique du Path Traversal (`..`, `./`, `/`).
   - Remplacement de `delete()` par `forceDelete()` pour assainir l'index RAG.
9. `app/Modules/AssistantIa/Services/MoteurRechercheDocumentaire.php` :
   - Échappement des caractères réservés SQL LIKE (`%`, `_`).
10. `app/Modules/AssistantIa/Services/FiltreSecuriteIa.php` :
    - Modificateur Unicode `/u` appliqué à l'ensemble des motifs d'injection de prompt.
11. `app/Modules/AssistantIa/Tools/Developpeur/InspecterFichiersCodeTool.php` & `ProposerPatchTool.php` :
    - Normalisation des antislashs avant contrôle de sécurité.
12. `tests/Feature/AssistantIa/AuditSecuriteIndependantTest.php` :
    - Suite de 10 tests de contre-audit indépendants et de sécurité avancée.

---

## 6. Commandes Réellement Exécutées et Résultats

### Suite Complète du Module Assistant IA :
```bash
php artisan test --filter="AssistantIa"
```
**Résultat :** `PASS` (Code retour: 0)  
- **Tests exécutés :** 60  
- **Tests réussis :** 60  
- **Échecs :** 0  
- **Assertions :** 245  
- **Temps d'exécution :** 2.47s  
- **Base de test :** SQLite `:memory:` (Strictement isolée)

Détail des 9 suites exécutées :
1. `tests/Unit/AssistantIa/ArchitectureFondationsTest.php` : 6 tests, 40 assertions (PASS)
2. `tests/Unit/AssistantIa/PasserelleFournisseursTest.php` : 6 tests, 26 assertions (PASS)
3. `tests/Unit/AssistantIa/SecuriteAutorisationsTest.php` : 6 tests, 41 assertions (PASS)
4. `tests/Unit/AssistantIa/RagEtIndexationTest.php` : 5 tests, 23 assertions (PASS)
5. `tests/Unit/AssistantIa/MemoireEtObservabiliteTest.php` : 3 tests, 16 assertions (PASS)
6. `tests/Feature/AssistantIa/ChatEtPersistanceTest.php` : 8 tests, 26 assertions (PASS)
7. `tests/Feature/AssistantIa/AgentsEtApprobationsTest.php` : 9 tests, 28 assertions (PASS)
8. `tests/Feature/AssistantIa/SecuriteIaEtEvaluationQualiteTest.php` : 7 tests, 16 assertions (PASS)
9. `tests/Feature/AssistantIa/AuditSecuriteIndependantTest.php` : 10 tests, 29 assertions (PASS)

### Suite de Non-Régression Transverse :
```bash
php artisan test --filter="CentreTravailDetailTest"
# Résultat : 12 passed, 42 assertions, 1.69s

php artisan test --filter="SeparationProfilsTest"
# Résultat : 17 passed, 76 assertions, 2.84s
```

### Qualité du Code et Formatage :
```bash
./vendor/bin/pint app/Modules/AssistantIa database/migrations/2026_10_23_120000_creer_table_ai_propositions_actions.php tests/Feature/AssistantIa/AuditSecuriteIndependantTest.php
# Résultat : Code 0 (Fichiers conformes aux conventions strictes PSR-12 / Laravel)

git diff --check
# Résultat : Code 0 (Aucun trailing whitespace ni conflit)
```

---

## 7. Statut des Fournisseurs et Recommandations Opérationnelles

1. **Fournisseur Distant (OpenAI / vLLM REST) :**
   - *Statut actuel :* Validé par simulation hermétique (`Http::fake()`).
   - *Règle :* Ne jamais inscrire de clé en clair dans les fichiers sources ou de configuration.
   - *Activation en production :* Définir `ASSISTANT_IA_FOURNISSEUR=distant` et injecter `ASSISTANT_IA_DISTANT_CLE` via un gestionnaire de secrets sécurisé (Vault / KMS).
2. **Fournisseur Local Souverain (Ollama / vLLM local) :**
   - *Statut actuel :* Pilote prêt et validé.
   - *Recommandation :* Privilégier ce fournisseur en environnement hospitalo-universitaire et pour tout traitement de données de santé ou académiques sensibles pour garantir qu'aucune donnée ne transite par un réseau externe.
3. **Politique de Rétention RGPD :**
   - *Statut :* Délai technique par défaut de 90 jours configuré.
   - *Décision requise :* Soumettre formellement la durée de conservation des journaux de conversation et d'actions au Délégué à la Protection des Données (DPO) de l'Université.

---

## 8. Verdict Global Final

Tous les contrôles critiques du cahier des charges ont été vérifiés par des preuves directes et tangibles. Les failles identifiées lors de l'audit adversarial ont été corrigées avec des tests de non-régression reproductibles. Les tests existants et nouveaux passent à 100%. L'intégrité du dépôt a été respectée sans aucune commande destructive.

**VERDICT OFFICIEL : `PASS`**

### Recommandation d'étape :
Le module **UniRegistre AI** est certifié robuste et sécurisé pour une mise en service en environnement de développement et pour la conduite d'un **pilote contrôlé en environnement de staging**.
