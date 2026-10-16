# LOOP-06-PERFORMANCE — Parcours autonome de changement d'e-mail

Date : 2026-10-14. Matériel : macOS arm64, PHP 8.5.11 CLI. Base de mesure : SQLite `:memory:` (contexte PHPUnit, `Notification::fake()` — transport exclu ; envois réels mesurés à part, voir §5). Méthode : test jetable `MesurePerfAutonomeTest` (créé, exécuté, **supprimé** — non commité), `DB::listen` + `hrtime`, médiane de fait sur run unique (ordres de grandeur, pas de benchmark statistique).

## Valeurs observées (parcours autonome, 1 dossier)

| Opération | Durée | Requêtes SQL | Notes |
|---|---|---|---|
| `demander` (recherche + bcrypt + gardes + émission) | ~14–21 ms | 5 | Dominé par bcrypt (~10 ms) — coût de sécurité assumé, anti-oracle temporel |
| `confirmer` code invalide | ~0,2 ms | 0 | Cache seul, aucune requête |
| `confirmer` + finalisation (transaction + verrou + audit) | ~8 ms | 7 | Verrou pessimiste + 1 ligne d'audit |

Aucune requête répétitive (N+1) : rechargements ciblés par clé primaire/unique. Envois synchrones (codes) : temps du transport SMTP non inclus ici — fail-soft avec reprise immédiate, sans cooldown bloquant (testé). Notifications post-commit en file `notifications` (retries natifs).

## Index (table `etudiants`, relevé `Schema::getIndexes`)
`PRIMARY id`, `cin` UNIQUE, `email` UNIQUE, `num_inscription` UNIQUE, `faculte_id`, `statut`, `nom+prenom`, `niveau`, `identifiant_national`, composites faculté/statut/groupe : **aucun index ajouté** — unicité et recherches couvertes. Audit : **1 ligne par changement**, conservation probatoire (pas de purge).

## Challenges et nettoyage
Clés cache TTL 600/1800 s + cooldowns 60 s, compteurs par identité (15 min) : auto-expirants, volume borné par les throttles (5–10/min/fonction/IP). Stockage cache dev = `database` : les lignes expirées y restent jusqu'à purge — **recommandation** (non appliquée, hors périmètre) : purge périodique `cache.expiration < now()` via la planification existante, à valider par l'exploitant. Aucun cache introduit pour contourner l'unicité (backstop UNIQUE vérifié en course).

## Seuils
Aucun seuil de performance fixé : volumes observés (~20 ms / ≤7 req) sans commune mesure avec les budgets ; pas d'optimisation requise. Concurrence évaluée en SQLite (atomisme `pull` + verrou + UNIQUE) ; comportement moteur MySQL de production : réserve documentée (§15), jamais substituée.
