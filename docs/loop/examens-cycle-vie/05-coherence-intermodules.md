# LOOP EX-LIFECYCLE — 05 · Cohérence inter-modules

## E1 · Examen / SessionExamen

| Invariant vérifié | Preuve code |
|---|---|
| Session distincte de l'examen (séparation des objets) | matrices `TRANSITIONS` séparées, routes et permissions séparées |
| Création impossible dans une session verrouillée | `StoreExamenRequest::withValidator` + garde contrôleur `estVerrouillee()` |
| Année cohérente avec la session | `StoreExamenRequest` (erreur explicite) |
| Une transition d'examen ne supprime jamais de sessions | aucun `delete` de session dans `ExamenService` ; FK `cascadeOnDelete` seulement à la suppression **physique** de l'examen (nulle en métier) |
| Session déjà réalisée reste historisée | aucune écriture de session depuis le cycle examen |

## E2 · Examen / Epreuve

| Invariant | Preuve |
|---|---|
| Publication propage explicitement (règle D-06 documentée) | `ExamPublicationOrchestratorService` : bulk `epreuves scheduled→published` pour débloquer convocations |
| Aucune autre propagation implicite | `ExamenService` n'écrit jamais sur `evaluation_epreuves` |
| Incohérences détectables | `ExamScheduleEngineService::auditerSessionExamens` (audit conformité) signale conflits bloquants ; le cahier EX-LC-037 (clôturé avec épreuve modifiable) reste détecté par cet audit — aucune correction silencieuse de données historiques |

## E3 · Scénarios de planification

- Génération/appliquer protégés par `Gate update` + périmètre facultaire 404 (`genererScenarios`, `appliquerScenario`).
- L'application d'un scénario vérifie la validité côté `ExamScheduleEngineService` (`forcer` contrôlé) ; le refus gouvernance/l'audit de conformité détectent un scénario obsolète (empreinte).
- Une simulation ne modifie pas le planning officiel (retour JSON `scenarios` seulement).
- **Vérifié sans régression** par les suites existantes (`ExamensFeatureCompleteTest`, `ExamSessionConfigurationEnterpriseTest`).

## E4 · Gouvernance

| Chemin de contournement potentiel | Verdict |
|---|---|
| `transiter` → published en 1 clic | bloqué (double défense : contrôleur + circuit) |
| Appel direct `ExamenService::transiter(published)` | bloqué par l'audit contraintes dures + en pratique la gouvernance est le seul appelant légitime ; la publication réelle passe par `publierExamen` qui re-vérifie la permission dans le service |
| `publierExamen` hors HTTP | permission `evaluations.publish` re-vérifiée dans le service (défense en profondeur) |
| Job/API/Livewire | aucun chemin d'écriture de statut recensé |
| Commande | `ExamChargeService` refuse toute base non-test |

## E5 · Acquis académiques

- Aucune écriture de notes, crédits ou acquis dans le cycle de vie d'examen (aucune dépendance ajoutée).
- Distinction note obtenue / module validé / crédit acquis : portée par les services existants (`SaisieNoteService`, `CalculEvaluationService`, `PvDeliberationService`) — non touchés.
- `ServiceEligibiliteExamen` (éligibilité opérationnelle) non modifié — le cycle de vie ne le réimplémente pas.
