# LOOP C — Risques (12/10/2026)

| ID | Risque | Priorité | Classe | Preuve |
|---|---|---|---|---|
| R-C-01 | Cache obsolète post-`forget` intra-transaction (`rememberForever` ressert l'ancienne valeur) | P0 | POTENTIAL_RACE | Séquence `ParametreService::set()` relue ; non reproduit |
| R-C-02 | Course d'écriture réelle MySQL non démontrée (optimiste prouvé en séquentiel seul) | P0 | NOT_RUN | Aucun test deux-connexions paramètres |
| R-C-03 | `sauvegarde.*` hors registre : validation faible (texte libre) via 2 chemins d'écriture | P1 | CONFIRMED (code) | `SauvegardesController:190-200` vs repli par type |
| R-C-04 | `sentinel.*` lus jamais seedés (défauts codés seuls) | P2 | ABSENCE_D_USAGE_NON_PROUVÉE | grep seeds vide |
| R-C-05 | Collision nommage `sauvegarde.*` (paramètres vs types notif) | P2 | CONFIRMED (lecture) | grep par dossier |
| R-C-06 | Restauration sans `attendu` (dernière gagnante) | P2 | CONFIRMED (code) | `restaurer()` → `set(..., null, ...)` |

Aucun P0 confirmé exploitable ; R-C-01/R-C-02 exigent une preuve avant
correction (ne pas déclarer résolu).
