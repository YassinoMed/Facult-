# LOOP N — Plan (13/10/2026, avant code)

## Architecture retenue

- **Serveur source de vérité** : middleware `SecondaireVerrou` sur les 3
  groupes authentifiés (`auth` admin, `etudiant.auth`, `enseignant.auth`).
  Il tient `derniere_activite` en session ; si `maintenant - activite >
  delai` (paramètre) → état verrouillé : GET → écran verrou (200, sans
  données) ; non-GET → 423. Exemptés : déverrouiller, déconnexion, setup PIN
  (setup exige déverrouillé), assets. Aucun polling JS détecté (`app.js` :
  fetches du wizard public uniquement) → toute requête authentifiée vaut
  activité humaine.
- **Client** : overlay Alpine (timer local + `localStorage` inter-onglets,
  avertissement compte à rebours, aucun heartbeat réseau). Le client
  n'autorise rien : après verrouillage, la 1re requête reçoit l'écran/423.
- **PIN** : table `verrouillage_pins` (`user_id` unique FK cascade,
  `pin_hash` bcrypt, `echecs`, `bloque_until`) ; setup = mot de passe
  (+ TOTP si actif) puis code+confirmation ; vérification bcrypt +
  temporisation progressive (30 s × échecs, plafond 15 min) + throttle 5/min ;
  jamais en clair/logs ; récupération = reset super-admin (coupe le PIN,
  audité) ; changement mot de passe / logout / suspension coupe le contexte.
- **Paramètres** (`SettingDefinition`, portée globale, super_admin) :
  `verrouillage.actif` (bool, défaut true), `verrouillage.delai_minutes`
  (5..480, défaut 15), `verrouillage.preavis_secondes` (0..300, défaut 60),
  `verrouillage.pin_longueur` (6..8, défaut 6), `verrouillage.tentatives_max`
  (3..10, défaut 5), `verrouillage.temporisation_secondes` (10..300,
  défaut 30). Seeds insert-if-missing.
- **Profils** : back-office `users` (5 rôles, inchangés) ; portails
  étudiant/enseignant par session — verrouillage appliqué au niveau
  session aussi ? Décision : **oui**, même middleware, PIN = celui du compte
  lié si existant, sinon verrouillage sans PIN (écran + reconnexion) —
  NON : complexité. **Tranché** : portails couverts avec PIN uniquement si
  compte `users` lié (enseignant) ; étudiant (sans compte) = verrouillage
  visuel + reconnexion exigée. Tests par profil en conséquence.

## Risques

- Faux verrouillage massif si `derniere_activite` mal initialisée →
  initialiser à la connexion (3 flux) et au déverrouillage ; défaut
  `verrouillage.actif=true` mais PIN inexistant = verrouillage sans
  déverrouillage possible ! **Garde** : verrouillage effectif seulement si
  PIN défini, sinon simple avertissement + journal. Testé.
- Polling futur compté comme activité → liste d'exclusion configurable
  (vide à ce jour, testée).
- `back()` après déverrouillage : `redirect()->intended` conservé en
  session avant verrouillage (pas de perte de page).

## Fichiers prévus

Migration `verrouillage_pins` + seeds paramètres ; `VerrouillageService` ;
`SecondaireVerrou` (+ alias) ; `VerrouillageController`
(setup/confirmer/modifier/verifier/deconnecter) ; routes ; vues
(écran, setup) ; composant overlay ; `SettingDefinition` (+6 clés) ;
hooks (login×3, logout, reset, suspension) ; tests `VerrouillageSecondaireTest`.
