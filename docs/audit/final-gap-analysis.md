# Analyse des écarts finaux (2026-09-30)

## CRITICAL — aucun.

`audit:routes-securite` : 0 route sans authentification. Suite complète :
732 tests, 0 échec. Montants en centimes partout, factures jamais
supprimées, notes verrouillées avec circuit de correction.

## HIGH

| # | Problème | Preuve | Solution | Effort |
|---|---|---|---|---|
| H1 | Backup jamais exécuté en production | `sauvegarde:creer` testé dry-run seul | Exécuter + `--verifier` + copie externe, RPO/RTO à signer | Ops, 1 j |
| H2 | Pas de MFA | grep totp/mfa = 0 | TOTP opt-in super_admin (dépendance à approuver) | 3 j |

## MEDIUM

| # | Problème | Solution |
|---|---|---|
| M1 | Portail étudiant 100 % web session (pas d'API mobile) | API étudiant + guard dédié (P3) |
| M2 | Webhooks sortants absents | table deliveries + retry (P3) |
| M3 | Pas d'`Idempotency-Key` (clés naturelles utilisées) | middleware + table (P3) |
| M4 | GUIDE-DEPLOY §8-14 partiel, pas de deploy.sh | compléter (P2) |
| M5 | Enseignants sans `faculte_id`/`user_id` directs | jointure département assumée ; dénormaliser si besoin mesuré |
| M6 | Scoping mono-faculté par utilisateur | multi-rattachement si besoin réel |

## LOW / OPTIONAL

Digest/opt-out, graphiques BI, push PWA, vue mois planning, relevés/PV auto,
provider paiement réel, Data Warehouse, E2E navigateur, fusion auto doublons
(**interdite** par règle métier — ne pas implémenter).
