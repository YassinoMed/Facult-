# LOOP-EXAM-PREPARATION-01 — Préparation des examens (surface SmartExam)

## 0. Périmètre et principe directeur

Objectif : ajouter à UniRegistre une **surface de préparation des examens**
(route cible `/admin/examens/preparation`) inspirée du fonctionnement
administratif de SmartExam — **sans remplacer l'architecture existante**.

Principe : le module Evaluation (Phase 4, §26-35) couvre déjà ~80 % du
besoin. Ce LOOP **réutilise** tout, ne crée **aucun doublon fonctionnel**,
et ne comble que les lacunes prouvées ci-dessous.

## 1. LAN — Audit de l'existant (18/10/2026)

### Existant réutilisable (aucun doublon à créer)

| Besoin du cahier des charges | Existant vérifié | Verdict |
|---|---|---|
| **A. Sessions** : CRUD, année, ordinaire/rattrapage, statuts, archivage | `SessionExamen` + `SessionExamenService` + `SessionExamenController` ; statuts serveur `draft/open/ongoing/closed/locked/archived` avec transitions atomiques ; types `principale/rattrapage/exceptionnelle` ; verrouillage | ✅ conforme aux conventions — statuts du cahier **mappés** (BROUILLON→draft, EN_PREPARATION/VALIDEE→open, EN_COURS→ongoing, TERMINEE→closed, ARCHIVEE→archived, +locked) |
| Validation bloquée par contrôles de cohérence | `SessionExamenService::readiness()` : épreuves vides / sans salle / sans surveillant / sans candidat → `DomainException` sur `transiter('open')` | ✅ (étendu en DEV : capacité + population, voir G6) |
| **B. Épreuves** : ECUE, date/heure/durée, groupe, consignes, conflits, doublons, multiples par session | `Epreuve` (matiere_id, ue_id, groupe_id, salle_id, date, heures, capacite_necessaire), `EpreuveService` CRUD/transitions, `ConflitEpreuveService::detecter/bloquants`, `chevauche()` | ✅ |
| **C. Salles** : référentiel, capacité, localisation, 1..n salles, capacité/chevauchement/double réservation | `Room` (Schedule) ; `ExamRoomAssignmentService` : affectation transactionnelle `lockForUpdate`, simulation, **répartition multi-salles + numérotation des places** (`candidats.salle_id`, `place`) | ✅ multi-salles couvert |
| **D. Candidats** : inscriptions validées, éligibilité, zéro doublon, effectifs/anomalies | `EpreuveService::genererCandidats()` : population centrale Data Mesh (`exam_candidates`/`students_by_group`), suspendus exclus, `InscriptionPedagogique` statut `inscrit` exigé, `firstOrCreate` idempotent (= zéro doublon) | ✅ construction — lacunes G4 (snapshot) |
| **E. Émargement PDF** | `ConvocationService::listeEmargement()` : PDF dompdf (via `PdfGeneratorInterface`), layout partagé `pdf/liste` (garde, sections, pagination, zone visa), traçé `IntegrationLog` ; convocations individuelles | ⚠️ partiel — lacunes G2/G3 |
| **F. Identification documentaire** | Codes sur examens/épreuves (migration 05/10) ; matricule (`num_inscription`) dans l'émargement ; **aucune CIN publique** dans les PDF | ⚠️ partiel — lacune G5 |
| **G. Interface admin** | `tableau-bord`, `sessions-index/form`, `examens-index`, `emargement` (thème existant) | ⚠️ partiel — lacune G1 |
| **Sécurité** | Gates `evaluations.view/create/update/delete/publish` (`App\Support\Permissions`), `EvaluationPolicy`, scope faculté (`Epreuve::faculteId()`, `pourFaculteId`), middleware `module:evaluation` (404 si inactif), throttle | ✅ réutilisé tel quel |

### Lacunes prouvées (seuls chantiers de ce LOOP)

