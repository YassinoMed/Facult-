# LOOP-POPUP-00-BASELINE — Popup intelligente d'inscription

Date : 2026-10-14. Environnement : dev local (`http://localhost:8000`, MySQL dev, tests SQLite `:memory:`, Chrome système + harness `/tmp/e2e-autonome`).

## État initial (inspecté)
- Page `/inscription/nouvelle` (`inscription.create`) : bloc de choix « Déjà inscrit ? » avec lien **direct** `Continuer mon inscription →` (`href=route inscription.existant.accueil`, navigation immédiate au clic), modale de détection post-identification (filet), formulaire 6 étapes (`inconnu` seul), Alpine (`periodeInscription`, `inscriptionForm`), aucune popup de choix.
- `/inscription/existant` : parcours OTP canonique (11 routes), inchangé par cette loop.
- Backend : strictement réutilisé (aucun endpoint à créer) ; CSRF/throttles/unicité/idempotence déjà prouvés.
- Tests du périmètre : `InscriptionAccueilParcoursTest` 6/6 + `CinPrefillTest` 27/27 = **33/33 verts** (baseline avant modification).
- E2E : harness opérationnel (dernier run 31/31, 0 erreur JS).

## Lacune à traiter
Le clic « Continuer » navigue immédiatement, sans laisser le choix (nouveau vs existant) ni contexte. Aucune logique à dupliquer : pure couche présentation (lien → bouton + modale + 2 liens canoniques).
