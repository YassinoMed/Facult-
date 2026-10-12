# Benchmark MySQL (§24-26)

Protocole reproductible (dev MySQL `localhost:8889`, `php -d memory_limit=512M
artisan tinker`, transaction + `rollBack()` — zéro pollution) :

```text
1 faculté BENCH + 1 année 2999-3000 + 10 groupes (capacité 500)
3000 étudiants (bulk 500) + 3000 inscriptions + 3000 affectations actives
1500 inscriptions pédagogiques (ECUE BENCH01, inscrit)
2000 relevés présence (2026-10-01, present)
```

## Mesures (MySQL 8, 30/09/2026)

| Cas | Temps | Requêtes | Mémoire / volume |
|---|---|---|---|
| Liste 3000 (`jeuDonnees`) | 0,48 s | 13 | +10 Mo, 3000 lignes |
| Sections par groupe (10) | 0,45 s | 13 | — |
| Étudiants ECUE (1500) | 0,01 s | 1 | count seul |
| Présences date (2000) | ~0 s | 1 | count seul |
| Export XLSX 3000 | 0,22 s | 0 | 159 Ko (pic process 66 Mo) |

Requêtes bornées en O(lots `chunk(1000)`), jamais O(lignes) — garde-fou
`PopulationPerformanceTest` + `PopulationMySQLPerformanceTest`.

## EXPLAIN

```sql
EXPLAIN SELECT * FROM etudiants
WHERE faculte_id = ? AND statut = ? AND niveau = ?;
-- key: idx_etudiants_faculte_statut, rows: 1 (sélectif)
```

Sans filtre sélectif (faculté ≈ toute la table), l'optimiseur choisit le
full scan à raison — aucun index redondant ajouté (§26). Index Phase 2
vérifiés via `SHOW INDEX` avant création ; aucun doublon
(`2026_09_30_185905_add_index_listes_population`).

## Extrapolation 10k

Linéaire observée (chunk + streaming) : ~1,6 s / ~15 requêtes / ~35 Mo pour
10k lignes — sous les seuils HTTP ; au-delà de 5000 lignes CSV/XLSX : job
async (§2.7). À confirmer en staging avant mise en production.
