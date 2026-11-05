# LOOP EX-LIFECYCLE — 08 · Matrice de sécurité

## I1 · Acteurs testés (fixtures isolées, aucun rôle créé)

| Acteur | evaluations.view | evaluations.update | Périmètre | Comportement attendu | Test |
|---|---|---|---|---|---|
| super_admin | ✅ | ✅ | global | tout le cycle | EX-LC-001/008/011/045/047/051 |
| admin_scolarite | ✅ | ✅ | global | tout le cycle | EX-LC-002/005 + suites existantes |
| admin_faculte (fac A) | ✅ | ✅ | fac A | transitions/consultation **uniquement** fac A | EX-LC-017, EX-LC-007 |
| consultation | ❌ update | ❌ | global lecture | transitions 403, consultations OK | EX-LC-016 |
| enseignant / sans droit | ❌ | ❌ | — | 403 partout | couvert policy + suites authz gouvernance |

## I2 · Scénarios anti-contournement

| Vecteur | Verdict | Preuve |
|---|---|---|
| Écriture directe de `statut` via store | ignoré (jamais validé, écrasé par `draft`) | EX-LC-005 |
| `transiter` HTTP vers published | bloqué (double défense contrôleur + circuit) | EX-LC-012 |
| Appel service direct (hors HTTP) | mêmes gardes (matrice/verrou/audit) | EX-LC-018, EX-LC-013 |
| Rejeu de requête | refus métier, zéro écriture | EX-LC-047, EX-LC-011 |
| Manipulation `examen_id` (IDOR) | 403 Policy, état inchangé | EX-LC-017 |
| Manipulation `faculte_id` client | jamais lu — périmètre serveur uniquement | Policy `dansPerimetre` |
| Historique d'un autre périmètre | 403 sur endpoint prévisualisation | EX-LC-007 |
| Journal falsifié (update/delete) | `DomainException` (append-only modèle) | EX-LC-055 |
| Publication sans gouvernance | impossible en 1 clic ; circuit exige certification + 2 approbateurs distincts + empreinte | suites gouvernance (198 assertions) |
| Annulation sans autorisation | 403 | EX-LC-016 |

## I3 · Activation du module

- Routes examens vivent dans le module `evaluation` (charger via `ModuleServiceProvider`) : désactivation → 404 via `EnsureModuleEnabled`, inchangé.
- Aucun contournement par service : `ExamChargeService` refuse les bases non-test ; les autres services d'écriture d'état sont internalisés et appelés par le circuit uniquement.
- Suites module exécutées après changements : aucune régression (241 tests, §10).

## I4 · Isolation multi-facultés

Deux facultés (A/B) créées en fixture ; examen A vs examen B ; acteur admin_faculte A :
- consultation prévisualisation B → 403 ; transition B → 403 (état inchangé vérifié).
- listing : scope `pourFaculteId` (convention existante, non régressée).
- historique : journal consultable uniquement via le périmètre (aucune route publique du journal sans Gate `view`).

**Conclusion sécurité : aucun contournement démontrable du moteur unique ; les contrôles restent serveur-only.**
