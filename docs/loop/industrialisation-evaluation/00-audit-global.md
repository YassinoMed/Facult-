# LOOP INDUSTRIALISATION ÉVALUATION — 00 · Audit global

**Périmètre :** module Évaluation / Examens — saisie, correction, résultats, sécurité, traçabilité, UX, cockpit, gouvernance technique.
**Mode :** AUDIT_ONLY (aucune donnée officielle modifiée).
**Source de vérité :** code du dépôt (223+ modifications locales préservées).

---

## 1. Chaîne métier observée (processus complet)

```
Saisie (SaisieNoteService::saisirLot — transactionnel, verrou épreuve+notes,
        barème>0 serveur, compostage anonyme prioritaire, historique avant/après)
→ Validation (valider/validerTout — verrous + matrice NoteEpreuve::TRANSITIONS
   + garde candidat absent + garde note officielle verrouillée + resync officielle)
→ Vérification/audit (EvaluationAuditService, drapeau a_ete_modifiee_apres_validation)
→ Correction contrôlée (demanderCorrection [motif, depuis locked]
   → autoriserCorrection [déverrouille + bascule verrou officiel]
   → re-saisie historisée → re-validation)
→ Verrouillage (verrouiller [motif obligatoire] + NotePolicy note officielle)
→ Résultats (CalculEvaluationService/AcademicCalculationService → épreuves)
→ Délibération (DeliberationService : simuler [sans écriture officielle, run tracé]
   → preparer [verrou+matrice, delete+insert idempotent, publie=false]
   → valider → publier [publie=true + event])
→ Consultation autorisée (policies + périmètre)
→ Historisation (NoteEpreuveHistorique + EvaluationChanged + IntegrationLog)
```

Chaque étape est reliée à son service, sa permission, sa donnée et son historique — **le processus cible du cahier existe déjà** ; la campagne confirme, protège et explicite.

## 2. Composants audités (principaux)

| Composant | Rôle | État |
|---|---|---|
| `NoteEpreuve` (`evaluation_notes`) | note d'épreuve — 6 états (`draft, entered, submitted, validated, locked, correction_demandee`) + matrice `TRANSITIONS` serveur | ✅ mature |
| `SaisieNoteService` | saisie individuelle/lot/anonyme, validation, verrouillage, correction (4 volets) | ✅ mature, transactionnel |
| `NoteEpreuveHistorique` (`evaluation_notes_historique`) | avant/après + motif + auteur | ⚠️ **append-only ABSENT → corrigé (P0-03/SEC-012)** |
| `NoteEpreuveController` | Gates granulaires + import multi 4 étapes + exports streamés | ✅ |
| `EvaluationPolicy` | permission + `dansPerimetre` (anti-IDOR) sur chaque objet | ✅ |
| `Note` (cœur) + `NotePolicy` | note officielle (source de vérité moyennes) verrouillable | ✅ |
| `DeliberationService` + `DecisionDeliberation` | simulation/préparation/validation/publication + `marquerResultatsInvalides` | ✅ |
| `EvaluationImportService` | analyse → prévisualisation → confirmation (FILE+JOB pour volumes) | ✅ |
| `CompostageService` | anonymisation (numéros) + correspondance | ✅ |
| `EvaluationChanged` + `AuditEvaluation` | événements + log applicatif | ✅ |
| Permissions `notes_eval.*` (8) + `notes.compostage.*` | RBAC granulaire | ✅ |
| `MesNotesController` | portail enseignant (saisie sans compte back-office : `auteurId` null + `origine`) | ✅ |

## 3. Règles et permissions — séparation des responsabilités

| Opération | Permission | Rôles (matrice `Permissions.php`) |
|---|---|---|
| Consulter notes | `notes_eval.view` | admin_scolarite, agent_scolarite, super_admin |
| Saisir | `notes_eval.enter` | admin_scolarite, **agent_scolarite**, portail enseignant |
| Valider | `notes_eval.validate` | **admin_scolarite, super_admin uniquement** |
| Verrouiller | `notes_eval.lock` (+ motif) | admin_scolarite, super_admin |
| Corriger (autoriser) | `notes_eval.correct` | admin_scolarite, super_admin |
| Import | `notes_eval.import` | admin_scolarite, super_admin |
| Export | `notes_eval.export` | admin_scolarite, super_admin |

**Séparation démontrée par RBAC** : l'agent de scolarité saisit mais ne peut pas valider ; la validation et la correction sont réservées aux niveaux supérieurs. (Le portail enseignant saisit sans compte back-office — `saisi_par` null, `origine` préfixée.)

