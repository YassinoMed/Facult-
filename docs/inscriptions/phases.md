# Phases d'inscription — chaîne complète détaillée

> Source de vérité : ce document décrit la chaîne réellement implémentée.
> Fichiers cités `chemin:ligne` (état au 03/10/2026). Complète
> `workflow.md` (cycle de vie), `rules.md` (règles), `re-enrollment.md`,
> `transfers.md` et `api.md` du même dossier.

```
CIN + DATE NAISSANCE
        ↓
IDENTIFICATION (MENS / locale)
        ↓
PÉRIODE OUVERTE ?
        ↓
DOCUMENTS OBLIGATOIRES COMPLETS ?
        ↓
VÉRIFICATION INTELLIGENTE (CONFORME ?)
        ↓
VALIDATION
        ↓
INSCRIPTION
        ↓
AFFECTATION AUTOMATIQUE
        ↓
AUDIT + NOTIFICATION
```

Règle absolue : **aucune couche frontend ne contourne la chaîne**.
Chaque phase est re-vérifiée côté serveur au moment où elle engage.

---

## Phase 0 — Référentiel MENS (avant campagne)

L'établissement importe le fichier officiel **avant** l'ouverture.

| Étape | Implémentation |
|---|---|
| Table source | `mens_references` (`database/migrations/2026_10_03_085054_*`) : CIN normalisé + original, arabe, matricule national, année, hash ligne, unique `[cin_normalise, annee]` |
| Import contrôlé | `MensImportDefinition` (`app/Services/DataExchange/Definitions/`) via DataExchange : upload → validation → prévisualisation → import transactionnel → rapport ; upsert idempotent, jamais de vidage |
| Déclaration | `DefinitionRegistry::IMPORTS` + whitelist `routes/web.php` (`referentiel-mens`) ; permission `data.import` existante |
| Traçabilité | `source_hash` par ligne + `ImportReport` (fichier, auteur, référence externe) |

Le MENS est une **référence** : il préremplit et identifie, il n'écrase
jamais silencieusement le dossier local (divergences → arbitrage, §Phase 4).

---

## Phase 1 — Identification (CIN + date de naissance)

L'étudiant commence avec **seulement CIN + date de naissance**.

| Étape | Implémentation |
|---|---|
| Service | `IdentificationIntelligenteService::identifier()` (`app/Services/`) |
| Normalisation CIN | `MensReference::normaliserCin()` : trim, espaces réduites, majuscules ; original conservé |
| Porte publique | `POST /inscription/identifier` → `InscriptionController::identifier`, `VerifierCinRequest` (CIN + naissance obligatoire), throttle `5,1`, CSRF |
| Porte locale existante | `POST /inscription/verifier-cin` (préremplissage, throttle `10,1`) — inchangée |

### Statuts possibles

| Statut | Sens | Formulaire |
|---|---|---|
| `INCONNU` | Ni MENS ni local (ou date incorrecte — réponse **indistinguable**, anti-énumération) | Complet (nouvel étudiant) |
| `MENS_SEUL` | Référence nationale, pas de dossier local | Prérempli MENS, dossier créé par la procédure (jamais à la recherche seule) |
| `LOCAL_SEUL` | Dossier établissement, pas de référence MENS | Réduit (dossier + historique) |
| `MENS_ET_LOCAL` | Les deux | Réduit + comparaison ; divergences signalées, **priorité au dossier local validé** |

### Divergences

Comparaison champ à champ (nom, prénom) après normalisation :
`À_VÉRIFIER` (correction workflowisée ultérieure), `BLOQUANT` si deux
dossiers locaux partagent le CIN (jamais fusionnés). Journalisées en
`Log::debug` **sans PII** (`mens.identification`).

---

## Phase 2 — Période d'inscription (source de vérité)

Une inscription n'existe que si une période l'autorise.

| Étape | Implémentation |
|---|---|
| Table | `registration_periods` : année, `nom`, portée nullable (faculté/programme/niveau/type/étudiant), `normale`/`exception`, `opened_at`/`closed_at` (bornes **inclusives**, UTC), motif obligatoire pour exception, `active`/`revoquee`/`utilisee` |
| Service unique | `PeriodeInscriptionService` — GET, POST, page et API utilisent **le même** `evaluer()` |
| Priorité | `NORMALE OUVERTE` → `OPEN` ; sinon exception applicable → `EXCEPTION_OPEN` ; sinon `CLOSED` ; rien configuré → `NOT_CONFIGURED` |
| Gardes création | `ArchiveService::creerInscription()` (première) + `InscriptionService::reinscrire()` (réinscription/progression) : vérification **avant** + **re-vérification verrouillée dans** la transaction (`lockForUpdate`) |
| Page publique | `GET /` → `etatGlobal()` ; `/inscription/periode-etat` (throttle `30,1`, sans PII) ; `POST /inscription` revalide (`verifierCreationPublique`) |
| Admin | `admin.periodes.*` (`configuration.manage`) : création, révocation/prolongation motivées, filtres, compteurs, chevauchements incompatibles refusés |
| API v1 | `GET students/{student}/registration-period` (scope `archives:read`, BOLA 404, openapi.yaml) |

