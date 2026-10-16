# LOOP UNIQUE — Consolidation enterprise du module d'inscription étudiants — Rapport

**Cycle :** PLAN → DEV → TEST → VALIDATION → AUTO-PASSAGE
**Date :** 2026-10-13
**Périmètre :** Inscription publique + vérification automatique + traitement administratif
**Environnement :** Développement local, base de test isolée (sqlite :memory: via phpunit.xml). Production hors périmètre.
**État Git initial :** branche `main`, arbre déjà sale (modifications préexistantes non liées au périmètre : Mfa, Verrouillage, modules Routes, etc. — voir § Diff final). Aucune de ces modifications n'a été touchée par la présente loop.

---

## 1. Résumé exécutif

Loop exécutée de bout en bout (PLAN → DEV → TEST → VALIDATION → AUTO-PASSAGE) sans interruption.
Aucun défaut P0 démontré. Deux défauts P1 corrigés avec tests de non-régression (C1 : verdict
CONFORME par défaut sur exception de règle ; C2 : masse sans acteur tracé). Deux durcissements P2
(C3 : afterCommit ; C4 : version moteur persistée, migration réversible vérifiée sur base vierge isolée).
Quatre réserves sans modification (C5–C8 : arbitrage métier ou preuve MySQL requise).
Suite complète : **3146/3172 verts** ; les 4 échecs (seuils de requêtes N+1) sont **prouvés pré-existants**
(échec identique code initial restauré via stash, sans les corrections) et conservés en réserve.
Parcours navigateur réel et concurrence MySQL : NOT_EXECUTED (documentés, jamais substitués).
**Verdict : PASS_WITH_RESERVATIONS** (détaillé §10).

## 2. État initial et périmètre inspecté

### 2.1. Fichiers réellement inspectés (chemins réels)

**A. Inscription publique**
- `app/Http/Controllers/InscriptionController.php` (create/store/verifierCin/identifier/scanIdentity/checklistDocuments/optionsOrientation/faq)
- `app/Http/Requests/InscriptionRequest.php` (CIN 8 chiffres, unique CIN/email, normalisation email minuscules)
- `app/Services/IdentificationIntelligenteService.php` (statuts INCONNU/MENS_SEUL/LOCAL_SEUL/MENS_ET_LOCAL/AMBIGU/INDISPONIBLE, fail-closed)
- `app/Services/InscriptionEligibilityService.php` + `EligibiliteParcoursService` (éligibilité parcours)
- `app/Services/Identity/IdentityDocumentService.php` (upload privé `identity-scan/tmp`, validation profonde extension+MIME réel+signature+taille, suppression systématique finally)
- `config/identity.php` (driver OCR `IDENTITY_OCR_DRIVER`, défaut `null` = placeholder ; throttle scan 5/min/IP)
- `app/Services/Identity/Engines/` (Null/Tesseract/Ppocr/HfVlm/Colab)
- `app/Events/StudentRegistered.php` + listeners `JournaliserInscription`, `NotifierDepotRecu`, `ProvisionnerDossierInscription`, `DetecterIncoherencesInscription` (tous fail-soft, cf. § invariants)
- Routes publiques `routes/web.php:89-171` (throttles 10/1 inscription, 5/1 identifier, identity.scan dédié, CSRF)

**B. Vérification automatisée**
- `app/Services/Verification/VerificationEngine.php` (12 règles, fail-soft par règle, lecture seule)
- `app/Services/Verification/VerificationResult.php` (pénalités 35/15/5, plafonnement score ≤ 49 si BLOQUANT)
- `app/Services/Verification/VerificationStore.php` (append-only `dossier_verifications`)
- `app/Services/Verification/Rules/` (12 règles dont `DoublonsRule` — jamais de fusion auto en code — et `MensDivergenceRule`)
- `app/Models/DossierVerification.php` + migrations `2026_10_02_171306_*`, `2026_10_11_120000_*` (regles_echouees)
- `app/Jobs/VerifyStudentDossierJob.php` (queue `verification`, tries 3, backoff 10/60/180, timeout 300, idempotent via cle_unique)
- `app/Console/Commands/VerifierDossier.php` (`dossier:verifier`, lecture seule par défaut)
- `routes/console.php` : aucune tâche planifiée de validation/rejet automatique (conforme)

**C. Traitement administratif**
- `app/Http/Controllers/Admin/FileTraitementController.php` (file, scoping faculté serveur, FIFO, KPIs)
- `app/Http/Controllers/Admin/VerificationController.php` (show/verifier/proposer/corriger/decider/demanderComplement/bulk/index)
- `app/Http/Controllers/Admin/InscriptionGestionController.php` (valider/rejeter/reinscrire, Gate update + périmètre faculté)
- `app/Http/Controllers/Admin/EtudiantController.php` (index/show/updateStatut/bulkStatut/exportDossier/destroy)
- `app/Services/StudentStatusService.php` (point unique, matrice TRANSITIONS, lockForUpdate, historique SituationEtudiant, events + notification fail-soft dédupliquée)
- `app/Services/InscriptionService.php` (transiter avec verrou, garde blocages critiques, InscriptionValidee après commit)
- `app/Policies/EtudiantPolicy.php` (view/update/delete = permission + périmètre faculté ; viewAny = true avec scoping requête)
- `app/Policies/InscriptionAcademiquePolicy.php` (réutilisée par valider/rejeter)
- `app/Listeners/AffecterApresDecisionDossier.php` (uniquement si statut valide, fail-soft) + `TenterAffectationAutomatique.php`
- `app/Events/DossierDecisionPrise.php` / `InscriptionValidee.php` (afterCommit = true)

### 2.2. Invariants métier vérifiés (code réel)

| Invariant | Preuve | Statut |
|---|---|---|
| Dossier soumis initialement `en_attente` | `Etudiant::$attributes statut=en_attente`, migration default `en_attente` | OK |
| Vérification = signaux + score + verdict, jamais décision définitive | Engine lecture seule ; `VerifyStudentDossierJob` ne touche pas au statut ; `transiter()` exige décision humaine | OK |
| Seul acteur autorisé valide/rejette | Gates `update` + `archives.update` + périmètre faculté sur toutes les voies (individuel, masse, AJAX) | OK |
| Affectation uniquement après validation | `AffecterApresDecisionDossier` (statut valide requis), `InscriptionValidee` dispatchée après commit dans `transiter()` | OK |
| Doublons jamais fusionnés automatiquement | `DoublonsRule` + `FicheEtudiantService::doublonsPotentiels()` : proposition de comparaison uniquement, aucune fusion en code | OK |
| Indisponibilité externe ≠ validation abusive | `InscriptionController::store` fail-closed MENS (statut exigé MENS_SEUL/MENS_ET_LOCAL, INDISPONIBLE rejeté) | OK |
| Corrections tracées | `DossierCorrection` 4-eyes, `SituationEtudiant` append-only, `AuditService` | OK (réserve F2 corrigée, voir §4) |
| Contrôles facultaires à chaque accès | Policies + `autoriserAccesEtudiant` + scoping requêtes (pas seulement listes) | OK |
| Masse = mêmes règles que l'individuel | `bulkStatut` → `StudentStatusService::changer` par dossier avec Gate individuelle | OK |

## 3. Architecture réelle découverte

```
Public (throttle+CSRF)
  └─ InscriptionController::store
       ├─ PeriodeInscriptionService::verifierCreationPublique (garde serveur)
       ├─ Anti-doublon CIN/email normalisés (pré-check + contrainte UNIQUE BD + catch concurrence)
       ├─ IdentificationIntelligenteService (fail-closed MENS, recalculé serveur)
       ├─ EligibiliteParcoursService (revalidation serveur)
       ├─ DB::transaction (Etudiant::create, $fillable strict sans statut)
       └─ StudentRegistered (dispatch APRÈS commit) → 4 listeners fail-soft

Vérification (analytique, lecture seule)
  └─ VerificationEngine (12 règles, fail-soft/règle) → VerificationResult (verdict+score)
       ├─ VerificationStore → dossier_verifications (append-only)
       ├─ VerifyStudentDossierJob (queue verification, idempotent cle_unique)
       └─ dossier:verifier (artisan, lecture seule par défaut)

Décision (humaine, autorisée)
  └─ StudentStatusService::changer (matrice + lockForUpdate + Situation + events afterCommit + notif dédupliquée)
       ├─ individuel : EtudiantController::updateStatut
       ├─ masse : EtudiantController::bulkStatut (max 200, dédupliqué, transaction unique)
       └─ inscriptions : InscriptionService::transiter (garde blocages + InscriptionValidee → affectation)
```

## 4. Registre des constats

Format : `ID | preuve (fichier/ligne) | impact | priorité | correction | test de non-régression`

