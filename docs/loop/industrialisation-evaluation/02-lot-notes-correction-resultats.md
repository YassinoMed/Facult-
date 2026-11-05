# LOOP INDUSTRIALISATION ÉVALUATION — 02 · Lot notes, correction et résultats

## 1. Modèle de données et états réels (PHASE B)

### `NoteEpreuve` (`evaluation_notes`) — machine d'états serveur

| État réel | Description | Modification permise | Acteur autorisé | Transitions |
|---|---|---|---|---|
| `draft` | valeur absente ou en préparation | oui (saisie) | `notes_eval.enter` | → entered |
| `entered` | valeur saisie | oui (re-saisie historisée) | `notes_eval.enter` | → submitted, validated |
| `submitted` | soumise (workflow portail enseignant) | non (passage validation) | — | → validated |
| `validated` | validée + synchronisée à la note officielle | re-validation admise (écrase, horodaté `valide_at`) | `notes_eval.validate` | → validated, locked, correction_demandee |
| `locked` | scellée | non (refus service) | `notes_eval.validate` (verrouiller) | → correction_demandee |
| `correction_demandee` | déverrouillée sur autorisation | oui (re-saisie) | `notes_eval.enter` | → entered, validated |

Garanties : matrice dans le modèle (le client ne décide jamais), `lockForUpdate` sur toute écriture, barème > 0 contrôlé serveur, candidat absent → validation refusée, note officielle verrouillée → validation refusée (voie sanctionnée = correction autorisée).

### Distinctions NOTIONNELLES (vérifiées, non fusionnées)

- **Note d'épreuve** ≠ **Note officielle** (`notes`, source de vérité moyennes — synchronisation à la validation via `ArchiveService`).
- **Note manquante** (`valeur` null) ≠ **note zéro** — absence explicite, jamais de 0 implicite.
- **Résultat provisoire** (décision `publie=false`) ≠ **résultat publié** (`publie=true`).

## 2. Saisie individuelle et en masse (PHASE C/D) — contrôles réels

1. Identité : acteur back-office (`auteurId`) ou portail enseignant (`auteurId` null + `origine`).
2. Habilitation : Policy `saisirNote` (`notes_eval.enter` + périmètre) sur chaque route **et** recontrôle serveur.
3. État épreuve : verrou → `DomainException`.
4. Barème : `note_max > 0` obligatoire **avant** toute écriture (défense derrière la règle `NoteDansBareme`).
5. Candidat : compostage (anonyme) prioritaire, sinon `etudiant_id` interne ; numéro inconnu → erreur de ligne.
6. Transaction : **rollback intégral** du lot en cas d'échec (§35) ; erreur par ligne collectée sans écriture partielle.
7. Historique : chaque valeur modifiée écrit `NoteEpreuveHistorique` (avant/après/auteur/motif) — **append-only depuis cette campagne**.
8. Double soumission : re-saisie = mise à jour historisée (pas de doublon — clé `epreuve_id`+`etudiant_id` unique).

Import multi : analyse → prévisualisation → **confirmation** → annulation (l'analyse seule n'écrit rien) ; FILE+JOB au-delà d'un volume ; rapport par ligne (perf mesurée : 1000 lignes ≈ 0,74 s).

## 3. Vérification et séparation des responsabilités (PHASE F)

- Validation = opération distincte (route + permission `notes_eval.validate` + matrice).
- **RBAC** : `agent_scolarite` (saisie) ne peut pas valider ; validation/correction réservées admin/super_admin.
- Verificateur ≠ auteur de saisie : **non imposé au niveau personne** (voir D-11).
- Audit complémentaire : `EvaluationAuditService` + drapeau `a_ete_modifiee_apres_validation`.

### D-11 · Décision métier ouverte — double validation saisie/validation

