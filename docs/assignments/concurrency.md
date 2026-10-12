# Concurrence

- Capacité : `Group::lockForUpdate` + re-vérification à l'écriture
  (29/30 + 2 admins → le 2e est refusé avec message explicite).
- Simulation : `AssignmentSimulation::lockForUpdate` à la confirmation.
- Obsolescence : hash re-calibré après chaque tranche (écritures
  propres absorbées, changement externe entre tranches détecté) ;
  chaque item reste re-validé à l'écriture (capacité verrouillée).
- Verrouillé : `affectations_groupe.verrouille` = jamais déplacé par
  simulation ; levée uniquement par action explicite tracée.
