# Préférences, favoris, vues (Phase 7)

- **Favoris** (`user_favoris`, UNIQUE user×type×référence) : toggle
  personnel, suppression propriétaire seul (404 sinon). Le lien reste
  gated à l'ouverture — aucun contrôle au favori lui-même.
- **Récents** (`elements_recents`, plafond 20) : écriture sur pages
  autorisées (dossier, demande), lecture palette + cockpit.
- **Vues sauvegardées** (`vues_sauvegardees`, tableaux taches/
  etudiants/demandes/epreuves) : la vue `par_defaut` pré-remplit les
  filtres du registre au chargement sans query.
- **Préférences serveur** (`user_preferences`, cache 1 h) : densité
  (`densite-compact`, classe `body`), lignes par page 5–100
  (registre étudiants). Thème/sidebar restent en localStorage.
- Raccourcis : voir page préférences (`?` dans la palette).
