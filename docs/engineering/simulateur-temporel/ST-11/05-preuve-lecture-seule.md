# ST-11 — 05 · Preuve de lecture seule et absence de mutation métier (Phase C)

> **Cohérence inter-documents (2026-11-02).** Ce rapport est réconcilié avec `15-plan-corrections.md` (tableau « Compléments post-verdict ») et `18-verdict-ST11.md` (annexe Phase B/C). Correspondance principale : **C3 ↔ ST11-ANO-03** (preuve avant/après élargie à 10 tables) ; réserve C5 = **ST11-RO-R1** (atomicité exécution + snapshots). Verdict global inchangé : `PASS_WITH_RESERVATIONS`.

**Date :** 2026-11-02 · **Mode :** AUDIT_ONLY + TESTING
**Objectif :** démontrer que la campagne et le module ne modifient **aucune** donnée métier (étudiants, inscriptions, groupes, référentiel, notes, décisions) — par inspection exhaustive des chemins d'écriture **et** par comparaison avant/après.

---

## 1. C1 — Adaptateur de lecture : classification des requêtes

### 1.1 `AdaptateurEffectifsCohortes::etatInitial()` (84 l.)

| # | Opération | Table(s) | Type |
|---|---|---|---|
| 1 | `AnneeUniversitaire::find($anneeSourceId)` | `annees_universitaires` | SELECT |
| 2 | `Niveau::find($niveauId)` (si niveau filtré) | `niveaux` | SELECT |
| 3 | `Group::whereIn($groupesIds)->pluck('code')` (si groupes) | `groups` | SELECT |
| 4 | Sous-requête `Etudiant::whereIn('groupe_id', …)` | `etudiants` | SELECT |
| 5 | Sous-requête `AffectationGroupe::whereIn(...)->where(annee)->where(statut)` | `affectations_groupe` | SELECT |
| 6 | `InscriptionAcademique` agrégée : `LEFT JOIN niveaux`, `whereHas(programme)`, `whereHas(etudiant)`, `COUNT(*)`, `GROUP BY`, `ORDER BY` | `inscriptions_academiques`, `niveaux`, `programmes`, `etudiants` | SELECT |

→ **Aucun** INSERT/UPDATE/DELETE, **aucune** transaction explicite, **aucun** event/job. La méthode ne retourne que des agrégats (`cohortes: {code→int}`, `total_initial`). Refus explicite si aucune donnée (`DomainException`, jamais de zéro silencieux).

### 1.2 `AuditeurQualiteDonnees` (232 l.)

- `AnneeUniversitaire::find`, `Faculte::find`, `Niveau::with('programme')->find`, `Group::whereIn(...)->get` → SELECT.
- `collecterStatistiques()` : `DB::table('inscriptions_academiques')->count()` + sous-requêtes `whereExists` sur `programmes` et `etudiants` → **SELECT uniquement** (aucun `insert/update/delete` sur le query builder).

### 1.3 Autres classes

`MoteurEvenements`, `HorlogeSimulee`, `GenerateurVariantes`, `EvaluateurScenarios`, `ClasseurScenarios`, `ExplicateurScenarios`, `ComparateurProjections`, `EstimateurCapacitesGroupes` (lit `Group`), `AnalyseurSensibilite`, `SimulateurMonteCarlo`, `OptimiseurPareto`, `ProjecteurBudgetaire`, `GestionnaireAlertesSeuils` : **calcul pur**, aucun accès base hors `Group` en lecture.
`ExplicateurIaSimulation` : lit `execution.resultat` + scénario (SELECT), ne persiste rien.
`ExporteurSimulation` : lit l'exécution, écrit uniquement sur `php://output` (flux HTTP) — **aucun fichier temporaire**.

**Conclusion C1 : aucun composant du module n'exécute d'écriture hors des 3 tables techniques.**

---

## 2. C2 — Registre des tables protégées

### 2.1 Inventaire réel du schéma

`254` tables créées par les migrations (`grep "Schema::create" database/migrations | wc -l = 254`). Le registre complet est trop large pour être listé table par table ici ; la protection est démontrée **par construction** : le module ne référence qu'un ensemble fermé de modèles/tables.

### 2.2 Modèles référencés par le module (grep exhaustif `app/Modules/SimulationTemporelle/`)

