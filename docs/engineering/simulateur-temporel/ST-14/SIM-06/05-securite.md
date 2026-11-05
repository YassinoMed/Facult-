# SIM-06 — 05 · Sécurité, permissions et lecture seule

**Date :** 2026-11-03  
**Périmètre :** exécution d'un creux avec retour progressif

---

## F.1 Permissions

Aucune permission nouvelle. Réutilisation stricte :

| Permission | Usage |
|---|---|
| `simulation.view` | Catalogue (lecture du contrat SIM-06) |
| `simulation.gerer` | Définition de scénario |
| `simulation.executer` | Exécution et snapshots |
| `simulation.export` | Export des résultats |
| `simulation.explication` | Explication des variantes |
| `simulation.gouvernance.proposer` / `.decider` | Circuit de gouvernance |

Le test de sécurité prouve : **403** sans permission et **403** pour un
admin de faculté B sur un scénario de faculté A, avec **zéro exécution**
créée par ces appels refusés.

---

## F.2 Isolation facultaire

| Contrôle | Implémentation |
|---|---|
| Catalogue | Contrat global, sans donnée métier |
| Définition | `faculte_id` du scénario |
| Exécution | Périmètre vérifié avant lecture des effectifs |
| Export / consultation | Périmètre existant, inchangé |

La profondeur et les durées sont des paramètres de scénario : ils
n'ouvrent l'accès à aucune donnée supplémentaire.

---

## F.3 Données personnelles

- Agrégats d'effectifs uniquement (adaptateur en lecture seule).
- Snapshots : `cohortes`, `total`, `admissions`, et — en mode creux —
  `admissions_reference`, `facteur_recuperation`, `phase_recuperation`.
- Aucune identité, aucun CIN, aucune PII dans les résultats ou les exports.

---

## F.4 Zéro écriture métier

| Table | Écriture |
|---|---|
| `executions_temporelles` | 1 ligne par jeu d'entrées non idempotent |
| `snapshots_executions` | `H + 1` lignes (pas 0 → H) |
| `scenarios_temporels` | 1 ligne par scénario créé explicitement |

Le test compare les compteurs **avant / après** et l'empreinte complète
d'un étudiant témoin. Aucune écriture sur `etudiants`,
`inscriptions_academiques`, `notes`, `groups`, `facultes`, `niveaux`.

---

## F.5 Garde d'exécution renforcée

Contrairement aux paramètres optionnels des scénarios précédents, le creux
introduit un **risque de division par zéro** si une ligne est insérée en
base hors du service. Deux barrières :

1. **Définition** (`validerCriteres`) : trio indissociable, bornes,
   observabilité, incompatibilités.
2. **Exécution** (`executer`) : mêmes bornes de forme vérifiées avant toute
   simulation — `durées ≥ 1`, `profondeur ∈ ]0 ; 1]`,
   `d + r ≤ horizon`. Une ligne corrompue est refusée par
   `DomainException`, jamais exécutée en erreur fatale.

---

## F.6 Traçabilité et déterminisme

| Mécanisme | Effet |
|---|---|
| `hash_entrees` | Inclut le trio, la graine, l'année, les variantes, la version moteur `1.5` |
| `hash_resultat` | Empreinte SHA-256 du résultat complet (référence, écarts, creux inclus) |
| Idempotence | Mêmes entrées → exécution existante, aucun doublon |
| Version moteur | Tracée ; les exécutions v1.4 restent lisibles |

---

## F.7 Contraintes et refus explicites

| Situation | Réponse |
|---|---|
| Trio incomplet | `DomainException` « indissociables » |
| Profondeur 0 | `DomainException` « strictement positive » |
| Profondeur > 1 | `DomainException` « au plus égale à 1 » |
| Durée < 1 | `DomainException` « au moins égale à 1 » |
| `d + r > horizon` | `DomainException` « dépassent l'horizon » |
| Creux + gel | `DomainException` « SIM-03 » |
| Creux + choc | `DomainException` « SIM-04 » |
| Creux + cible | `DomainException` « SIM-05 » |

Aucun refus silencieux, aucun résultat partiel.

---

## F.8 Réserves de sécurité

| ID | Réserve | Traitement |
|---|---|---|
| `SIM06-SEC-1` | La création HTTP n'appelle pas le catalogue ; la protection repose sur la validation moteur | Héritée ; le trio est détecté dès qu'une clé est présente, y compris à l'exécution |
| `SIM06-SEC-2` | Catalogue global, scénarios seuls porteurs de valeurs | Comportement identique aux contrats ST-14 précédents |
| `SIM06-SEC-3` | Pas de throttling spécifique | Throttling existant du contrôleur ; hors périmètre |

---

## F.9 Verdict sécurité

✅ **PASS_WITH_RESERVATIONS** — permissions, isolation, zéro écriture métier
et absence de PII prouvées ; garde d'exécution renforcée pour le trio.
Les réserves sont architecturales et héritées, jamais masquées.
