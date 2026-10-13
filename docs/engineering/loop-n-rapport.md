# LOOP N — Rapport technique (13/10/2026)

## Architecture avant/après

Avant : session 120 min sans verrou intermédiaire ; inactivité invisible.
Après : `SecondaireVerrou` (`auth`+groupes admin) impose l'état serveur
(`derniere_activite` + flag, GET→écran sans données, non-GET→423/JSON) ;
overlay Alpine (avertissement, inter-onglets, zéro heartbeat) ; PIN bcrypt +
temporisation + throttle dans `verrouillage_pins` ; paramètres versionnés ;
hooks reset/suspension/mot de passe.

## Fichiers créés/modifiés

Créés : migration `verrouillage_pins` + 6 seeds, `VerrouillagePin`,
`VerrouillageService`, `SecondaireVerrou`, `VerrouillageController`,
vues `verrouillage/ecran|code`, composant `verrouillage-surveillance`,
`VerrouillageSecondaireTest` (10), `loop-n-plan.md` (+ présent).
Modifiés : `SettingDefinition` (+6 clés), `bootstrap/app.php` (alias),
`routes/web.php` (5 routes + groupe verrouillé), `AuthController`
(initialisation ×2), `AdministrateurController` (purges),
`layouts/app.blade.php` (overlay si éligible, jamais sur l'écran).

## Fonctionnement / sécurité / paramètres

Délai 15 min / préavis 60 s / PIN 6-8 / 5 tentatives / 30 s ×n (plafond
15 min) / throttle 5/min ; PIN éligible-seulement ; setup = mot de passe
(+TOTP si actif) ; récupération = reset super-admin ; logs sans secret ;
aucun privilège ajouté ; MFA/déconnexion/sessions préservés.

## Tests exacts

`VerrouillageSecondaireTest` **10/10 (77 assertions)** : setup, garde
sans-PIN, GET écran + POST 423 (+JSON), reprise sans reconnexion, brute
force (4 erreurs + 429 + temporisation DB + throttle config), session
expirée, manuel + hooks, overlay ciblé, 3 profils. Voisins : MFA/
Révocation/Archives **48/48**, Connexion/Auth **97 (96+1 skip)**. Pint vert
périmètre. Bugs trouvés par tests et corrigés : noms de routes
`admin.verrouillage.*`, `x-data` Alpine, throttle cumulé inter-tests,
limite effective 4+429.

## Risques résiduels et décisions

Portails étudiant/enseignant : overlay + gate non appliqués (pas de PIN
liable, pas de piège) — réserve P2, extension décisionnelle. API tokens :
hors périmètre session (documenté). Navigateur réel : NOT_EXECUTED.
Polling futur : liste d'exclusion vide, à alimenter si besoin.
Production : NOT_VERIFIED.

## Verdict : `PASS_WITH_RESERVATIONS`
