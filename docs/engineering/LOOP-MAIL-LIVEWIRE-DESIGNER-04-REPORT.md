# LOOP-MAIL-LIVEWIRE-DESIGNER-04-REPORT — Designer une page (Livewire)

**Date :** 14/10/2026 · **Méthode :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Verdict :** `PASS_WITH_RESERVATIONS` (réserves §8 : navigateur réel, clients mail, SMTP réel studio)

## 1. Décision d'architecture (comparatif avant tout code, cf. baseline STUDIO-04/03)
| Option | Verdict |
|---|---|
| GrapesJS (0.23.7, MIT) + grapesjs-mjml (1.0.8) — présents dans `package.json` mais **jamais intégrés à Vite ni initialisés** | **Non retenu.** Le canvas Livewire/Alpine couvre la mission (blocs/propriétés/réorg/aperçu responsive) sans dépendance lourde, sans adaptateur HTML non fiable, sans double moteur. Aucun code de la page n'en dépendait (le script partial était mort et cassé). |
| Unlayer (SaaS) | Rejeté (contenu édité chez un tiers). |
| MJML | Rejeté (rendu serveur tables/inline déjà conforme ; migration non prouvée). |
| **Livewire + Alpine + moteur serveur existant** | **Retenu.** Zéro dépendance ajoutée, serveur souverain, mêmes tables/permissions. |

**Conséquence :** supprimé `resources/views/livewire/admin/courriels/_grapesjs-script.blade.php`
(mort : jamais importé, wire:ignore inutile, script avec erreurs de syntaxe). Les packages
npm `grapesjs`/`grapesjs-mjml` restent dans `package.json` (non utilisés) — retrait suggéré
au prochain `npm install` (hors périmètre de cette LOOP, aucun impact bundle car jamais
importés dans `app.js`/vite.config).

## 2. Erreur initiale corrigée (motif de l'intervention)
`Undefined constant "schema"` sur `GET /admin/studio/designer` : la vue historique référencait
`{{ schema.schema_version }}` sans variable définie (fermeture `}` orpheline ligne 40,
`blocActif.t type`, `.slice` scindé, partial `_grapesjs-script` invalide — page jamais
rendable). **Réécriture complète** de la vue + composant Livewire réel.

## 3. Ce qui a été livré (une seule page, zéro navigation)
- **Composant** `app/Livewire/Admin/Courriels/DesignerCourriel.php` : bibliothèque paginée
  + création + édition + aperçu + cycle **sur la même page** (`chargerModele` bascule
  sans quitter) ; undo/redo (30 niveaux, serveur) ; verrou optimiste 409 avec **modifications
  locales conservées** ; `estModifiable()` refusé (système/archivé) ; aperçu = rendu
  **serveur** (données fictives), jamais le DOM client.
- **Vue** `resources/views/livewire/admin/courriels/designer.blade.php` : topbar (nom,
  statut, version, sale, undo/redo/aperçu/sauvegarder/soumettre/publier), bibliothèque
  de blocs (11 types), canvas (sélection, drag&drop natif, ↑↓⧉✕, aperçu bureau/tablette/
  mobile), panneau propriétés (texte, alignement, couleurs, largeur, id, verrou),
  thèmes, chips de variables documentées, aperçu `<iframe sandbox="">` + texte brut.
- **Route** `admin.studio.designer` → classe Livewire, `can:mail.modeles.view` (inchangée
  d'URI), CSRF du groupe `web` pour toutes les mutations Livewire.
- **Adaptateur explicite** vers les 4 tables STUDIO-03 (versions immuables via
  `StudioCourrielService`, aucun second système) ; schéma v1 inchangé, modèles système
  **non migrables** (refus `chargerModele`, testé).
- **Méthodes canvas** : `ajouter/selectionner/supprimer/dupliquer/monter/descendre/
  deposerBloc`, listes & tableaux (+/− lignes et éléments), `appliquerSquelette`,
  `annuler/retablir`, `marquerSale` (aucun réseau par frappe : aperçu via `wire:change`
  et actions explicites).

## 4. Tests réellement exécutés — 205/205 verts (1620 assertions)
- Nouveau `DesignerLivewireTest` **9/9** : page protégée (invité→login, 403 sans
  permission), création→édition même page, canvas (réorg/duplication/suppression/verrou),
  undo/redo, sauvegarde + rechargement + **409 sans perte locale**, aperçu serveur
  fictif (zéro PII) + `sandbox=""`, XSS/variable inconnue rejetées (serveur), cycle
  soumettre→publier→archivage refusé, catalogue système intact + OTP impossibles.
- Non-régression mail/studio/cockpit/dossiers : **205/205** (Studio 27, Builder 10,
  Moteur 8, Module 12, Smtp/Messagerie/Notifs, Dossier×3, Popup, Accueil).
- Corrections en cours de route (rejouées) : règle `sometimes` (confirmation), FK
  `SET NULL`, tri `dernierVersion`, garde `chargerModele` sur `estModifiable()`.

## 5. Performance mesurée (réel, à chaud)
- Page designer (bibliothèque) : **128 ms / 11 SQL / 124 Ko HTML**.
- Édition + aperçu **30 blocs** : **38 ms / 5 SQL**, HTML rendu 3 875 o.
- Aucune requête par frappe (aperçu débouncé côté serveur via actions explicites),
  pagination 20, zéro N+1 (bibliothèque = 1 requête paginée + compteurs).
- Référence STUDIO-03 (création 21 ms/4 SQL ; aperçu 0 ms/0 SQL) : inchangée.

## 6. Sécurité (inchangée et testée)
Validation serveur souveraine à chaque sauvegarde (blocs/props/variables/URL/verrous),
échappement au rendu, aperçu en `iframe sandbox=""` alimenté par le **rendu serveur**,
CSRF Livewire, permissions distinctes, publication atomique, versions immuables, journal
expurgé, allowlist d'envoi de test, OTP non éditables (impossibles structurellement).

## 7. Déploiement progressif
La page remplace l'ancienne route morte (même URI `admin/studio/designer`, même
permission). Le designer s'ajoute au cockpit existant (`admin/studio` v1/v2 conservés) ;
aucune migration de données (les 4 tables existantes sont réutilisées telles quelles).

## 8. Réserves (explicites)
1. **Parcours navigateur réel non exécuté** (aucun Dusk dans cet environnement) — check-list : créer brouillon → squelette → blocs/props → aperçu 3 largeurs → sauvegarder → 409 simulé (2 onglets) → comparer → publier.
2. **Rendu Gmail/Outlook/Apple Mail non testé** (matrice à jouer ; canvas ≠ preuve client).
3. **Envoi de test SMTP réel du studio non effectué** (allowlist vide par défaut).
4. Upload d'images non implémenté (URL validées uniquement).
5. Packages npm `grapesjs`/`grapesjs-mjml` présents mais inutilisés (retrait suggéré).
6. Worktree partagé (135 entrées) : ne toucher que les fichiers listés ; rejouer les suites après merge.

## 9. Fichiers modifiés/créés (périmètre exact)
`app/Livewire/Admin/Courriels/DesignerCourriel.php` (nouveau) ·
`resources/views/livewire/admin/courriels/designer.blade.php` (réécrit) ·
`resources/views/livewire/admin/courriels/_grapesjs-script.blade.php` (supprimé, mort) ·
`routes/web.php` (route designer → classe Livewire + import) ·
`tests/Feature/Livewire/Admin/Courriels/DesignerLivewireTest.php` (nouveau).
