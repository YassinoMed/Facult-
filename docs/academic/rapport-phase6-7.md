# Rapport Phase 6 + Phase 7 (§99)

## A. Architecture

Modules réutilisés : Evaluation (délibérations, règles, calculs),
StudentDossiers (registre, moteur PDF, policies), StudentArchive
(4e source), Inscriptions (progression), Notifications, Audit,
DataExchange, API v1. Nouveaux : `OfficialDocumentService`,
`OfficialDocumentController`, `VerifyDocumentController`,
`RegleEvaluationController`, `GenerateOfficialDocumentsJob`,
`TypeEvaluation` (Phase 5, ce lot), catalogue
`config/documents-officiels.php`. Zéro permission créée.

## B. Modèle de données

`evaluation_sessions.session_origine_id` (FK self, restrict) ;
`evaluation_types_evaluation` (code UNIQUE + graine 8) ;
`generated_documents.statut` (valide/remplace/revoque + index).
Relations : session↔rattrapages, délibération→décisions→étudiants,
dossier→generated. Contraintes : UQ `reference`, UQ semestre+matière+
session (notes), UQ étudiant+année (inscriptions). Enum inscription
`type` inchangée (redoublement en `observation`, contrainte
SQLite/MySQL partagée).

## C. Règles académiques

Versionnées (année [+programme], UQ, actif, snapshot en délibération) ;
seuils 10/8 + note éliminatoire + 3 compensations + arrondi central.
`compensation_semestre` load-bearing (admis_compensation vs ajourne).
Rattrapage remplace normale (historique conservé). Progression jamais
N+1 aveugle : décision publiée → passage/redoublement/blocage/fin de
cycle ; L3→M1 = admission explicite.

## D. Documents

Catalogue (relevé, attestation), gabarits versionnés (Blade),
`GeneratedDocument` (référence UNIQUE REL-/ATT-, version, sha256,
hash contenu, statut), vérification publique minimale, révocation
motivée + audit, supersession automatique, masse via job
(chunk 200, ImportReport, notif). GED/archive : 4e source `officiel`.

## E. Sécurité

RBAC existant (deliberations.*, notes_eval.*, student_dossiers.pdf,
archives.*), Faculty Scope (policies + fix 500 EvaluationPolicy),
IDOR testé (convocation, documents, attestation, API 404), API
scopée + throttle + openapi resynchronisé, téléchargements gated +
audit, vérification sans PII, anti-régénération, retry concurrence.

## F. Tests

Nouveaux : `DeliberationPhase6Test` (4 : compensation, règles CRUD,
workflow+notif+immuabilité+optimistic, redoublement+idempotence),
`DocumentsPhase7Test` (7 : versionnement/hash/audit, garde
attestation, download/révocation/IDOR, vérification, archive, API
results, job masse). Voisins : 76 OK. Chunks complets : 0 échec
(voir §99-I du précédent rapport pour la méthode).

## G. Performance

`preparer` : delete+insert chunk 500 ; job masse chunk 200 +
timeout 600 ; PDF jamais en HTTP pour la masse (queue `database`,
`sync` en test) ; exports bornés (10 000 lignes) ; index
(dossier,type,statut). Non rejoué à 10k/50k : seuils existants
inchangés (chunking + files déjà bornés).

## H. Risques résiduels

Anonymisation copies (à concevoir, §32) ; QR (dépendance à
approuver) ; multilingue FR uniquement ; PV/relevé PDF via exports
(listes) plutôt que gabarits dédiés ; saisie individuelle = lot ;
`compensation_ecue/ue` non validantes seules (motifs) ; correction
post-publication = révocation + régénération (pas de versionnage de
décision) ; crash suite mono-processus pré-existant (validé par
chunks).

## I. Préparation Phase 8

Données prêtes : décisions publiées + progression + documents
versionnés + archive unifiée (4 sources) + API results + vérification
publique → dossier étudiant 360°, services en ligne (demandes adossées
aux décisions/documents), processus administratifs (transferts,
admissions Master explicites).
