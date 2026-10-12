# API + échanges demandes (Phase 9 §71, §76)

Pas d'endpoints `requests` dédiés (workflow protégé : création double,
transitions verrouillées —exposées web uniquement). Admin : export
métadonnées `data/demandes/export` (`data:exchange`, scopé, jamais de
contenu/pièce). Import interdit (contournerait le workflow).
Suivi étudiant : portail + notifications (pas de polling API).
