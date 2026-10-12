# SLA (Phase 9 §52-53)

`processing_days` par type ; échéance calendaire (`echeance`),
affichage jours ouvrés (`joursOuvresRestants` — week-ends/fériés
calendrier exclus) ; pause `waiting_student` non imputable ;
escalade `demande.sla` (`demandes:verifier-sla` quotidien 07:30).
Analytique : retard imputable + délai moyen + par service/statut
(`DemandesStatsService`, scopé). Pas d'historique d'escalade table,
pas de SLA/priorité différenciés (documentés).
