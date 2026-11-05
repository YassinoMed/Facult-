# ST-11 — 14 · Performance et fiabilité

Mesures (sortie réelle, suite 51 tests) :

| Mesure | Valeur |
|---|---|
| Suite complète (51 tests, 305 assertions) | 4,4 s |
| Scénario de charge existant (`test_performance_20_variantes_6_ans`) | inclus dans la suite, sous plafond |
| Plafonds moteur | 10 000 événements max ; horizon borné ; N Monte-Carlo ≤ 200 |

Concurrence : idempotence par hash + contrainte UNIQUE SQL (deux exécutions identiques → une seule ligne) — testée. L'ajout concurrent sur le même scénario passe par la contrainte unique (échec contrôlé). Preuve multi-processus MySQL : réservée CI (R-06).

Dégradation contrôlée : une erreur d'analyse (export/IA/alertes) ne corrompt pas l'exécution persistée — les analyses sont calculées après persistance du résultat principal ; les erreurs remontent en messages contrôlés (DomainException → JSON erreur), jamais d'état partiel (les écritures du module se limitent aux 3 tables techniques dans une transaction).

**Verdict domaine : PASS_WITH_RESERVATIONS** (R-06 concurrence multi-processus).
