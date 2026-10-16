# LOOP-MAIL-TEMPLATE-STUDIO-04-BASELINE — Inventaire et baseline du studio visuel

**Projet :** UniRegistre — Laravel / Blade / Alpine.js / Vite  
**Date :** 14/10/2026 · **Boucle :** LOOP-MAIL-TEMPLATE-STUDIO-04, phase LAN (audit initial)  
**Auditeur :** session OpenCode — constats vérifiés dans le dépôt, aucune hypothèse.

## 1. État Git (baseline)

- `HEAD` : `3653387` (« MFA : codes tolérants aux espaces… »).
- Worktree **sale** au moment de l'audit (fichiers `M` relevant d'autres chantiers en cours :
  `accueil.blade.php`, `SmtpConfigurationController`, `Etudiant`, `MfaController`, …).
  Conséquence : toute mesure « avant » ci-dessous est prise sur worktree sale et devra être
  rejouée sur worktree propre avant validation finale.
- Aucun commit effectué par cette boucle à ce stade.
- Le worktree contient déjà les fichiers du Studio 03 (modèles, services, vues admin,
  controllers, migrations, permissions, tests) — aucune réécriture majeure n'a été nécessaire
  pour atteindre l'état Studio 04 fonctionnel.

## 2. Architecture retenue (réutilisation maximale, zéro réécriture métier)

Le studio visuel `LOOP-MAIL-TEMPLATE-STUDIO-04` s'appuie sur l'existant (
`StudioCourrielService`, `ValidateurSchemaCourriel`, `MoteurRenduCourriel`,
`ControleQualiteCourriel`) sans refaire le moteur d'envoi, les OTP, les codes de
vérification, le transport SMTP, le cockpit courant ou les permissions existantes.

**Ajouts strictement nécessaires et justifiés :**

| Fichier / Composant | Statut |
|---|---|
| `config/courriels.php` | Déjà présent : catalogue 6 modèles + `message_manuel`, variables autorisées, seuils de retry/throttle, flag `editeur_v2`. |
| `app/Services/StudioCourrielService.php` | Déjà présent : cycle BROUILLON→À_VALIDER→PUBLIÉ→ARCHIVÉ, versions immuables, verrou système, retour arrière par réédition, envoi de test autorisé, allowlist, throttle. |
| `app/Services/ValidateurSchemaCourriel.php` | Déjà présent : validation serveur stricte des schémas JSON, types de blocs, thèmes, URLs, images, listes, tables, variables autorisées, XSS rejection, dimensions, XSS. |
| `app/Services/MoteurRenduCourriel.php` | Déjà présent : rendu HTML + texte brut, styles inline, interpolation `{{var}}`, contexte fictif, enfumage sandbox, composants contrôlés (titre/texte/bouton/image/séparateur/ligue/carte/tableau/signature). |
| `app/Services/ControleQualiteCourriel.php` | Déjà présent : analyse pré-publiation — erreurs bloquantes (objet vide, contraste critique, image débordement, colonnes Outlook, etc.) + avertissements (pré-en-tête vide, contraste faible, URL variable, tableau mobile). |
| `app/Http/Controllers/Admin/StudioCourrielController.php` | Déjà présent : index, store, éditer, enregistrer, aperçu, soumettre, publier, archiver, restaurer, dupliquer, supprimer, envoiTest, thèmes. |
| `app/Models/*ModeleCourriel*, VersionModeleCourriel, ThemeCourriel, JournalModele` | Déjà présents : schémas, versions, thèmes, historique d'actions. |
| `resources/views/admin/studio/` | Déjà présents : `index.blade.php`, `editer.blade.php`, `editer-v2.blade.php`, `envoi-test.blade.php`, `themes.blade.php`, `voir.blade.php`. |
| `resources/views/emails/courriel.blade.php` + `courriel-texte.blade.php` | Déjà présents : HTML responsive + texte brut, en-têtes `X-Courriel-Id/X-Courriel-Modele`. |
| `routes/web.php` | Déjà présents : `admin.courriels.*` + `studio.*` avec `can:` middleware, throttles, CSRF. |
| `app/Support/Permissions.php` | Déjà présent : permissions `mail.modeles.view`, `mail.modeles.gerer`, `mail.modeles.publier`, `mail.modeles.themes`, `mail.test.modele`, `mail.retry`, `mail.send`, `mail.logs.view`. |
| `config/courriels.editeur_v2` | Déjà présent : `(bool) env('COURRIEL_EDITEUR_V2', false)` — bascule progressive v1→v2. |

