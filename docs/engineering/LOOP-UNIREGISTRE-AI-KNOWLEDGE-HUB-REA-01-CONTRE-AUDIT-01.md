# LOOP-UNIREGISTRE-AI-KNOWLEDGE-HUB-REA-01 — Contre-audit indépendant

> **Nature** : audit indépendant (lecture seule) du mandat « UNIREGISTRE AI KNOWLEDGE HUB », exécuté
> par une session distincte de la session d'implémentation active. Ce document ne modifie ni le
> code, ni le rapport cible `LOOP-UNIREGISTRE-AI-KNOWLEDGE-HUB-REA-01.md` (détenu par la session
> concurrente). Aucun commit, push, migration ni opération destructive. HEAD inchangé.
>
> **Date** : 23 octobre 2026, 19:00–19:20 · **Branche** : `feature/admin-modifier-etudiant`
> **HEAD** : `38ef14933de9980a4c62cb805bd19bc86c2b3081` · **Arbre** : ~621+ entrées WIP.

---

## 0. Constat opérationnel dominant — le mandat est en cours d'implémentation par une session concurrente

**Faits horodatés vérifiés pendant cet audit** :

| Heure (23/10) | Fait |
|---|---|
| 19:00 | Rapport cible déjà présent : `LOOP-UNIREGISTRE-AI-KNOWLEDGE-HUB-REA-01.md` (321 lignes, **LOT 0→5 tous annoncés `PASS`**) |
| 19:01–19:05 | Fichiers du plan **écrits pendant mon audit** : `ConnecteurCodeUniRegistre`, `ConnecteurDocumentationMetier`, `RegistreSourcesConnaissances`, `AiSourceConnaissance`, `AiDocumentIndex`, migrations `2026_10_23_140000/150000`, tests `AssistantIa/ConnecteurCodeTest.php` |
| 19:0x | L'ensemble `app/Modules/AssistantIa/` et `app/Modules/AiAssistant/` est **non suivi** (travail en cours non commité) |

**Conséquence appliquée (§0.1 du mandat — préservation obligatoire)** : cette session n'écrit ni
dans le module, ni dans le rapport cible. Elle livre l'audit indépendant exigé par le mandat
(« Ne pas considérer le rapport comme preuve suffisante sans vérification ») et la vérification
réelle des verdicts annoncés.

---

## 1. Architecture réelle constatée (audit LOT 0 indépendant, lecture seule)

### 1.1 Deux modules complémentaires (à ne pas fusionner ni doubler)

- **`app/Modules/AiAssistant/`** (fondations antérieures, chantier `AI-ASSISTANT-FOUNDATION-REPORT.md`
  + `docs/architecture/UNIREGISTRE-AI-ASSISTANT.md`) : assistant chat/RAG de première génération.
- **`app/Modules/AssistantIa/`** (le chantier du présent mandat, 39+ fichiers) :
  - **Contracts** : `FournisseurIaInterface`, `OutilIaInterface`, `RechercheDocumentaireInterface` ;
  - **Drivers** : `FauxFournisseurIa` (mock déterministe), `FournisseurLocal` (adaptateur
    Ollama/vLLM sur `127.0.0.1:11434/v1`, timeouts, diagnostic Apple Silicon, liste des modèles),
    `FournisseurRestDistant` (option explicite, **jamais le défaut**) ;
  - **DTO** : `DemandeIa`, `MessageIa`, `ReponseIa`, `AppelOutil`, `ResultatOutil` ;
  - **Services** : `OrchestrateurAgentIa`, `PasserelleFournisseurs`, `RegistreOutils`
    (nom→outil via contrat), `FiltreSecuriteIa`, `GestionnaireMemoireIa`, `JournalisationIa`,
    `ObservabiliteIaService`, `IndexeurDocumentaire`, `MoteurRechercheDocumentaire`, plus les
    nouveaux du plan : `RegistreSourcesConnaissances`, `ConnecteurCodeUniRegistre`,
    `ConnecteurDocumentationMetier` ;
  - **Tools** : développeur (`InspecterFichiersCodeTool`, `InspecterRoutesTool`, `ProposerPatchTool`)
    et métier (`ExpliquerCycleInscriptionTool`, `DiagnostiquerBlocageDossierTool`,
    `VerifierPiecesManquantesTool`, `ConsulterStatutTacheTool`, `PreparerActionScolariteTool`) ;
  - **Models** : conversations/messages, `AiPropositionAction` (approbation humaine),
    `AiDocumentIndex`, `AiSourceConnaissance` (nouveau LOT 1) ;
  - **Sécurité** : `FiltreSecuriteIa` = détection d'injection de prompt, validation des entrées,
    purge des sorties, vérification du périmètre faculté — les quatre protections clés exigées
    par le LOT 9 sont physiquement présentes.

