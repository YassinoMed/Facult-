# LOOP I — Migrations (12/10/2026)

## `2026_10_12_205213_creer_versions_parametres`

- Objet : `parametre_versions` append-only (FK `parametre_id` cascade,
  `acteur_id` nullOnDelete, `valeur`+`valeur_precedente`, `motif`,
  `created_at`, index `[parametre_id,id]`).
- Init : aucune donnée (création à l'usage). Idempotente par nature
  (table vide au départ). `down()` : drop (perte d'historique assumée,
  documentée).
- Installations existantes : `migrate` standard, zéro écrasement (aucune
  écriture sur `parametres`).

## `2026_10_12_231817_ajouter_parametres_sentinel`

- Objet : 3 seeds `sentinel.*` aux défauts codés (5/1000/60).
- Idempotence : `updateOrInsert` par clé — **n'écrase jamais une valeur
  personnalisée** (2e arg = insert seul ; existant conservé). Vérifié par
  lecture du code (pas de test dédié — réserve mineure, risque nul par
  construction `updateOrInsert`).
- `down()` : delete des 3 clés (retire les seeds, pas les personnalisations
  hors liste — correct).

## `2026_10_13_002424_create_smtp_configurations_table`

- Objet : `smtp_configurations` (champs §E, `actif`, index).
- Init : table vide (aucun fournisseur configuré — cohérent SMTP
  `NOT_CONFIGURED`). `down()` : drop.
- Ordre : après `parametres` (aucune FK croisée, indépendante).
