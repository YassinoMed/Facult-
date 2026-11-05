# LOOP INDUSTRIALISATION ÉVALUATION — 10 · Registre des risques

| ID | Risque | Probabilité | Gravité | Criticité | Preuve | Mesure | État | Responsable |
|---|---|---|---|---|---|---|---|---|
| RISK-011 | Fuite inter-facultés | faible | élevée | ÉLEVÉE | Policy + suites (403) | périmètre serveur forcé | Contrôlé | Scolarité |
| RISK-013 | Historique écrasé | (avant campagne : possible) | élevée | ÉLEVÉE | ANO-01 | garde append-only + test SEC-012 | **Corrigé** | Technique |
| RISK-015 | État partiel en échec | faible | élevée | ÉLEVÉE | rollback intégral testé | transaction par lot | Contrôlé | Technique |
| RISK-016 | Rejeu de Job | faible | moyenne | MOYENNE | re-verrouillage à l'exécution | état rechargé sous verrou | Contrôlé | Technique |
| RISK-005 | Salle surcapacitaire | faible | élevée | ÉLEVÉE | moteur capacité + audit conformité | contrôle avant application scénario | Contrôlé (hors lot notes) | Planification |
| RISK-003 | Calcul non reproductible | faible | moyenne | MOYENNE | historique + versions | journal avant/après + `valide_at` | Contrôlé | Technique |
| RISK-014 | Cache obsolète | très faible | faible | FAIBLE | permissions réévaluées à chaque requête | — | Contrôlé | Technique |
| RISK-004* | Éligibilité mal interprétée | faible | élevée | ÉLEVÉE | service central fail-closed | règles en anomalie, jamais implicite | Contrôlé (hors lot) | Académique |
| RISK-009 | Approbation d'un autre contenu | très faible | critique | CRITIQUE | empreinte + verrous gouvernance | contrôle à chaque étape | Contrôlé | Gouvernance |
| RISK-010 | Publication non autorisée | très faible | critique | CRITIQUE | circuit + permissions + verrous | re-vérification en service | Contrôlé | Gouvernance |

\* Risques de la matrice globale du cahier hors périmètre notes : repris pour exhaustivité, contrôlés par les mécanismes des LOT antérieurs (EX-LIFECYCLE, gouvernance §2.7/§2.8).

## Risques résiduels ouverts (non critiques)

| ID | Risque | État | Décision attendue |
|---|---|---|---|
| R-11 | Auto-validation par la même personne (D-11) | Ouvert | Direction des études |
| R-12 | Rectification post-publication (D-12) | BLOCKED_BUSINESS | Autorité académique |
| R-06 | Concurrence MySQL multi-processus non démontrée localement | Ouvert | Environnement CI (pcntl/MySQL) |
| R-07 | UX responsive/accessibilité non audité en profondeur | Ouvert | Campagne UX dédiée |