| ID | Lacune | Preuve d'audit |
|---|---|---|
| **G1** | Route `/admin/examens/preparation` + assistant agrégé (session → épreuves → salles → candidats → vérifications → validation → PDF) inexistants | `Routes/web.php` : préfixe `admin/evaluations/*` uniquement ; aucun regroupement « préparation » |
| **G2** | Émargement **par salle** manquant (PDF actuel = 1 fichier par épreuve, colonne salle sans regroupement) | `ConvocationService::listeEmargement()` : `sections => null` |
| **G3** | Pas d'**aperçu** (attachment only) ni d'**historique de génération/réimpression** exploitable en UI (`IntegrationLog` générique, sans lien épreuve/salle) | `Content-Disposition: attachment` fixe ; aucune table dédiée |
| **G4** | Pas de **snapshot de population à la validation** ni de signalement des changements ultérieurs | aucun champ population sur `evaluation_epreuves` |
| **G5** | Pas d'**identifiant stable anonyme** par candidat×épreuve (structure prête code-barres, sans CIN) | `evaluation_candidats` : pas de colonne dédiée |
| **G6** | `readiness()` ne contrôle ni la **capacité** ni la **population modifiée** | `SessionExamenService::readiness()` lignes 19-47 |

### Décisions conservées (aucune invention)

- Statuts du cahier **mappés** sur les conventions existantes (transitions serveur déjà en prod de test) — aucun renommage.
- Éligibilité : **réutilise** `genererCandidats` (population Data Mesh + IP `inscrit` + suspendus exclus). Aucune règle inventée.
- Capacité de salle : réutilise `Room.capacite` ; salle sans capacité renseignée = avertissement (jamais de capacité inventée).
- PDF : réutilise dompdf + layout `pdf/liste` (sections/visa/pagination déjà prévus).
- Scan/anonymisation/copies : **non implémentés** — seule la structure (numéro stable) est posée ; documenté comme tel.

## 2. DEV — Plan minimal additif

1. **Migration additive** : `evaluation_candidats.numero_anonyme` (unique par épreuve), `evaluation_epreuves.population_validated_at/population_hash/population_effectif`, table `evaluation_documents_generes` (historique par épreuve/salle : type, auteur, effectif, fichier).
2. **Modèle** `DocumentExamenGenere` (module Evaluation).
3. **Service** `PreparationExamenService` : tableau de bord agrégé, `verifierEpreuve` (bloquants/avertissements), `validerPopulation` (transaction + verrou : idempotence, numéros anonymes, hash snapshot), `populationModifiee`.
4. **Étendre** `readiness()` (capacité + population) — additif.
5. **Étendre** `ConvocationService::listeEmargement(?Room, bool $apercu)` : sections par salle, disposition inline, trace `evaluation_documents_generes`.
6. **Contrôleur** `PreparationExamenController` + routes `/admin/examens/preparation*` (mêmes middlewares : `auth`, `admin`, `secondaire.verrou`, `module:evaluation`, gates `evaluations.*`, scope faculté 404).
7. **Vues** `preparation-index` + `preparation-session` (thème existant, `x-page-header`, `ds-card`).
8. **Tests** `ExamPreparationTest` + non-régression suite Evaluation/Data Mesh.

## 3. Risques identifiés

- Migration additive sur tables volumineuses → nullable + index, zéro donnée modifiée.
- PDF gros effectifs → pagination du layout existant déjà prouvée (emploi du temps).
- Concurrency génération PDF → lecture seule + trace en transaction courte.
- Hors périmètre respecté : ni notes, ni inscriptions, ni affectations modifiées.

## 4. Contraintes respectées

Pas de `migrate:fresh`/`db:wipe`, pas de commit/push/déploiement, journalisation sans PII, transactions pour les écritures, gates existantes.

---

## 8. Clôture des étapes (18/10/2026)

| Étape | Résultat |
|---|---|
| **LAN** | Audit complet : existant cartographié, 6 lacunes G1-G6 prouvées (§1) |
| **DEV** | Migration additive appliquée (454 ms) · 2 vues · contrôleur+service · `readiness()` étendu · `listeEmargement` étendu · 4 routes |
| **PERFORMANCE** | Requêtes agrégées, index posés, zéro N+1, PDF lecture seule |
| **AUDIT** | ExamPreparationTest 18/18 (68 assert.) · suites Evaluation/Surveillance 300/301 · DataMesh 78/78 · pint OK |
| **CORRECTION** | 2 corrections de boucle : import `Room` manquant, test unicité réécrit (découverte : unique DB (épreuve, étudiant) déjà présent) |
| **VALIDATION** | Échec unique restant **prouvé préexistant** (stash sélectif) — hors périmètre, documenté R1 |

**Verdict final : `PASS_WITH_RESERVATIONS`** — voir `RAPPORT-EXAM-PREPARATION-01.md` (R1-R5).
