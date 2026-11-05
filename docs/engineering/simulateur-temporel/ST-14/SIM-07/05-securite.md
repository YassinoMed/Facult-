# SIM-07 — 05 · Sécurité, permissions et lecture seule

**Date :** 2026-11-04  
**Périmètre :** comparaison de deux jeux de taux de flux

---

## F.1 Permissions

Aucune permission nouvelle. Réutilisation stricte :

| Permission | Usage |
|---|---|
| `simulation.view` | Catalogue (lecture du contrat SIM-07) |
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

Les taux déclarés (référence et améliorés) sont des paramètres de scénario :
ils n'ouvrent l'accès à aucune donnée supplémentaire.

---

## F.3 Données personnelles

- Agrégats d'effectifs uniquement (adaptateur en lecture seule).
- Les restitutions SIM-07 (totaux comparés, écarts, sortants) sont des
  entiers agrégés.
- La trajectoire de référence parallèle vit **en mémoire** : aucun snapshot
  supplémentaire, aucune donnée nominative.
- Aucune identité, aucun CIN, aucune PII dans les résultats ou les exports.

---

## F.4 Zéro écriture métier

| Table | Écriture |
|---|---|
| `executions_temporelles` | 1 ligne par jeu d'entrées non idempotent |
| `snapshots_executions` | `H + 1` lignes (pas 0 → H) — trajectoire principale uniquement |
| `scenarios_temporels` | 1 ligne par scénario créé explicitement |

Le test compare les compteurs **avant / après** et l'empreinte complète d'un
étudiant témoin. Aucune écriture sur `etudiants`, `inscriptions_academiques`,
`notes`, `groups`, `facultes`, `niveaux`.

---

## F.5 Garde d'exécution

| Niveau | Contrôle |
|---|---|
| **Définition** (`validerCriteres`) | Jeu amélioré complet, bornes [0,1], somme ≤ 1, référence requise, hausse stricte du passage, incompatibilités gel/choc/cible/creux |
| **Exécution** (garde dans `executer`) | Forme du jeu amélioré (3 clés), référence complète présente, incompatibilités avec les autres profils — refus explicite avant toute simulation |

Aucun risque de division ou d'indice manquant : le garde de forme élimine
les tableaux incomplets avant lecture.

---

## F.6 Traçabilité et déterminisme

| Mécanisme | Effet |
|---|---|
| `hash_entrees` | Inclut les deux jeux de taux, la graine, l'année, les variantes, la version moteur `1.6` |
| `hash_resultat` | Empreinte SHA-256 du résultat complet (référence comparée, écarts, sortants inclus) |
| Idempotence | Mêmes entrées → exécution existante, aucun doublon |
| Version moteur | Tracée ; les exécutions v1.5 restent lisibles |

---

## F.7 Contraintes et refus explicites

| Situation | Réponse |
|---|---|
| `taux_ameliore` incomplet | `DomainException` « doit fournir passage, redoublement et abandon » |
| Passage ≤ référence | `DomainException` « strictement supérieur » |
| Somme améliorée > 1 | `DomainException` « > 1 » |
| Taux amélioré hors [0,1] | `DomainException` « hors [0,1] » |
| Référence absente | `DomainException` « taux de référence requis » |
| Amélioration + gel | `DomainException` « SIM-03 » |
| Amélioration + choc | `DomainException` « SIM-04 » |
| Amélioration + cible | `DomainException` « SIM-05 » |
| Amélioration + creux | `DomainException` « SIM-06 » |

Aucun refus silencieux, aucun résultat partiel.

---

## F.8 Réserves de sécurité

| ID | Réserve | Traitement |
|---|---|---|
| `SIM07-SEC-1` | La création HTTP n'appelle pas le catalogue ; la protection repose sur la validation moteur | Héritée ; le jeu amélioré est détecté dès sa présence, à la définition **et** à l'exécution |
| `SIM07-SEC-2` | Catalogue global, scénarios seuls porteurs de valeurs | Comportement identique aux contrats ST-14 précédents |
| `SIM07-SEC-3` | Pas de throttling spécifique | Throttling existant du contrôleur ; hors périmètre |

---

## F.9 Verdict sécurité

✅ **PASS_WITH_RESERVATIONS** — permissions, isolation, zéro écriture métier
et absence de PII prouvées ; garde de forme à l'exécution pour le double
taux. Les réserves sont architecturales et héritées, jamais masquées.