### 1.2 Configuration

- `config/assistant-ia.php` : **`'fournisseur' => env('ASSISTANT_IA_FOURNISSEUR', 'faux')`** —
  le défaut est le mock déterministe, jamais un appel distant : **aucun basculement distant
  silencieux possible** dans la configuration par défaut. `local` (Ollama) et `distant` sont des
  bascules explicites documentées avec leurs timeouts (`ASSISTANT_IA_LOCAL_TIMEOUT`, etc.).
- `config/ai-assistant.php` : config du module fondation antérieur (coexistence à assumer, pas
  à supprimer).

### 1.3 Écart matériel décisif — Ollama absent du poste

`which ollama` → **absent** (vérifié ce soir). Conséquences :
- Le LOT 6 (« modèles locaux avec Ollama », §9) **ne peut pas être validé en conditions réelles
  sur cette machine** : ni latence, ni mémoire, ni exactitude des modèles ne sont mesurables.
- Les 204 tests qui passent s'appuient sur `FauxFournisseurIa` — légitime pour valider
  l'architecture, l'indexation, la sécurité et les outils (LOT 0-5, 7-10 côté logique), mais le
  critère de sortie global « le modèle local fonctionne réellement avec Ollama » reste
  **NON VÉRIFIÉ**, et le mandat (§0.1) interdit de déclarer PASS sur la seule base d'un mock.

---

## 2. Vérification indépendante des verdicts annoncés (exécutions réelles de ce soir)

| Suite | Commande | Résultat |
|---|---|---|
| Module du mandat | `php artisan test --compact tests/Unit/AssistantIa tests/Feature/AssistantIa` | **107 tests / 107 PASS / 460 assertions** (2,7 s) |
| Fondations antérieures | `php artisan test --compact tests/Feature/AiAssistant{Chat,Fondations,Gateway,Outils,Rag,Securite}Test` | **97 tests / 97 PASS / 304 assertions** (2,7 s) |

**Les verdicts `PASS` des LOT 0 à 5 annoncés par le rapport concurrent sont confirmés** par
exécution réelle (registre de sources, connecteur code, documentation métier, connecteur web
contrôlé, recherche hybride — y compris les tests sécurité : `AuditSecuriteIndependantTest`,
`SecuriteIaEtEvaluationQualiteTest`, `SecuriteAutorisationsTest`).

## 3. Verdicts par lot (cette session)

| Lot | Verdict | Justification |
|---|---|---|
| LOT 0 — audit | **PASS** (audit indépendant livré) | Architecture réelle documentée ci-dessus ; aucune modification de code |
| LOT 1-5 | **Confirmés PASS** (par exécution) | 107/107 tests du module passent ; inspection conforme au plan |
| LOT 6 — Ollama | **BLOCKED** | (a) fichiers en écriture concurrente active ; (b) **Ollama absent du poste** — validation réelle impossible ici ; tout PASS sur `FauxFournisseurIa` serait une violation du §0.1 |
| LOT 7-14 | **BLOCKED** | Fichiers du plan détenus par la session concurrente (écriture minute par minute) ; toute modification créerait un conflit interdit |

## 4. Contraintes respectées

Aucun commit/pull/push/reset/clean/stash ; aucune migration exécutée ; aucun secret, `.env` ou
donnée réelle manipulé ; aucun test affaibli ; aucun résultat annoncé sans exécution (les deux
suites ci-dessus ont été lancées ce soir par cette session) ; aucun fichier concurrent modifié.
Seul fichier créé : le présent contre-audit.

---

## 6. Vérification post-stabilisation (20:14–20:35, à la demande de l'exploitant)

### 6.1 Stabilisation constatée (choix n° 1)

Le rapport cible est **complet** : 619 lignes, LOT 0→14 tous `PASS`, synthèse globale et
« VERDICT FINAL DU LOOP REA : `PASS` ». Livrable doc présent :
`docs/architecture/UNIREGISTRE-AI-KNOWLEDGE-HUB.md` (188 lignes, guide Ollama Apple M2,
matrice RBAC, 11 outils). Activité résiduelle faible mais non nulle (2 fichiers module modifiés
après 20:00) — finitions, rapport considéré comme déposé.

### 6.2 Suites rejouées (choix n° 3) — exécutions réelles

