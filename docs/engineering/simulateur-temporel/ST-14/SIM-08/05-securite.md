# SIM-08 — 05 · Sécurité, permissions et lecture seule

**Date :** 2026-11-04  
**Périmètre :** comparaison de deux jeux de taux (dégradation)

---

## F.1 Permissions

Aucune permission nouvelle. Réutilisation stricte :

| Permission | Usage |
|---|---|
| `simulation.view` | Catalogue (lecture du contrat SIM-08) |
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

Les taux dégradés sont des paramètres de scénario : aucune donnée
supplémentaire accessible.

---

## F.3 Données personnelles

- Agrégats d'effectifs uniquement (adaptateur en lecture seule).
- Restitutions SIM-08 : entiers agrégés (totaux, écarts, sortants).
- Trajectoire témoin **en mémoire** : aucun snapshot supplémentaire.
- Aucune identité, aucun CIN, aucune PII.

---

## F.4 Zéro écriture métier

| Table | Écriture |
|---|---|
| `executions_temporelles` | 1 ligne par jeu d'entrées non idempotent |
| `snapshots_executions` | `H + 1` lignes (pas 0 → H) — trajectoire principale uniquement |
| `scenarios_temporels` | 1 ligne par scénario créé explicitement |

Compteurs métier et empreinte étudiant témoin comparés **avant / après** :
aucune écriture sur `etudiants`, `inscriptions_academiques`, `notes`,
`groups`, `facultes`, `niveaux`.

---

## F.5 Garde d'exécution et exclusion mutuelle

| Niveau | Contrôle |
|---|---|
| **Définition** (`validerCriteres`) | Jeu dégradé complet, bornes [0,1], somme ≤ 1, référence requise, baisse stricte, incompatibilités gel/choc/cible/creux, exclusion mutuelle avec `taux_ameliore` (bloc SIM-07) |
| **Exécution** (gardes dans `executer`) | Mêmes contrôles de forme et d'exclusion avant toute simulation |

L'exclusion mutuelle hausse/dégradation est vérifiée **en premier** par le
bloc SIM-07 : aucune combinaison ambiguë ne peut atteindre le moteur.

---

## F.6 Traçabilité et déterminisme

| Mécanisme | Effet |
|---|---|
| `hash_entrees` | Inclut les deux jeux de taux, la graine, l'année, les variantes, la version moteur `1.7` |
| `hash_resultat` | Empreinte SHA-256 du résultat complet |
| Idempotence | Mêmes entrées → exécution existante, aucun doublon |
| Version moteur | Tracée ; exécutions v1.6 et antérieures lisibles |

---

## F.7 Contraintes et refus explicites

| Situation | Réponse |
|---|---|
| `taux_degrade` incomplet | `DomainException` « doit fournir passage, redoublement et abandon » |
| Passage ≥ référence | `DomainException` « strictement inférieur » |
| Somme dégradée > 1 | `DomainException` « > 1 » |
| Taux dégradé hors [0,1] | `DomainException` « hors [0,1] » |
| Référence absente | `DomainException` « taux de référence requis » |
| Dégradation + gel | `DomainException` « SIM-03 » |
| Dégradation + choc | `DomainException` « SIM-04 » |
| Dégradation + cible | `DomainException` « SIM-05 » |
| Dégradation + creux | `DomainException` « SIM-06 » |
| Amélioration + dégradation | `DomainException` « SIM-08 » (bloc SIM-07) |

Aucun refus silencieux, aucun résultat partiel.

---

## F.8 Réserves de sécurité

| ID | Réserve | Traitement |
|---|---|---|
| `SIM08-SEC-1` | La création HTTP n'appelle pas le catalogue ; la protection repose sur la validation moteur | Héritée ; le jeu dégradé est détecté dès sa présence, à la définition **et** à l'exécution |
| `SIM08-SEC-2` | Catalogue global, scénarios seuls porteurs de valeurs | Comportement identique aux contrats ST-14 précédents |
| `SIM08-SEC-3` | Pas de throttling spécifique | Throttling existant du contrôleur ; hors périmètre |

---

## F.9 Verdict sécurité

✅ **PASS_WITH_RESERVATIONS** — permissions, isolation, zéro écriture métier
et absence de PII prouvées ; exclusion mutuelle prouvée. Les réserves sont
architecturales et héritées, jamais masquées.