| Champ | Contenu |
|---|---|
| Question | Un validateur peut-il valider une note qu'il a lui-même saisie ? |
| Code | `SaisieNoteService::valider()` — aucun contrôle `validateurId === saisi_par` ; la séparation est implémentée **par rôle** (RBAC), pas par personne. |
| Risque | MOYEN — un admin qui saisit peut valider sa propre saisie (circuit reste tracé : `saisi_par` + `valide_par` + horodatage). |
| Décision nécessaire | Imposer `validateur ≠ saisi_par` (quatre yeux stricts) ou valider le contrôle par rôle comme suffisant. |
| Statut | **Ouvert — non implémenté** (aucune preuve d'exigence dans le code ; aucune garde inventée). |
| Responsable | Direction des études / scolarité centrale. |

## 4. Verrouillage et correction (PHASE G/H)

- `verrouiller(motif obligatoire)` — verrou d'épreuve et verrou de note distingués ; note officielle verrouillée protégée par `NotePolicy`.
- `demanderCorrection(motif obligatoire)` — uniquement depuis `locked`, **sans changement d'état**, historise.
- `autoriserCorrection(motif obligatoire)` — transition `correction_demandee`, déverrouille, bascule le verrou de la note officielle, historise.
- La nouvelle valeur passe par la saisie (historisée) puis re-validation — **aucune réécriture silencieuse** ; l'ancienne valeur reste dans l'historique (consultation `GET notes/{note}/historique` + comparaison de versions).
- Correction après publication : passe par la même chaîne (déverrouillage autorisé + resync) — toute rectification d'un résultat **publié** exige le circuit délibération (voir D-12).

### D-12 · Décision métier ouverte — rectification post-publication

| Champ | Contenu |
|---|---|
| Question | Procédure dédiée de rectification d'un résultat officiellement publié (hors re-délibération) ? |
| Code | `DeliberationService` : décisions publiées (publie=true) ne sont pas réécrites ; `marquerResultatsInvalides` invalide les calculs dépendants. |
| Statut | **Ouvert** — non implémenté (aucune règle institutionnelle disponible) — `BLOCKED_BUSINESS` pour ce sous-périmètre. |

## 5. Calcul, résultats et consultation (PHASE I/J)

- Résultats : `CalculEvaluationService`/`AcademicCalculationService` (épreuves) → `CalculAcquisPedagogiquesService` (acquis, décret 2022-631).
- Délibération : `simuler` (run tracé, **aucune écriture officielle**) → `preparer` (verrou + matrice + delete/insert idempotent, `publie=false`) → `valider` (verrouille) → `publier` (`publie=true` + event).
- Invalidation dépendante : `marquerResultatsInvalides(inscription)` — une correction de note peut invalider les calculs ; **aucun recalcul/republication automatique** sans workflow.
- Consultation : étudiant = ses décisions publiées uniquement (manipulation d'ID testée — Policy + périmètre) ; enseignant = notes de ses épreuves via portail contrôlé.

## 6. Matrice de tests — couverture réelle

| ID | Scénario | Couverture |
|---|---|---|
| NOTE-001..009 | saisie valide/non numérique/hors barème/absence/permissions/doublons | ✅ suites existantes (NotesEvaluationsSecuriteTest, AmeliorationsTest, CompostageAnonymeTest) |
| NOTE-010..013 | lot + import (erreurs par ligne, doublons, inconnus) | ✅ NotesImportMultiTest + AssociationTest (perf vérifiée) |
| NOTE-014/015 | modification note verrouillée refusée | ✅ garde service + suites |
| NOTE-017 | auto-vérification | ⚠️ non exigée par le code (D-11) — comportement documenté |
| NOTE-018..022 | correction (workflow, motif, historique, rejeu) | ✅ + **nouveau test append-only (SEC-012)** |
| NOTE-023..026 | recalcul, provisoire vs publié, consultation étudiant | ✅ suites délibérations + éligibilité |
| NOTE-028 | échec transactionnel → aucun état partiel | ✅ rollback intégral testé (saisie/import) |

## 7. Réserve du lot

- R-11 (D-11) : séparation par personne non imposée — décision attendue.
- R-12 (D-12) : rectification post-publication — BLOCKED_BUSINESS.
