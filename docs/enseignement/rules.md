# Enseignement — règles Phase 4

Inscription pédagogique : ECUE rattachée à une UE de la maquette ACTIVE
(même programme+année), `firstOrCreate` idempotent ; bulk `inscrireEcues`
(mêmes gardes, succès partiel avec rapport créés/existants/erreurs,
plafond 100/lot). Retrait interdit si évaluée (statut ≠ inscrit → 422).

Affectations : une seule active (capacité `lockForUpdate`, transfert
clôturé, 10 codes de contrôle) ; enseignants : rôle whitelist, volume ≥ 0,
désaffectation = clôture historisée ; groupes : cohérence programme,
sous-groupe ∈ groupe.

Charge : somme des affectations actives par type (CM/TD/TP/projet/autre),
seuils grade > faculté > global, statuts normal/surcharge/sous-charge
informatifs (jamais bloquants). `tableauServices` scopé faculté via
Teacher→département (les sans-département remontent en anomalies
qualité, pas dans le tableau restreint).

Recherche : sections Enseignements/Épreuves gated (permission + module)
et scopées (policies miroirs). API : générique `data/*`
(`data:exchange`) — aucun endpoint métier dédié (surface minimale,
comme `students`/`registrations` en lecture).
