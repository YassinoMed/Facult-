# ST-11 — 08 à 13 · Audits analytiques consolidés (trajectoires, OAT, Monte-Carlo, Pareto/Budget, IA, Qualité/Alertes)

## 08 · Trajectoires et classements (F1-F4)

- **Séries** : snapshot 0 = référence (état réel de l'adaptateur, jamais recalculé) ; snapshots 1..horizon = projections horodatées (`anneeBase+p`). Référence ≠ projection toujours distinguables.
- **Écarts** : division par zéro protégée (`totalₚ₋₁ > 0 ? … : 0` — F-VAR) ; écart relatif non calculable → 0 explicite (convention documentée, jamais NaN).
- **Classement multicritère** (`EvaluateurScenarios` + `ClasseurScenarios`) : score = Σ contributions pondérées − pénalités de contraintes ; **les pénalités dominent** (un scénario non conforme ne peut pas être premier) — testé (`test_contrainte_durée_isole_les_non_conformes`). Explication par contribution (`ExplicateurScenarios`, lot 3 ST-10).
- **Sensibilité du classement** : score reproductible à entrées égales (déterminisme) ; changement de poids = effet traçable, sans effet caché.

## 09 · Sensibilité OAT (G1-G5)

- `AnalyseurSensibilite` : perturbation **un paramètre à la fois** (admissions, passage ; autres maintenus constants) — conforme OAT.
- Élasticité = Δrésultat/Δparamètre ; **taux absents → jamais inventés** (testé : `test_st10_taux_absents_non_inventes_monte_carlo_et_sensibilite`).
- Cas limites : référence nulle → élasticité non calculable signalée ; résultat final nul = valeur valide.
- **Limite documentée** : OAT ne détecte pas les interactions inter-paramètres — jamais présentée comme exhaustive.

## 10 · Monte-Carlo (H1-H5)

- **Génération** : perturbations explicites et constantes — delta ±8 %, passage ±4 % (hypothèses codées, jamais dynamiques) ; clamp [0,1] ; graine par itération via hash (déterministe).
- **Statistiques** : P10/P50/P90 par index plancher après tri (convention « index plancher / médiane supérieure pour n pair » — documentée) ; écart-type **population** (variance/n) ; min/max exacts ; fréquence de dépassement = dépassements/n.
- **Taille d'échantillon** : N ∈ [10, 200] (`MAX_ITERATIONS=200`) — **50 itérations ne prouvent pas de convergence** : les sorties sont « intervalle P10-P90 simulé » (jamais des probabilités institutionnelles). Avertissement explicite quand taux absents.
- **Indépendance** : graines séparées (`:mc_admissions` / `:mc_taux`) — indépendance **assumée et documentée**, aucune corrélation introduite sans source.

## 11 · Pareto et budget (I1-I3)

- **Frontière** (`OptimiseurPareto`) : dominance directionnelle (maximiser score, minimiser pénalités) ; égalités → non-dominées mutuelles ; vérifié lot 9 ST-10 (petits ensembles contrôlés). **Non-dominance ≠ décision optimale** — la décision reste humaine (bannière « Simulations uniquement »).
- **Budget** (`ProjecteurBudgetaire`) : paramètres non saisis → **hypothèse fictive explicitement marquée** (`parametres_configures=false` + « aucun impact réel » — testé). Aucun tarif par défaut inventé ; mention « sans valeur comptable » exigée côté UI (ST-12).

## 12 · Explication IA (J1-J5)

- `ExplicateurIaSimulation` : **purement local et déterministe** — gabarits de phrases nourris par les données structurées du résultat (140 lignes, **aucun client HTTP**, aucune donnée transmise). Le nom « Ia » ne préjuge de rien : vérifié par lecture.
- **Fidélité** : chaque phrase interpole des valeurs du résultat — aucun chiffre inventé possible (pas de génération libre). Test lot 12 ST-10 : correspondance explication ↔ valeurs.
- **hash_preuve** : lie l'explication aux données de l'exécution (vérification ultérieure de non-altération).
- **Distinctions** : observation (snapshots) / calcul (écarts, scores) / hypothèse (budget par défaut, taux manquants) / recommandation — portées par les champs dédiés (`avertissement`, `taux_absents`, `parametres_configures`).

## 13 · Qualité des données et alertes (K1-K3)

- `AuditeurQualiteDonnees` : anomalies `QUAL_*` (code, gravité, source, critère) ; **blocage sans inscription valide** explicite — l'adaptateur lève `DomainException` « Aucune inscription validée… » (jamais de zéro silencieux).
- Score de complétude ∈ [0,1] calculé sur les données réellement lues ; **une complétude élevée ne prétend pas à l'exactitude** (aucune affirmation de ce type dans les sorties).
- `GestionnaireAlertesSeuils` : saturation de capacité, hausse annuelle excessive, déficit budgétaire — **seuils issus des paramètres du scénario** (plafond, bornes) — aucun seuil inventé ; déclenchement = dépassement d'une règle définie.
