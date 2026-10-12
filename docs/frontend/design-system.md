# Design system (index — détail : `docs/DESIGN-SYSTEM.md`)

Tokens `:root` (`--ds-primary #4338ca`, radius/shadow/transition 150ms,
dark par surcharge). 21 composants figés (`DesignSystemTest`) :
`page-header, alert, btn, card, modal, dropdown, tabs, confirm, loading,
palette, skeleton, stepper, input, select, textarea, stat-card, status-badge,
empty-state, timeline, export-actions, task-list`.

Responsive `sm/md/lg`, tableaux `overflow-x-auto` + cartes mobile, 44px
tactiles, onglets scrollables. A11y : `role=dialog|menu|tablist|status`,
`aria-*`, focus-visible, `reduced-motion`, jamais couleur seule.
Layout : sidebar collapsible + navbar (recherche `/`, cloche, thème) +
toasts. Règle : AUCUN composant équivalent recréé par module.
