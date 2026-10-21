# Instructions PM — tracker du dépôt

## Tracker

| Élément | Valeur |
|---|---|
| Type | GitHub Issues (tracker único) |
| Organisation / dépôt | `YassinoMed/Facult-` |
| URL | https://github.com/YassinoMed/Facult- |
| Accès | via MCP `github-mcp` (read/write) |
| Labels | aucun label utilisé à ce jour — ne pas en créer |
| Branches distantes | `main` uniquement (pas de trunk `develop`) |

## Conventions d'identifiants

- L'identifiant de ticket est le **numéro de l'issue GitHub** (ex. `#12`).
- Le dossier de spec associé porte l'identifiant du ticket : `doc/changes/<id>/` où `<id>` est le numéro d'issue (`doc/changes/12/`).
- Les fichiers d'une spec : `spec.md` (spécification canonique), `plan.md` (plan d'implémentation), `tests.md` (plan de test) — created par les agents dédiés (`spec-writer`, `plan-writer`, `test-plan-writer`).

## Triage — règles de priorisation

| Impact | Action |
|---|---|
| Bloque la prod / perte de données / faille | Label `P0` implicite : traitement immédiat, PR fusionnée en priorité |
| Cassure fonctionnelle sur un parcours existant | P1 : à traiter dans le cycle en cours |
| Nouvelle fonctionnalité demandée explicitement | P2 : ce cycle |
| Amélioration d'ergonomie / cosmétique | P3 : backlog, sans engagement |

## Règles de dépôt (branches)

- Branche de travail : `feature/<slug-ticket>` pour une fonctionnalité, `fix/<slug-ticket>` pour un correctif.
- La branche est créée **depuis `main`** au moment de prendre le ticket.
- Un ticket ne commence pas sur `main` : la branche est créée avant la première modification de fichier.
- Pas de commit direct sur `main`.

## Règles de spec

- Une spec = un ticket. Le dossier `doc/changes/<id>/` est la source de vérité du périmètre.
- Toute modification de comportement métier doit être répercutée dans `docs/` (le dossier `docs/` est la documentation canonique du projet, cf. `AGENTS.md`).
- Les règles d'or produit (français, pas de duplication, modules CORE+MODULES, sécurité PII) sont dans `AGENTS.md` — elles s'appliquent aux specs.

## État du tracker à l'ouverture

- Issues ouvertes : 0
- PR ouvertes : 0
- Le backlog vit dans `docs/` (rapports de loops, docs fonctionnelles) et dans les issues créées au fil de l'eau.
