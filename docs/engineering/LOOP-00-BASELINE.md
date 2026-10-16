# LOOP-00-BASELINE — Changement d'e-mail sans scolarité (maturation enterprise)

Date : 2026-10-14. Environnement : dev local (`http://localhost:8000`, MySQL `inscription_etudiants`, serving `artisan serve`, tests SQLite `:memory:`).

## Git
- 81 entrées `git status` (fichiers du périmètre e-mail **non suivis** `??` + 54 fichiers suivis modifiés hors périmètre) ; pas de commit pendant la loop (hors périmètre).
- Fichiers du périmètre (tous `??`, préexistants) : `DossierExistantController.php`, `DossierExistantService.php`, `DossierExistant*Request.php` (×5), `CodeConfirmationNouvelleAdresse.php` (+ 4 notifications), `resources/views/inscription/existant/` (×3), `tests/Feature/DossierExistant{Email,Parcours,Autonome}Test.php`, `docs/engineering/loop-existant-email-rapport.md`.
- Suivis touchés par les loops précédentes : `routes/web.php`, `app/Providers/AppServiceProvider.php` (limiters), `.env` local seul (`SESSION_SECURE_COOKIE=false`, git-ignoré).

## Routes (11, toutes groupe `web`)
`accueil`, `recherche` (5/min), `verification` (10/min), `formulaire`, `store` (5/min), `confirmation`, `email` (5/min), `email/confirmer` (10/min), `email/confirmer-nouvelle` (10/min), `autonome` (5/min), `autonome/confirmer` (10/min) — throttles nommés par fonction + limiteurs identité en service.

## Migrations
`migrate:status` : toutes exécutées sauf `2026_10_13_..._dossier_verifications` (Pending, hors périmètre verrouillage — non touchée). Contraintes utilisées : `etudiants.email` UNIQUE, `password` nullable, `mot_de_passe_version`. Aucune migration requise ni exécutée par cette loop.

## Tests baseline (avant modification)
`DossierExistantEmailTest|DossierExistantParcoursTest|DossierExistantAutonomeTest` : **82/82, 809 assertions**. Suite complète de référence : 3235 tests, 3209 verts, 4 échecs N+1 préexistants identiques au baseline (hors périmètre).

## Mécanismes réutilisés (pas de doublon)
OTP `random_int` hashés SHA-256, TTL 10/30 min, 5 essais, `Cache::pull` atomique, `password` bcrypt (`hashed`), `dontFlash` framework, notifications file `notifications` (codes synchrones), audit `HistoriqueAcademique` masqué, Alpine global (Livewire ESM), Chrome système + harness `/tmp/e2e-autonome` (18/18 §14.8).

## Lacunes à traiter (DEV)
1. Pas de confirmation pré-envoi sur l'autonome (LOOP 1 : popup 3 boutons).
2. Pas de snapshot de l'ancienne adresse (invariant 8 : changement source concurrent non détecté).
3. Pas de limite par destinataire candidate (mailbomb via CIN multiples).
4. Batterie adverse à compléter (codes croisés, double demande, 419 CSRF, annulation/expiration).
5. Mesures performance à produire (LOOP-06).