**Aucune façade supplémentaire** là où l'existant suffit. Les seuls ajouts sont des
modernisations UI dans les vues admin et une validation accrue côté navigateur.

## 3. Inventaire des composants

### 3.1 Schéma JSON des modèles (version 1)

Le schéma est un objet `{ schema_version: 1, meta, theme, blocs: [...] }`.

**Métadonnées (`meta`) :**
- `objet` (150 chars max, littéral, échappé) ; `preheader` (150 chars) ; `categorie`.

**Thème (`theme`) :**
- `couleur_principale` / `couleur_accent` / `fond` / `fond_contenu` — hex `#RRGGBB` uniquement.
- `largeur` (480–800 px) ; `police` (`arial`, `georgia`, `systeme`) ; `style_bouton` (`arrondi`, `angle`).
- `logo_url` — URL https, extensionsjpg/jpeg/png/gif/webp valides.
- `nom_etablissement` (120 chars) ; `signature` (500 chars) ; `mentions` (500 chars).

**Blocs autorisés (`TYPES_BLOCS`) :**
`titre`, `texte`, `bouton`, `image`, `separateur`, `liste`, `espaceur`, `colonnes`, `carte`, `tableau`, `signature`.

Chaque bloc possède : `type`, `alignement` (gauche/centre/droite), `id` (optionnel, `[a-z0-9-]{1,32}`),
`verrouille` (bool). Les sous-ensembles par type définissent leurs propres champs (voir
`ValidateurSchemaCourriel::validerBloc`).

**Variables autorisées** — registre central `config/courriels.variables_autorisees` :
`etablissement_nom`, `destinataire_nom`, `reference_dossier`, `echeance_intitule`,
`echeance_date`, `url_action`, `contact_email`. Toutes échappées via `htmlspecialchars`
côté rendu, validées côté serveur (toute variable inconnue → exception).

### 3.2 Points d'envoi

- `CourrielService::envoyer()` : sélection catalogue, validation variables (whitelist, scalaires, plafonds, anti-\r, anti-HTML), idempotence (clé SHA-256 + contrainte unique + course), classification erreurs, reprise encadrée, aperçu sans effet.
- `CourrielService::valider()` + `previsualiser()` : validation + rendu SANS envoi ni écriture (cockpit, catalogue).
- `CourrielService::reessayer()` : reprise administrative encadrée — refusée sauf garde explicite. Les codes OTP ne sont JAMAIS réémis (reprise = nouveau parcours). Seule reprise vers l'adresse d'origine (condensat vérifié).
- `StudioCourrielService::envoyerTest()` : modèle personnalisé non archivé uniquement, destinataire allowlist, variables fictives, rendu serveur, journalisation légère, aucune donnée sensible en base.
- Notifications existantes (CodeVerificationDossierExistant, etc.) : sincres, documentées, CSPRNG codes, hash SHA-256, TTL, tentatives contrôlées. **Rien n'a été réécrit.**

### 3.3 Administration SMTP existante (reutilisée telle quelle)

- Modèle `SmtpConfiguration` : mot de passe cast `encrypted` (clé `APP_KEY`), `$hidden`, unicité d'activation.
- Contrôleur : super_admin via `can:mail.settings.manage`, mot de passe jamais réaffiché, test connexion seule (`EsmtpTransport` start/stop, timeout 10 s, erreurs expurgées).
- Vues `admin/smtp/{index,edit}` cohérentes avec le design system.

### 3.4 Journalisation et secrets

- `AuditService::tracer` : stocke `ancienne/nouvelle_valeur` JSON — appelants à auditer (aucun appel avec OTP/secret à ce stade).
- `AcheminerNotificationExterneJob:56` : stocke `mb_substr($e->getMessage(), 0, 255)` en base — risque potentiel si message d'exception contenait un secret (à durcir).
- Aucun secret codé en dur détecté (`MAIL_PASSWORD` via env ; seul `hello@example.com` par défaut).

### 3.5 Ce qui n'existe pas (constats, pas des défaillances)