| Modèle | Usage | Écriture ? |
|---|---|---|
| `ScenarioTemporel` | scénarios | ✅ table technique |
| `ExecutionTemporelle` | exécutions | ✅ table technique |
| `SnapshotExecution` | snapshots | ✅ table technique |
| `Etudiant` | lecture agrégée (filtre groupes/faculté) | ❌ SELECT |
| `InscriptionAcademique` | lecture agrégée | ❌ SELECT |
| `AffectationGroupe` | lecture agrégée | ❌ SELECT |
| `Group` | lecture (capacités, cascade niveau) | ❌ SELECT |
| `Niveau` | lecture (référentiel) | ❌ SELECT |
| `AnneeUniversitaire` | lecture | ❌ SELECT |
| `Faculte` | lecture (audit qualité) | ❌ SELECT |
| `User` | identité/périmètre (gouvernance, IA) | ❌ lecture |

### 2.3 Tables métier protégées (registre explicite — toute écriture y est interdite)

**Cœur académique :** `etudiants`, `inscriptions_academiques`, `inscriptions_pedagogiques`, `historique_academique`, `situations_etudiant`, `dossiers_paiement`, `paiements`, `factures`, `facture_lignes`, `recus_paiement`, `remboursements`, `tarifs`.
**Structure pédagogique :** `facultes`, `programmes`, `niveaux`, `mentions`, `domaines`, `specialites`, `type_diplomes`, `semestres`, `semestres_lmd`, `matieres`, `unites_enseignement`, `maquettes`, `maquette_ue`, `maquette_ue_ecue`, `groups`, `sous_groupes`, `affectations_groupe`, `affectations_enseignement`, `enseignements`.
**Évaluation & décisions :** `notes`, `evaluation_notes`, `evaluation_notes_historique`, `evaluation_decisions`, `evaluation_deliberations`, `evaluation_epreuves`, `evaluation_examens`, `evaluation_sessions`, `diplomes`.
**Parcours & alertes :** tables `parcours_*`, `alertes_decrochage`, `suivis_decrochage`, `regles_decrochage`, `campagnes_redoublement`.
**Identités :** `users`, `personnels`, `teachers`, `iam_*`.

### 2.4 Tables techniques du module (écritures autorisées)

`scenarios_temporels`, `executions_temporelles`, `snapshots_executions` — additives, réversibles (migration `2026_10_25_040000_creer_tables_simulation_temporelle.php`), sans FK entrante depuis les tables métier.

---

## 3. C3 — Preuve avant/après (exécutée)

### 3.1 Tests existants (verts, exécutés ce jour)

| Test | Protocole | Résultat |
|---|---|---|
| `test_preuve_absolue_absence_ecriture_tables_metier` | snapshot complet (toutes lignes, `orderBy id`, `toArray`) de **10 tables** : `inscriptions_academiques`, `etudiants`, `programmes`, `niveaux`, `matieres`, `unites_enseignement`, `groups`, `notes`, `evaluation_decisions` (`DecisionDeliberation`), `inscriptions_pedagogiques` ; puis exécution 10 variantes/3 ans + **ré-exécution idempotente** + export CSV ; puis comparaison `assertSame` ligne à ligne | **PASS** |
| `aucune_ecriture_metier_et_snapshots_sans_pii` | exécution + inspection des snapshots (aucun champ nominatif) | **PASS** |

Mesure : `php artisan test --filter="ecriture|snapshots_sans_pii"` → **2 tests PASS, 24 assertions**.

### 3.2 Vérifications complémentaires du test

- `ExecutionTemporelle::where(scenario)->count() === 1` après double exécution → idempotence réelle (aucune ligne en double) ;
- `SnapshotExecution::count() > 0` → seules les tables du module ont été écrites.

### 3.3 Limites honnêtes de la preuve dynamique

- 10 tables comparées sur 254 : la preuve dynamique est **ciblée** sur les tables à plus fort risque (cœur académique).
- La couverture **globale** est assurée par la preuve statique (C1 + C2 + inventaire exhaustif des écritures §4), qui démontre l'absence de tout autre chemin d'écriture.
- **Recommandation Phase M** : test instrumenté `DB::listen()` pendant une exécution complète, assertant que toute requête non-SELECT ne cible que les 3 tables techniques — couverture exhaustive sans comparaison de 254 tables.

---

## 4. C3-bis — Inventaire exhaustif des écritures du module (grep + lecture)

| # | Site | Table | Condition |
|---|---|---|---|
| 1 | `ServiceSimulationTemporelle::definir` l.84 | `scenarios_temporels` | création scénario |
| 2 | `ServiceSimulationTemporelle::nouvelleVersion` l.111 | `scenarios_temporels` | nouvelle version (append, l'ancienne n'est jamais écrasée) |
| 3 | `ServiceSimulationTemporelle::dupliquer` l.129 | `scenarios_temporels` | duplication |
| 4 | `ServiceSimulationTemporelle::executer` l.334 | `executions_temporelles` | après calcul complet réussi |
| 5 | `ServiceSimulationTemporelle::executer` l.350 | `snapshots_executions` | boucle post-création |
| 6 | Contrôleur `executer` l.151 | `executions_temporelles` | `forceFill(cree_par)` **uniquement** si `wasRecentlyCreated && cree_par === null` (CORR-1 : jamais sur la voie idempotente) |
| 7 | `GouvernancePlanificationService::soumettreProposition` l.79 | `executions_temporelles` | proposition 4-yeux |
| 8 | `GouvernancePlanificationService::decider` l.139 | `executions_temporelles` | décision 4-yeux |

