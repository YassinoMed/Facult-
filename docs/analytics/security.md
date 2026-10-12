# Sécurité BI (Phase 10 §10.18)

`statistiques.view` (lecture) / `statistiques.export` (export) ;
périmètre faculté imposé serveur (filtre global ignoré si restreint).
Anomalies : siennes + globales (jamais celles d'une autre faculté).
Exports : throttle 10/min, audités, agrégats sans PII. Cache scopé
par clé (pas de lecture inter-périmètre). Consultations sensibles
(dashboards) : mêmes gates que les pages sources.
