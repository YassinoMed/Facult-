# Système de mouvement UniRegistre

## 1. Diagnostic produit

| Question | Réponse |
|---|---|
| Pour qui ? | Candidats sur les parcours publics (accueil, nouvelle inscription, FAQ, suivi, confirmation, vérification de document) ; personnels et étudiants dans les espaces connectés. |
| Quel problème ? | Les parcours publics sont denses et statiques : le regard ne distingue pas l'entrée, les étapes et les blocs d'aide. Les pages longues donnent une impression de lourdeur. |
| Quelle valeur ? | Le mouvement crée une hiérarchie de lecture (hero → étapes → formulaire → aide), confirme les transitions et donne une finition institutionnelle sans ralentir la saisie. |
| Anti-objectifs | Pas de scroll détourné au tactile ou sous `prefers-reduced-motion` ; pas de curseur personnalisé ; pas d'animation en boucle sur les données ; pas de contenu inaccessible sans JavaScript. |

Le back-office reste un outil de travail : son mouvement est borné à
l'entrée de page, aux cartes/sections et à une cascade de lignes plafonnée.
Aucun Lenis, aucune parallaxe, aucun curseur ; les formulaires nus et les
champs ne sont jamais animés, et les cartes/sections de formulaire n'entrent
qu'en opacité (plancher 70 %). Les écrans de connexion et de mot de passe
admin restent en portée `app`. L'espace étudiant possède son module
GSAP + Lenis dédié : Lenis n'y est créé que sur pointeur fin, jamais au
tactile ni sous `prefers-reduced-motion`. Le back-office garde son module
GSAP borné, chargé à la demande, sans Lenis.

## 2. Architecture

```
layouts/app.blade.php
  ├─ calcule $motionScope (cinematic | etudiant | admin | app)
  ├─ <body data-motion="...">
  ├─ script anti-flash : html.motion-prep (délai de secours 1,8 s)
  ├─ @vite app.css + app.js
  └─ @vite etudiant.js si portée « etudiant » (admin chargé à la demande)

resources/js/app.js
  └─ import './motion-loader'

motion-loader.js
  ├─ cinematic → import dynamique de motion-cinematic.js
  ├─ admin     → import dynamique de admin.js (GSAP borné, sans Lenis)
  ├─ etudiant  → ne charge rien : le layout charge etudiant.js, qui pose ses états et démarre Lenis
  └─ app       → aucune ressource JS ; animation CSS uniquement

motion-cinematic.js
  ├─ Lenis (pointeur fin uniquement)
  ├─ GSAP + ScrollTrigger
  ├─ hero, révélations, groupes, parallaxe optionnelle, boutons magnétiques
  └─ nettoyage complet (navigation Livewire, réduction du mouvement)

etudiant.js
  ├─ Lenis (pointeur fin uniquement)
  ├─ GSAP + ScrollTrigger
  ├─ entrée de page, titre mot à mot, cartes/sections, lignes de tableaux
  ├─ SpotlightCard, boutons magnétiques, parallaxe optionnelle
  ├─ si le minuteur de secours a déjà révélé la page, ne la remasque pas
  └─ exclusions : formulaires/champs, navigation, footer, tableaux, zones
     live, fiche profil et PDF

admin.js
  ├─ GSAP + ScrollTrigger, sans Lenis
  ├─ entrée de page, en-tête, cartes/sections, lignes de tableaux (≤ 30)
  ├─ formulaires nus/champs jamais ciblés ; cartes de formulaire (ou
  │  contenant un formulaire) en opacité seule, sans déplacement
  └─ exclusions : navigation, footer, tableaux, listes, alertes, détails,
     modales et overlays masqués
```

### Portées

- `cinematic` : `accueil`, `inscription.*`, `documents.verifier`,
  `documents.verify`.
- `etudiant` : `etudiant.*` authentifié, hors fiche profil (`profil.show`) et
  PDF (parcours, récépissé, dossier).
- `admin` : `/admin` et `/admin/*`, hors écrans de connexion et de mot de
  passe (`/admin/connexion*`, `/admin/mot-de-passe*`). Le module est chargé à
  la demande par `motion-loader.js`, jamais pour les autres portées.