**Total : 8 sites d'écriture, 3 tables techniques, zéro écriture métier.** Aucune écriture sur `users` (les noms des acteurs sont stockés dans le JSON de l'exécution, pas dans la table `users`).

---

## 5. C4 — Effets secondaires

| Canal | Constat | Preuve |
|---|---|---|
| Events / Listeners | aucun `dispatch`, `Event::`, listener dans le module | grep exhaustif |
| Jobs / Queue | aucun job, aucune notification | grep exhaustif |
| Mail | aucun `Mail::` / `notify` | grep exhaustif |
| Fichiers | aucun `Storage::`, `File::`, `file_put_contents` ; seul `fopen('php://output')` (flux CSV) | grep |
| Cache applicatif | uniquement les compteurs de throttle (middleware framework), aucun cache métier | revue |
| Sessions | aucune écriture session spécifique au module | revue |
| Exports | flux HTTP uniquement, pas de fichier temporaire, PDF en mémoire | `ExporteurSimulation` |
| Historique technique | `executions_temporelles.resultat` (gouvernance) — traçabilité attendue | service |

**Mode AUDIT_ONLY** (routes GET : index, scénarios, options, audit qualité, exécutions, détail) : aucune écriture (les GET ne font que des SELECT — confirmé par le grep §4, tous les sites d'écriture sont sur des POST).

---

## 6. C5 — Tests négatifs et robustesse en cas d'échec

| Scénario d'échec | Comportement | Preuve |
|---|---|---|
| Validation formulaire invalide | 422 avant toute écriture | `reprise_apres_erreur_formulaire` PASS ; validations contrôleur |
| `DomainException` métier (bornes, taux, groupes) | 422, aucune exécution créée | tests `contraintes_incompatibles...`, `groupe_d_un_autre_niveau...` PASS |
| Année source inexistante | 422, aucune écriture | `source_annee_universitaire_inexistante_rejetee` PASS |
| Graine manquante/invalide | 422 | `graine_manquante_ou_invalide_rejetee` PASS |
| Moteur : plafond d'événements | `DomainException` avant persistance (calcul en mémoire, écriture en fin de méthode) | architecture `executer` l.172-332 (calcul) puis l.334 (écriture) |
| Ré-exécution idempotente | retourne l'exécution existante, **aucune** nouvelle ligne | test `execution_reproductible_et_idempotente` PASS |
| Export en erreur | flux non persisté (pas de fichier partiel) | `ExporteurSimulation` (stream) |
| Gouvernance : resoumission | refus, historique conservé | `st10_gouvernance_resoumission_refusee_et_trace_conservee` PASS |

**Réserve ST11-RO-R1 (MOYEN)** : `executer` n'enveloppe pas la création `execution` + `snapshots` dans une **transaction**. Si l'insertion d'un snapshot échouait après la création de l'exécution, un état partiel (exécution sans tous ses snapshots) resterait en base. Aucun impact sur les tables métier (les 3 tables sont techniques), mais l'intégrité de l'artefact technique n'est pas garantie atomiquement. Correction Phase N recommandée : `DB::transaction(fn () => …)` autour des l.334-356. Aucune décision métier requise.

---

## 7. C6 — Synthèse et GATE C

| Anomalie | Gravité | Statut |
|---|---|---|
| ST11-RO-R1 · atomicité exécution + snapshots (pas de transaction) | MOYEN | correction Phase N |
| Preuve dynamique limitée à 10 tables | INFO | renforcement Phase M (`DB::listen`) |

**Aucune écriture métier constatée** : ni par inspection exhaustive des 8 sites d'écriture, ni par comparaison avant/après des 10 tables sensibles, ni par l'absence totale d'effets secondaires (events/jobs/files/cache métier).

### ✅ GATE C : FRANCHI — verdict provisoire `PASS_WITH_RESERVATIONS`
La garantie de lecture seule est démontrée par (1) l'inventaire exhaustif des chemins d'écriture, (2) la preuve avant/après exécutée, (3) l'inventaire des effets secondaires. Seule réserve : l'atomicité interne des artefacts techniques (hors données métier).