Erreur métier : `REGISTRATION_PERIOD_CLOSED`. Une exception individuelle
est à usage unique (`utilisee`, verrouillée).

### Fuseau et bornes (Lot 3)

`config('inscriptions.fuseau')` (`Africa/Tunis`, surcharge `INS_FUSEAU`) :
saisie interprétée locale (date simple → 00:00:00 / 23:59:59, datetime →
heure locale), **stockage UTC**, affichage reconverti. Comparaison à
bornes **inclusives** (`opened_at <= now <= closed_at`). Migration de
conversion `-1h` des lignes existantes (hypothèse : saisies en heure de
Tunis ; DST inexistante depuis 2009), réversible.

---

## Phase 3 — Documents obligatoires

| Étape | Implémentation |
|---|---|
| Matrice | `config/inscriptions.php` → `documents_requis_par_type` (`premiere`/`reinscription`/`transfert`, vide = rien imposé) ; repli global `documents_requis` |
| Moteur | `DocumentsRequisService::verifier()` : dernière version par `document_type` (GED `StudentDocument`) → `VALIDE` / `MANQUANT` / `EN_ATTENTE` / `REJETE` / `EXPIRE` (+ `progression: "x / y validés"`) |
| Réutilisation | Pièce `validated` non expirée = réutilisée, jamais redemandée |
| Règle | **Upload ≠ validé** : `pending`/`rejected`/`expired` bloquent |
| Moteur intelligent | 11e règle `documents.statuts` (informative) ; `DocumentsRequiredRule` historique conservée |

---

## Phase 4 — Vérification intelligente + centre de résolution

| Étape | Implémentation |
|---|---|
| Moteur | `VerificationEngine` : 11 règles, verdicts `CONFORME` / `A_VERIFIER` / `BLOQUANT`, fail-soft, lecture seule (prouvé : aucun `UPDATE` métier) |
| Fiche par blocage | `BlocageInscriptionService` : moteur + pièces → `{code, gravité, titre, constat, source, recommandation}` (ex. `MISSING_REQUIRED_DOCUMENT`, `IDENTITE_COMPLETE`) |
| Centre admin | `admin/verification/bloquees` (file + filtres + compteurs scopés) ; fiche `verification.show` (blocages + formulaire correction) ; compteurs dashboard |
| Correction contrôlée | `InscriptionCorrectionService` : niveau/programme/type, **motif obligatoire**, verrou, audit avant/après immuable, **re-vérification automatique**, inscription validée intouchable |
| Interdit | Aucun bouton « forcer » côté inscription (vérifié par audit) |

---

## Phase 5 — Validation (finalisation)

`InscriptionService::transiter($inscription, 'valide', …)` exécute, **dans l'ordre** :