- `app` : tout le reste (connexion admin, enseignant, erreurs, hors-ligne,
  profil étudiant).

La fiche profil étudiant (`profil.show`) conserve son implémentation dédiée
`profil.js` et `data-profil` : elle reste en portée `app`, et ni `etudiant.js`
ni le socle cinématique ne s'y superposent. Les autres pages du profil
(notamment `profil.edit`) suivent le mouvement commun de l'espace étudiant.

## 3. Attributs disponibles

| Attribut | Effet |
|---|---|
| `data-motion-hero` | Conteneur du hero ; une timeline GSAP animera ses enfants. |
| `data-motion-hero-item` | Enfant du hero, révélé en cascade. |
| `data-motion-text="off"` | Désactive le découpage mot à mot du `h1` de la page étudiante. |
| `data-reveal="fade-up"` | Révélation à l'entrée dans le viewport (une seule fois). |
| `data-reveal-group` + `data-reveal-item` | Groupe révélé en cascade. |
| `data-parallax="0.14"` | Parallaxe légère ; vitesse positive. |
| `data-magnetic="0.1"` | Bouton/lien magnétique sur pointeur fin. |
| `data-motion-block` | Bloc explicitement animable dans l'espace étudiant ou le back-office. |
| `data-motion-zone` | Restreint la collecte des blocs à une zone de la page étudiante ou admin. |
| `data-motion-row` | Ligne de tableau à révéler (max. 40 étudiant, 30 back-office). |
| `data-lenis-prevent` | Conteneur défilant qui garde le scroll natif dans une page pilotée par Lenis. |
| `data-motion="off"` | Exclut un élément des modules étudiant et back-office. |

Règles d'usage :

1. Ne pas poser `data-reveal` sur un formulaire entier ni sur un tableau.
2. Réserver `data-motion-hero` à un seul bloc par page.
3. Ne pas activer `data-parallax` sur un conteneur qui influence le layout.
4. Toute nouvelle page publique doit être ajoutée à `$motionScope` seulement
   si elle contient réellement des attributs de mouvement. Les pages
   `etudiant.*` restent en portée `etudiant` sauf fiche profil/PDF
   explicitement exclus.
5. Dans le back-office, ne pas poser `data-motion-block` sur un champ, un
   tableau ou une zone live. Les cartes/sections de formulaire — y compris les
   cartes de filtre qui contiennent un formulaire — sont animées en opacité
   uniquement, jamais en déplacement.
6. Les pages `/admin/*` authentifiées sont en portée `admin` par défaut ; les
   écrans de connexion et de mot de passe restent en `app`.
7. Dans l'espace étudiant, le `h1` est découpé automatiquement en mots.
   `data-motion-text="off"` le laisse entier ; ne pas découper un titre qui
   contient déjà des éléments interactifs.
8. Tout panneau défilant d'une page étudiante doit porter
   `data-lenis-prevent`. Les navigations latérales, la cloche de notifications
   et la palette de commandes du layout le font déjà.

### Panneaux défilants (modales)

Un panneau `overflow-y: auto` ouvert au-dessus d'une page cinématique ou
étudiante reste hors du moteur inertiel :

