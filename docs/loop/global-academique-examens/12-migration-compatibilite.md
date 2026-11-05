# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 12 · Migration et compatibilité

**Date :** 2026-11-03  
**Principe :** aucune migration destructive ; toute migration est additive et réversible  

---

## 1. Migrations introduites par ce LOOP

**Aucune.** Les corrections `ACQ-RED-01` sont exclusivement des corrections
de services PHP (méthode restaurée, clés de retour, non-blocage de la revue
humaine). Aucun schéma, aucune colonne, aucune table n'a été modifié par ce
LOOP.

---

## 2. Migrations WIP existantes à appliquer avant déploiement

L'état local (`php artisan migrate:status`, 2026-11-03) montre **2 migrations
en attente**, issues de LOOP antérieurs :

| Migration | Objet | Nature | `down()` |
|---|---|---|---|
| `2026_10_31_150000_creer_evaluation_examen_evenements` | Journal append-only des transitions d'examen | Création de table additive | Drop de la seule table créée |
| `2026_11_01_000000_creer_campagnes_redoublement` | Persistance des campagnes de redoublement (référence, agrégats, sans PII) | Création de table additive | Drop de la seule table créée |

Aucune de ces migrations ne modifie ou ne supprime une table existante.
Elles sont exécutées en mémoire par `RefreshDatabase` lors des tests, ce qui
explique que les suites soient vertes alors que la base locale de
développement ne les a pas encore appliquées.

**Conséquence :** un déploiement doit exécuter `php artisan migrate --force`
avant de servir le trafic ; sinon les journaux et les campagnes échoueront à
la persistance.

---

## 3. Compatibilité des contrats

| Contrat | Avant | Après | Rupture ? |
|---|---|---|---|
| A — Calcul académique | `revue_humaine[]` absent | `revue_humaine[]` ajouté, `avertissements_revue_humaine` ajouté | **Non** : ajout de clés, aucune suppression |
| B — Éligibilité | `DecisionEligibiliteExamen` | inchangé | Non |
| C — Planification | scénarios + audit | inchangé | Non |
| D — Gouvernance | `PlanningValidation` + événements | inchangé | Non |

Les consommateurs qui ignorent les nouvelles clés continuent de fonctionner.
Les tests historiques (201/201 Acquis) le prouvent.

---

## 4. Compatibilité des données

- Aucune donnée officielle n'est migrée, réécrite ou supprimée.
- Les identifiants, statuts et empreintes existants restent valides.
- Les journaux append-only ne sont jamais corrigés par `UPDATE`.
- La migration dry-run historique (`MigrationHistoriqueDryRunLoop41Test`)
  passe (incluse dans l'exécution 27/27 du 2026-11-03).

---

## 5. Retour arrière

En cas de régression fonctionnelle :

1. restaurer les trois services corrigés (`ReglementationAcquisService`,
   `ValidationApprobationPlanService`, `PlanRedoublementService`) ;
2. ne pas rollback les migrations WIP si elles ont déjà reçu des données :
   leur `down()` supprimerait les journaux/campagnes persistés ;
3. conserver les exécutions et journaux existants (aucune purge).

Le rollback **code** est donc possible sans perte de données ; le rollback
**schéma** est déconseillé après exploitation.

---

## 6. Verdict

**PASS_WITH_RESERVATIONS.** Aucune migration destructive, contrats
rétrocompatibles, rollback code sûr. La réserve opérationnelle est
l'application obligatoire des 2 migrations en attente avant déploiement.
