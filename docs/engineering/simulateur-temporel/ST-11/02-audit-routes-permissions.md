# ST-11 — 02 · Audit routes & permissions

## 1. Matrice des 16 routes (état APRÈS correction P0-1)

Groupe : `auth` + `admin` + `secondaire.verrou` + `module:simulation-temporelle` + préfixe `admin` · Fichier : `app/Modules/SimulationTemporelle/Routes/web.php`.

| Route | Verbe | Action contrôleur | Permission (route + Gate contrôleur) | Throttle |
|---|---|---|---|---|
| `simulation-temporelle` | GET | `index` | `simulation.view` | — |
| `…/scenarios` | GET | `scenariosIndex` | `simulation.view` | — |
| `…/scenarios` | POST | `scenarioCreer` | `simulation.gerer` | `st-def` 20/m |
| `…/scenarios/{id}/executer` | POST | `executer` | `simulation.executer` | `st-exec` 10/m |
| `…/options/niveaux` | GET | `optionsNiveaux` | `simulation.view` | — |
| `…/options/groupes/{niveauId}` | GET | `optionsGroupes` | `simulation.view` | — |
| `…/audit-qualite` | GET | `auditQualite` | `simulation.view` | — |
| `…/scenarios/{id}/dupliquer` | POST | `scenarioDupliquer` | `simulation.gerer` | `st-dup` 20/m |
| `…/scenarios/{id}/nouvelle-version` | POST | `scenarioNouvelleVersion` | `simulation.gerer` | `st-ver` 20/m |
| `…/executions/{id}/export/csv` | GET | `exportCsv` | `simulation.export` | — |
| `…/executions/{id}/export/pdf` | GET | `exportPdf` | `simulation.export` | — |
| `…/executions/{id}/expliquer-ia` | POST | `expliquerIa` | `simulation.explication` | `st-ia` 20/m |
| `…/executions/{id}/gouvernance/proposer` | POST | `gouvernanceProposer` | `simulation.gouvernance.proposer` | `st-gov` 15/m |
| `…/executions/{id}/gouvernance/decider` | POST | `gouvernanceDecider` | `simulation.gouvernance.decider` | `st-gov` 15/m |
| `…/executions` | GET | `executionsIndex` | `simulation.view` | — |
| `…/executions/{id}` | GET | `executionDetail` | `simulation.view` | — |

**Double défense** : chaque action du contrôleur répète `Gate::authorize(...)` avec la MÊME permission que la route (16/16 synchronisés, vérifié par script).

## 2. Anomalie P0-1 (corrigée)

**Avant** : les 16 routes + 16 Gates utilisaient `statistiques.view` — permission de **consultation** détenue par 7 rôles (`admin_scolarite`, `agent_scolarite`, **`consultation`**, `admin_faculte`, `certificateur_qualite`, `direction_etudes`, `autorite_publication`).

**Conséquence démontrée** : un compte de consultation pouvait créer/exécuter des scénarios et **statuer une proposition de gouvernance** (approuver/rejeter la planification proposée par autrui) — rupture de la séparation des responsabilités (cahier §B1 : « Ne pas supposer que `evaluations.*` est un wildcard… » ; transposé ici à `statistiques.view`).

**Correction (respectant le cahier)** :
1. 7 permissions dédiées ajoutées à `Permissions::TOUTES` (source de vérité) → Gates auto-enregistrés par `AppServiceProvider` (ligne 209).
2. Routes + Gates par famille (matrice §1).
3. **Aucun rôle existant élargi** : seuls les comptes habilités individuellement (IAM `HabilitationIam`, moteur LOOP 43-BIS) ou `super_admin` (TOUTES) obtiennent les accès.
4. Tests : acteurs de test habilités via le chemin IAM réel ; 3 tests de sécurité nouveaux (SEC-011/012/013) vérifiant le refus consultation + non-attribution automatique aux rôles.

**Comportement** : refus par défaut (403) pour tout acteur non habilité — le module est devenu plus restrictif, jamais plus permissif.

## 3. Isolation facultaire (B2) — vérifiée dans les 16 actions

- `FacultePerimetre::resoudre(user, demandée)` : refus `AuthorizationException` si faculté demandée hors périmètre ; retourne la faculté restreinte du compte sinon.
- Chaque accès à un scénario/exécution : `if ($objet->faculte_id !== null && ! $user->peutAccederFaculte(...)) abort(403)`.
- Requêtes de listing : `->when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))` — filtrage **serveur**.
- Tests existants : `test_isolation_perimetre_et_module`, `test_options_niveaux_filtrees…` (403 hors périmètre) — ✅ non régressés.

## 4. Validation d'entrée par action

- `scenarioCreer` : FormRequest-like validation inline (`faculte_id` exists + périmètre, `niveau_id`, `groupes[]`, bornes…).
- `executer` : `annee_universitaire_id` exists, `graine` requis, `nb_variantes` [1..N], validation métier complète dans `ServiceSimulationTemporelle::validerCriteres` (delta ∈ bornes, horizon ∈ [1..plafond], taux ∈ [0,1], somme ≤ 1, groupes compatibles niveau).
- `gouvernanceProposer/Decider` : titre/justification/motif/decision requis, décisions restreintes à `approuvee|rejetee`.

## 5. Conclusion

**PASS_WITH_RESERVATIONS** — la seule réserve est institutionnelle : l'attribution des 7 permissions `simulation.*` aux rôles métier (décision à documenter ; par défaut, refus par défaut = comportement sûr).