- `data-lenis-prevent` sur le conteneur défilant : la molette y garde son
  défilement natif (Lenis ignore l'événement) ;
- `overscroll-contain` : en fin de panneau, la molette ne se propage pas à la
  page mère ;
- `mouvement:suspendre` / `mouvement:reprendre` émis à l'ouverture/fermeture :
  `motion-cinematic.js` et `etudiant.js` appellent `lenis.stop()` puis
  `lenis.start()` ;
- `etudiant.js` observe en plus un verrou `overflow: hidden` sur `<body>` ou
  `<html>` (par exemple `x-trap.noscroll`) et met Lenis en pause
  automatiquement. C'est nécessaire car Lenis déplace la page par
  `window.scrollTo` programmatique.

Dans le layout étudiant, les navigations latérales, la cloche de
notifications et la palette de commandes portent déjà
`data-lenis-prevent`. La palette et le tiroir mobile utilisent aussi
`x-trap.noscroll`, donc `etudiant.js` met Lenis en pause tant qu'ils sont
ouverts.

Référence : popup du parcours d'accueil
(`resources/views/inscription/existant/accueil.blade.php`, composant
`parcoursAccueil` dans `resources/js/app.js`). À chaque changement d'état, le
panneau repart du haut (`remonterPanneau()`).

## 4. Accessibilité

- `prefers-reduced-motion: reduce` : aucun Lenis, aucune timeline, contenu
  immédiatement visible. Le changement de préférence à chaud est écouté.
- Sans JavaScript : `motion-prep` n'est jamais posé, le contenu reste visible.
  Si le chunk échoue, le minuteur de 1,8 s retire la préparation.
- Les révélations utilisent `opacity` et `transform`, jamais `visibility:
  hidden` : le contenu reste dans l'arbre d'accessibilité et le focus clavier
  peut déclencher le scroll donc la révélation.
- Aucun `overflow`, `position` ou dimension n'est animé : pas de décalage de
  mise en page ni de scroll horizontal.
- Lenis est réservé aux pointeurs fins ; le tactile garde son momentum natif.
- Espace étudiant : le `h1` est découpé en mots masqués. Le texte complet
  reste dans `aria-label`, les fragments visuels sont `aria-hidden` ; sans
  JavaScript, le titre reste entier.
- Espace étudiant : les cartes qui contiennent un formulaire et leurs
  descendants sont exclues de la collecte ; elles apparaissent avec le fondu
  de `main`, sans déplacement. Les champs ne sont jamais animés. Les lignes de
  tableaux sont plafonnées à 40.
- Les écrans `app` (connexion admin/enseignant, erreurs) ne reçoivent ni
  smooth scroll, ni parallaxe, ni curseur, ni chunk GSAP.
- Dans l'espace étudiant et le back-office, seul `main#contenu` est pré-masqué
  avant peinture ; la barre latérale et l'en-tête restent immédiatement
  lisibles. Les entrées CSS historiques (`animate-fade-up*`) cèdent la main à
  GSAP dans ces deux espaces.
- Back-office : les champs et formulaires nus ne sont jamais ciblés. Une
  carte/section de formulaire — y compris une carte de filtre qui contient un
  formulaire — démarre à 70 % d'opacité (jamais à 0) et ne se déplace pas : les
  champs restent lisibles et focusables pendant l'entrée. Les tableaux de plus
  de 30 lignes — ou dont la carte contient un formulaire d'action — n'ont pas
  de cascade ; leur carte reste animée en opacité.
- Back-office : `beforeprint` révèle tous les blocs et toutes les lignes déjà
  animées, et les règles `@media print` forcent `main#contenu` à une opacité
  de 1.
- Espace étudiant : `beforeprint` révèle également les blocs et les mots déjà
  animés. Si la page ne défile pas, les derniers blocs sont révélés à la frame
  suivante.
- Modales, overlays `aria-hidden`, zones inertes et cartes volontairement
  transparentes sont exclus du mouvement : aucune révélation ne peut les
  faire apparaître.
- Back-office : si la page ne défile pas, les derniers blocs sont révélés
  immédiatement au lieu d'attendre un scroll impossible ; aucun contenu ne
  reste bloqué à `opacity: 0`.

## 5. Performance

- `motion-app` n'existe pas : la portée `app` ne paie que quelques règles CSS.
- Les chunks sont chargés à la demande : `motion-cinematic.js` pour les
  parcours publics, `admin.js` pour le back-office, `etudiant.js` pour l'espace
  étudiant. Lenis n'est chargé que sur les parcours publics et l'espace
  étudiant (pointeur fin), jamais dans le back-office.
- Build de référence (octobre 2026) : `motion-cinematic` ≈ 3,5 kB
  (1,4 kB gzip) ; `admin` ≈ 5,3 kB (2,0 kB gzip) ; `etudiant` ≈ 9,2 kB
  (3,4 kB gzip) ; `lenis` ≈ 18,7 kB (5,4 kB gzip) ; `ScrollTrigger` ≈ 112,8 kB
  (44,4 kB gzip), partagé par les trois modules.
- Les animations se limitent à `transform`, `opacity` et un flou court sur les
  mots du titre ; `will-change` n'est posé que sur ces mots et retiré à la fin
  de la révélation. Le back-office plafonne les lots de défilement à 6 blocs
  et les cascades à 30 lignes ; l'espace étudiant plafonne les lots à 5 blocs
  et les lignes à 40.
- `ScrollTrigger.refresh()` est appelé après les polices et au `load` ; le
  back-office ajoute un refresh à la frame suivante. Lenis pilote l'update du
  ScrollTrigger sur les parcours cinématiques et dans l'espace étudiant.

## 6. Preuves de vérification

| Vérification | Résultat |
|---|---|
| `npm run build` | OK, chunks `motion-cinematic`, `admin`, `etudiant` et `ScrollTrigger` séparés. |
| `php artisan view:cache` | OK, toutes les vues Blade compilent. |
| `php artisan test --filter="DesignSystemTest|InscriptionTest|FaqTest|SuiviTest"` | 37 tests, 130 assertions, 100 %. |
| `php artisan test --filter="LoopPopupHomeOnly04Test"` | 16 tests, 135 assertions, 100 % (dont scroll de la popup hors Lenis). |
| Chrome headless, page publique | `lenis` actif, hero à `opacity: 1`, `#aide` masquée puis révélée au scroll (`opacity: 1`). |
| Chrome headless, portée `app` | `data-motion="app"`, pas de Lenis, animation CSS `motion-app-in`, opacité finale 1 (vérification antérieure). |
| Chrome headless, espace étudiant (vérification antérieure) | Connexion réelle, 20 fenêtres authentifiées (tableau de bord, notes, documents, examens, suivi, archives, inscriptions, historique, parcours, corrections, recherche, rendez-vous, absences, demandes, dossier, notifications, ressources, pointage, profil/modifier) : `data-motion="etudiant"`, `etudiant.js` chargé, `main#contenu` à `opacity: 1`, aucun bloc invisible après défilement, formulaires visibles. Cette campagne précède l'activation de Lenis. |
| Vérification après activation Lenis | `npm run build` OK (`etudiant` 9,2 kB / 3,4 kB gzip, `lenis` 18,7 kB / 5,4 kB gzip) ; `node --check resources/js/etudiant.js` OK ; `MotionEtudiantTest` 3 tests / 21 assertions ; `EspaceEtudiantTest` + `MotionAdminTest` 19 tests / 72 assertions. |
| Harnais navigateur, module étudiant | Chrome headless : `data-motion="etudiant"` → Lenis actif (`html.lenis`), `motion-prep` retiré, `main#contenu` à 1, `h1` découpé en 3 mots avec `aria-label` (un titre long de 14 mots tient sur 2 lignes desktop et 4 lignes mobile sans rognage), cartes visibles, formulaire et champ sans style inline, carte de formulaire exclue du halo, spotlight sur 2 cartes, bloc sous la ligne de flottaison à 0 puis 1 après molette. Verrou `overflow: hidden` et événements `mouvement:suspendre` / `reprendre` → Lenis stoppé puis relancé. Réduction du mouvement : aucun Lenis, aucun découpage, contenu visible. Pointeur grossier : Lenis et spotlight inactifs, découpage conservé. Aucune erreur console. |
| Chrome headless, fiche profil | `data-motion="app"`, `profil.js` chargé, `profil-motion-ready`, couverture indigo, aucun `etudiant.js`. |
| Chrome headless (CDP), harnais module admin | `data-motion="admin"` : `motion-ready`, `main#contenu`, en-tête, cartes, tableau et lignes à `opacity: 1` / `transform: none` ; formulaire nu et champs sans style inline ; section de formulaire, carte de formulaire et carte de filtre contenant un formulaire à `0,7` puis `1`, sans déplacement ; bloc sous la ligne de flottaison à `0` puis `1` après défilement ; modale (`role="dialog"`), carte `data-motion="off"` et carte volontairement transparente non touchées. |
| Chrome headless (CDP), admin + reduce | Aucun style inline d'opacité/transform, tous les blocs à `1`, `motion-prep` retiré. |
| Chrome headless (CDP), nettoyage et impression | `beforeprint` porte les blocs suivis et les lignes animées à `1` ; après nettoyage, plus aucun style inline d'opacité/transform sur les blocs et lignes suivis, `motion-ready` retiré (la navigation Livewire le restaure). |
| Chrome headless (CDP), admin sans défilement | Page plus courte que le viewport (`scrollHeight = innerHeight`) : la dernière carte est portée à `opacity: 1` sans scroll, aucun bloc ne reste masqué. |
| Chrome headless, `prefers-reduced-motion: reduce` | Pas de Lenis, pas de `motion-prep`, `main#contenu` et cartes à `opacity: 1` (y compris le harnais admin). |
| Tests de portée | `MotionEtudiantTest` 3 tests / 21 assertions ; `MotionAdminTest` 3 tests / 8 assertions (tableau de bord, page imbriquée, écrans de connexion/mot de passe) ; suite ciblée 6 tests / 29 assertions. |
| Console navigateur | Aucune erreur du socle ; seuls des avertissements CSP préexistants du serveur de dev (`http://[::1]:5173`). |

## 7. Limites connues

- Pas de test visuel automatisé sur les 305 vues : la non-régression repose
  sur les tests ciblés, le build et les vérifications headless. Le harnais
  admin vérifie le module, pas chaque vue.
- Le back-office utilise une collecte heuristique : cartes `.ds-card`,
  `.card-hover`, sections `aria-labelledby`, articles et conteneurs
  `rounded-2xl/3xl + border`, ou `data-motion-block`. Un conteneur sans ces
  marqueurs n'est pas animé (seul le fondu de `main` s'applique).
