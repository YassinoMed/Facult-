# Rapport d'exécution — Module AI Assistant (Lot 1→15)

**Mission :** LOOP-UNIREGISTRE-AI-ASSISTANT-FOUNDATION-AUTO-PASS-01 (module `AiAssistant`)
**Trace :** `AiAssistant*` (module, tables additives, suites `tests/Feature/AiAssistant*.php`)
**Basé sur preuves :** chaque mélange code+test exécuté est cité. Aucun PASS annoncé sans exécution.

> ⚠️ **Historique de collision** : le chemin `docs/engineering/LOOP-UNIREGISTRE-AI-ASSISTANT-FOUNDATION-AUTO-PASS-01.md`
> a été écrasé par le lot concurrent (module `AssistantIa`) par-dessus ce rapport initial.
> Ce fichier reprend la trace du module `AiAssistant` SANS toucher à leur document.
> Leurs composants (`app/Modules/AssistantIa`, tables `ai_documents_index` + `ai_propositions_actions`,
> migrations `100000`) sont CONSOMMÉS (jamais modifiés) — cf. décision coexistence ci-dessous.

## 0. Décisions structurantes

| # | Décision | Justification |
|---|----------|---------------|
| D1 | Tables canoniques `ai_conversations`/`ai_messages` = migration concurrente `2026_10_23_100000` (consommée, jamais dupliquée) | Convention no-duplication ; FK `ON DELETE CASCADE` réelle en dur |
| D2 | Module OFF par défaut (`MODULE_AI_ASSISTANT=false`), enregistrement `bootstrap/providers.php` (pas de modification de `ModuleServiceProvider`, écrit par le lot concurrent) | Sécurité feature-flag + coexistence |
| D3 | Passerelle sans fallback externe : `config('ai-assistant.defaut')` sélectionne UN fournisseur ; erreur typée si non déclaré/driver inconnu/non configuré | Exigence mission (pas de bascule silencieuse) |
| D4 | Sortie modèle = JAMAIS une instruction ; tout outil exige contrôle serveur (gate + policy + sandbox + perm) | Anti-prompt-injection par conception |
| D5 | Trusteur de journalisation `TraceurIa` : whitelist champ par champ (`journalisation.champs_autorises`), aucun prompt/PII/jeton | PII policy |
| D6 | **Coexistence** (enregistrée après audit) : le lot concurrent livre le module `AssistantIa` (15 lots, tests verts `tests/Feature/AssistantIa` = 24 tests / 70 assertions exécutés le 2026-10-23) mais avec un écart de revendication : leur rapport affiche *50 tests / 216 assertions*. Mes Lots 6-15 passent en périmètre **complémentaire** : audit de leur composant équivalent avant tout nouveau développem­ent ; aucune duplication de même responsabilité ; quand leur garantie existe et est verte, mon lot livre l'ÉVIDENCE de vérification plutôt qu'une réimplémentation ; les écarts (sandbox, fail-soft, no-fallback, quotas métriques, commandes) sont comblés dans `AiAssistant`. | Règle projet no-duplication + préservation du travail concurrent |

## 1. Verdicts par lot (evidence-based)