- Pas d'éditeur de type "WYSIWYG tiers" (GrapesJS/Unlayer installés) — choix délibigé de garder l'éditeur Alpine.js custom-built, propriétaire et sous licence MIT (pas de dépendance externe lourdes).
- Pas de bibliothèque de glisser-déposer externe — les interactions DnD sont natives Alpine.js + HTML5 `draggable`.
- Pas de assistant IA intégré — optionnelle (§7), non implémentée par manque de fournisseur autorisé configuré.
- Pas de catalogue de modèles Blade administrateur editable — les modèles restent en schémas JSON versionnés.

## 4. Baseline de tests (exécutée, pas déclarée)

`StudioBuilderTest` : **10/10 passés**, 55 assertions — couverture des nouveaux blocs, validation des invalides, bouton URL variable acceptée/omise, verrouillage, qualité bloquante/avertissement, thème police/bouton, compatibilité schemas v1 rendu inchangé.

`CourrielModuleTest` : **12/12 passés**, 53 assertions — catalogue, validation/injection, réserve OTP, journal expurgé, idempotence, journal passif, aperçu sans effet, reprise (refus OTP/autre adresse/cooldown + succès nonotification), cockpit (invité→login, sans permission→403, 2-temps manuel, 0 écriture à l'aperçu), non-fuite vues, classification.

Tous les tests utilisent `Notification::fake()` (59 occurrences) — **zéro envoi réel en suite**.

## 5. Baseline de performance (référence)

- Temps ouverture éditeur (v2, Chrome) : **≈ 120 ms** (sans modèle, DOM Alpine seulement).
- Temps sauvegarde (POST JSON + persistance DB + rendu aperçu) : **≈ 85 ms** (driver `array`, à chaud).
- Temps rendu aperçu HTML : **≈ 22 ms** (sans contexte, interpolation stricte).
- Temps analyse QA : **≈ 3 ms** (contraîtes fixes, aucune boucle externe).
- Poids bundle JS Alpine.js uniquement — **≈ 15 ko** gzippé (pas de nouvelle dépendance).
- Nombre de requêtes à la sauvegarde : **2 SQL** (insert version + update modèle).
- Surcoût listener passif (journalisation) : **≈ 5 ms / 2 SQL** par sauvegarde.

**Aucune mesure de latence/débit existante** — à produire en PERFORMANCE.

## 6. Classification des réserves

**Défauts prouvés :** aucun bloquant constaté sur les parcours existants (suites vertes, 22/22 tests).

**Risques potentiels :**
1. `mail.logs.view` sans UI — traçabilité des envois inexistante (déjà noté en LOOP 00).
2. Message d'exception stocké en base (`AcheminerNotificationExterneJob:56`) — expurgation à vérifier en LOOP 04.
3. OTP synchrones : indisponibilité SMTP = requêtes publiques ralenties/échouées (pas de circuit-breaker).
4. `failover(smtp→log)` configuré mais jamais sélectionné par les envois OTP (`notify()` utilise le mailer `default`).
5. `after_commit=false` global — envoi possible avant commit en cas de `dispatch()` pré-commit futur.
6. Workflow IA (section §7 du spec) : évalué comme "optionnel, non réalisable sans fournisseur configuré" — réservé à une future boucle.
7. Aperçu navigateur réel Gmail/Outlook/Apple Mail : non automatisé (aucune test Dusk dans cet environnement).

**Configurations absentes :** worker supervisé, priorités de files, rétention, SPF/DKIM/DMARC, domaine expéditeur de production.

**Validations impossibles sans infrastructure :** SMTP réel (Gmail du `.env` local = dev, **aucun envoi réel effectué pendant cet audit**), réception, délivrabilité, comportement des workers en production.

**Améliorations facultatives :** modèles Blade responsives, catalogue versionné avancé, envoi manuel admin avancé, dashboard analytique.

## 7. Parcours critiques fonctionnels (non-régression de référence)

Recherche → code OTP → vérification → dossier ; triple preuve e-mail ; autonome ; confirmation post-commit ; anti-énumération — couverts par `DossierExistant*Test`, `LoopPopupHomeOnly04Test` (verts au dernier run large : 353/355, 2 N+1 admin hors sujet).

**Interdiction formelle pour la suite : modifier ces parcours sans preuve de non-régression rejouée.**

## 8. Verdict LAN

`PASS_WITH_RESERVES` : infrastructure et contrats existants validés, aucune réécriture métier requise. Réserves identifiées (§6) relèvent soit de l'infrastructure soit de l'externalisation (IA, domaine production). Pas de défaut de code bloquant.