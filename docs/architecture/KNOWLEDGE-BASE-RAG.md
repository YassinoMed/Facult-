---
titre: "KB-RAG UniRegistre — Contexte condensé"
domaine: general
statut: valide
version: "2026-10-24"
---

# KB-RAG UniRegistre — Contexte condensé (v. 2026-10-24)

> Bloc destiné au contexte système d'un LLM/RAG. Faits vérifiés au code ; source étendue :
> `docs/architecture/KNOWLEDGE-BASE.md`. En cas de doute : citer le fichier canonique listé ici,
> jamais inventer.

## 1. IDENTITÉ

Monolithe Laravel **modulaire** (CORE + 17 MODULES). PHP 8.5 · Laravel 13.33 · MySQL · Blade + Tailwind v4
+ Vite + Alpine/Livewire ciblé · API REST v1 (Sanctum). 173 contrôleurs · 407 services · 518 suites de tests
(sqlite isolé). Rôles back-office : `super_admin`, `admin_scolarite`, `agent_scolarite`, `consultation`,
`admin_faculte` — matrice : `app/Support/Permissions.php` (source de vérité). Portails étudiant/enseignant :
sessions séparées, scoping par session (jamais par URL). Modules activables par env `MODULE_XXX`
(`config/modules.php`) — middleware `module:xxx` → 404 si inactif.

## 2. MACHINES À ÉTATS (sources de vérité — ne jamais improviser)

| Entité | États | Transitions | Source canonique |
|---|---|---|---|
| Etudiant.statut | `en_attente, valide, rejete` | en_attente→valide/rejete ; valide→en_attente/rejete ; rejete→en_attente/valide | `StudentStatusService::TRANSITIONS` (idempotent no-op) |
| InscriptionAcademique.statut | `en_cours, valide, ajourne, abandon` | en_cours→valide/ajourne/abandon ; **terminaux sans retour** | `InscriptionService::transiter():117` (porte unique, verrou + re-vérification) |
| NoteEpreuve.statut | `draft, entered, submitted, validated, locked, correction_demandee` | validated→locked→correction_demandee→entered | `NoteEpreuve::TRANSITIONS` + `SaisieNoteService` |
| Deliberation.statut | `draft→prepared→(in_review/convened/in_progress)→validated→published→locked→archived` | serveur uniquement ; quorum = président + ≥2 présents ; zéro bloquant ; zéro `a_examiner` | `Deliberation::TRANSITIONS` + `DeliberationService` |
| DossierPaiement | `NON_PAYE→RECU_DEPOSE→EN_VERIFICATION→VALIDE|REFUSE` | un seul reçu VALIDE/dossier ; commentaire si écart | `DossierPaiement` + `DossierPaiementService` |
| RegistrationPeriod | `active, revoquee, utilisee` | normale ouverte→exception→CLOSED ; **non configurée = autorisé** (`SANS_PERIODE`) | `PeriodeInscriptionService::evaluer/resoudre` |

## 3. SERVICES CANONIQUES (réutiliser, jamais dupliquer)

