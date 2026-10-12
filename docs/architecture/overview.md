# Vue d'ensemble — architecture réelle

> Synthèse fondatrice : `docs/architecture/final-architecture.md`.
> Procédure module : `docs/ARCHITECTURE-MODULES.md`, `docs/ADDING-A-MODULE.md`.

```text
Blade (Tailwind v4 + Alpine, 21 composants)
  ↓ routes web.php (auth/admin/module/can/throttle) + 12 Routes/web.php modules
  ↓ Controllers fins (Gate/policy → service → redirect)
  ↓ Services métier (transactions, DomainException, events)
  ↓ Modèles Eloquent (fillable strict, TRANSITIONS)
  ↓ MySQL (FK restrict/cascade, uniques, index) + storage privé
```

## Transverse (détail : docs/final-architecture)

Events → Listeners (audit sans PII, notifs batch) ; scheduler 12 jobs
(`routes/console.php`) ; `X-Request-ID` global ; supervision + cooldown.

## Règles structurelles (bloquantes)

- Cœur jamais dépendant d'un module en dur ; modules → cœur autorisé.
- Jamais de colonne en table centrale, jamais de route module dans `routes/web.php`.
- `module:xxx` → 404 si inactif ; activation serveur (`MODULE_*`, défaut `false` sauf referentiel/enseignement).
- Fillable strict, `password` hidden/hashed, `cin` masquée hors permission.
- `DomainException` métier (422/409 lisibles), `abort 404` scope / `403` policy.
