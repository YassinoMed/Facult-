# LOOP-INSCRIPTION-STRICT-SAISIE — Contrôle strict CIN + date de naissance (4 niveaux)

**Date :** 2026-10-14. **Demande :** CIN = exactement 8 chiffres ; date de naissance = JJ/MM/AAAA
avec validation réelle (jour 01-31, mois 01-12, année 1900 → aujourd'hui, dates impossibles refusées).

## Architecture retenue

Le contrôle est effectué à **4 niveaux** ; la validation **serveur reste souveraine** (jamais de
confiance au navigateur — doctrine du projet) :

| Niveau | Mécanisme | Emplacement |
|---|---|---|
| 1. HTML5 | `required`, `minlength/maxlength="8"`, `pattern="[0-9]{8}"`, `inputmode="numeric"`, `pattern` date JJ/MM/AAAA, `maxlength="10"` | Les 3 champs d'identification : recherche (`/`), autonome (`/`), wizard (`/inscription/nouvelle`) |
| 2. JS/Alpine | Filtre CIN `oninput` (chiffres seuls, cap 8), auto-formatage `formaterDateNaissance()` (JJ/MM/AAAA), **pré-contrôles regex dans `verifierIdentite()` — AUCUNE requête serveur pour une saisie non conforme** | `accueil.blade.php`, `create.blade.php` (script inline, aucun build requis) |
| 3. Laravel | `VerifierCinRequest` + `DossierExistantAutonomeRequest` : `regex:\A[0-9]{8}\z`, `date_format:Y-m-d`, `before:today`, `after:1900-01-01` | FormRequests (deux, règles partagées via helper) |
| 4. Règle métier | `App\Support\SaisieIdentification::normaliserDateNaissance()` : JJ/MM/AAAA → Y-m-d avec **comparaison aller-retour** — `31/02/2025` (rollover silencieux de `DateTime::createFromFormat`) est refusé, jamais converti ; l'année plancher passe de 1920 à **1900** | `app/Support/SaisieIdentification.php` |

**Décision clé — normalisation, pas refonte :** la saisie publique devient JJ/MM/AAAA (texte +
auto-format), mais le format INTERNE des services (MENS, `whereDate`, vérification OTP) reste
Y-m-d : `prepareForValidation()` convertit AVANT validation, `validated()` renvoie Y-m-d. **Tous
les services/controllers existants sont inchangés** ; le format legacy Y-m-d (API, tests) reste
accepté (non-régression). Le helper unique évite la duplication entre les deux FormRequests
(convention « no duplication »).

## Fichiers modifiés/créés

| Fichier | Changement |
|---|---|
| `app/Support/SaisieIdentification.php` (**créé**) | Helper partagé : `filtrerCin` (filtre client), `normaliserDateNaissance` (JJ/MM/AAAA → Y-m-d, dates impossibles jamais roulées) |
| `app/Http/Requests/VerifierCinRequest.php` | Normalisation date en `prepareForValidation`, plancher 1900 (`after:1900-01-01`), messages reformulés pour le format affiché JJ/MM/AAAA |
| `app/Http/Requests/DossierExistantAutonomeRequest.php` | Même normalisation via le helper partagé (aucune duplication), mêmes bornes |
| `resources/views/inscription/existant/accueil.blade.php` | Recherche + autonome : filtre CIN `oninput`, date texte JJ/MM/AAAA (`pattern` + `maxlength="10"` + auto-format), aides explicites sous chaque champ, script `formaterDateNaissance` |
| `resources/views/inscription/create.blade.php` | Wizard étape 1 : mêmes contrôles sur `ident-cin`/`ident-naissance` ; **pré-contrôles regex dans `verifierIdentite()`** (CIN 8 chiffres puis date JJ/MM/AAAA — message explicite, retour immédiat, zéro requête serveur) ; `appliquerCinNaissanceManuel()` convertit JJ/MM/AAAA → Y-m-d pour le champ d'état civil (étape 2, `type="date"`) |
| `tests/Feature/SaisieIdentificationStricteTest.php` (**créé**) | 12 tests HTTP (54 assertions) : acceptation JJ/MM/AAAA + normalisation en session (Y-m-d), legacy Y-m-d accepté, identifier + autonome avec JJ/MM/AAAA, 31/02 refusé (message exact, aucun envoi), date future refusée, 1899 refusé, format tirets refusé, CIN lettres/court/long refusés, markup 4 niveaux des 2 vues |

## Sécurité préservée

- **Aucun oracle nouveau** : les rejets ont lieu en validation AVANT tout envoi d'OTP
  (`Notification::assertNothingSent()` dans les tests d'échec) ; la neutralité anti-énumération
  des réponses existantes est inchangée.
- **Zéro requête serveur pour saisie non conforme** (niveau 2) — prouvé en navigateur : les
  pré-contrôles bloquent avant `fetch`, le throttle `identifier` (5/min/IP) reste intact
  (429 observé lors de la saturation par les tests E2E eux-mêmes).
- Le CIN ne quitte jamais le format `regex:\A[0-9]{8}\z` côté serveur ; la date soumise est
  revalidée à chaque route (validation serveur exclusive).

## Tests et preuves réels

| Vérification | Résultat |
|---|---|
| `php artisan test --filter=SaisieIdentificationStricteTest` | **12/12 PASS, 54 assertions** |
| Batterie non-régression (email, parcours, autonome, accueil, CinPrefill, MENS ×2) | **159/159 PASS, 1248 assertions** |
| `vendor/bin/pint --test --dirty` | **PASS** |
| E2E navigateur (Chrome système, serveur live) : filtre CIN `12A45B678` → `1245678` ; `17052003` → `17/05/2003` ; pattern HTML (7 chiffres → `checkValidity()=false`) ; recherche réelle JJ/MM/AAAA → étape code affichée ; wizard : erreurs Alpine CIN puis date affichées avec **0 appel serveur** ; `identifier` réel `14/02/2005` → **200 EXISTING_STUDENT** (email masqué, redirection canonique) | **CONFORME** |
| Réserves | Aucune pour ce périmètre : comportements serveur et navigateur observés ; les 4 échecs N+1 pré-existants de la suite complète restent hors sujet (inchangés) |
