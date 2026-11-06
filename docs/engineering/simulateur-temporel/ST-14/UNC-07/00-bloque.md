# UNC-07 — 00 · Statut bloqué

**Scénario :** Dépendances et interactions
**Sous-LOOP :** ST-14.07
**Statut :** `BLOCKED_BUSINESS` / `NOT_STARTED`
**Lot :** F
**Réserve bloquante :** `L0-R7`
**Date :** 2026-11-06
**Phases réalisées :** 0 / 10

---

## A. État initial

| Élément | Constat |
|---|---|
| Catalogue | Entrée planifiée, sans schéma exécutable |
| Version | `null` (planifié) |
| Disponibilité | `false` |
| Règle de domaine | absente |

## B. Blocage métier

- `L0-R7` : le scénario ne peut pas être spécifié, développé ni testé sans les valeurs institutionnelles requises par cette réserve.
- Aucune valeur par défaut n'est inventée (règle ST-14 : « ne pas inventer de paramètres, seuils, capacités, coûts ou distributions »).
- Aucun développement, aucun test et aucun verdict n'est produit tant que la réserve n'est pas levée.

## C. Dépendances

- [UNC-01, UNC-04]

## D. Phases A→J

| Phase | État | Preuve |
|---|---|---|
| A — Audit | ⛔ bloqué | — |
| B — Spécification | ⛔ bloqué | — |
| C — Conception | ⛔ bloqué | — |
| D — Développement | ⛔ bloqué | — |
| E — Validation math. | ⛔ bloqué | — |
| F — Sécurité | ⛔ bloqué | — |
| G — Tests | ⛔ bloqué | — |
| H — UX | ⛔ bloqué | — |
| I — Documentation | ⛔ bloqué | — |
| J — Verdict | ⛔ bloqué | — |

## E. Condition de déblocage

Fournir les valeurs institutionnelles demandées par la réserve `L0-R7` (capacités réelles / coûts réels), puis ouvrir le LOOP normal (A→J) de ce scénario.

---

*Preuves : aucun fichier métier n'est créé tant que le blocage persiste.*
