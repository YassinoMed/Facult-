# SIM-10 — 05 · Sécurité, permissions et lecture seule

**Date :** 2026-11-05  
**Périmètre :** variation mono-axe générique (réduction du redoublement)

---

## F.1 Permissions

Aucune permission nouvelle. Réutilisation stricte des permissions existantes :

| Permission | Usage |
|---|---|
| `simulation.view` | Catalogue (lecture du contrat SIM-10) |
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

Le jeu modifié est un paramètre de scénario : aucune donnée supplémentaire
n'est accessible par SIM-10.

---

## F.3 Données personnelles

- Agrégats d'effectifs uniquement (adaptateur en lecture seule).
- Restitutions SIM-10 : entiers agrégés (totaux, écarts, sortants).
- Trajectoire témoin **en mémoire** : aucun snapshot supplémentaire.
- Aucune identité, aucun CIN, aucune PII dans les sorties ou les logs.

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
| **Définition** (`validerCriteres`) | Trio indissociable, bornes [0,1], somme ≤ 1, axe/sens ∈ vocabulaire, référence complète, direction stricte, incompatibilités gel/choc/cible/creux, exclusion mutuelle des alias (blocs SIM-07/08 en premier) |
| **Exécution** (gardes dans `executer`) | Mêmes contrôles de forme, de direction et d'exclusion avant toute simulation |
| **Catalogue** | Schéma épinglé `redoublement` / `baisse`, incompatibilités structurelles |

L'ordre des blocs (SIM-07 → SIM-08 → générique) rend toute combinaison
ambiguë impossible à atteindre.

---

## F.6 Traçabilité et déterminisme

| Mécanisme | Effet |
|---|---|
| `hash_entrees` | Inclut `taux_modifie`, `axe_variation`, `sens_variation`, la graine, l'année, les variantes, la version moteur `1.8` |
| `hash_resultat` | Empreinte SHA-256 du résultat complet |
| Idempotence | Mêmes entrées → exécution existante, aucun doublon |
| Version moteur | Tracée ; exécutions v1.7 et antérieures lisibles |
| Version SIM-10 | `1`, formules `1.0` |

---

## F.7 Contraintes et refus explicites

| Situation | Réponse |
|---|---|
| Trio incomplet | `DomainException` « indissociables » |
| Jeu modifié incomplet | `DomainException` « doit fournir passage, redoublement et abandon » |
| Axe invalide | `DomainException` « axe_variation doit être passage, redoublement ou abandon » |
| Sens invalide | `DomainException` « sens_variation doit être hausse ou baisse » |
| Redoublement égal ou supérieur | `DomainException` « strictement inférieur » |
| Somme modifiée > 1 | `DomainException` « > 1 » |
| Taux hors [0,1] | `DomainException` « hors [0,1] » (moteur) / « hors bornes » (catalogue) |
| Référence absente | `DomainException` « taux de référence requis » |
| Variation + gel / choc / cible / creux | `DomainException` « SIM-03 / 04 / 05 / 06 » |
| Alias passage simultané | `DomainException` « SIM-07 » ou « SIM-08 » |
| Axe ou sens hors contrat catalogue | Refus « non autorisée » |

Aucun refus silencieux, aucun résultat partiel, aucune valeur par défaut.

---

## F.8 Réserves de sécurité

| ID | Réserve | Traitement |
|---|---|---|
| `SIM10-SEC-1` | La création HTTP n'appelle pas le catalogue ; la protection repose sur la validation moteur | Héritée (`SIM09-SEC-1`) ; le descripteur est détecté dès la présence d'une des trois clés, à la définition **et** à l'exécution |
| `SIM10-SEC-2` | Le moteur accepte tout axe du vocabulaire ; SIM-10 épingle `redoublement` au schéma catalogue | Reclassement assumé : la contrainte d'axe est portée par le contrat du scénario, pas par le moteur partagé |
| `SIM10-SEC-3` | Pas de throttling spécifique | Throttling existant du contrôleur ; hors périmètre |

---

## F.9 Verdict sécurité

✅ **PASS_WITH_RESERVATIONS** — permissions, isolation, zéro écriture métier
et absence de PII prouvées ; exclusion mutuelle des trois formes prouvée.
Les réserves sont architecturales et héritées, jamais masquées.
