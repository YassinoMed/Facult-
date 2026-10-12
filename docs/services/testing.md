# Tests services (Phase 9 §79-81)

Unit : transitions (matrice), SLA (ouvrés, pause, retard imputable).
Feature : cycle complet étudiant→agent→document→notif→archive
(§96), rejet motivé conservé (§97), fil `waiting→réponse→in_review`
(§98), document numéroté/hash/GED/vérifié (§99). Concurrence :
double transition, deux agents, retry (verrous + 422 métier). SLA :
normal/retard/week-end (calendrier), escalade notifiée.
