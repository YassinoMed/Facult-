# LOOP N — Plan (13/10/2026, avant code)

## Audit de la tentative antérieure (session du 13/10, reprise)

Un run précédent a livré plan + implémentation + rapport (`PASS_WITH_RESERVATIONS`)
sans commit. Conformément aux règles LOOP, ses affirmations ont été re-exécutées :
`VerrouillageSecondaireTest` 10/10 réel. L'audit ligne à ligne révèle **3 écarts
au mandat** nécessitant une reprise :

1. **Perte de données non enregistrées (P1)** : au terme local, le client fait
   `window.location.reload()` → le DOM (formulaires non soumis) est remplacé
   par l'écran serveur → contredit §3.D « conserver la page courante et les
   données non enregistrées » et §5.9 « ne pas introduire de perte de
   données ». Correctif : **voile opaque client** (contenu recouvert, DOM
   préservé) + déverrouillage par `fetch` (sans rechargement). L'écran serveur
   reste le recours autoritaire (JS inactif, désynchronisation, navigation
   directe).
2. **Divergence compteurs client/serveur (P1)** : une saisie continue > délai
   sans requête HTTP laisse le serveur expirer alors que le client compte
   l'activité → la soumission reçoit 423 → perte de données. Et le bouton
   « Rester connecté » ne prolonge JAMAIS le serveur, même sur action
   explicite (contradiction §3.B). Correctif : route `verrouillage/prolonger`
   (POST, auth, throttle) appelée **uniquement** sur événements de saisie
   réels (debounce ≥60 s, jamais de `setInterval`) et au clic « Rester
   connecté » ; elle ne touche `derniere_activite` QUE si le verrou n'est pas
   déjà acquis (flag posé OU délai dépassé → 423 : aucun rafraîchissement
   rétroactif impossible). Menace inchangée : toute requête authentifiée
   touche déjà le minuteur via `SecondaireVerrou::toucher` — un client
   scripté n'a besoin d'aucune route dédiée pour cela ; le verrou vise la
   consultation furtive d'une machine laissée déverrouillée, pas le vol de
   cookie.
3. **Écran serveur étend `layouts.app` (P2)** : la navigation complète et le
   menu utilisateur s'affichent verrouillés (§3.C masquer le contenu).
   Correctif : shell autonome minimal (thème clair/sombre conservé).

Autres écarts corrigés dans la reprise : `verifier()` sans branche JSON
(contrat `fetch` ambigu : 302 suivi → HTML 200), compteur d'échecs non
atomique (concurrence §4), réinitialisation par broker (`MotDePasseController::
enregistrer`) ne purge pas le PIN, `verifier()` ne restaure pas
`secondaire_eligible`, cosmétique `routes/web.php` (2 instructions/1 ligne).

Décisions maintenues : sans PIN défini → aucun verrouillage effectif (garde
anti-blocage) ; exclusions polling inutiles à ce jour (aucune requête
automatique : seules les routes exemptées par nom ne touchent pas) ;
portails étudiant/enseignant hors périmètre (mandat = profils back-office :
Administrateur, Emploi du temps/consultation, Agent administratif) ;
récupération PIN = réinitialisation par super-admin (purge auditée). Les 5
rôles back-office réels (`super_admin`, `admin_scolarite`, `admin_faculte`,
`agent_scolarite`, `consultation`) sont couverts par les tests.

## Architecture retenue

- **Serveur source de vérité** : middleware `SecondaireVerrou` sur les 3
  groupes authentifiés (`auth` admin, `etudiant.auth`, `enseignant.auth`).
  Il tient `derniere_activite` en session ; si `maintenant - activite >
  delai` (paramètre) → état verrouillé : GET → écran verrou (200, sans
  données) ; non-GET → 423. Exemptés : déverrouiller, déconnexion, setup PIN
  (setup exige déverrouillé), assets. Aucun polling JS détecté (`app.js` :
  fetches du wizard public uniquement) → toute requête authentifiée vaut
  activité humaine.
- **Client** : composant Alpine (timer local + `localStorage` inter-onglets,
  avertissement compte à rebours, **voile opaque + déverrouillage `fetch`**
  pour préserver la page et les données non enregistrées). Le client
  n'autorise rien : le serveur tranche à chaque requête ; le voile n'est
  qu'un recouvrement. Anti-boucle : après déverrouillage, minuteurs
  réinitialisés et l'écran serveur n'embarque pas la surveillance.
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
- Voile client ≠ verrou serveur : désynchronisation possible (horloge,
  onglet gelé). **Garde** : le serveur tranche à chaque requête (écran/423)
  ; le `fetch` de déverrouillage suit ce verdict (200 → voile levé,
  423 → rechargement vers l'écran serveur, redirect → connexion). Testé.
- `prolonger` détourné en heartbeat automatique : **garde** — le composant
  n'émet JAMAIS de requête sans événement de saisie réel (debounce ≥60 s,
  pas de `setInterval`), et la route refuse tout touch après expiration
  (impossible de réanimer un minuteur échu). Testé.
- Polling futur compté comme activité → aucune requête automatique n'existe
  à ce jour (vérifié `app.js`) ; routes exemptées par nom, liste d'exclusion
  non nécessaire (décision documentée).
- `back()` après déverrouillage : `redirect()->intended` conservé en
  session avant verrouillage (pas de perte de page) ; avec le voile,
  aucun rechargement n'a lieu si la session reste valide.

## Fichiers prévus

Migration `verrouillage_pins` + seeds paramètres ; `VerrouillageService` ;
`SecondaireVerrou` (+ alias) ; `VerrouillageController`
(setup/confirmer/modifier/verifier/deconnecter) ; routes ; vues
(écran, setup) ; composant overlay ; `SettingDefinition` (+6 clés) ;
hooks (login×3, logout, reset, suspension) ; tests `VerrouillageSecondaireTest`.
