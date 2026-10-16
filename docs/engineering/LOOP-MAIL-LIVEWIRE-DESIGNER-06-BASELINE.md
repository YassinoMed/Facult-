# LOOP-MAIL-LIVEWIRE-DESIGNER-06-BASELINE — Inventaire et contraintes avant développement (Phase 0)

**Projet :** UniRegistre — Laravel 13.33.0, Livewire ^4.4, Alpine.js ^3.17.4, TailwindCSS ^4.0.0, Vite ^8.0.0  
**Date :** 14/10/2026 · **Boucle :** LOOP-MAIL-LIVEWIRE-DESIGNER-06, Phase 0 — Audit initiale (zéro modification code)  
**Auditeur :** session OpenCode — constats vérifiés dans le dépôt, aucune hypothèse.

## 1. État Git et environnement

- `HEAD` : `3653387` (« MFA : codes tolérants aux espaces… »).
- Branche courante : `main`.
- Worktree **sale** au moment de l'audit (108 fichiers `M` relevés de chantiers parallèles :
  `InscriptionController`, `MfaController`, `Etudiant`, `MfaController`, `SmtpConfigurationController`, …).
  Conséquence : toute mesure « avant » ci-dessous est prise sur worktree sale et devra être
  rejouée sur worktree propre avant validation finale.
- Aucun commit effectué par cette boucle à ce stade.

### Configuration technique

| Élément | Valeur observée | Source |
|---|---|---|
| `mail.default` | `smtp` (driver `array` en test) | `config/mail.php:17`, `.env` |
| `mail.mailers.smtp` | `smtp.gmail.com:587`, STARTTLS | `config/mail.php:40-50`, `.env` |
| Livewire | `^4.4` | `composer.json` |
| Alpine.js | `^3.17.4` | `package.json` |
| TailwindCSS | `^4.0.0` | `package.json` |
| Vite | `^8.0.0` | `package.json` |
| GrapesJS | **absente** | Non dans `composer.json` ni `package.json` |
| MJML plugin | **absente** | Non installée |
| Flag `editeur_v2` | **non défini** (fallback `false`) | `config/courriels.php:134` |
| Nombre de modèles en base | **0** | `ModeleCourriel::count()` |
| Nombre de versions en base | **0** | `VersionModeleCourriel::count()` |
| Nombre de thèmes en base | **1** | `ThemeCourriel::count()` |
| Nombre de journal_modeles en base | **0** | `JournalModele::count()` |
| Routes `admin.studio.*` | **15 routes** | `route:list` |
| Services existants | `ValidateurSchemaCourriel`, `MoteurRenduCourriel`, `StudioCourrielService`, `ControleQualiteCourriel` | chargement automatique |

## 2. Architecture existante (Studio 03 — intégrité préservée)

### 2.1 Tables de base de données (4 migrations)

| Table | Objectif | Colonnes clés |
|---|---|---|
| `themes_courriel` | Thèmes réutilisables | id, nom, config(json), actif, timestamps |
| `modeles_courriel` | Modèles de courriel | id, code(unique), nom, categorie, statut, version_courante, theme_id, verrou_systeme, timestamps |
| `versions_modele_courriel` | Versions immuables | id, modele_id FK, numero, schema(json), auteur_id, note(255), timestamps, unique(modele_id, numero) |
| `journal_modeles` | Historique d'actions | id, modele_id FK, version_numero, action(30),acteur_id, detail(json), timestamps |

### 2.2 Modèles Eloquent

