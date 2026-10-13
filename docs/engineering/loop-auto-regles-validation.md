# Portes de validation (moteur LOOP K)

## GATE A — PASS → NEXT

`verdict=pass`, preuves présentes, 0 P0/P1 ouvert, aucun changement hors
périmètre, LOOP suivant résolu dans le registre. Effet : clôture + `DISCOVER` suivant.

## GATE B — PASS_WITH_RESERVATIONS

Réserves non bloquantes documentées (impact + responsable + clôture).
Poursuite **uniquement** si le registre l'autorise pour ce LOOP ;
sinon `BLOCKED` en attente de décision.

## GATE C — BLOCKED / FAILED

`verdict=blocked` (décision/permission/secret/risque/preuves insuffisantes),
ou 3 échecs (`tentative >= 3` → `FAILED`). Effet : rapport + état conservés,
aucun passage.

## GATE D — STOPPED

Demande d'arrêt, périmètre terminé, limite atteinte (10 LOOP / 3 tentatives),
LOOP suivant absent du registre, ou dépassement d'autorisations.

## Règles d'évaluation (`EvaluateurPorte::evaluer`)

```
verdict=pass + reserves=[] + suivant connu      → NEXT
verdict=pass + reserves[] + poursuite autorisée → NEXT (avec réserves)
verdict=pass + reserves[] + poursuite interdite → BLOCKED
verdict=pass + P0/P1 ouvert                     → BLOCKED (incohérent)
tentative >= 3                                  → FAILED (prioritaire)
verdict=blocked                                 → BLOCKED
suivant absent du registre                      → STOPPED
```

Compteur : `loops_consecutifs` (max 10), `tentative` par LOOP (max 3).
Reprise idempotente : réévaluer le même état ne duplique ni migration ni
rapport (même verdict + mêmes preuves = même transition).
