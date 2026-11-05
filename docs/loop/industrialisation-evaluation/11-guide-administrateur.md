# LOOP INDUSTRIALISATION ÉVALUATION — 11 · Guide administrateur

## Sessions et examens
- Créer les sessions (`evaluations/sessions`), puis les examens (état initial `draft` forcé serveur).
- Faire transiter les examens via « Transiter » — la **publication** passe exclusivement par le circuit de gouvernance (soumission → certification → double approbation → publication).
- Prévisualiser les transitions disponibles : `GET evaluations/examens/{id}/transitions` (lecture seule).

## Saisie et contrôle des notes
1. Ouvrir l'épreuve → écran de saisie (individuelle, anonyme par compostage, import multi avec prévisualisation + confirmation).
2. Valider les notes (individuellement ou « valider tout ») — la validation synchronise la note officielle.
3. Verrouiller (motif obligatoire) — toute modification devient refusée côté serveur.

## Correction
1. « Demander une correction » sur la note verrouillée (motif obligatoire, aucun changement d'état).
2. Une autorité (`notes_eval.correct`) « autorise la correction » (déverrouillage + historique).
3. Re-saisie de la nouvelle valeur (ancienne valeur conservée dans l'historique) puis re-validation.

## Historiques et anomalies
- Historique par note : `evaluations/notes/{note}/historique` (+ comparaison de versions).
- Journal **append-only** : aucune modification/suppression possible.
- Anomalies : écran dédié + audit de conformité par examen.

## Gouvernance et publication des résultats
- Délibérations : **simuler** (sans écriture officielle) → **préparer** (décisions `publie=false`) → **valider** (verrou) → **publier** (`publie=true`).
- Une publication répétée ne crée jamais une nouvelle décision (idempotence).