1. Transition autorisée (`en_cours → valide`, verrou `lockForUpdate`, anti-concurrence).
2. `DocumentsRequisService::exigerPourValidation()` → `REGISTRATION_DOCUMENTS_INCOMPLETE` si écart.
3. `BlocageInscriptionService` → refus listant les codes si `BLOQUANT`.
4. Commit → `SituationEtudiant` + audit `modification_inscription` + notification centre (`inscription.valide`) + event `PopulationChanged`.
5. Event `InscriptionValidee` **après commit** → affectation automatique (listener catch-all : l'affectation n'invalide jamais l'inscription).

Controller : `InscriptionGestionController::valider` (Gate `update` + périmètre, `throttle:20,1`) affiche le bilan affectation ; `reaffecter` rejoue les mêmes règles (§26 du LOOP affectation).

Autres transitions : `rejeter` (→ `ajourne`, motif obligatoire), `reinscrire` (nouvelle année, anti-doublon unique `[etudiant, annee]` + idempotence concurrence), `progresser` (`AcademicProgressionService` : décision officielle → cible LMD, **jamais `niveau + 1`**, `fin_cycle` exige admission explicite).

### Relevés de notes (officiels)

`OfficialDocumentService` (catalogue `config/documents-officiels.php`,
jamais de type ad hoc) : `releve_notes` (annuel/inscription),
`releve_semestriel` (1 semestre, paramètre obligatoire),
`releve_cursus` (toutes inscriptions, années ordonnées), attestations
(`decision_requise` admis*, inscription active). Données = snapshot
figé (moteur `AcademicCalculationService` en amont, jamais recalculé
dans le gabarit) ; `contenu_hash` anti-régénération ; supersession
(`remplace`, jamais supprimé) ; `code_verification` opaque imprimé
(URL + code, pas de QR sans dépendance agréée) ; révocation motivée
(reste vérifiable `révoqué`) ; audit + notification centralisés.
- Masse : `GenerateOfficialDocumentsJob` (file, chunks 200, rapport) —
  bouton délibération (`deliberations.publish`) + **auto à la publication**
  via `GenererRelevesPublication` (désactivable
  `documents-officiels.generation_auto_publication`), jamais en HTTP.
- Portail : `etudiant/dossier/officiels` (liste + téléchargement, session
  seule faisant foi, 404/410).
- Vérification publique : `/verify/{code}` (type, statut, date, nom
  masqué, IP hachée).

---

## Phase 6 — Affectation automatique

Chaîne : `InscriptionValidee` → `TenterAffectationAutomatique` → `AffectationAutomatiqueService::tenter()` → moteur `AffectationService::controler/affecter` (aucun 2e moteur).

| Cas | Résultat (inscription **conservée**) |
|---|---|
| Groupe compatible dispo | `ASSIGNED` (moins rempli, déterministe) |
| Déjà affecté | `EXISTING` (idempotent) |
| Verrouillée | `LOCKED` (jamais déplacée) |
| Tous complets / aucun compatible | `NOT_ASSIGNED` (`CAPACITY_EXCEEDED` / `NO_COMPATIBLE_GROUP`, capacité manquante chiffrée) |
| Panne moteur | `ERROR` (auditée, à traiter) |

Garanties : niveau officiel uniquement, capacité revérifiée sous verrou, audit `affectation.auto_*`, notification `affectation.en_attente` si échec, scope faculté d'abord. Détail : `tests/Feature/AffectationAutomatiqueApresValidationTest.php` (11 cas).

---

## Phase 7 — Audit, notifications, sécurité (transverse)

- **Audit** : `AuditService` (`HistoriqueAcademique`, immuable) — créations, transitions, corrections (avant/après + motif), vérifications, documents (génération/téléchargement/révocation) ; `PopulationChanged` → `Log` pour les faits globaux.
- **Notifications** : centre unique (`CommunicationDispatcher` + `NotificationService`, gabarits versionnés, déduplication) — jamais de 2e système.
- **Sécurité** : RBAC granulaire existant (`archives.*`, `configuration.manage`, `data.import`, …), périmètre faculté systématique (404 hors périmètre), IDOR testés, throttle sur toutes les écritures, uploads (MIME réel + antivirus + stockage privé), PII hors logs.
- **Concurrence** : `lockForUpdate` (inscriptions, affectations, périodes, corrections, numérotation documentaire), contraintes uniques, idempotence retry/double-clic.

---

## Matrice de décision (rappel)

| Situation | Formulaire | Boutons | POST | Validation |
|---|---|---|---|---|
| Période ouverte, dossier conforme | Actif | Actifs | Autorisé | Possible |
| Période fermée / non configurée | Désactivé | Désactivés | `REGISTRATION_PERIOD_CLOSED` | — |
| Exception applicable | Actif + badge ⚡ | Actifs | Autorisé | Possible |
| Pièce manquante/rejetée/expirée/en attente | Visible, dépôt proposé | Submit verrouillé | Création OK, **validation refusée** (`REGISTRATION_DOCUMENTS_INCOMPLETE`) | Bloquée |
| Verdict `BLOQUANT` | Fiche + correction motivée | Re-vérifier | Création OK, **validation refusée** (codes) | Bloquée après correction conforme |

## Fichiers pivots

- Identification : `app/Services/IdentificationIntelligenteService.php`, `app/Models/MensReference.php`, `app/Services/DataExchange/Definitions/MensImportDefinition.php`
- Périodes : `app/Services/PeriodeInscriptionService.php`, `app/Models/RegistrationPeriod.php`, `app/Http/Controllers/Admin/PeriodeInscriptionController.php`
- Documents : `app/Services/DocumentsRequisService.php`, `config/inscriptions.php`
- Vérification : `app/Services/Verification/VerificationEngine.php`, `app/Services/BlocageInscriptionService.php`, `app/Services/InscriptionCorrectionService.php`
- Inscriptions : `app/Services/InscriptionService.php`, `app/Services/AcademicProgressionService.php`, `app/Services/ReinscriptionService.php`, `app/Services/AffectationAutomatiqueService.php`
- Tests : `tests/Feature/MensInscriptionIntelligenteTest.php`, `PeriodeInscriptionTest.php`, `InscriptionPagePeriodeTest.php`, `DocumentsRequisTest.php`, `CentreResolutionTest.php`, `AffectationAutomatiqueApresValidationTest.php`, `AcademicProgressionTest.php`
