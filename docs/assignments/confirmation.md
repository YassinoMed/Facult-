# Confirmation

Par groupe : verrou simulation → obsolescence → re-vérification
(`controler` + `affecter` avec `lockForUpdate` capacité) → écriture →
`confirme_le/par` → audit `assignment.group_confirmed` → statut.

- Groupe déjà confirmé → 0 écriture (idempotent, double-clic sûr).
- Deux admins confirment G1 → le 2e trouve 0 item à traiter.
- Tranches (200/requête nouveau flux avec bouton « Continuer »,
  500/requête `appliquerAuto` sous le seuil avec reliquat réaffiché,
  sans worker ; au-delà de `affectations.lots.seuil_sync` (1000),
  `appliquerAuto` bascule sur le lot asynchrone — progression sur la
  page du lot, plus de reliquat à reconfirmer) : échecs définitifs
  tracés `non_affecte` (pas de boucle infinie) ; écriture optimisée
  (`contexteBulk` + notification unique en lot, ~10 ms/affectation).
- Modification manuelle avant confirmation : re-validée (`modifie`,
  audit `assignment.manually_changed`), jamais de contournement.
- Annulation : possible tant que tout n'est pas confirmé.
