# Notes et délibérations (Phase 14)

## Contrôles

`GradeCheckSuite` (module Evaluation) : notes hors bornes 0–20,
coefficients nuls, candidats présents sans note, moyennes d'épreuve
anormales (< 5 ou > 16, unanimité 0/20), écarts normale/rattrapage
≥ 5. Anomalies **informatives** (`bloquant: false`) fusionnées au
snapshot de préparation — seuls `note_non_validee`/`sans_note` du
compilateur bloquent la validation.

## Traçabilité des calculs

`calculation_runs` (délibération, mode simulation|officiel, règles
JSON, `hash_entrees` SHA-256, résumé, auteur) : `simuler()` et
`preparer()` enregistrent chacun leur run. Rejouable : mêmes entrées
+ même version de règles = même hash.

## Invalidation

Toute écriture de note (`ArchiveService` : saisie, modification,
suppression) marque `resultats_invalides_le` sur les délibérations
non verrouillées concernées ; `valider()` refuse tant que non
re-préparée (message explicite) ; `preparer()` lève le marqueur.
Avertissement affiché sur le tableau du jury.

## Cas limites et PV

`cas_limite` (|moyenne − seuil| ≤ 0,5) : arbitrage jury, informatif.
PV PDF (`PvDeliberationService`, moteur central, `export.pv-
deliberation`) : jury, résultats, statistiques — validée+ uniquement
(422 sinon), sans CIN.

## Visibilité

Portail (`etudiant.notes`) et API `results` : inscriptions à décision
**publiée** uniquement (enveloppe inchangée, valeurs nulles sinon).