| Service | Rôle | Invariants critiques |
|---|---|---|
| `InscriptionService` | Cycle de vie académique | Préconditions validation : pièces (`DocumentsRequisService::exigerPourValidation`) + verdict non-BLOQUANT (`BlocageInscriptionService::blocages`) ; event `InscriptionValidee` afterCommit → affectation auto fail-soft |
| `ArchiveService` | Notes officielles `notes` | Recalcul immédiat semestre+année ; invalidation délibérations dépendantes ; verrou officiel ≠ verrou épreuve |
| `AcademicCalculationService` | Moyennes/crédits/décisions | Centièmes entiers ; rattrapage **remplace** la normale ; crédits ≥10 ; seuils cœur 10/8 (≠ `evaluation_regles` versionnées : décision D-B ouverte) |
| `AffectationService` / `AffectationAutomatiqueService` | Groupes | Verrous ordre fixe étudiant→groupe ; idempotent (même groupe/année = no-op) ; auto jamais bloquant ; simuler ≠ écrire |
| `NotificationService` / `CommunicationDispatcher` | Notifications | Dédup : `cleDedup` (forte) / fenêtre 60 min ; **masse sans dédup** (attention) |
| `AuditService::tracer` | Traçabilité | → `HistoriqueAcademique` immuable (IP, UA, avant/après JSON) ; `tracerReferentiel` sans PII |
| `SaisieNoteService` | Notes d'épreuve | Pont UNIQUE vers `notes` à la validation ; absent ⇒ aucune note ; bornes 0..note_max ; re-saisie d'une validée → `entered` |
| `PeriodeInscriptionService` | Périodes | `etat()/etatPublic()` centralisés ; exceptions à usage unique ; garde AVANT écriture |
| `BlocageInscriptionService` / `InscriptionEligibilityService` | Diagnostic explicable | Codes structurés + gravité ; dry-run strict (jamais d'écriture) |
| `FileTraitementService` | File FIFO dossiers en attente | KPI = builder filtré (même base que la liste) ; action attendue par famille |
| `DeliberationService` | Jury | Préparation idempotente (delete+insert) ; `CalculationRun` hashé ; publication post-transaction ; `publie=true` = seule source portail |
| `AssistantIa` (Orchestrateur/PolitiqueRoutage/WorkflowApprobation) | Assistant IA local-first | RAG lexical filtré permissions ; distant = 6 gardes + jeton HMAC ; zéro fallback cloud ; approbation 4-yeux + sha256 ; **jamais d'auto-exécution** |

## 4. SÉCURITÉ

- Middleware admin : `auth + admin + mfa.obligatoire + secondaire.verrou` ; routes modules + `module:xxx` + `can:`.
- Isolation facultaire : `faculteRestreinteId()` + `peutAccederFaculte()` **fail-closed** ; convention scope→404 avant Gate.
- API v1 : Sanctum, tokens à périmètre (`grades:read`) ; sans décision publiée → enveloppe nulle.
- PII : zéro dans logs/URLs/exports (masquage `JournalisationIa::masquerDonneesSensibles`) ; pièces temporaires supprimées après OCR.
- CSRF partout ; webhooks HMAC+timestamp ; rate-limit par route sensible (5-30/min).

## 5. CONVENTIONS DE CODE

1. Français systématique (code, messages, exceptions). 2. `DomainException` = métier lisible ≠ 500. 3. Écriture critique =
`DB::transaction` + `lockForUpdate` + **re-vérification sous verrou** ; ordre de verrous fixe. 4. Événements `afterCommit` ;
listeners fail-soft (échec de bord ≠ échec métier). 5. Idempotence : contraintes uniques traduites en exceptions métier ;
`cle_unique`/SHA-256/dédup. 6. Module = `app/Modules/Xxx/` + flag config — jamais de route métier dans `routes/web.php`
ni colonne en table centrale. 7. Tests sqlite (garde anti-MySQL dans `Tests\TestCase`) ; caractérisation avant correction ;
gardes anti-N+1 par comptage. 8. Composants Blade partagés (`x-btn` 44px, `x-alert`, `x-status-badge`, `x-empty-state`,
`x-page-header`, `x-modal` x-trap+Échap) ; anti double-soumission `form-guard.js`. 9. Tout changement majeur → rapport
`docs/engineering/LOOP-*.md` ; **jamais de commit/push sans décision** ; migrations additives uniquement (jamais
`migrate:fresh`/`db:wipe`).

## 6. PIÈGES CRITIQUES (priorité anti-hallucination)

1. **Deux niveaux de notes** : `notes` (officielle, source des moyennes) ≠ `evaluation_notes` (épreuves) — pont unique
`SaisieNoteService::valider()` ; note matière = **dernière épreuve validée** (pas une composition ; décision D-A ouverte).
2. **Trois vocabulaires de statuts** : fr minuscule / MAJUSCULES (paiement) / anglais GED — mapper via source de vérité.
3. **Deux moteurs de décision** : cœur (10/8, 2 décimales) vs `evaluation_regles` (seuils+arrondi versionnés) — incohérence
possible au seuil (décision D-B ouverte).
4. **Paiement NON bloquant** pour la validation d'inscription (`PAIEMENT_NON_CONFIRME` = étiquette de file).
5. **Période non configurée = autorisé** (`SANS_PERIODE`) — ne pas bloquer par défaut.
6. **Modules inactifs en prod** : page « manquante » = souvent 404 `module:xxx` ; les tests activent les flags explicitement.
7. **Deux piles IA** : `AssistantIa` (actif) vs `AiAssistant` (inactif) — ne pas confondre tests (`AiAssistant*Test` cible
l'inactif).
8. **IA locale-first** : défaut fournisseur `faux` ; distant désactivé par politique (`routage.distant_autorise=false`) ;
requiert activation + permission + contenu non sensible + jeton HMAC lié à `sha256(prompt|sources)` ; offline local = 504,
**jamais de bascule**.
9. `x-alert` ignore les attributs arbitraires ; Chrome headless ≥500 px (captures).
10. Résultats étudiant : uniquement `DecisionDeliberation` avec `publie=true` (dashboard, notes, archives, API) — jamais les
moyennes recalculées.

## 7. FLUX ESSENTIELS

- **Validation inscription** : pièces + verdict → `transiter()` (BEGIN; lock; re-vérifier; update; Situation; audit; COMMIT)
→ `InscriptionValidee` (afterCommit) → affectation auto (fail-soft). Échec de bord ≠ échec métier.
- **Notes** : saisie anonyme (compostages) → `entered` → validation → `ArchiveService` (notes + recalcul + invalidation
délibérations) → `validated`. Re-saisie validée → `entered` (revalidation exigée). Correction verrouillée = 2 étapes.
- **Délibération** : preparer (delete+insert idempotent, `CalculationRun` hashé) → valider (quorum, zéro bloquant, zéro
`a_examiner`, verrou) → publier (`publie=true`, notifications post-transaction) → portail.
- **Affectation auto** : `tenter()` idempotent (EXISTING/LOCKED) ; capacité re-vérifiée sous verrou ; jamais bloquant ;
répartition de masse = simulation puis confirmation par tranches (200).

## 8. DÉCISIONS MÉTIER OUVERTES (ne pas trancher, citer)

- **D-A** : note matière = dernière épreuve validée vs composition multi-épreuves (`Matiere::composantes()` inutilisé).
- **D-B** : alignement seuils/arrondi cœur ↔ règles versionnées.
- **D-C** : rectification d'un résultat déjà publié (aucune voie actuelle).
- **D-D** : imports DataExchange posant `publie=true` sans délibération validée.
- Paiement : règle institutionnelle de blocage non définie (actuellement non bloquant).

## 9. POINTeurs RAPIDES (path:line)

- Transitions inscription : `app/Services/InscriptionService.php:30-35,117-200`
- Statuts étudiant : `app/Services/StudentStatusService.php:24-114`
- Périodes : `app/Services/PeriodeInscriptionService.php:43-59,229-335`
- Notes officielles : `app/Services/ArchiveService.php:141-293`
- Calcul : `app/Services/AcademicCalculationService.php:31-201`
- Notes épreuve : `app/Modules/Evaluation/Services/SaisieNoteService.php`
- Délibérations : `app/Modules/Evaluation/Services/DeliberationService.php`
- RBAC : `app/Support/Permissions.php` (ROLES:11, MATRICE:323+)
- Assistant IA : `app/Modules/AssistantIa/Services/{OrchestrateurAgentIa,PolitiqueRoutageIa,WorkflowApprobationService}.php`
- Centre de travail : `app/Http/Controllers/Admin/CentreTravailController.php`
- KB étendue : `docs/architecture/KNOWLEDGE-BASE.md` · Rapports : `docs/engineering/LOOP-*.md`
