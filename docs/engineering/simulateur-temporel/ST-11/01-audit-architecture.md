# ST-11 — 01 · Audit d'architecture

## Inventaire réconcilié (26 fichiers, 2 713 lignes)

| Couche | Composants |
|---|---|
| HTTP | `SimulationTemporelleController` (16 actions, 408 l.) — orchestration seule, aucun calcul métier |
| Services | `ServiceSimulationTemporelle` (533 l.) — point d'entrée métier unique (définir/dupliquer/versionner/exécuter) + `VERSION_MOTEUR` |
| Adaptateurs | `AdaptateurEffectifsCohortes` (lecture seule des effectifs) + `ContratAdaptateur` (interface) |
| Moteur | `MoteurEvenements` (file déterministe) + `HorlogeSimulee` (temps simulé) + `EvenementSimule` (DTO) |
| Analyses | GenerateurVariantes, EvaluateurScenarios, ClasseurScenarios, ExplicateurScenarios, ComparateurProjections, EstimateurCapacitesGroupes, AnalyseurSensibilite, SimulateurMonteCarlo, OptimiseurPareto, ProjecteurBudgetaire, AuditeurQualiteDonnees, GestionnaireAlertesSeuils |
| Transverse | ExplicateurIaSimulation (local), ExporteurSimulation (CSV/PDF), GouvernancePlanificationService (4 yeux) |
| Modèles | ScenarioTemporel (versionné), ExecutionTemporelle (hash+idempotence), SnapshotExecution |
| Vues | index.blade.php (5 onglets), pdf.blade.php |

## Vérifications

- **Aucun doublon** : chaque responsabilité a UNE classe ; pas de moteur parallèle ; l'isolation facultaire réutilise les primitives `User::peutAccederFaculte()` (zéro redéfinition — commentaire FacultePerimetre).
- **Pas de dépendance circulaire** : Support ← Services ← Controller ; adaptateur consommé par le service uniquement.
- **Contrôleur sans logique métier** : validation d'entrée + périmètre + délégation (vérifié — les calculs vivent dans Services/Support).
- **Le décompte « 15 services » annoncé** : incohérent — réel = 1 service + 17 classes Support + 2 adaptateurs (détail dans 00-baseline).

**Verdict domaine : PASS** — architecture canonique respectée, aucune correction nécessaire.
