# SIM-05 — 05 · Sécurité, permissions et lecture seule

**Date :** 2026-11-03  
**Périmètre :** exécution de la comparaison à une cible de croissance

---

## F.1 Permissions

Aucune permission nouvelle. Le scénario réutilise strictement :

| Permission | Usage |
|---|---|
| `simulation.view` | Catalogue (lecture du contrat SIM-05) |
| `simulation.gerer` | Création / définition de scénario |
| `simulation.executer` | Exécution et snapshots |
| `simulation.export` | Export des résultats (existant) |
| `simulation.explication` | Explication des variantes (existant) |
| `simulation.gouvernance.proposer` / `.decider` | Circuit de gouvernance (existant) |

Le test de sécurité vérifie :

- utilisateur **sans permission** → `403 Forbidden`, **aucune** exécution
  créée ;
- admin de **faculté B** exécutant un scénario de **faculté A** →
  `403 Forbidden` (isolation facultaire `FacultePerimetre`).

---

## F.2 Isolation facultaire

| Contrôle | Implémentation |
|---|---|
| Catalogue | Contrat global : identique pour tous les acteurs habilités (`simulation.view`), sans donnée métier |
| Définition | `faculte_id` du scénario ; l'adaptateur lit uniquement le périmètre autorisé |
| Exécution | Vérification du périmètre avant toute lecture d'effectifs |
| Export / consultation | Périmètre facultaire existant, inchangé |

La cible SIM-05 est un **paramètre de scénario** : elle ne donne accès à
aucune donnée supplémentaire. Un acteur de la faculté B ne peut ni lire ni
exécuter le scénario de la faculté A, même si la cible est un simple nombre.

---

## F.3 Données personnelles et agrégats

- L'adaptateur `AdaptateurEffectifsCohortes` restitue des **agrégats** par
  niveau ; aucune identité, aucun nom, aucun CIN.
- Les snapshots (`etat` JSON) contiennent `cohortes` et `total` uniquement.
- Les écarts de cible portent sur des entiers agrégés d'admissions.
- Aucune PII dans les résultats, les logs ou les exports SIM-05.

---

## F.4 Zéro écriture métier

Périmètre technique écrit, et lui seul :

| Table | Écriture |
|---|---|
| `executions_temporelles` | 1 ligne par jeu d'entrées non idempotent |
| `snapshots_executions` | `H + 1` lignes (pas 0 → H) |
| `scenarios_temporels` | 1 ligne par scénario créé explicitement |

Tables métier **jamais écrites** : `etudiants`, `inscriptions_academiques`,
`notes`, `groups`, `facultes`, `niveaux`. Le test de lecture seule compare
les compteurs **avant / après** et l'empreinte complète d'un étudiant
témoin (`getAttributes()`).

---

## F.5 Traçabilité et déterminisme

| Mécanisme | Effet |
|---|---|
| `hash_entrees` | Inclut critères (dont la cible), graine, année, variantes, version moteur `1.4` |
| `hash_resultat` | Empreinte SHA-256 du résultat complet, cible incluse |
| Idempotence | Mêmes entrées → exécution existante retournée, aucun doublon |
| Version moteur | Tracée par exécution ; les exécutions v1.3 restent lisibles |

Aucune donnée n'est écrasée : deux cibles différentes produisent deux
exécutions distinctes, jamais une mise à jour silencieuse.

---

## F.6 Contraintes et refus explicites

| Situation | Réponse |
|---|---|
| Cible absente d'un scénario libre | Aucune comparaison : pas de clé de cible, pas d'erreur inventée |
| Cible présente mais invalide | `DomainException` explicite, aucune exécution |
| Cible + gel | `DomainException` orientant vers SIM-03 |
| Cible + choc | `DomainException` orientant vers SIM-04 |
| `delta ≤ cible` | `DomainException` « strictement supérieur » |
| Cible négative ou > 2.0 | `DomainException` « hors bornes » |

Aucun de ces refus n'est silencieux ni ne produit un résultat partiel.

---

## F.7 Réserves de sécurité

| ID | Réserve | Traitement |
|---|---|---|
| `SIM05-SEC-1` | La route de création n'appelle pas `CatalogueScenarios::validerParametres` : la protection repose sur la validation moteur dès que la cible est présente | Héritée de l'architecture ; documentée `SIM05-S4` ; aucune règle contournée côté moteur |
| `SIM05-SEC-2` | Le catalogue est global : la cible publiée n'est pas par-faculté | Comportement identique aux autres contrats ST-14 ; les valeurs métier restent dans les scénarios |
| `SIM05-SEC-3` | Aucun throttling spécifique à l'exécution | Throttling existant du contrôleur ; hors périmètre SIM-05 |

---

## F.8 Verdict sécurité

✅ **PASS_WITH_RESERVATIONS** — permissions prouvées (403 ×2), isolation
facultaire prouvée, zéro écriture métier prouvée, aucune PII. Les réserves
sont architecturales et héritées, jamais masquées.
