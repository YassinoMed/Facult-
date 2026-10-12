# Catalogue KPI (Phase 10 §10.4-10.5)

Population : `effectif`, `effectif_par_niveau`, `inscriptions_par_statut`.
Parcours : `taux_passage` (décisions publiées admis* / publiées),
`redoublements` (réinscriptions explicites jury).
Résultats : `moyenne_mediane` (n affiché, insuffisant si n<5),
`distribution_moyennes` (5 tranches), `credits_validation` (%).
Enseignement : `volumes_enseignement` (ouverts + heures prévues).
Services : `demandes_retard` (hors pause étudiant).
Documents : `documents_genere` (valides / révoqués).
Gouvernance : `anomalies_ouvertes` (périmètre : siennes + globales).
Cohorte : année bac × niveau (descriptif, jamais prédictif).

Chaque entrée expose `valeur + definition + source` (gouvernance
affichée). Versions : `BIService::version()` (md5 max/count 5 tables).
