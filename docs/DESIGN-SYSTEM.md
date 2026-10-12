# Design System global — plateforme universitaire

Base UX unique pour tous les modules (Dashboard, Schedule, Requests,
Documents, Students…). Ne pas créer de style par module.

## §1. Tokens

`resources/css/app.css` (`:root`) + `@theme` Tailwind existant :

| Token | Valeur | Usage |
|---|---|---|
| `--ds-primary` | `#4338ca` (primary-700) | actions, focus, liens |
| `--ds-success` / `warning` / `danger` / `info` | `#059669` / `#d97706` / `#dc2626` / `#0284c7` | statuts (toujours + icône + texte) |
| `--ds-background` / `surface` / `border` / `text` / `muted` | `#f8fafc` / `#fff` / `#e2e8f0` / `#0f172a` / `#64748b` | fonds et textes |
| `--ds-radius` / `lg` | `0.75rem` / `1rem` | cards, boutons, champs |
| `--ds-shadow-sm/md`, `--ds-transition: 150ms` | — | sobriété (pas d'ombres lourdes) |

Dark mode : surcharger ces variables suffit (composants prêts).

## §2. Typographie (mapping, pas de classes dispersées)

H1 page `text-2xl sm:text-3xl font-black tracking-tight` · H2 section
`text-base font-black` · H3 `text-sm font-bold` · body `text-sm` ·
secondaire `text-xs text-slate-500` · métadonnées `text-[11px] uppercase
tracking-wider text-slate-400`. Police : Inter (système en repli).

## §3. Composants (`resources/views/components/`)

`page-header` (fil d'Ariane + titre + actions), `alert`
(success/warning/error/info, `role` adapté), `btn`
(primary/secondary/danger/ghost, 44px min), `card`, `modal` (Alpine,
Escape, focus, `role=dialog`), `skeleton` (+ texte sr-only),
`stepper` (pastille + état textuel), `stat-card`, `status-badge`
(pastille + libellé FR), `empty-state` (`role=status`),
`dropdown` (menu `⋮`, `role=menu`, Escape + clic extérieur),
`tabs` (`role=tablist/tab/tabpanel`, flèches clavier),
`confirm` (modale de destruction partagée), `loading` (spinner + `role=status`),
`input`/`select`/`textarea` (label, `aria-invalid`, erreur `role=alert`).
Markup figé par `tests/Unit/DesignSystemTest.php` — ne pas renommer.
Règle : nouveau besoin UI = d'abord chercher ici (§35-36).

## §4. États et feedback (§19-22)

Succès/erreur/info : `x-alert` (+ flash layout existant). Chargement :
bouton désactivé + libellé (« Analyse… »), `x-skeleton` pour les
contenus. Vide : `x-empty-state`. Destruction : `x-modal` (jamais de
`confirm()` natif pour les nouvelles vues). Erreurs : texte + icône,
`role=alert`, jamais la couleur seule.

## §5. Formulaires, tables, responsive, a11y (§23-24, §27-28)

Labels visibles, erreurs sous les champs, focus visible global
(`:focus-visible`), 44px tactiles, tableaux `overflow-x-auto` +
version cartes sur mobile, breakpoints `sm/md/lg`, `prefers-reduced-motion`
neutralise les animations. Icônes : style heroicons inline uniquement.

## §6. Navigation — University Admin Pro (§7-8, §31)

Sidebar à domaines (source unique `layouts/_navigation`, desktop + drawer) :
**Pilotage** (Tableau de bord), **Scolarité** (Registre des étudiants,
Archives, Groupes, Facultés), **Formation** (Enseignants, Salles),
**Planification & services** (modules actifs via `ModuleRegistry`),
**Données** (Import / Export, Recherche globale, Notifications),
**Administration** (Ma Faculté / Administrateurs / Maintenance selon rôle),
**Contrôle** (Journal d'audit). Chaque section est collapsible (Alpine +
`localStorage` clé `admin-nav-*`, `aria-expanded`/`aria-controls`,
chevron) ; lien actif = fond + barre latérale + `aria-current` (jamais la
couleur seule). Menus filtrés par module actif puis permission (`@can`) ;
aucune action non autorisée affichée (la sécurité reste serveur).
Navbar : recherche globale (`/` pour focusser, `role=search`), cloche +
badge (`aria-label="Notifications"`, `X non lue`), thème
(`Choisir le thème`), menu utilisateur, drawer mobile (`role=dialog`,
Escape). Breakpoints `sm/md/lg`, `prefers-reduced-motion` neutralise les
animations.

## §7. Schedule premium (§10-13)

Semaine navigable, grille desktop + liste mobile, carte créneau
partagée (normal/modifié/annulé + motif + valeurs normales),
aujourd'hui surligné, sondage 45s + badge + `aria-live`, widget
« Prochain cours ». Import : stepper 5 étapes, dropzone drag&drop,
résumé entités reconnues, modale de remplacement, rapport final.
Tableau déplaçable (`tableau`) : colonnes par jour, cartes drag & drop
(HTML5) + bouton « Déplacer » clavier/tactile, vérification live des
conflits (dry-run `slots.verifier`), confirmation modale, sauvegarde
`slots.deplacer` (transaction + conflits + audit via `modifierCreneau`,
JSON ou redirection).

## §8. Interactions productives (sans SPA)

Alpine.js + fetch uniquement : `registreBulk` (sélection + barre
flottante + confirmation, POST `etudiants.bulkStatut` : permission +
périmètre par dossier, transaction, `confirmation` exigée),
`editionInline` (PATCH JSON, restauration à l'erreur),
`paletteCommandes` (Ctrl+K, liste serveur permission-gatée, flèches +
Entrée), `ordreWidgets` (↑/↓ + `localStorage`), `tableauDeplacement`
(§7). Raccourcis `/`, `N`/`E`/`I` (opt-in `data-raccourci`, jamais en
saisie), Escape global. Règle : le serveur re-valide tout ; l'UI
optimiste seulement après confirmation serveur.

## §9. Finalisation (sidebar, notifications, recherche, dark)

- Sidebar : `lg:w-64` ↔ `lg:w-20` via Alpine, clé `sidebar-collapsed`
  (localStorage), `aria-expanded`, libellés masqués en CSS
  (`.is-reduite`), drawer mobile avec Escape + `role=dialog`.
- Tables : conteneur `.admin-table-scroll` (scroll + en-tête collant
  desktop), actions secondaires en `x-dropdown` (`⋮`, `role=menu`),
  version cartes sur mobile, destruction via modale partagée.
- Détail : `x-tabs` (`Informations` / `Dossier & services`, flèches
  clavier, contenu dans le DOM).
- Dashboard : KPI + `Actions rapides` + `À traiter` (inscriptions et
  demandes en attente, liens filtrés) + `Activité récente`.
- Notifications : cloche + badge + panneau (6 dernières), page centre
  avec pagination, `role=status/alert` — même composant visuel partout.
- Dark : variante Tailwind `dark` + clé `theme` (light/dark/system,
  `prefers-color-scheme`, script anti-flash) + remappage global des
  neutres et pastels sémantiques (contraste conservé) + couche Pro
  (`.admin-kbd`, sections).