| Suite | Résultat |
|---|---|
| `php artisan test --compact tests/Unit/AssistantIa tests/Feature/AssistantIa` (17 fichiers Feature, dont `BenchmarkValidationKnowledgeHubTest`, `FournisseurLocalOllamaTest`, `WorkflowApprobation`, `RouteurIntentions`) | **146 tests / 146 PASS / 606 assertions** (3,75 s) |
| `php artisan test --compact tests/Feature/AiAssistant{Chat,Fondations,Gateway,Outils,Rag,Securite}Test` | **97 tests / 97 PASS / 304 assertions** (2,63 s) |
| **Total** | **243 tests / 910 assertions / 0 échec** |

### 6.3 Distingu mock/réel — preuve formelle (exigence §0.1 du mandat)

1. **`FournisseurLocalOllamaTest` repose sur `Http::fake`** (3 occurrences : `/models` 200,
   `/models`+`/chat/completions` 503, `/chat/completions` 200) : le `PASS` du LOT 6 valide le
   **comportement du driver** (URL, erreurs, fail-soft), **pas le fonctionnement réel d'un
   modèle**. Idem pour le benchmark LOT 12 (tourne sur le faux fournisseur).
2. **Diagnostic réel exécuté ce soir** (driver instancié, lecture seule, aucun mock) :
   - `estDisponible()` → `false` ; `listerModelesInstalles()` → `[]` ;
   - `diagnostiquerEnvironnement()` → `service_joignable: false`, `statut: service_offline` —
     **échec propre, fail-soft, aucune bascule distante** (conformité §9.3 confirmée en réel).
3. **Mesures réelles du LOT 6 (latence, mémoire, exactitude des modèles) : `NOT_EXECUTED`** —
   Ollama absent, zéro modèle installé. Le verdict final du rapport (« assistant souverain
   fonctionnant localement avec Ollama ») **n'est pas prouvé sur ce poste** ; en l'état, le verdict
   global correct serait `PASS_WITH_RESERVATIONS` avec la réserve explicite « validation réelle
   du modèle local NOT_EXECUTED ». Aucun `PASS` sur mock ne peut la remplacer (§0.1).

### 6.4 Constat matériel nouveau (dimensionnement)

Diagnostic réel : **`ram_totale_go: 8`**, **`disque_libre_go: 11,2`**. Le poste réel n'est pas
dimensionné pour la recommandation principale du rapport (`qwen2.5-coder:7b-instruct-q4_K_M`,
~4,7 Go de poids modèle) : sur 8 Go de RAM partagés avec macOS, un 7B Q4 serait à la limite
infaisable. **Cible réaliste sur ce poste : un 3B quantifié** (`qwen2.5-coder:3b`, mentionné en
variante dans la doc) + `bge-m3` ; l'espace disque impose de ne télécharger qu'un modèle de
génération à la fois. La reprise doit recalibrer la recommandation avant toute installation.

### 6.5 Verdicts actualisés de cette session

| Lot | Verdict actualisé |
|---|---|
| LOT 1-5, 7-11, 13 (tests), 14 (doc) | **Confirmés par exécution** (243/243 ; livrable doc présent) |
| LOT 6 — validation réelle du modèle local | **`NOT_EXECUTED`** (driver OK en simulé ; Ollama absent ; RAM 8 Go) |
| LOT 12 — benchmark réel sur modèle | **`NOT_EXECUTED`** (le `PASS` actuel vaut pour le parcours sur faux fournisseur) |
| Verdict global du programme en l'état | **`PASS_WITH_RESERVATIONS`** — système intégré, testé, sécurisé ; la souveraineté locale reste à prouver sur le poste réel |

## 5. Condition de reprise

1. **Calibrer le dimensionnement au poste réel** (8 Go RAM / 11,2 Go disque) : viser un modèle 3B
   quantifié plutôt que le 7B recommandé (§6.4), un seul modèle de génération à la fois.
2. **Installer Ollama** (`brew install ollama` + `ollama serve`) puis le modèle retenu, en
   contrôlant mémoire/espace disque avant téléchargement — opérations à mener explicitement,
   jamais en tâche de fond du LOOP.
3. Basculer `ASSISTANT_IA_FOURNISSEUR=local` via le mécanisme de config local (jamais `.env`
   partagé), rejouer les suites §6.2, puis exécuter les mesures réelles du LOT 6 (latence,
   mémoire, exactitude, résistance à l'injection) et le benchmark LOT 12 **en réel**.
4. Closer le rapport cible avec la distinction mock/réel : tant que les mesures réelles ne sont
   pas exécutées, le verdict global doit rester `PASS_WITH_RESERVATIONS` avec la réserve
   « validation du modèle local NOT_EXECUTED » — conformément au §0.1 et §15 du mandat.