- L'espace étudiant utilise une collecte voisine, avec un filtre de taille
  pour ne pas animer les badges et pastilles : cartes `.ds-card`,
  `.card-hover`, sections `aria-labelledby`, articles et conteneurs
  `rounded-lg/xl/2xl/3xl` d'au moins 180 × 64 px. Les cartes contenant un
  formulaire sont exclues de la collecte.
- Lenis n'est pas activé sur pointeur grossier, tactile ou sous
  `prefers-reduced-motion` : le scroll natif reste la référence sur mobile.
  Le découpage mot à mot s'applique au `h1` de chaque page étudiante ;
  `data-motion-text="off"` permet de le désactiver.
- Dans le back-office, les formulaires nus, champs et tableaux ne sont jamais
  animés comme unités. Une carte/section de formulaire — y compris une carte
  qui contient un formulaire — n'entre qu'en opacité (70 % → 100 %), sans
  déplacement ; les tableaux de plus de 30 lignes n'ont pas de cascade. Une
  carte de tableau qui contient un formulaire d'action est traitée comme une
  carte de formulaire : la cascade de lignes est réservée aux tableaux sans
  formulaire.
- Dans l'espace étudiant, une carte qui contient un formulaire est exclue de
  la collecte : elle apparaît avec le fondu de `main`, sans opacité propre ni
  déplacement. Les tableaux de plus de 40 lignes n'ont pas de cascade.
- Les mises à jour Livewire (recherche, filtres, morphing) ne rejouent pas
  l'animation : seules l'entrée de page et les navigations complètes ou
  Livewire la déclenchent. C'est volontaire pour ne pas distraire la saisie.
- Aucun curseur personnalisé ni transition de page : choix assumé de sobriété
  et de performance.
- La fenêtre « Emploi du temps » n'a pas été vérifiée avec un planning publié :
  le compte de test n'a pas de groupe, le contrôleur répond 404. La portée est
  appliquée par la même règle `etudiant.*` dès qu'un planning publié existe.
- Les modules désactivés (`annonces`, `calendrier`, `finances`) répondent 404 ;
  leur page d'erreur conserve la portée étudiante et se révèle correctement.
