# LOOP-MAIL-LIVEWIRE-DESIGNER-04-BASELINE — Designer une page

**Date :** 14/10/2026 · **Phase :** LAN (avant réécriture)

## 1. État observé (error « Undefined constant "schema" »)
- Route `admin.studio.designer` (Closure) rendait une vue **jamais rendable** :
  `{{ schema.schema_version }}` sans variable, accolade orpheline ligne 40,
  `blocActif.t type` (×7), `.slice` scindé ligne 351, `this.blocs` jamais déclaré,
  partial `_grapesjs-script.blade.php` syntaxiquement invalide et **jamais référencé**
  par Vite/JS (`grapesjs`/`grapesjs-mjml` dans `package.json` mais ni importés ni
  initialisés).
- Décision éditeur (comparatif, cf. STUDIO-04) : GrapesJS/Unlayer/MJML rejetés ;
  **Livewire + Alpine + moteur serveur existant retenu** (0 dépendance, serveur
  souverain, adaptateur direct vers les 4 tables du Studio 03).
- Contrats à préserver : `StudioCourrielService`, validateur strict, versions
  immuables, 409 optimiste, 5 permissions `mail.*`, journal expurgé, allowlist,
  modèles système non migrables, aperçu iframe sandbox + rendu serveur fictif.

## 2. Critères d'acceptation
1. Page unique : bibliothèque → création → édition (blocs, propriétés, réorganisation) → aperçu → sauvegarde → cycle, sans navigation hors page.
2. Le canvas client n'est jamais une source de confiance : toute sauvegarde traverse le validateur ; XSS/variables inconnues refusées (tests).
3. Conflit 409 : modifications locales conservées, comparaison possible, rechargement explicite.
4. Modèles système/archivés : refus d'édition (tests).
5. Aperçu = rendu serveur + données fictives + `iframe sandbox=""` (zéro PII).
6. Undo/redo, drag&drop + clavier, indicateur sale, `wire:loading`.
7. Permissions : invité→login, sans `mail.modeles.view`→403.
8. Non-régression : 7 modèles catalogue, OTP, idempotence, reprise, journal.
9. Perfs mesurées (page, édition 30 blocs, SQL) ; 0 requête par frappe.

## 3. Risques
- Worktree 135 entrées partagées (chantiers parallèles) — ne toucher que les fichiers du designer.
- Base dev recréée : migrations studio à relancer sélectivement (une migration tierce `seance_appels` bloque `migrate` global — contournée par `--path`).
- Livewire : hydratation du schéma à chaque action → garder les actions ciblées, éviter les gros états dérivés.