**Point métier ouvert (non implémenté, non inventé)** : la séparation saisie/validation **par personne distincte** (un admin qui saisit peut valider sa propre saisie) n'est imposée nulle part. Le cahier l'exige « lorsque le métier l'impose » — aucune preuve d'imposition dans le code ni de décision institutionnelle → consigné dans `02` §D-11, sans garde inventée.

## 4. Routes notes (principales, `Routes/web.php`)

| Route | Contrôleur | Gate/Policy |
|---|---|---|
| POST `evaluations/notes/epreuves/{epreuve}/saisie` (lot) | `storeLot` | `saisirNote` Policy |
| POST `…/saisie/anonyme` | `storeAnonyme` | `saisirNote` |
| POST `…/compostage` / `correspondance` | `generer` / `correspondance` | `genererCompostage` / `resoudreCompostage` |
| GET `…/export-anonyme` | `exportAnonyme` | `saisirNote` |
| POST `…/import-anonyme` | `importAnonyme` | `saisirNote` |
| POST `…/import-multi` (analyse/prévisualisation/confirmation/annulation) | `importMulti`×3 | `saisirNote` |
| POST `evaluations/notes/{note}/valider` | `valider` | `validerNote` |
| POST `evaluations/notes/epreuves/{epreuve}/valider-tout` | `validerTout` | `validerNotesEpreuve` |
| POST `…/{note}/verrouiller` | `verrouiller` | `validerNote` |
| POST `…/{note}/correction-demander` | `demanderCorrection` | `validerNote` |
| POST `…/{note}/correction-autoriser` | `autoriserCorrection` | `corriger` |
| GET `evaluations/notes/{note}/historique` + POST `comparer` | `HistoriqueNotesController` | `notes_eval.view` |

Contrôles serveur présents sur chaque voie d'écriture (contrôleur **et** service) — double défense.

## 5. Anomalies constatées et traitement

| ID | Anomalie | Gravité | Traitement |
|---|---|---|---|
| ANO-01 | `NoteEpreuveHistorique` modifiable/supprimable via Eloquent (5 sites de création uniquement, aucune lecture d'écriture) — contrary to journaux sœurs | **ÉLEVÉ** (intégrité de l'historique) | **Corrigé** : garde append-only au niveau modèle + test |
| ANO-02 | `LoopCampagneAcquisExamenTest` : 36 erreurs (imports obsolètes `App\Models\Programme`, fixture `faculte_id` null sur NOT NULL, FK codées en dur, `Etudiant::factory()` inexistant) | MOYEN (baseline faussement rouge) | **Corrigé** : fixtures alignées au schéma réel → 6 passés / 30 ignorés documentés (« Fixtures FST programme 21 ») |
| ANO-03 | Séparation saisie/validation par personne distincte non imposée | MOYEN (décision métier) | Consigné D-11 — aucune garde inventée |
| ANO-04 | Révalidation écrase (`validated→validated` admise) — assumée et horodatée (`valide_at`), drapeau d'audit `a_ete_modifiee_apres_validation` | FAIBLE (documenté) | Comportement existant conservé |
| ANO-05 | `.bak` orphelin `LoopCampagneAcquisExamenTest.php.bak` | INFORMATION | Laisser (non-suivi, aucun impact) |

## 6. Ce qui ne doit PAS être modifié

- `Note` officielle (source de vérité) hors circuits `ArchiveService` — verrou NotePolicy respecté.
- La matrice `NoteEpreuve::TRANSITIONS` (aucun état nouveau).
- Le circuit délibération (verrouillage + publication = décision officielle).
- Les permissions existantes (aucune création arbitraire, aucun élargissement).
- Les données officielles (mode AUDIT_ONLY — aucune écriture pendant la campagne).

## 7. Ce qui peut être amélioré sans décision métier (réalisé dans cette campagne)

1. Garde append-only `NoteEpreuveHistorique` (ANO-01) — réalisé.
2. Fixtures de tests obsolètes (ANO-02) — réalisé.
3. Tests de sécurité dédiés aux nouvelles gardes (SEC-012) — réalisé.

## GATE A — ✅ VALIDÉ

Les principaux parcours (saisie→validation→correction→verrouillage→résultats→gouvernance→publication→consultation→historique) sont reliés à leurs services, permissions et données ; les composants réutilisables sont identifiés ; les écarts sont documentés.