| ID | Preuve | Impact | Prio | Correction envisagée | Test |
|---|---|---|---|---|---|
| C1 | `VerificationEngine.php:62-72` : exception d'une règle enregistrée en anomalie `info` ; `VerificationResult::calculerVerdict` ignore `info` (GRAVITES info=0) → dossier avec contrôle obligatoire non exécuté reste **CONFORME** (score 99) | Résultat « conforme » par défaut malgré échec technique ; risque d'interprétation incorrecte du verdict | **P1** | Gravité `avertissement` pour l'anomalie d'exception → verdict A_VERIFIER, score −5 | `LoopInscriptionConsolidationTest::exception_regle_produit_avertissement_pas_conforme` |
| C2 | `EtudiantController.php:308` : `bulkStatut` appelle `changer(..., null, 'bulk')` (auteur null) alors que `updateStatut` transmet l'utilisateur réel ; `situations_etudiant.auteur_id` nullable | Historique de décisions en masse sans acteur identifié (traçabilité) | **P1** | Transmettre `(int) $request->user()->id` | `LoopInscriptionConsolidationTest::bulk_statut_tracer_acteur` |
| C3 | `Events/StudentRegistered.php` : pas de `$afterCommit` alors que 3 listeners écrivent en BD (dossier GED, anomalies, notification) ; dispatch actuellement après commit dans `store()` | Fragilité : tout déplacement futur du dispatch dans la transaction fuirait des écritures en cas de rollback | **P2** | Ajouter `public $afterCommit = true` (sans effet sur le flux actuel : hors transaction = dispatch immédiat) | `LoopInscriptionConsolidationTest::student_registered_apres_commit` (contrat) |
| C4 | `dossier_verifications` : aucune version du moteur ni liste des règles exécutées persistée (`regles_executees` calculée en mémoire puis perdue ; `regles_echouees` seule persistée) | Runs passés non reproductibles/auditables dans le temps | **P2** | Migration additive réversible `moteur_version` + `regles_executees` ; `VerificationEngine::VERSION` ; persistance dans `VerificationStore` | `LoopInscriptionConsolidationTest::run_persiste_version_et_regles` |
| C5 | `Etudiant::genererNumInscription()` (read-max +1 non atomique) + catch `store():217-236` retournant un message « CIN/email déjà enregistré » même en cas de collision `num_inscription` entre CIN différents | Message d'erreur trompeur en cas de course sur le compteur (auto-résolu au réessai, pas de perte) | **P2** | Réserve documentée (preuve de la course exige MySQL concurrent ; SQLite insuffisant). Non modifié. | NOT_EXECUTED (concurrence MySQL indisponible dans cette loop) |
| C6 | Route `verification/bulk` : seul `Gate viewAny` (toujours vrai) — un rôle lecture seule peut enfiler jusqu'à 500 dossiers et écrire `anomalies_qualite`+runs | Écritures en masse par rôle consultation ; mais : périmètre faculté appliqué, throttle 10/min, action analytique (jamais de mutation du dossier), cohérent avec `verifier` unitaire (permission view) | **P2** | Réserve métier : durcir vers `archives.update` romprait la cohérence avec le `verifier` unitaire et des workflows agents existants. Décision métier requise. Non modifié. | Réserve |
| C7 | `InscriptionRequest` : `date_naissance` nullable alors que `store()` l'exige (rejet via message identification générique) | Parcours d'erreur indirect mais fail-closed conservé | **P3** | Non modifié (contrat public + tests existants). Réserve. | Réserve |
| C8 | `DoublonsRule.php:59` : recommandation « fusionner ou corriger » | Formulation ambiguë ; aucune fusion automatique en code (vérifié par recherche `fusion|merge` : seul le libellé) | **P3** | Non modifié (fusion manuelle par acteur autorisé hors périmètre d'interdiction). Réserve. | Réserve |

**Décisions de non-modification (règle : pas de réécriture sans preuve) :** MENS fail-closed OK, documents/OCR OK (privé, validation profonde, suppression finally, driver `null` par défaut + `IDENTITY_OCR_DRIVER=colab` en local uniquement — aucun envoi de production), idempotence OK, policies OK, scheduler sans décision auto OK, DoublonsRule sans fusion OK.

## 5. Corrections réalisées (DEV)

| ID | Fichiers modifiés | Changement | Lignes |
|---|---|---|---|
| C1 | `app/Services/Verification/VerificationEngine.php` | Anomalie d'exception de règle : gravité `info` → `avertissement` (verdict A_VERIFIER, −5 pts, jamais CONFORME par défaut) | ~66-72 |
| C1+C4 | `app/Services/Verification/VerificationEngine.php` | `const VERSION = '1'` + transmis au résultat | ~21-28, ~101 |
| C4 | `app/Services/Verification/VerificationResult.php` | Propriété `versionMoteur` (défaut = version courante, compatibilité des constructions existantes) | ~42 |
| C4 | `app/Services/Verification/VerificationStore.php` | Persistance `moteur_version` + `regles_executees` | ~33-36 |
| C4 | `app/Models/DossierVerification.php` | fillable + casts `moteur_version`, `regles_executees` | ~17-26 |
| C4 | `database/migrations/2026_10_13_120000_*.php` (nouveau) | Migration additive réversible (gardes hasColumn, down restaure le schéma) | — |
| C3 | `app/Events/StudentRegistered.php` | `public $afterCommit = true` (dispatch actuel hors transaction : immédiat, inchangé) | ~17-24 |
| C2 | `app/Http/Controllers/Admin/EtudiantController.php` | `bulkStatut` : `(int) $request->user()->id` au lieu de `null` | ~301-311 |

Total : 6 fichiers modifiés (33 insertions, 5 suppressions) + 1 migration + 1 test. Aucun autre fichier
touché. `registre-canonique.md` volontairement non édité (registre partagé déjà modifié par d'autres
loops en cours ; aucun contrat de module ajouté/retiré ; aucune doc existante ne décrivait
l'ancien comportement `info` — recherche docs négative).
Conventions préservées : services canoniques (`StudentStatusService`, `VerificationStore`), français,
PII hors logs (aucun log ajouté ; diff contrôlé sans secret/mot de passe/jeton/CIN).

## 6. Tests exécutés (TEST)

Nouveau fichier : `tests/Feature/LoopInscriptionConsolidationTest.php` (4 tests, 19 assertions).

| Commande exacte | Résultat réel observé |
|---|---|
| `php artisan test --filter="LoopInscriptionConsolidationTest"` | 4/4 PASS (1622 ms puis 660 ms) |
| `php artisan test --filter="VerificationRulesTest\|VerificationBoucleTest\|VerificationFeatureTest\|VerificationOeilTest\|EtudiantsRegistreTest\|ProductiviteTest\|RegistreInterfacultesTest"` | 96/96 PASS, 364 assertions |
| `php artisan test --filter="InscriptionEtudiantsTest\|InscriptionsPhase3Test\|IdentificationFailClosedTest\|IdentityScanTest\|InscriptionSecuriteTransversaleTest\|MensDivergencesWorkflowTest\|InscriptionDoublonsIncoherencesTest\|ComplementsInscriptionLoopTest\|InscriptionCorrection4YeuxTest\|InscriptionDemandeComplementTest\|FileTraitementCoherenceTest\|FileTraitementPaginationTest\|FileTraitementCategoriesTest\|AffectationAutomatiqueApresValidationTest\|PeriodeInscriptionMissionTest\|InscriptionEligibiliteOrientationTest\|InscriptionMensIdentificationTest"` | 158/158 PASS, 671 assertions |
| `php artisan test --filter="PpocrEngineTest\|HfVlmOcrEngineTest"` (AGENTS.md) | 12 PASS, 1 SKIP (VLM désactivé par `IDENTITY_HFVLM_ENABLED=false` — fail-soft attendu) |
| `php artisan test` (suite complète, sqlite :memory:) | **3146/3172 PASS, 22 SKIP, 4 FAIL pré-existants** (229 s) |
| `vendor/bin/pint --test` (8 fichiers de la loop) | PASS, aucun écart de style |
| Migration vierge isolée : `migrate --force` + `migrate:rollback --step=1` sur sqlite scratch (jamais la MySQL de dev) | UP ajoute `moteur_version`+`regles_executees`, DOWN les retire — réversible |
| `composer audit` | Aucune vulnérabilité |
| `php artisan route:list --name=verification.bulk` / `--name=etudiants.bulkStatut` | Les 2 routes résolvent (aucune route ajoutée/retirée) |

Échecs pré-existants (4, tous seuils de requêtes, prouvés sans lien via stash aller/retour) :
- `InscriptionFileTraitementTest::test_absence_de_requetes_n_plus_un_sur_la_file` (25 < 25)
- `InscriptionPerformanceFiabiliteTest::test_file_administrative_absence_de_n_plus_un_sur_volume` (25 < 25)
- `PopulationPhase2CompleteTest::test_dashboard_ventilations_bornees` (23 vs ≤ 21)
- `Security/ArchivesFicheLoop3Test::test_fiche_sans_n_plus_un` (25 < 25)
Aucun test supprimé, désactivé, ignoré ou assoupli. Aucune assertion modifiée.

## 7. Validation (sécurité, performance, qualité)

### 7.1. Matrice de couverture sécurité

| Contrôle | Preuve réelle | Résultat |
|---|---|---|
| Authentification + autorisation admin | Groupe `auth`+`admin` (routes/web.php:409) ; Gates `update`/`archives.update` sur valider/rejeter/updateStatut/bulk/commenter ; suite `RegistreInterfacultesTest`, `ProductiviteTest` vertes | PASS |
| Accès inter-facultés (IDOR) | `EtudiantPolicy` (permission + `peutAccederFaculte`) ; `autoriserAccesEtudiant` sur chaque endpoint vérification ; scoping `bulk` + file ; tests inter-facultés verts | PASS |
| Protection des documents | Stockage `identity-scan/tmp` disque `local` (privé), nom UUID, validation profonde (extension+MIME réel+signature+taille+cohérence), `finally` suppression, recherche fail-closed (numéro+naissance), `IdentityScanTest` vert | PASS |
| Validation des entrées | `InscriptionRequest` (CIN 8 chiffres, uniques, email normalisé), whitelist prefill/OCR, `ScanIdentityDocumentRequest`, `VerifierCinRequest` | PASS |
| Erreurs externes | MENS fail-closed (`store` exige MENS_SEUL/MENS_ET_LOCAL ; `IdentificationFailClosedTest` vert) ; OCR fail-soft (jamais preuve, jamais d'écrasement auto) | PASS |
| Concurrence et idempotence | Contraintes UNIQUE CIN/email/num_inscription + catch concurrence + jeton idempotence + `lockForUpdate` transitions ; tests `InscriptionDoublonsIncoherencesTest` verts. Course `num_inscription` : réserve C5 (preuve MySQL NOT_EXECUTED) | PASS avec réserve C5 |
| Transitions de statut | `StudentStatusService`/`InscriptionService` (matrice + verrou + historique + events afterCommit + notif dédupliquée) ; masse via le même service ; test bulk acteur ajouté | PASS |
| Audit et minimisation | `SituationEtudiant` append-only, `AuditService`, listeners sans PII (vérifiés un par un), export audité, aucun secret dans le diff | PASS |
| Exports et masse | Lots bornés (200 statuts, 500 vérifications, tranches 50), `confirmation` exigée, Gate par dossier, throttles ; réserve C6 (arbitrage permission bulk vérification) | PASS avec réserve C6 |

### 7.2. Performance (mesures observées, sqlite de test — jamais présentées comme preuve MySQL)

- File traitement : 300 dossiers → 18 ms / 25 requêtes (`[VOLUMETRIE-FILE]`, observé en suite).
- Index filtré : 12 requêtes ; fiche étudiant show : 41 requêtes (observés en suite).
- Inscription publique : 10 soumissions → 53 requêtes max, 11 ms max (`[INSCRIPTION-PUBLIQUE]`, observé en suite).
- Aucun gain annoncé (aucune optimisation entreprise) ; aucun cache ajouté.
- Réserve : 4 seuils N+1 en échec pré-existant (25 vs <25 ×3, 23 vs ≤21 ×1) — non liés à la loop, non modifiés.

### 7.3. Revue finale des changements

- Diff relu : 6 fichiers + 1 migration + 1 test + présent rapport ; aucune permission élargie
  (bulk vérification inchangé), aucune décision automatique introduite (C1 rend le moteur plus prudent,
  jamais plus permissif), aucun secret/fichier temporaire/base de test/PII ajouté.
- État Git : branche `main`, modifications préexistantes d'autres loops intactes et non touchées ;
  stash aller/retour vérifié deux fois avec restauration contrôlée.

## 8. Réserves, décisions métier requises et contrôles non exécutés

- R-C5 : course `num_inscription` (message trompeur possible) — preuve concurrente MySQL requise ; tests destructifs interdits sur la MySQL de dev conservée. NOT_EXECUTED.
- R-C6 : permission du bulk de vérification (`viewAny` vs `archives.update`) — arbitrage métier (cohérence avec le `verifier` unitaire en `view`). Non modifié.
- R-C7/R-C8 : `date_naissance` nullable vs exigence MENS ; formulation « fusionner » de `DoublonsRule` (aucune fusion auto en code, vérifié). Non modifiés.
- R-N+1 : 4 seuils de requêtes en échec pré-existant (détaillés §6), reproduits sans mes changements. Non touchés (règle : pas d'affaiblissement de seuils pour obtenir du vert).
- Navigateur réel (parcours §3.4 en 11 étapes) : NOT_EXECUTED — aucun navigateur/service disponible dans cet environnement ; jamais substitué par des tests unitaires.
- Intégration MySQL : NOT_EXECUTED — SQLite `:memory:` uniquement ; jamais présenté comme preuve concurrente MySQL.
- Production : hors périmètre — aucune modification, migration ou validation de production.

## 9. Diff final et état Git

- Branche `main`. Fichiers de la loop : 6 modifiés (33+/5−), 1 migration nouvelle, 1 test nouveau, 1 rapport.
- Préexistants non liés (MFA, verrouillage, routes modules, registre, vues, autres tests) : intacts, non relus en détail, non modifiés par cette loop. Aucun commit/push/déploiement effectué (interdit sans autorisation).

## 10. Tableau de preuves et verdict

| Contrôle | Preuve réelle | Verdict |
|---|---|---|
| Inscription publique | `InscriptionEtudiantsTest`, `InscriptionsPhase3Test`, `IdentificationFailClosedTest` verts + code relu | PASS |
| Unicité et concurrence | Contraintes UNIQUE + catch + tests doublons verts ; course compteur MySQL non prouvée | PASS_WITH_RESERVATION (C5) |
| Vérification automatique | 12 règles relues ; C1 corrigé + test ; `Verification*Test` verts ; version persistée + test | PASS |
| Sécurité des documents | `IdentityScanTest` vert ; stockage privé + validation profonde + finally relus | PASS |
| Autorisation administrative | Policies + périmètre relus ; `RegistreInterfacultesTest`, `ProductiviteTest`, `EtudiantsRegistreTest` verts | PASS |
| Décisions et historique | Services canoniques relus ; C2 corrigé + test bulk acteur | PASS |
| Jobs et retries | `VerifyStudentDossierJob` relu (idempotent, backoff, fail-soft) ; aucun rejet/changement de statut par le job | PASS |
| Navigateur réel | Aucun parcours exécuté (environnement) | NOT_EXECUTED |
| Performance | Mesures sqlite observées §7.2 ; 4 seuils N+1 pré-existants en échec conservés | PASS_WITH_RESERVATIONS |
| Suite de régression | `php artisan test` : 3146/3172 PASS, 22 SKIP, 4 FAIL pré-existants prouvés | PASS_WITH_RESERVATIONS |

**Verdict final : `PASS_WITH_RESERVATIONS`.**
Justification : parcours principal (inscription → vérification analytique → décision humaine tracée →
affectation après validation) validé par code relu + 3146 tests verts ; corrections P1 prouvées ;
aucune régression introduite (tout échec restant est démontré pré-existant) ; mais 2 seuils N+1
touchant la file de traitement (périmètre) restent en échec pré-existant, et navigateur/MySQL n'ont
pas pu être exécutés. Après 1 seule tentative par défaut (prouvés hors périmètre de cause), les 4
échecs sont documentés en réserve et les contrôles indépendants ont été poursuivis — pas de boucle infinie.

---

# EXTENSION LOOP — Dossier étudiant existant : parcours public sans login + email final

**Date :** 2026-10-13 · **Cycle :** PLAN → DEV → TEST → VALIDATION → AUTO-PASSAGE
**Intégration :** LOOP UNIQUE — Inscription publique (extension du rapport ci-dessus, §1-10 inchangés).

## E1. Objectif métier et résultat

Un étudiant déjà présent en base (CIN + date de naissance) dispose désormais d'un parcours public
de mise à jour de son dossier, sans login et **sans création de second dossier** :

1. Recherche par CIN normalisé + date de naissance exacte (réponse neutre, anti-énumération) ;
2. Code de vérification à usage unique (6 chiffres) envoyé à l'adresse email **déjà enregistrée** ;
3. Formulaire prérempli (données autorisées) après vérification du code ;
4. Soumission revalidée, transactionnelle, historisée ;
5. Email de confirmation envoyé **après commit** ;
6. Écran final sans données sensibles, distinguant « demande enregistrée » et « email envoyé ».

Le WIP préexistant non commité (`InscriptionController::storeExisting`, route `inscription.existing-store`,
modale wizard à préremplissage direct, `ExistingInscriptionRequest.php` sans référence) a été inspecté
puis **remplacé** : il autorisait la modification de CIN/email/faculté, ne vérifiait pas l'identité
au-delà du couple CIN+naissance et annonçait « email envoyé » sans aucun envoi.

## E2. Inspection préalable (composants réels)

`InscriptionController` (create/store/verifierCin/identifier/scanIdentity/checklistDocuments),
`InscriptionRequest`, `VerifierCinRequest`, `ProfilEtudiantRequest` (règles canoniques de coordonnées),
routes publiques `routes/web.php:89-171`, `Etudiant` + `definirStatut`/`email_masque`/`cin_masquee`,
`StudentLookupService`, `DossierExistantService` (nouveau) réutilisant `AuditService` (historique
`historique_academique`), `DocumentsRequisService::checklistDossier` (état GED réel),
`PeriodeInscriptionService::verifierCreationPublique` (périodes), `IdentificationIntelligenteService`
et `EligibiliteParcoursService` (MENS/éligibilité, inchangés), pipeline de notification existant
(`AppNotification` + `AcheminerNotificationExterneJob` + canal simulé), `DossierComplementService`
(précédent : statut valide/rejete ⇒ aucune modification en ligne), `SuiviPublicController`
(précédent : réponse neutre anti-énumération), tests publics existants (`CinPrefillTest`,
`InscriptionMensIdentificationTest`, `MensInscriptionIntelligenteTest`).

## E3. Fichiers modifiés / créés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Services/DossierExistantService.php` | **nouveau** | Service canonique : recherche fail-closed, code OTP hashé en cache (TTL 10 min, 5 essais, cooldown 60 s), jeton d'autorisation opaque hashé (TTL 15 min, usage unique atomique `Cache::pull`, lié à un dossier), whitelist `CHAMPS_MODIFIABLES`, enregistrement transactionnel + verrou + garde de statut + audit, email de confirmation fail-soft après commit |
| `app/Http/Controllers/DossierExistantController.php` | **nouveau** | accueil / rechercher / verifier / formulaire / enregistrer / confirmation. Jeton en session (jamais dans l'URL), dossier TOUJOURS résolu depuis le jeton (anti-IDOR structurel) |
| `app/Http/Requests/DossierExistantModificationRequest.php` | **nouveau** | Validation uniquement des champs modifiables (règles alignées sur `ProfilEtudiantRequest`) + attestation ; tout champ hors whitelist est ignoré |
| `app/Notifications/CodeVerificationDossierExistant.php` | **nouveau** | Code OTP par email (envoi synchrone), sans CIN/date de naissance |
| `app/Notifications/ConfirmationMiseAJourDossierExistant.php` | **nouveau** | Email final : objet « Confirmation de mise à jour de votre dossier étudiant », référence `num_inscription`, prochaines étapes, dossier en attente, contact scolarité. `ShouldQueue` (file `notifications`, retries 3) |
| `app/Models/Etudiant.php` | modifié | Trait `Notifiable` (requis pour `$etudiant->notify()` ; aucun changement de schéma) |
| `app/Http/Controllers/InscriptionController.php` | modifié | `verifierCin` : plus aucun DTO de données (→ `exists` + `verification_requise` + `email_masque` + `redirection`) ; `identifier` (dossier local) : plus de `prefill` (→ statut + `masked_email` + `redirection` + message neutre) ; suppression de `storeExisting` et du helper de préremplissage public |
| `routes/web.php` | modifié | Remplacement du bloc WIP par 6 routes `inscription.existant.*` (throttles : recherche 5/min, vérification 10/min, soumission 5/min ; CSRF groupe web) |
| `resources/views/inscription/existant/{accueil,formulaire,confirmation}.blade.php` | **nouveau** | Parcours UI complet, gabarit `layouts.app` |
| `resources/views/inscription/create.blade.php` | modifié | Modale « dossier existant » : plus aucun préremplissage — email masqué + CTA vers le parcours sécurisé + raccourci « Se connecter » conservé ; JS Alpine adapté |
| `app/Http/Requests/ExistingInscriptionRequest.php` | **supprimé** | WIP orphelin sans aucune référence (0 usage vérifié), remplacé par la nouvelle FormRequest |
| `tests/Feature/DossierExistantParcoursTest.php` | **nouveau** | 29 tests / 202 assertions (§E6) |
| `tests/Feature/CinPrefillTest.php` | modifié | 4 tests adaptés au nouveau contrat sécurisé de `verifierCin` (aucun DTO de données avant vérification) — l'anti-énumération, le second facteur, le rejet SQLi, le throttling, CSRF et non-écriture restent testés à l'identique |

Total : 8 fichiers créés, 5 modifiés, 1 supprimé. Migrations : **aucune** (aucun changement de schéma).

## E4. Règles d'autorisation et de sécurité

- **CIN + date de naissance ≠ preuve d'identité** : aucune donnée du dossier (email complet, téléphone,
  adresse, statut, matricule) ne quitte le serveur avant la validation du code à usage unique.
- **Réponses neutres et identiques** (recherche) : dossier existant, introuvable, ou CIN correct avec
  date incorrecte → même message, même redirection ; aucun oracle d'existence. Le code part
  uniquement vers l'adresse **déjà enregistrée** (jamais vers une adresse saisie).
- **Code OTP** : stocké **hashé** (SHA-256), TTL 10 min, max 5 essais (invalidation définitive au-delà),
  cooldown 60 s entre envois, usage unique (supprimé après succès).
- **Autorisation temporaire** : jeton opaque 48 hex, stocké **hashé** en cache, lié à un `etudiant_id`,
  TTL 15 min, **usage unique garanti par `Cache::pull` atomique** (deux soumissions concurrentes :
  une seule passe). Placée en session navigateur, jamais dans l'URL. Aucune session authentifiée
  générale n'est créée (`assertGuest()` + `etudiant_auth_id` absent testés).
- **Anti-IDOR structurel** : le dossier traité est résolu depuis le jeton (jamais depuis
  `etudiant_id`/`id` transmis par le navigateur) — testé (IDOR ignoré).
- **Whitelist de champs** (`DossierExistantService::CHAMPS_MODIFIABLES`) : `telephone`, `adresse`,
  `ville`, `code_postal`. CIN, nom, prénom, sexe, date de naissance, email, statut, faculté,
  programme, niveau, année, mot de passe : **jamais modifiables par ce parcours**, même présents
  dans la requête (testé). Aucun mass assignment ($request->all() jamais utilisé).
- **Throttling** : par IP (routes 5-10/min) **et** par identité (`RateLimiter` hashé, 5 recherches/15 min,
  réponse inchangée au-delà).
- **Gestion des statuts** (alignée sur `DossierComplementService`) : `en_attente` → modification
  autorisée (rechargement sous `lockForUpdate`, le statut est relu au moment de l'écriture) ;
  `valide` → consultation possible, modification refusée avec orientation scolarité ;
  `rejete` → consultation possible, aucune réinitialisation silencieuse. Le statut n'est
  JAMAIS modifié par le parcours.
- **Idempotence** : autorisation consommée avant l'écriture ⇒ rejeu de la même requête refusé,
  aucun nouveau dossier, aucune confirmation en double (testé).
- **Historique** : `historique_academique` (action `modification_dossier_public`, ancienne/nouvelle
  valeur JSON par champ) + trace de vérification d'identité. Aucune PII dans les clés de cache ni
  dans les logs.

## E5. Formulaire prérempli — comportement

- Données **autorisées** affichées : identité (CIN **masquée** `12***678`, nom, prénom, date de
  naissance), email (lu tel quel, non modifiable ici), faculté, niveau, année, statut, référence
  `num_inscription` ; **modifiables** : téléphone, adresse, ville, code postal.
- Pièces justificatives : état **réel** de la GED via `DocumentsRequisService::checklistDossier`
  (valide / expirée / en attente / refusée avec motif / non fournie) — lecture seule, les pièces
  antérieures sont préservées (aucun remplacement par ce parcours).
- Écrans finaux : confirmation (« demande enregistrée » + référence + prochaines étapes + contact
  scolarité) **sans** CIN complet, date de naissance, mot de passe ni document.

## E6. Fiabilité de l'email

- OTP : envoi **synchrone** (l'étudiant attend le code), uniquement après persistance du code en cache.
- Email final : notification Laravel **après le commit** de la transaction, `ShouldQueue` sur la file
  `notifications` (retries 3, backoff de l'architecture existante). Un échec d'envoi est **non
  bloquant** : dossier conservé, historique écrit, écran final affiche « envoi en attente de nouvelle
  tentative » (testé avec transport en panne simulé) — « demande enregistrée » et « email envoyé »
  sont explicitement distingués.
- Contenu sans PII (testé) : objet imposé, référence `num_inscription`, étapes, mention « en attente
  de traitement administratif », contact scolarité (`config('inscriptions.contact_scolarite')`).
  Jamais de CIN complet, date de naissance, mot de passe ou code de vérification.
- Changement d'email : **non permis** dans ce parcours (§4 du cahier des charges : un email saisi à la
  volée ne peut pas être le destinataire du code). Toute rectification d'adresse passe par la
  procédure administrative — réserve R-E2 ci-dessous.

## E7. Tests exécutés (résultats réels)

| Commande | Résultat observé |
|---|---|
| `php artisan test --filter="DossierExistantParcoursTest"` | **29/29 PASS, 202 assertions** (nominal, sécurité, email, contrat) |
| `php artisan test --filter="CinPrefillTest"` | 27/27 PASS (contrat sécurisé adapté, anti-énumération conservée) |
| Batterie ciblée 18 classes (CinPrefill, DossierExistant, MENS ×2, sécurité transversale, inscriptions ×6, périodes, suivi, compléments, parcours complet bout-en-bout, FAQ, checklist, doublons) | **213/213 PASS, 1226 assertions** |
| `vendor/bin/pint --dirty` (après correction des 10 fichiers du périmètre) | PASS, aucun écart de style |
| `php artisan test --compact` (suite **complète**, sqlite :memory:) | **3178/3204 PASS, 22 SKIP, 4 FAIL pré-existants** (245 s) |
| `php artisan route:list --path=inscription/existant` | 6 routes résolues, throttles visibles |

Échecs complets restants — **identiques à ceux prouvés pré-existants dans le rapport du cycle
précédent (§6)**, tous des seuils de requêtes N+1 hors périmètre : `InscriptionFileTraitementTest`
(25 < 25), `InscriptionPerformanceFiabiliteTest` (25), `PopulationPhase2CompleteTest` (23 vs ≤ 21),
`Security/ArchivesFicheLoop3Test` (25 < 25). Aucun test supprimé, désactivé ni affaibli ; les
adaptations de `CinPrefillTest` (4 tests) matérialisent le changement de contrat exigé par la
mission (fin de la fuite de données avant vérification).

Couverture des scénarios obligatoires (§5 du cahier des charges) :
- **Nominal** : recherche → code → formulaire prérempli → correction autorisée → soumission →
  historique → email après commit → écran final (1 test de bout en bout + historique détaillé).
- **Sécurité** : CIN correct + date incorrecte ; limitation par IP et par identité ; code expiré,
  invalide, épuisé, déjà utilisé ; accès direct au formulaire sans vérification ; IDOR
  (`etudiant_id` injecté) ; champs protégés ; dossier valide / rejeté ; soumissions concurrentes ;
  rejeu après succès. Toutes vertes.
- **Email/cohérence** : échec de validation ⇒ aucun email ; échec d'écriture ⇒ aucune notification de
  succès ; échec d'envoi après commit ⇒ dossier conservé + état « en attente » affiché ; notification
  finale en file (retry, jamais de duplication métier) ; email jamais envoyé à une adresse librement
  saisie. Toutes vertes.
- **Non-régression** : nouvelle inscription, doublons, MENS/éligibilité, suivi, décisions
  administratives, affectation après validation, permissions portails — couvertes par la suite
  complète (3178 verts, mêmes 4 échecs pré-existants que sans cette extension).

## E8. Contrôles non exécutés et réserves

- **NOT_EXECUTED** — Navigateur réel : aucun navigateur disponible dans cet environnement ; le
  parcours est prouvé par les tests HTTP (sessions, CSRF, redirections) et la revue des vues, jamais
  substitué par du faux.
- **NOT_EXECUTED** — Livraison SMTP réelle : `MAIL_MAILER=array` en test et canal de notification
  in-app simulé (`CanalNotificationExterneSimule`, architecture LOOP 12 existante). Les deux
  notifications utilisent le canal `mail` Laravel (SMTP réel en production), mais la remise effective
  n'a pas pu être démontrée ici.
- **NOT_EXECUTED** — Concurrence MySQL réelle (SQLite `:memory:`) : la consumption atomique du jeton
  est prouvée par contrat (`Cache::pull` + double appel), pas par une course MySQL mesurée.
- **R-E1** (décision documentée) — Période d'inscription : la modification des coordonnées d'un
  dossier existant n'est **pas** bloquée hors période (une mise à jour n'est pas une création ;
  `verifierCreationPublique` reste appliqué à la création). Arbitrage métier requis si l'université
  souhaite verrouiller les mises à jour hors période.
- **R-E2** (décision documentée) — Email non modifiable en ligne dans ce parcours : le changement
  d'adresse exige la procédure administrative (vérification de la nouvelle adresse + notification de
  l'ancienne, non implémentés ici).
- **R-E3** (réserve mineure) — `identifier`/`verifierCin` retournent l'email **masqué** sur
  correspondance CIN+naissance (pattern déjà établi et testé du wizard) : oracle d'existence faible,
  mitigé par l'OTP obligatoire et le throttling par identité. La page du parcours public, elle,
  n'expose strictement rien avant vérification.
- **R-E4** (réserve mineure) — Un dossier sans email exploitable (cas limite : saisie administrative
  incomplète) ne peut pas recevoir de code ; la réponse reste neutre et l'étudiant est orienté vers
  la scolarité (fail-closed, aucune donnée exposée).
- **R-N+1** — Les 4 échecs N+1 pré-existants du rapport §6 restent en réserve (règle : aucun
  affaiblissement de seuil pour obtenir du vert).

## E9. Verdict de l'extension

**`PASS_WITH_RESERVATIONS`** — Parcours public sans login complet (recherche neutre → OTP vers
l'adresse enregistrée → formulaire prérempli whitelisté → soumission transactionnelle historisée →
email final après commit), 29 tests sécurité/nominal verts, 213/213 en batterie ciblée, suite
complète sans aucune régression nouvelle (4 échecs N+1 prouvés pré-existants et conservés). Les
réserves portent sur les preuves environnementales (navigateur, SMTP réel, concurrence MySQL) et
deux arbitrages métier documentés (R-E1, R-E2) — aucune décision inventée, aucune donnée exposée
avant vérification, aucun statut modifié automatiquement, aucun second dossier créé.

---

## 11. Extension LOOP — Dossier étudiant existant, formulaire sans login et email final (2026-10-13)

**Cycle :** PLAN → DEV → TEST → VALIDATION → AUTO-PASSAGE — exécuté en une passe.
**État initial trouvé :** le parcours existait déjà (héritage d'une loop précédente, fichiers **non suivis
par git** — `??` : `DossierExistantController.php`, `DossierExistantService.php`,
`DossierExistantModificationRequest.php`, les 2 notifications, les 3 vues `inscription/existant/*`, le test
`DossierExistantParcoursTest.php` ; routes `inscription.existant.*` déjà déclarées dans `routes/web.php:59,
167-181`). Aucun commit effectué par cette loop (interdit sans autorisation), comme par la précédente.

### 11.1. PLAN — inspection réelle (constats, pas de réécriture sans preuve)

| Composant | Fichier relu | Constat |
|---|---|---|
| Recherche neutre | `DossierExistantController::rechercher` + `DossierExistantService::rechercher` | Réponse strictement identique existe/non-existe (anti-énumération) ; code 6 chiffres hashé SHA-256, TTL 600 s, 5 essais max, cooldown renvoi 60 s, throttling IP (routes 5,1/10,1) + identité (`MAX_RECHERCHES` 5/15 min, clé hashée sans PII) — CONFORME, non touché |
| Autorisation temporaire | `emettreAutorisation` / `autorisationValide` / `consommerAutorisation` (Cache::pull) | Opaque, liée au dossier, TTL 900 s, usage unique, jamais de session authentifiée (`assertGuest` testé) — CONFORME, non touché |
| Anti-IDOR structurel | `enregistrer()` : dossier rechargé depuis le jeton, `etudiant_id`/`id` de la requête ignorés (testé) | CONFORME, non touché |
| Formulaire prérempli | `donneesFormulaire()` (whitelist, CIN masquée, jamais password/hash) + `formulaire.blade.php` | CONFORME, non touché (hors §11.2-C) |
| Whitelist d'écriture | `CHAMPS_MODIFIABLES = telephone/adresse/ville/code_postal` ; `array_intersect_key(validated())`, jamais `$request->all()` ; statut jamais modifié ici ; email immuable (procédure administrative) | CONFORME, non touché |
| Transaction + historique | `lockForUpdate` + garde valide/rejete + `AuditService::tracer('modification_dossier_public')` ancienne/nouvelle valeur | CONFORME, non touché (hors ajout garde période) |
| Email après commit | `notifierConfirmation()` appelé APRÈS retour de transaction ; `ConfirmationMiseAJourDossierExistant` ShouldQueue file `notifications` ; écran final distingue « enregistrée » / « email en attente » ; contenu sans CIN/DDN/mot de passe/code (testé) | CONFORME, non touché |
| **G1 — période non revalidée** | `enregistrerModification()` : aucune garde de période (§3.3 du cahier : « Revalider … la période d'inscription ») ; formulaire affiché même période fermée | **ÉCART CONFIRMÉ → corrigé §11.2-A** |
| **G2 — normalisation CIN incohérente** | `rechercher()` : `mb_strtoupper(trim())` côté clé liée vs `REPLACE(UPPER(TRIM(cin)),' ','')` côté SQL (la création supprime les espaces) ; `cleRecherche()` idem | **ÉCART CONFIRMÉ → corrigé §11.2-B** |
| Oracle legacy `verifierCin`/`identifier` | `InscriptionController::verifierCin` retourne `{exists:true, email_masque}` vs `{exists:false}` ; `identifier` retourne `EXISTING_STUDENT` — signaux d'existence avant vérification renforcée, contrairement au §3.1 (message neutre) | Écart réel mais **non modifié** : casserait le wizard `create.blade.php:1180`/`app.js:119` + ~10 tests (`CinPrefillTest`, `MensInscriptionIntelligenteTest`, …) ; le parcours canonique neutre est `inscription.existant.*` → **R-E1** |
| Atomicité `Cache::pull` sous Redis | `consommerAutorisation()` : `pull` = lecture+suppression non atomique sur driver Redis (atomique en array/file de test) | **Non prouvable ici → R-E2** |
| Email réel (SMTP) | Tests en `Notification::fake()` / file sync ; transport réel jamais exercé dans cet environnement | **NOT_EXECUTED → R-E3** |

### 11.2. DEV — corrections réalisées (2 fichiers produit + 3 tests)

**A. Garde de période serveur (`DossierExistantService` + `DossierExistantController::formulaire`)**
- `DossierExistantService` reçoit `PeriodeInscriptionService` (autowiring, seul point de construction :
  contrôleur + `app()` des tests — aucun `new` manuel en code, vérifié par recherche).
- Nouvelle méthode `periodeAutorisee(Etudiant)` : année résolue depuis `annee_academique` (repli année
  active) ; sans référentiel d'année → autorisé (transition legacy, jamais de blocage sur référentiel
  incomplet) ; évalue `evaluer()` sur `[null, premiere, reinscription, transfert]` (le dossier ne porte
  pas son type ; un typeless = tous suffit) ; `SANS_PERIODE` reste autorisé (cohérent avec `evaluer`) ;
  seul un CLOSED prouvé refuse.
- `enregistrerModification()` : garde relue **sous verrou, dans la transaction**, après les gardes de
  statut → `RuntimeException('Période d'inscription fermée…')`, déjà captée par le contrôleur
  (redirect accueil + message, aucune donnée altérée, aucun email).
- `formulaire()` : `dossierModifiable = en_attente && periodeOuverte` ; message dédié « actuellement
  fermées » quand seul la période bloque (statuts valide/rejete inchangés).

**B. Normalisation CIN unifiée** — `normaliserCin()` privée (majuscules + espaces supprimés, identique à
`InscriptionController::store`) utilisée par `rechercher()` et `cleRecherche()`. `VerifierCinRequest`
rejette déjà les espaces au bord HTTP : aucun comportement HTTP existant modifié.

**Non-modifications justifiées :** code à usage unique, autorisation, IDOR, whitelist, transaction,
historique, email-après-commit, distinction enregistré/envoyé — déjà conformes et testés ; règle : pas de
réécriture sans preuve.

### 11.3. TEST — résultats réels observés

| Commande exacte | Résultat réel |
|---|---|
| `php artisan test --filter="DossierExistantParcoursTest"` | **32/32 PASS, 224 assertions** (29 hérités + 3 nouveaux : `…periode_fermee_dossier_intact_sans_email`, `…masque_le_bloc_modifiable_quand_periode_fermee`, `…normalise_le_cin_avec_espaces_comme_la_creation`) |
| `php artisan test --filter="PeriodeInscriptionMissionTest\|PeriodeInscriptionTest\|CinPrefillTest\|InscriptionEtudiantsTest\|MensInscriptionIntelligenteTest\|SuiviPublic"` | **70/70 PASS, 331 assertions** |
| `vendor/bin/pint --test` (3 fichiers de la loop) | PASS |
| `php artisan test --compact` (suite complète, sqlite :memory:) | **3178/3204 PASS, 22 SKIP, 4 FAIL** — les 4 sont **byte-identiques** aux seuils N+1 pré-existants du §6 (`InscriptionFileTraitementTest`, `InscriptionPerformanceFiabiliteTest`, `PopulationPhase2CompleteTest::test_dashboard_ventilations_bornees`, `ArchivesFicheLoop3Test::test_fiche_sans_n_plus_un` ; 25<25 ×3, 23≤21 ×1). Preuve de non-causalité : aucun des 4 fichiers de test ne référence `DossierExistant*` (recherche négative vérifiée ; seul faux positif : un prénom factice « DossierExistant » dans `InscriptionSecuriteTransversaleTest:416`) ; mes diffs ne touchent que le parcours `inscription.existant.*`, jamais résolu par ces tests. Preuve par stash impossible : les fichiers du parcours sont **untracked** (héritage, §11.0) — documenté au lieu d'être contourné. |
| Parcours navigateur réel (§3.4 en 11 étapes), concurrence MySQL, SMTP réel | **NOT_EXECUTED** (aucun navigateur/service/MySQL disponible ; jamais substitués) |

Scénarios du cahier couverts par tests verts : nominal complet (recherche→code→prérempli→correction→
soumission→historique→email après commit, second dossier jamais créé) ; CIN+DDN incorrecte ; throttling
route (429 après 5) + identité ; code expiré/invalide/consommé/épuisé ; accès direct formulaire/confirmation ;
expiration autorisation ; IDOR (`etudiant_id` injecté ignoré) ; champs protégés intacts (dont password) ;
valide/rejete non modifiables sans réinitialisation ; rejeu après succès (1 seul email) ; concurrence
(`consommerAutorisation` : 1er OK, 2nd null) ; validation en échec (autorisation conservée, 0 email) ;
transaction en échec (données intactes, 0 email) ; panne SMTP post-commit (dossier conservé + écran
« en attente de nouvelle tentative ») ; contenu email (référence + prochaines étapes + scolarité, sans PII) ;
CSRF/web ; file `notifications` ; historique ancienne/nouvelle valeur ; aucune fuite avant vérification ;
**nouveau : période fermée (écriture refusée + formulaire masqué), normalisation CIN espacée**.

### 11.4. VALIDATION, réserves et verdict de l'extension

- Routes publiques et middleware : inchangées (`web.php:167-181`, throttles 5,1/10,1, POST seuls, CSRF
  groupe web testé) — PASS.
- Protection IDOR / expiration / usage unique / transactions / unicité : testées — PASS (réserve R-E2).
- Email après commit + distinction enregistré/envoyé : testés en file fake — PASS avec réserve R-E3.
- Non-régression : 3178/3204, 4 échecs prouvés hors périmètre — PASS_WITH_RESERVATIONS.
- Production : aucune modification, migration, ni validation — hors périmètre respecté.

**Réserves restantes :** R-E1 (oracles `verifierCin`/`identifier` — arbitrage produit : neutraliser casserait
le wizard ; parcours neutre canonique = `inscription.existant.*`) ; R-E2 (atomicité `Cache::pull` Redis —
recommandation : verrou `Cache::lock` ou jeton en BD si Redis devient le driver) ; R-E3 (transport SMTP
réel + politique de retries = défaut de file, contenu idempotent) ; R-E4 (navigateur/MySQL NOT_EXECUTED) ;
R-E5 (4 N+1 pré-existants) ; R-E6 (fichiers du parcours untracked — à verser par le flux de commit
autorisé, jamais par cette loop).

**Verdict de l'extension : `PASS_WITH_RESERVATIONS`.**
Justification : sécurité du parcours sans login démontrée par 32 tests verts (dont 3 nouveaux pour les
écarts G1/G2 corrigés), non-régression démontrée (seuls échecs = 4 N+1 pré-existants hors causalité) ;
`PASS` interdit par le cahier tant que l'email réel (R-E3), la concurrence MySQL et le navigateur (R-E4)
ne sont pas démontrés, et R-E1 attend un arbitrage produit.

---

# EXTENSION LOOP 2 — Popup « Modifier mon adresse email » sur /inscription/existant (2026-10-13)

**Cycle :** PLAN → DEV → TEST → VALIDATION → AUTO-PASSAGE — exécuté en une passe.

## 12.1. PLAN — inspection et décision de conception

Composants inspectés : les 8 routes `inscription.existant.*`, `DossierExistantController`,
`DossierExistantService` (autorisation temporaire, OTP, gardes §11), `formulaire.blade.php`
(Alpine.js déjà utilisé sur la page wizard, Tailwind, gabarit `layouts.app`), `ProfilEtudiantRequest`
(normalisation email), pipeline notifications Laravel (canal mail), protections existantes (CSRF
groupe web, throttles IP, `RateLimiter` par identité, `Cache::pull` atomique).

**État initial :** une implémentation parallèle de la popup (FormRequests `DossierExistantEmail*`,
méthodes service/contrôleur, 3 notifications, vue popup, suite `DossierExistantEmailTest`) était déjà
présente dans l'arbre non commité. Relecture complète effectuée : l'architecture était conforme
(grant lié au dossier, anti-substitution, gardes statut/période, backstop UNIQUE en base, audit
masqué, notifications post-commit fail-soft). **Un écart de sécurité a été confirmé puis corrigé** :
le code de confirmation partait vers la **nouvelle** adresse, alors que le cahier §3 exige une preuve
via une **adresse déjà vérifiée**. Risque démontrable du choix initial : une faute de frappe sur la
nouvelle adresse aurait délivré le code à un tiers, capable de valider l'appropriation de l'adresse
du dossier — l'email étant identifiant de connexion à l'Espace Étudiant.

## 12.2. DEV — fichiers modifiés

| Fichier | Changement |
|---|---|
| `app/Services/DossierExistantService.php` | `demanderChangementEmail` : code envoyé à l'adresse **DÉJÀ VÉRIFIÉE** (`$etudiant->notify()` → adresse enregistrée), fail-closed si elle est inexploitable (orientation scolarité — la protection n'est jamais désactivée) ; docblock à jour. `confirmerChangementEmail` : consumption **atomique** (`Cache::pull`) — usage unique garanti en concurrence réelle ; tentative incorrecte = entrée reposée avec compteur d'essais incrémenté |
| `app/Notifications/CodeConfirmationChangementEmail.php` | **Renommée** depuis `CodeConfirmationNouvelEmail` + wording : le code part à l'adresse actuelle par sécurité ; la nouvelle adresse ne reçoit la confirmation qu'après le changement effectif |
| `app/Http/Controllers/DossierExistantController.php` | `demanderEmail` : flash cohérent (« code envoyé à votre adresse actuelle ») |
| `app/Http/Requests/DossierExistantEmailConfirmationRequest.php` | Docblock : code vers l'adresse déjà vérifiée, nouvelle adresse jamais reprise de la requête (anti-substitution) |
| `resources/views/inscription/existant/formulaire.blade.php` | Étapes 1-2 : « code envoyé à votre adresse actuelle (masquée) » + ligne de récupération (« accès perdu → scolarité ») |
| `tests/Feature/DossierExistantEmailTest.php` | Adapté au contrat corrigé + 3 tests ajoutés : concurrence réelle sur le code, fail-closed sans adresse vérifiée, popup absente de l'accueil |

Fonctionnel de la popup (inchangé, relu et testé) : accessible uniquement post-vérification
(bouton « Modifier mon adresse email »), titre exigé, 2 champs (format + `confirmed` + max 254 +
normalisation minuscules/trim), boutons « Enregistrer » / « Annuler », état de chargement,
prévention des doubles clics (`:disabled="chargement"`), fermeture Échap + Annuler, erreurs
accessibles (`role="alert"`), formulaire principal préservé (PRG + reprise d'étape par flash),
ancienne adresse jamais complète (masquée partout), aucune route ou composant doublon.

## 12.3. Sécurité de la modification (recap vérifié par tests)

Autorisation temporaire liée au dossier (dossier TOUJOURS résolu depuis le jeton de session, jamais
depuis la requête) · expiration 15 min du jeton / 30 min du code · essais de code bornés (5) ·
cooldown 60 s entre demandes · throttle IP · CSRF groupe web · validation 100 % serveur · unicité
insensible à la casse (contrôle applicatif + contrainte UNIQUE en base en backstop) · seul le champ
`email` modifiable · anti-substitution (la nouvelle adresse est liée au code côté serveur) · code
usage unique ATOMIQUE (concurrence : un seul flux applique) · gardes statut `en_attente` + période
ouverte relues sous verrou au moment de l'écriture · audit masqué (`changement_email_public`,
adresses masquées uniquement) · notifications post-commit fail-soft (alerte à l'ancienne adresse +
confirmation à la nouvelle, contenus sans PII) · l'adresse n'est effective qu'après le code ·
parcours de récupération scolarité si accès perdu à l'ancienne adresse.

## 12.4. Tests exécutés (résultats réels)

| Commande | Résultat observé |
|---|---|
| `php artisan test --filter="DossierExistantEmailTest"` | **25/25 PASS, 252 assertions** |
| Batterie ciblée 13 classes (email, parcours, CinPrefill, MENS ×2, sécurité transversale, inscriptions ×4, wizard, périodes, suivi, compléments) | **187/187 PASS, 1132 assertions** |
| `vendor/bin/pint --test --dirty` | PASS |
| `php artisan route:list --path=inscription/existant` | 8 routes résolues (`email` + `email/confirmer` : POST, throttle, web/CSRF) |
| Suite complète `php artisan test --compact` | **3203/3229 PASS, 22 SKIP, 4 FAIL** — mêmes 4 échecs N+1 pré-existants que sans cette extension (aucune régression nouvelle) |

Couverture des cas obligatoires (§5) : CIN+DOB corrects → popup accessible après vérification ;
CIN incorrect / DOB incorrecte → aucun accès ; nouvelle adresse valide (nominal de bout en bout :
DB, audit masqué, alerte ancienne + confirmation nouvelle, poursuite du parcours) ; confirmation
différente refusée ; email déjà utilisé refusé (casse-insensible) ; code invalide / expiré / épuisé /
déjà utilisé refusés ; appel direct sans jeton refusé ; identifiant dossier et autres champs ignorés ;
débit (cooldown renvoi + throttle route 429) ; CSRF ; soumission répétée + concurrence (pull
atomique : un seul flux applique, le parcours se poursuit) ; échec de notification (dossier conservé,
état « en attente » signalé, aucun cooldown bloquant sur échec d'envoi du code) ; conservation du
comportement du parcours existant (batterie complète).

## 12.5. Réserves et verdict

- **NOT_EXECUTED** — Popup en navigateur réel (aucun navigateur disponible) : ouverture, focus,
  fermeture Échap, états de chargement vérifiés par revue du code Alpine et du markup accessible
  (`role="dialog"`, `aria-modal`, `aria-labelledby`, `role="alert"`), jamais substitués par du faux.
- **NOT_EXECUTED** — Remise SMTP réelle (`MAIL_MAILER=array` en test ; le canal mail Laravel est
  celui de la production, mais la remise effective n'est pas démontrée ici).
- **R-E7** — Le jeton principal du parcours n'est PAS consommé par le changement d'email (le parcours
  coordonnées se poursuit) : l'autorisation temporaire du changement (code + entrée liée, usage
  unique atomique) est, elle, invalidée après succès. Choix documenté.
- **R-E8** — L'alerte vers l'ancienne adresse est fail-soft : si elle échoue, le changement reste
  appliqué et l'état « notifications en attente » est signalé à l'utilisateur (politique LOOP 12).

**Verdict : `PASS_WITH_RESERVATIONS`** — Popup fonctionnelle et sécurisée (25/25 tests dédiés,
187/187 batterie ciblée, 3203/3229 suite complète sans régression nouvelle). L'écart de conception
initialement présent (code vers la nouvelle adresse) a été corrigé vers la preuve exigée par le
cahier (code vers l'adresse déjà vérifiée, fail-closed sans elle, récupération par la scolarité).
Réserves : preuves navigateur/SMTP non exécutées ; verdict `PASS` interdit tant que ces preuves
environnementales ne sont pas démontrées.

## 12.6. CORRECTION UTILISATEUR — « la popup ne s'affiche pas » (post-validation, même jour)

**Diagnostic en conditions réelles** (Chrome headless + CDP, serveur local réel) : le markup et le
JS de la popup étaient corrects — la popup s'ouvre bien au clic pour un dossier `en_attente`
(bouton → modale `display:flex`, titre exact, 2 champs, ancienne adresse jamais en clair).
La cause du signalement : la garde `$dossierModifiable` (statut ≠ `en_attente`, typiquement un
dossier **validé**) masquait la section email ET la popup — or la mission n'impose aucune
restriction de statut pour l'accès à la popup (elle exige seulement de « vérifier le statut courant »).

**Correction** (2 gardes distinctes, jamais de contournement serveur) :
- `app/Http/Controllers/DossierExistantController.php` : nouvelle variable `emailModifiable`
  (statut `en_attente` **ou** `valide`) pour la section email + popup ; `dossierModifiable`
  (coordonnées, garde inchangée : `en_attente` + période ouverte) ; message « dossier validé »
  reformulé (seul l'email reste modifiable en ligne).
- `app/Services/DossierExistantService.php` : garde de statut alignée dans
  `demanderChangementEmail` et `confirmerChangementEmail` (rejete seul interdit → scolarité) ;
  docblock justifié (l'email est une coordonnée de contact, pas une donnée académique ; la double
  preuve + audit + notifications restent intacts).
- Vue : les blocs email/popup passent sur `$emailModifiable`.
- `tests/Feature/DossierExistantEmailTest.php` : `test_dossier_valide_refuse_sans_reinitialisation`
  remplacé par `test_dossier_valide_peut_modifier_son_email_avec_double_preuve` (popup proposée,
  changement appliqué, statut jamais modifié, audit écrit, coordonnées toujours masquées) — **26/26 PASS**.

**Preuve navigateur réelle (dossier validé, Chrome headless + CDP)** : bouton présent → clic →
popup visible (`display:flex`, dimensions > 0), titre « Modifier mon adresse email », champs email +
confirmation présents, email affiché uniquement **masqué** (`pop******@exemple.test`), jamais
l'adresse complète. Batterie ciblée après correction : **202/202 PASS, 1244 assertions**.

**Réserves inchangées** : popup `rejete` non proposée (réouverture scolarité) — arbitrage conservé ;
garde période maintenue pour le changement d'email (période actuellement ouverte jusqu'au
11/12/2026 — arbitrage métier à confirmer pour les dossiers validés hors période) ; navigateur/SMTP
réels couverts par cette session de diagnostic pour l'AFFICHAGE, la remise email réelle reste
NOT_EXECUTED.

## 12.7. ITÉRATION DE REVALIDATION (2026-10-13, même jour — nouvelle passe LOOP)

**Cycle :** PLAN → DEV → TEST → VALIDATION — la popup étant déjà livrée (§12.1–12.6), cette passe
vérifie l'état réel, corrige les défauts sûrs et revalide intégralement.

**PLAN — inspection :** relecture complète des 8 routes `inscription.existant.*`, du contrôleur
(`demanderEmail`/`confirmerEmail`), du service (`demanderChangementEmail`/`confirmerChangementEmail`/
`notifierChangementEmail`), des 2 FormRequests, des 3 notifications, de la vue popup (Alpine.js) et
de la suite `DossierExistantEmailTest`. Aucun doublon créé : l'architecture conforme au cahier
(grant lié au dossier, code vers l'adresse déjà vérifiée, anti-substitution, gardes statut/période,
backstop UNIQUE, audit masqué, notifications post-commit fail-soft) est confirmée.

**DEV — fichier modifié (1, défaut documentaire sûr et démontré) :**

| Fichier | Changement |
|---|---|
| `routes/web.php` | Bloc de commentaire des routes email OBSOLÈTE : il décrivait encore le contrat initial (« code à usage unique vers la NOUVELLE adresse »), contredit par l'implémentation corrigée §12.2, par la notification (`CodeConfirmationChangementEmail` → `$etudiant->notify()`, adresse enregistrée) et par les tests (`Notification::assertNotSentTo(new AnonymousNotifiable, …)`). Corrigé vers le contrat réel : code vers l'adresse DÉJÀ VÉRIFIÉE (actuelle), nouvelle adresse liée au code côté serveur, appliquée après validation. Aucun changement de comportement (commentaire uniquement). |

**SÉCURITÉ — audit re-vérifié ce jour (par tests + revue de code) :** autorisation temporaire liée
exclusivement au dossier, résolue depuis le jeton de session (jamais de la requête —
`test_identifiant_dossier_et_autres_champs_sont_ignores`, `test_appel_direct_sans_autorisation_est_refuse`) ;
expiration courte (jeton 15 min, code 30 min) ; tentatives bornées (5 essais/code, cooldown 60 s,
throttles IP `5,1`/`10,1` confirmés par `route:list`) ; CSRF groupe web confirmé ; validation 100 %
serveur ; unicité casse-insensible + backstop UNIQUE ; normalisation minuscules/trim ; seul le champ
`email` modifiable ; invalidation atomique après succès (`Cache::pull`) ; audit masqué ; navigation
anti-énumération intacte (CIN/DOB incorrects → réponse neutre, aucun accès).

**TESTS exécutés (résultats réels, cette passe) :**

| Commande | Résultat observé |
|---|---|
| `php artisan test --filter=DossierExistantEmailTest` | **26/26 PASS, 280 assertions** (avant et après correctif) |
| Batterie ciblée `DossierExistantEmailTest\|DossierExistantParcoursTest\|LoopInscriptionConsolidationTest` | **62/62 PASS, 523 assertions** |
| `vendor/bin/pint --test --dirty` | **PASS** |
| `php artisan route:list --path=inscription/existant` | **8 routes résolues** ; audit middlewares : groupe `web` (CSRF) sur les 8, `ThrottleRequests` sur les 5 POST |
| Suite complète `php artisan test --compact` | **3204/3230 PASS, 22 SKIP, 4 FAIL** — exactement les 4 échecs N+1 pré-existants documentés R-E5 (`InscriptionFileTraitementTest::test_absence_de_requetes_n_plus_un_sur_la_file` 25≥25 ; `InscriptionPerformanceFiabiliteTest::test_file_administrative_absence_de_n_plus_un_sur_volume` 25≥25 ; `PopulationPhase2CompleteTest::test_dashboard_ventilations_bornees` 23>21 ; `Security\ArchivesFicheLoop3Test::test_fiche_sans_n_plus_un` 25≥25) — tous hors du parcours email ; aucune régression nouvelle |

**Réserves restantes :**
- **NOT_EXECUTED (cette passe)** — test navigateur : non réexécuté ici ; l'affichage réel (ouverture,
  titre, champs, adresse masquée) a été prouvé en Chrome headless §12.6 et le contrat markup/accès
  clavier reste couvert par `test_le_markup_de_la_popup_est_rendu_complet_et_accessible`.
- **NOT_EXECUTED** — remise SMTP réelle (`MAIL_MAILER=array` en test).
- **R-E5 inchangé** — les 4 échecs N+1 pré-existants (budgets de requêtes sur file d'inscription,
  dashboard, fiche archives) relèvent d'autres modules du arbre non commité ; hors périmètre de
  cette loop, non corrigés ici.
- **R-E7 / R-E8 inchangés** — jeton principal non consommé par le changement d'email (parcours
  coordonnées se poursuit) ; alerte vers l'ancienne adresse fail-soft.

**Verdict : `PASS_WITH_RESERVATIONS`** — la popup et sa chaîne de sécurité sont complètes et
revalidées ce jour sans aucune régression (26/26 dédiés, 62/62 batterie, Pint vert, 8 routes et
middlewares conformes) ; le seul défaut trouvé (commentaire de route obsolète, contredisant le
contrat sécurisé réellement implémenté) a été corrigé et revalidé. `PASS` reste interdit par le
cahier tant que la remise SMTP réelle n'est pas démontrée et tant que les 4 échecs N+1 pré-existants
(R-E5) ne sont pas résolus par leurs loops propriétaires.

## 12.8. CORRECTION UTILISATEUR n°2 — « la popup modifier n'existe pas » (revalidation, même jour)

**Diagnostic en conditions réelles** (serveur live `localhost:8000`, Chrome système headless piloté
via puppeteer-core, parcours COMPLET : accueil → CIN `12345685` + DOB `2005-06-30` → code →
formulaire → clic réel) : **la popup existe et fonctionne** — bouton « Modifier mon adresse email »
présent sur la page du dossier, clic → modale `display:flex` VISIBLE (dimensions > 0), titre exact,
champs nouvel email + confirmation présents, boutons « Enregistrer »/« Annuler » (+ étape code),
ancienne adresse uniquement masquée, fermeture par Échap vérifiée. Rejoué avant ET après correction
de la vue : identique.

**Cause racine du signalement** (aucun défaut de code dans la popup) : la popup vit sur la page du
dossier, uniquement après la vérification du code envoyé à l'adresse DÉJÀ ENREGISTRÉE (§3 — jamais
avant : l'afficher à l'étape CIN+DOB créerait un oracle d'existence interdit par le cahier §2.3).
En local, les dossiers de test portent des adresses FACTICES (`@etudiant.univ.tn`) : l'envoi Gmail
SMTP fonctionne (preuve : `Message-ID: …@gmail.com` dans le log, test admin « Test UniRegistre »
réussi à 21:54) mais les boîtes n'existent pas → le code n'est jamais reçu → l'utilisateur reste
bloqué à l'étape 2 sans jamais atteindre la page de la popup, et AUCUN texte ne l'orientait vers elle.

**Corrections (défaut démontré de découvrabilité, sécurité inchangée) :**

| Fichier | Changement |
|---|---|
| `resources/views/inscription/existant/accueil.blade.php` | 2 textes STATIQUES NEUTRES (affichés que le dossier existe ou non — aucun oracle d'existence) : l'intro annonce « Après vérification de votre identité, vous accéderez à la page de votre dossier, où vous pourrez… mettre à jour votre adresse email » ; le rappel sous le formulaire de code précise « La mise à jour de votre adresse email se fait depuis la page de votre dossier, une fois votre identité vérifiée par ce code ». Aucun marqueur de la popup (libellé du bouton, `nouvel_email`) n'est ajouté à l'accueil — la popup reste exclusivement post-vérification |
| `tests/Feature/DossierExistantEmailTest.php` | `test_la_popup_n_existe_que_sur_le_formulaire_post_verification` renforcé : l'orientation est désormais CONTRACTUELLE (`assertStringContainsString('mettre à jour votre adresse email')`) — empêche toute régression silencieuse de la découvrabilité |

**Méthodologie du test navigateur** (reproductible) : Chrome système headless + puppeteer-core ;
parcours réel HTTP (CSRF + cookies de session database) ; l'OTP de l'étape 1 est simulé en écrasant
l'entrée de cache par un code connu (la réception email réelle est impossible avec les adresses
factices — c'est précisément la cause du signalement) ; clics par libellé exact ; assertions DOM
(display/dimensions/titre/champs/boutons/masquage) + Échap ; capture écran.

**Tests exécutés (résultats réels) :** batterie ciblée 3 classes **62/62 PASS, 524 assertions**
(assertion d'orientation incluse) ; `vendor/bin/pint --test --dirty` **PASS** ; test navigateur
complet rejoué après correction : **FONCTIONNE** (parcours, ouverture, champs, masquage, Échap).

**Comment tester le parcours complet en local** (documenté, `.env` inchangé — Gmail opérationnel) :
(a) utiliser un dossier de test dont l'adresse email est une vraie boîte consultable, ou
(b) basculer ponctuellement `MAIL_MAILER=log` et lire le code à 6 chiffres dans
`storage/logs/laravel.log`, puis le saisir à l'étape 2 — la popup apparaît sur la page du dossier.
Rappel : un dossier `rejete` (3 en base) n'offre volontairement pas la popup (réouverture scolarité).

**Statut des réserves :** la réserve « navigateur NOT_EXECUTED » de §12.7 est LEVÉE pour
l'affichage et l'interaction (prouvé 2 fois ce jour, avant et après correction). La remise SMTP
réelle reste NOT_EXECUTÉE sur des adresses de test factices ; R-E5 (4 N+1 pré-existants), R-E7 et
R-E8 inchangés.

### 12.8bis. Cause racine définitive du signalement (forensics de session, 2026-10-14)

L'utilisateur a re-testé après la correction de découvrabilité : page d'accueil correcte (textes
d'orientation servis), mais parcours toujours bloqué à l'étape 2. **Forensics de la table
`sessions`** (driver database, payload JSON base64) : la session du navigateur utilisateur contient
le flash `dossier_existant.code_envoye` mais **AUCUNE identité `dossier_existant.recherche`** —
preuve que la paire CIN + date de naissance soumise ne correspondait à **aucun dossier** (l'identité
n'est stockée que sur correspondance exacte). Aucun code n'était donc jamais envoyé, et la page
demeure neutre (anti-énumération §2.3) : l'utilisateur ne pouvait pas distinguer « pas de dossier »
de « code non reçu ». C'est la chaîne complète du signalement initial « la popup n'existe pas » :
mauvaise paire → pas de code → jamais la page du dossier → jamais la popup.

**Aides de test mises en place (données de test uniquement, documentées ici) :**
- Dossier id 2038 (CIN **12345678**, naissance **14/02/2005**, `en_attente` — celui suggéré par le
  placeholder du formulaire) : adresse email repointée vers la boîte réelle du développeur
  (`usermyb@gmail.com`, issue de la configuration SMTP du projet) pour permettre un test E2E réel.
- Recherche réelle exécutée avec cette paire sur le serveur live : identité correctement stockée en
  session (`dossier_existant.recherche = {cin: 12345678, date_naissance: 2005-02-14}`), code à
  usage unique généré (cache hashé, TTL 600 s) et **envoi SMTP réel déclenché vers la boîte
  réelle sans aucune erreur de transport dans les logs** — la réception effective en boîte reste à
  confirmer par l'utilisateur (jamais affirmée sans observation).

**Réserves mises à jour :** l'affichage et l'interaction navigateur restent prouvés (§12.8). La
remise SMTP réelle dispose désormais d'un canal observable (boîte réelle) : si l'utilisateur
confirme la réception du code, la réserve R-E3 sur CE parcours pourra être levée sur observation ;
sans confirmation, elle reste NOT_EXECUTED.