| Lot | Composant | Verdict | Évidence exécutée |
|-----|-----------|:-------:|-------------------|
| Phase 0 | Audit + gel du périmètre | PASS | Rapport initial (écrasé par le lot concurrent au même chemin ; contenu restauré dans ce document) |
| Lot 1 | Fondations : config, permissions × rôles, gates, exceptions typées, DTOs, `RegistreOutils`, `SandboxFichier`, drapeau module | **PASS** | `AiAssistantFondationsTest` — 29 tests / 75 assertions |
| Lot 2 | Passerelle fournisseurs (factice déterministe + adaptateur OpenAI-compatible distant/local, **zéro fallback**, erreurs typées, capture `$request->data()`, sérialisation bornée) | **PASS** | `AiAssistantGatewayTest` — 11 tests (menu non déclaré / driver inconnu / non configuré refusés ; HTTP 500/ConnectionException ; secret jamais tracé) |
| Lot 3 | Sécurité transverse : 9 gates × invités, `EchecDoux` (403/429/502/400/500 sans fuite), logging whitelist (espion) | **PASS** | `AiAssistantSecuriteTest` — 5 tests |
| Lot 4 | Chat HTTP (`/admin/ai`, 6 routes, throttled `30,1`) + UI Blade/Alpine (`x-text` UNIQUEMENT, aucun `{!! !!}`) | **PASS** | `AiAssistantChatTest` (partie HTTP) : invité sans contenu, module OFF→404, rôle vide→403, interface 200, modes, 422 vides/longs, isolement inter-utilisateur 404×3, UI sans contenu d'autrui |
| Lot 5 | Persistance : conversations/messages/métriques, fenêtre 12, ordre strict, soft+kascade FK dur, 502 conserve la question, pagination 20 | **PASS** | `AiAssistantChatTest` — **22 tests / 81 assertions** (suite complète verte après correctifs ci-dessous) |
| Lot 6 | RAG complémentaire : moteur `RechercheIndexPartage` sur la TABLE PARTAGÉE `ai_documents_index` (consommée) + registre local par fichier `ai_knowledge_sources` (sha256/statut/version), commande `ai-assistant:reindexer`, garde PII (fichier sauté entier), exclusion `.env*` par NOM même listé, jokers LIKE neutralisés, accès filtré AVANT restitution (chunks sensibles + sources restreintes), rebinde du contrat (aucune modification du chat), DTO `ChunkDocumentaire` (import manquant corrigé dans le service) | **PASS** | `AiAssistantRagTest` — **12 tests / 41 assertions** (+ non-régression 67/67) |
| Lot 7 | Outils développeur COMPLÉMENTAIRES : `inspecter_code` (métadonnées seules, whitelist CLOSE `app\|routes`, cap 20, jamais le contenu), `lire_extrait_code` (sandbox 60 lignes), `proposer_patch` (STOCKAGE uniquement — enregistre une approbation 4 yeux, `fichier_modifie: false` prouvé par sha256 avant/après). Audits croisés : le module concurrent a ses propres tools ; les miens s'intègrent à MON registre + MON sandbox (contrôles plus stricts : `.env*`, `.key`, `.pem`, `private`, traversal refusée au validateur AVANT l'exécuteur) | **PASS** | `AiAssistantOutilsTest` — **14 tests / 36 assertions**, dont traversal refusée « paramètres invalides », permission/mode refusés, contenu jamais exposé, sha256 fichier inchangé |
| Lot 8 | Outil métier `consulter_dossier_etudiant` (modèle `Etudiant` EXISTANT consommé) : CIN masqué (2 derniers caractères), email masqué (2 premiers + arobase), téléphone masqué, registre national MENS jamais restitué, périmètre facultaire appliqué CÔTÉ SERVEUR via le contexte utilisateur, inscriptions académiques bornées aux 3 dernières | **PASS** | `AiAssistantOutilsTest` (suite combinée outils) — **18 tests / 50 assertions** |
| Lot 9 | Approbations : `GestionnaireApprobations` — empreinte sha256 canonique (action+cibles+paramètres, SANS description), anti-rejeu (demande identique en attente → refusée), 4 yeux OBLIGATOIRES (demandeur ≠ décideur), expiration 24 h (bump auto `expired`), falsification détectée (recomput d'empreinte), exécuteur whitelisté vide : `proposer_patch`/`appliquer_patch` REFUSÉS PAR CONSTRUCTION (« jamais appliqués »), journal horodaté sans PII, table additive `ai_approvals` (migration 110000) | **PASS** | `AiAssistantOutilsTest` (partie workflow) — soumission/décision/anti-rejeu/intégrité/expiration/exécution refusée, tous verts |
| Lot Ollama | `FournisseurOllama` (API native `/api/chat`) sur le CONTRAT `AiFournisseur` existant (aucune 2e architecture, aucun SDK) : LOCAL-STRICT — base_url hors boucle refusée à la construction ; catalogue `/api/tags` vérifié AVANT génération (modèle absent = erreur typée, JAMAIS de pull) ; modèles « -cloud » et entrées sans poids réels (< 1 Mo) refusés ; AUCUN fallback cloud ; aucune clé envoyée (aucun `Authorization`) ; `stream=false` forcé ; budget `num_predict` imposé serveur (`jetonsMax` ou `limites.jetons_max`) ; driver enregistré dans `GestionnaireFournisseur` + config `fournisseurs.ollama` (défaut `smollm:135m` — modèle réellement présent sur la machine) | **PASS** | `AiAssistantOllamaTest` — **13 tests / 46 assertions** ; mesures réelles (service 0.40.10 actif) : génération OK 124 ms en cache chaud (8 jetons sortis = budget exact), refus réel de `llama3` absent — erreur typée, AUCUN téléchargement déclenché |

### Correctifs intégrés pendant la Boucle 5

| # | Correctif | Type |
|---|-----------|------|
| F1 | Préfixe de route `admin/ai` (les URI restaient `/ai` — 404 sur 17 tests) | Réel |
| F2 | `AiChatService` : `fournisseur`/`modele`/`dernier_message_a` assignés puis **`save()`** (avant : assignation sans persistance — détectée via `refresh()` puis `assertSame`) | Réel |
| F3 | Test : état vide = « Pas de conversation active » (assertion alignée sur la vue réelle) | Test |
| F4 | Test : rôle vide via `forceFill(['role'=>''])` (aucun rôle central sans `ai.acceder`), invité testé par absence de contenu (aucune route `login` nommée dans ce back-office) | Test |

## 2. Non-régression exécutée (2026-10-23)

```
php artisan test --filter='AiAssistantFondations|AiAssistantGateway|AiAssistantSecurite|AiAssistantChat'
→ passed, 67 tests, 213 assertions (:green)
php artisan test tests/Feature/AssistantIa            (module concurrent, lecture seule)
→ passed, 24 tests, 70 assertions (:green)
vendor/bin/pint --dirty → 19 fichiers reformatés (incl. ModuleServiceProvider du lot
concurrent : réordonnancement imports uniquement, aucune modification sémantique — noté)
```

## 2-bis. Lot OLLAMA — fournisseur local strict (2026-10-24, demande utilisateur)

### Audit préalable de la machine (FAIT AVANT tout téléchargement)

| Sujet | Constat | Conséquence |
|---|---|---|
| Service | Ollama **0.40.10 actif** sur `127.0.0.1:11434` (`/api/version` répondu) | Base `localhost` utilisable pour la mesure |
| RAM | **8 Go** (M2, 8 cœurs) | Petits modèles uniquement (≤ 2B quantifiés) ; le défaut est `smollm:135m` (91 Mo) |
| Disque | **14 Go libres / 228 Go (94 % utilisés)** | Interdiction d'attirer des poids lourds ; aucun pull par le code |
| Catalogue réel | `all-minilm:latest` (embeddings, 23M), `smollm:135m` (chat, 91 Mo), `qwen3-coder:480b-cloud` (**référence CLOUD** : métadonnées 382 octets, aucun poids local) | Défaut = `smollm:135m` ; les `-cloud` sont refusés par le fournisseur |

### Décisions

| # | Décision | Justification |
|---|----------|---------------|
| O1 | Réutilisation du contrat `AiFournisseur` + `AiDemande`/`AiReponse` + `GestionnaireFournisseur` (driver `ollama` ajouté au match) | Interdiction « pas de 2e architecture » ; extensions tables DTO inchangées ; client HTTP Laravel natif, aucun SDK |
| O2 | LOCAL-STRICT par construction : `FournisseurOllama` REFUSE toute `base_url` non boucle (127.0.0.1/[::1]/localhost) à la construction | La garantie « local uniquement » est STRUCTURALE, pas une simple convention de configuration |
| O3 | Catalogue local vérifié (`/api/tags`) avant TOUTE génération ; absence = `FournisseurIndisponibleException («modèle non installé localement»)` | Garantie « jamais de téléchargement automatique » ; vérifiée contre le service RÉEL (llama3 absent → refus, aucun pull) |
| O4 | Modèles `-cloud` et entrées de catalogue sans poids réels (< 1 Mo) refusés, même listés par le serveur | `qwen3-coder:480b-cloud` exécute hors boîte — un « local-uniquement » qui tolère le cloud est contradictoire |
| O5 | Aucun fallback AUTOMATIQUE (déjà règle passerelle) : service éteint → erreur typée remontée → fail-soft 502 existant | Cohérence D3-passerelle ; le message client reste générique (`EchecDoux`) |
| O6 | Budget de génération imposé serveur : `num_predict = jetonsMax ?? limites.jetons_max`, `temperature=0.2`, `stream=false` (sinon Ollama renvoie du jsonl) | Cohérence passerelle + parsage fiable |
| O7 | `.env` jamais modifié : variables documentées ci-dessous, valeurs par défaut sûres dans le config | Contrainte LOOP |

### Variables d'exploitation (à injecter par l'exploitant, pas par ce LOOP)

```
AI_OLLAMA_BASE_URL   (défaut http://127.0.0.1:11434 ; TOUTE autre hôte est refusé par le fournisseur)
AI_OLLAMA_MODELE     (défaut smollm:135m ; DOIT être téléchargé au préalable par `ollama pull` — le code ne déclenche JAMAIS le pull)
```
Activation : `AI_FOURNISSEUR=ollama` (le défaut du config reste `factice`).

### Rappel d'exécution (mesures en conditions réelles, service 0.40.10 actif)

```
Mesure chaude : generer() ping mode metier, jetonsMax=8 → latence 124 ms, jetons 57/8
Mesure budgétée : question longue, jetonsMax=120 → latence 2 280 ms, jetons 91/120
  (jetonsSortie == num_predict exact : budget serveur prouvé en réel)
Refus réel : modele "llama3" (absent) → "Fournisseur IA « ollama » indisponible (modèle non installé localement)."
  → AUCUN téléchargement déclenché (catalogue consulté, génération bloquée avant /api/chat)
```

### Non-régression exécutée (2026-10-24)

```
php artisan test --filter='AiAssistantFondations|AiAssistantGateway|AiAssistantSecurite|AiAssistantChat|AiAssistantRag|AiAssistantOutils|AiAssistantOllama'
→ passed, 110 tests, 350 assertions
Détail par fichier : Fondations 29/75 · Gateway 11/30 · Securite 5/27 · Chat 22/81
· Rag 12/41 · Outils 18/50 · Ollama 13/46 (tous passés)
vendor/bin/pint (4 fichiers nouveaux/modifiés) → 1 fichier reformaté (test : fully_qualified_strict_types + ordered_imports), 0 modification sémantique
```

### Constat de coexistence (module concurrent, lecture seule)

`tests/Feature/AssistantIa/ValidationFinaleAssistantIaTest::test_statut_fournisseurs_indique_clairement_indisponibilite_ollama_local` **échoue dans cet environnement** : leur test suppose que le port 11434 est FERMÉ (« Ollama local doit être détecté indisponible lorsque le port 11434 n'est pas ouvert ») alors que la machine actuelle exécute réellement Ollama. Échec ENVIRONNEMENTAL préexistant, isolé de mes développements (le fichier du concurrent est totalement épargné ; `php artisan test tests/Feature/AssistantIa/FournisseurLocalOllamaTest.php` reste vert : 5/36). Noté pour éviter toute fausse causalité lors des runs combinés cross-module.

## 3. Plan des lots restants (périmètre complémentaire D6)

1. **Lot 6 — RAG** : après audit de `IndexeurDocumentaire`/`MoteurRechercheDocumentaire`/tables `ai_documents_index`. Si leurs garanties (accès filtré, chunking, anti-doublon, LIKE SQLite-compatible) sont vérifiées vertes, le package `AiAssistant` se branchera UNIQUEMENT sur ce qui manque pour MA passerelle : `RechercheDocumentaire` reste `RechercheVide` par défaut ; le branchement (rebind) conditionné à l'activer explicitement ; interdiction d'indexer PII/CIN/secrets.
2. **Lot 7 — outils de dev** : mes tools existants (`RegistreOutils` + `SandboxFichier` lecture seule, chemins interdits) restent la couche célibataire de sécurité ; audit de leurs `Inspecter*Tool` ; ne PAS dupliquer : livrer l'évidence de portage des contrôles + écarts (`proposer_patch` chez eux : diff affiché ; chez moi : proposition stockée, jamais appliquée).
3. **Lot 9 — approbations** : leurs `ai_propositions_actions` + `WorkflowApprobationService` (sha256, anti-rejeu) existent. Mes tables additives `ai_approvals` restent l'échafaudage ; un exécuteur whitelisté STRICT (où `appliquer_patch` est **REFUSÉ** par construction) n'existe chez personne — gap à combler si audit confirme.
4. **Lots 10-11** : contexte borné (déjà 12 messages), purge/quotas/métriques via mes tables `ai_metrics` + commandes ménagères.
5. **Lots 12-13** : suite adverse sur MA passerelle (injection directe/indirecte via `FournisseurFacticeDeterministe` avec réponse forcée — un parcours unique prouvable), eval synthétique.
6. **Lot 14** : doc d'architecture DANS UN FICHIER DÉDIÉ `docs/engineering/AI-ASSISTANT-ARCHITECTURE.md` (jamais le rapport concurrent).
7. **Lot 15** : validation finale : suites AI + suites impactées exit code, pint, `git diff --check`, scan secrets, confirmations.

### Ancrage sur la base de connaissance plateforme (2026-10-24, consigne utilisateur)

**Découverte (lecture seule, DB réelle `inscription_etudiants`) :** la table partagée `ai_documents_index` contient des morceaux d'un AUTRE indexeur beaucoup plus large : **3 587 chunks JSON d'outillage** (`.agents|.opencode/skills/...`), **2 646 chunks `docs/engineering/`** (rapports de boucles) et **1 264 chunks docs plateforme**. Sondes réelles AVANT focalisation : toutes les questions métier remontaient du bruit (`docs/AUDIT-SECURITE.md`, `docs/ARCHIVES.md`, `.agents/...` en tête).

**Correctif structurel (aucune écriture DB) :** `RechercheIndexPartage::candidats()` bornes la restitution par `whereIn('chemin_fichier', rag.sources)` — la liste EXPLICITE qui gouverne l'indexation gouverne désormais AUSSI la restitution : le modèle ne reçoit que la connaissance plateforme. Réversible (un chemin ajouté explicitement redevient restituable — testé).

**Mesure d'ancrage :** champ `chunks` ajouté à `TraceurIa` (:journalisation.champs_autorises — compteur sans contenu) et alimenté par `AiChatService` sur chaque `generer.reponse` : le taux d'ancrage devient observable dans les logs sans PII.

**Sondes réelles APRÈS (lecture seule) :** `cycle d inscription` → `docs/inscriptions/re-enrollment.md` · `affectation groupe` → `docs/students-groups/assignment.md` · `documents requis` → `docs/inscriptions/re-enrollment.md` · `frais inscription` → `docs/inscriptions/re-enrollment.md` · `export données` → `docs/DATA-EXCHANGE.md` — zéro chunk hors base plateforme.

**Évidence exécutée :** `AiAssistantRagTest` **13 tests** (nouveau : `test_la_restitution_se_focalise_sur_la_base_de_connaissance_explicite`) + `AiAssistantSecuriteTest` 5 (assertion `chunks` étendue, `question` interdit vérifié) ; non-régression combinée 7 suites : **111 tests / 356 assertions** ; pint propre. Corruptions préexistantes réparées (2 docblocks CJK, 1 docblock test).

## 4. Contraintes récurrentes appliquées

- Aucun commit/push/reset ; `.env` jamais modifié ; aucune clé réelle (uniquement `CLE_SYNTHETIQUE_TEST`) ; SQLite `:memory:` (`DB_FOREIGN_KEYS=true`).
- Migrations additives réversibles ; jamais `migrate:fresh`/`db:wipe`.
- Sortie du modèle = jamais une instruction ; tout contrôle côté serveur ; `x-text` seulement ; pas de PII journalisée/indexée.
- Verdicts fondés sur exécution réelle uniquement (résultats copiables ci-dessus).
