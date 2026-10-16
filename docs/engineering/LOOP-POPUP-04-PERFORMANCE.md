# LOOP-POPUP-04-PERFORMANCE — Popup de choix du parcours

Date : 2026-10-14. Matériel : macOS arm64, Chrome 155 headless, serveur dev `:8000`. Méthode : harness `/tmp/e2e-autonome/run.cjs` (compteur requêtes document/xhr/fetch + `performance.now()` autour du clic d'ouverture).

## Valeurs observées
- Ouverture popup : **18 ms** (clic → titre visible + focus).
- Requêtes réseau à l'ouverture/fermeture : **0** (ni document, ni xhr/fetch).
- Requêtes SQL à l'ouverture/fermeture : **0 par construction** (aucun appel backend ; vérifié par relecture : le dialogue ne contient que des liens).
- Dépendances JS ajoutées : **0** (Alpine déjà chargé) ; ~4 Ko HTML statique.
- Rechargement de page : aucun (ouverture, fermeture, Échap, choix B).
- Erreurs console/page pendant les scénarios : **0**.

## Conclusion
Coût d'intégration nul en réseau/base ; aucun seuil requis. Pas de double chargement (un seul bundle Vite), pas de polling ajouté.