| Modèle | Comportement notable |
|---|---|
| `ModeleCourriel` | Méthode `estModifiable()` corrigée (`reorder()`, `orderBy` hérité faussait le conflit optimote) |
| `VersionModeleCourriel` | Une ligne par sauvegarde, numéro incrémental, comparaison optimiste via `base_version` |
| `ThemeCourriel` | 1 ligne existante avec config par défaut (couleurs, largeur 600, police arial) |
| `JournalModele` | Action + version + acteur + détail masqué (pas de corps, pas d'adresse, pas de secret) |

### 2.3 Services existants (inchangés)

| Service | Responsabilité |
|---|---|
| `ValidateurSchemaCourriel` | Validation serveur stricte à chaque sauvegarde/aperçu/publication/restauration : types de blocs, propriétés, variables autorisées (`{{var}}` seulement), anti-XSS (`script/iframe/form/on*/javascript:/vbscript:`), URLs `https|mailto`, images `jpg|jpeg|png|gif|webp`, couleurs `#rrggbb`, bornes numériques. Rejette les variables inconnues, les expressions PHP/Blade, les fonctions arbitraires. |
| `MoteurRenduCourriel` | Rendu HTML tables+styles inline + texte brut miroir. Interpolation `{{var}}` échappée (`htmlspecialchars`). Aperçu sandbox via iframe. Contexte fictif (données de test uniquement, jamais de dossier réel). |
| `StudioCourrielService` | Cycle BROUILLON→À_VALIDER→PUBLIÉ→ARCHIVÉ, versions immuables, publication atomique (revalidation QA + convergence schema), rollback = réédition nouvelle version, conflit optimiste (409/422), envoi de test autorisé (allowlist, throttle, journal masqué). |
| `ControleQualiteCourriel` | Analyse pré-publiation : erreurs bloquantes (objet vide, contraste critique, image débordement, colonnes Outlook) + avertissements (pré-en-tête vide, contraste faible, URL variable, tableau mobile). |

### 2.4 Catalogue de 7 modèles système

Tous verrouillés en lecture seule, jamais migrés automatiquement par le designer (caractéristique `test_non_regression_catalogue_intact`). Aucun modèle système n'a été modifié par les tests existants.

### 2.5 Permissions (5 via matrice Gates auto)

- `mail.modeles.view` (bibliothèque/voir)
- `mail.modeles.gerer` (créer/éditer/sauvegarder/dupliquer/supprimer)
- `mail.modeles.publier` (soumettre/publier/archiver/restaurer)
- `mail.modeles.themes`
- `mail.test.modele`

Super_admin complet via la matrice ; invité → login, sans permission → 403 (testé).

### 2.6 Vues existantes (toutes Blade, aucune Livewire)

- `resources/views/admin/studio/index.blade.php` — bibliothèque + filtres
- `resources/views/admin/studio/editer.blade.php` — éditeur Alpine.js v1
- `resources/views/admin/studio/editer-v2.blade.php` — éditeur Alpine.js v2 avec drapeaux qualité
- `resources/views/admin/studio/envoi-test.blade.php` — envoi de test en 2 temps
- `resources/views/admin/studio/themes.blade.php` — gestion des thèmes
- `resources/views/admin/studio/voir.blade.php` — détail modèle + journal + comparatif

### 2.7 Tests de référence (tous verts)

- `StudioBuilderTest` : 10/10, 55 assertions (blocs, validation, verrouillage, thème, compatibilité v1)
- `CourrielModuleTest` : 12/12, 53 assertions (catalogue, OTP réserve, journal, idempotence, cockpit, non-fuite)
- Non-régression mail+dossiers+accueil : **186/186** (1508 assertions)
- Tous les tests utilisent `Notification::fake()` — **zéro envoi réel en suite**

## 3. Contrats de données (existant, inchangés)

### 3.1 Schéma JSON versionné

Format `{ schema_version: 1, meta, theme, blocs: [...] }`.

- `meta.objet` (150 chars max, littéral) ; `meta.preheader` (150 chars) ; `meta.categorie`.
- `theme.couleur_principale` / `couleur_accent` / `fond` / `fond_contenu` — hex `#RRGGBB` uniquement.
- `theme.largeur` (480–800 px) ; `theme.police` (`arial`, `georgia`, `systeme`) ; `theme.style_bouton` (`arrondi`, `angle`).
- `theme.logo_url` — URL https, extensions jpg/jpeg/png/gif/webp valides.
- `theme.nom_etablissement` (120 chars) ; `theme.signature` (500 chars) ; `theme.mentions` (500 chars).
- `blocs` : `titre`, `texte`, `bouton`, `image`, `separateur`, `liste`, `espaceur`, `colonnes`, `carte`, `tableau`, `signature`.
  Chaque bloc possède : `type`, `alignement` (gauche/centre/droite), `id` (optionnel), `verrou` (bool).
  Sous-ensembles par type définissent leurs champs (voir `ValidateurSchemaCourriel::validerBloc`).

### 3.2 Variables autorisées ( registre )

`config/courriels.variables_autorisees` en code :
- `etablissement_nom`, `destinataire_nom`, `reference_dossier`, `echeance_intitule`, `echeance_date`, `url_action`, `contact_email`.
Toutes échappées via `htmlspecialchars` côté rendu, validées côté serveur (toute variable inconnue → exception).

### 3.3 Format de persistance

- `versions_modele_courriel.schema` : JSON normalisé par `ValidateurSchemaCourriel::valider()`.
- `modeles_courriel.verrou_systeme` : empêche modification/suppression des modèles critiques.
- `journal_modeles.detail` : JSON expurgé (action + version + acteur + détail masqué).

## 4. Risques et compatibilité (Phase 0)

| Risque | Gravité | Description |
|---|---|---|
| Worktree partagé | Élevée | 108 fichiers modifiés ailleurs — aucune modification hors périmètre studio |
| Aucune dépendance GrapesJS | Critique | Doit être ajoutée (npm install) avec vérification compatibilité |
| Livewire version 4.4 | Moyenne | Vérifier compatibilité avec GrapesJS et Alpine.js dans même page |
| Flag editeur_v2 non documenté | Faible | Par défaut false ; activation nécessite validation |
| Modèles système protégés | Élevée | 7 modèles catalogue intacts, lecture seule, jamais migrés automatiquement |
| Aperçu navigateur ≠ rendu mail | Moyenne | Gmail/Outlook/Apple Mail interprètent différemment les styles |
| Pas de Livewire designer existant | Élevée | Doit être créé de toutes pièces |

## 5. Critères d'acceptation Phase 0

1. Inventaire réel complété et validé (ce document)
2. Routes et permissions existantes identifiées (inchangées)
3. Modèles et services existants compris (aucun code modifié)
4. Worktree propre identifiable (git status avant/après chaque incrément)
5. GrapesJS à installer, Livewriter à intégrer, adapter au contrat existant

## 6. Stratégie de retour arrière

- `git stash` ciblé sur les seuls fichiers de studio (jamais `git reset --hard` ou `git clean -fd` global)
- `composer install --no-dev` / `npm install` avec verrouillage existant
- `php artisan migrate:rollback --step=N` ciblé sur les tables studio uniquement
- `config/courriels.php` `editeur_v2` remis à `false` si problème

## 7. Verdict Phase 0

`PASS_WITH_RESERVATIONS` : architecture et contrats existants validés, aucune réécriture métier requise. Réserves identifiées relèvent soit de l'infrastructure (worktree partagé), soit de l'intégration nouvelle (GrapesJS/Livewire), soit de la configuration. Aucun défaut de code bloquant à ce stade.

**Prochaine étape : Phase 1 — Architecture d'intégration** (définir la structure cible, évaluer les versions, planifier les modifications nécessaires).