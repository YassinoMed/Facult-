# ST-14 — Phases A→J non commencées (0 / 10)

**Campagne :** ST-14 — scénarios avancés du simulateur temporel
**Date :** 2026-11-06
**Statut global :** 20 scénarios développés (PASS_WITH_RESERVATIONS), 26 scénarios non commencés (NOT_STARTED)

---

## A. Légende des phases

| Phase | Preuve attendue |
|-------|-----------------|
| **A** | Audit (`00-audit.md`) |
| **B** | Spécification (`01-specification.md` + `02-formules.md`) |
| **C** | Conception (`03-architecture.md`) |
| **D** | Développement (`04-implementation.md`) |
| **E** | Validation mathématique (`06-tests.md` + `07-resultats.md`) |
| **F** | Sécurité (`05-securite.md`) |
| **G** | Tests (`06-tests.md` + `07-resultats.md`) |
| **H** | UX (`08-guide-utilisation.md`) |
| **I** | Documentation (dossier complet `00→09`) |
| **J** | Verdict (`09-verdict.md`) |

Symbole `—` = phase non commencée (0/10).

---

## B. Scénarios non commencés (NOT_STARTED — 0 phase sur 10)

### LOT C — Capacités et infrastructures (SIM-20 → SIM-25)

Bloqué par `L0-R7` : capacité par défaut 35 non institutionnelle. Aucune valeur par défaut n'est inventée.

| Code | Nom | A | B | C | D | E | F | G | H | I | J | Réserve |
|------|-----|---|---|---|---|---|---|---|---|---|---|---|
| SIM-20 | Saturation des capacités | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| SIM-21 | Extension des capacités | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| SIM-22 | Perte temporaire de capacité | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| SIM-23 | Création théorique de groupes | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| SIM-24 | Réduction de capacité par groupe | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| SIM-25 | Répartition déséquilibrée des groupes | — | — | — | — | — | — | — | — | — | — | L0-R7 |

### LOT D — Budgets et ressources (SIM-26 → SIM-30)

Bloqué par `L0-R6` : aucun coût unitaire / devise / enveloppe validé. Aucune valeur par défaut n'est inventée.

| Code | Nom | A | B | C | D | E | F | G | H | I | J | Réserve |
|------|-----|---|---|---|---|---|---|---|---|---|---|---|
| SIM-26 | Budget constant | — | — | — | — | — | — | — | — | — | — | L0-R6 |
| SIM-27 | Réduction budgétaire | — | — | — | — | — | — | — | — | — | — | L0-R6 |
| SIM-28 | Coût supplémentaire par groupe | — | — | — | — | — | — | — | — | — | — | L0-R6 |
| SIM-29 | Investissement de capacité | — | — | — | — | — | — | — | — | — | — | L0-R6 |
| SIM-30 | Croissance sous contrainte budgétaire | — | — | — | — | — | — | — | — | — | — | L0-R6 |

### LOT E — Crises et résilience (CR-01 → CR-06)

Bloqué par `L0-R7` (dépendance à LOT C). Dépend aussi de A (SIM-01 → SIM-13).

| Code | Nom | A | B | C | D | E | F | G | H | I | J | Réserve |
|------|-----|---|---|---|---|---|---|---|---|---|---|---|
| CR-01 | Choc négatif sur les admissions | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| CR-02 | Crise de réussite et de rétention | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| CR-03 | Admissions élevées et réussite dégradée | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| CR-04 | Réduction importante des capacités | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| CR-05 | Retour progressif après une crise | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| CR-06 | Crises successives | — | — | — | — | — | — | — | — | — | — | L0-R7 |

### LOT F — Sensibilité et incertitude (UNC-01 → UNC-08)

Bloqué par `L0-R7` (dépendance à LOT A → F).

| Code | Nom | A | B | C | D | E | F | G | H | I | J | Réserve |
|------|-----|---|---|---|---|---|---|---|---|---|---|---|
| UNC-01 | Sensibilité combinée | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| UNC-02 | Stress testing | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| UNC-03 | Analyse de robustesse | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| UNC-04 | Comparaison probabiliste | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| UNC-05 | Détection des seuils de rupture | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| UNC-06 | Analyse de récupération | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| UNC-07 | Dépendances et interactions | — | — | — | — | — | — | — | — | — | — | L0-R7 |
| UNC-08 | Stabilité de la frontière de Pareto | — | — | — | — | — | — | — | — | — | — | L0-R7 |

### Campagne d'intégration (INT)

Bloqué : dépend de la clôture individuelle de tous les scénarios précédents.

| Code | Nom | A | B | C | D | E | F | G | H | I | J | Réserve |
|------|-----|---|---|---|---|---|---|---|---|---|---|---|
| ST-14.45 | Intégration globale (INT-001 → INT-015) | — | — | — | — | — | — | — | — | — | — | — |

---

## C. Condition de déblocage

Aucun scénario de cette section ne peut entrer en phase A (audit) tant que les réserves métier suivantes ne sont pas levées par l'autorité institutionnelle :

| Réserve | Scénarios bloqués | Valeur institutionnelle requise |
|---------|-------------------|----------------------------------|
| `L0-R7` | SIM-20 → 25, CR-01 → 06, UNC-01 → 08 | Capacité réelle par groupe / niveau (référentiel faculté) |
| `L0-R6` | SIM-26 → 30 | Coût unitaire, devise, enveloppe budgétaire (service financier) |

Règle ST-14 : **aucune valeur par défaut n'est inventée**. Aucun développement, aucun test et aucun verdict ne sont produits avant levée de la réserve correspondante.

---

## D. Fichiers de preuve

Chaque scénario non commencé dispose d'un dossier `ST-14/<CODE>/` contenant un fichier `00-bloque.md` qui documente le statut `BLOCKED_BUSINESS`, la réserve bloquante, les dépendances et les 10 phases A→J marquées `⛔ bloqué`.
