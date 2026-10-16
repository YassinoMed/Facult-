# LOOP-MAIL-TEMPLATE-STUDIO-03-REPORT — Studio de modèles e-mail

**Date :** 14/10/2026 · **Méthode :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Verdict :** `PASS_WITH_RESERVATIONS` (réserves §8 : navigateur réel, clients mail, SMTP réel du studio)

## 1. Architecture
- **Schéma JSON versionné** (`schema_version: 1`) : meta (objet/pré-en-tête/catégorie),
  7 blocs (`titre|texte|bouton|image|separateur|liste|espaceur`), thème inline ; plafond 200 Ko, 30 blocs.
- **Validateur serveur** (`ValidateurSchemaCourriel`, exécuté à chaque sauvegarde/aperçu/
  publication/restauration) : types stricts, textes bruts (aucune balise), anti-XSS
  (`script/iframe/form/on*/javascript:/vbscript:/data:text/html`), variables `{{x}}`
  du registre uniquement (inconnues/malformées rejetées), URL `https|mailto` (+ images
  https + `jpg|jpeg|png|gif|webp`), couleurs `#rrggbb`, bornes numériques.
- **Moteur de rendu** (`MoteurRenduCourriel`) : tables + styles inline, pré-en-tête masqué,
  header logo+établissement, pied signature/mentions, texte brut miroir, interpolation
  échappée (`{{var}}` absente → vide).
- **Registre de variables** (`config/courriels.php`, en code, documentées : type, sens,
  contextes, `si_absente`, `exemple` fictif) : 7 variables sûres ; **`code`/`token`
  inexistants au registre → impossibles à référencer**.
- **Cycle** BROUILLON→À_VALIDER→PUBLIÉ→ARCHIVÉ : versions immuables (une ligne par
  sauvegarde), verrou optimiste (409), publication atomique revalidée, rollback =
  republication d'une version antérieure sous nouveau numéro, suppression définitive
  réservée aux brouillons jamais publiés, comparatif structurel, journal d'audit.
- **Modèles système** (7 du catalogue) : verrouillés, liste lecture seule, jamais migrés
  automatiquement (caractérisation : `test_non_regression_catalogue_intact`).
- **Éditeur** (`Alpine.data` inline, aucune lib ajoutée) : palette, CRUD blocs, DnD natif +
  boutons clavier ↑↓⧉✕, undo/redo (50), sale + `beforeunload`, chips `{{var}}` (insertion
  sans collision Blade), thèmes inline + application, aperçu débouncé (800 ms) vers
  `<iframe sandbox="">` + onglet texte, mobile/bureau, test-send modal.
- **Envoi de test** (`mail.test.modele`, allowlist `COURRIEL_TEST_ALLOWLIST` vide par
  défaut, throttle 5/min, 2 temps, journal masqué + version, transport `array` en test).
- **Thèmes** : CRUD validé (hex, 480–800, logo https+ext) ; pas d'upload (URL validées
  uniquement — réserve documentée).

## 2. Permissions (5, matrice, Gates auto ; super_admin complet)
`mail.modeles.view` (bibliothèque/voir), `mail.modeles.gerer` (créer/éditer/sauvegarder/dupliquer/supprimer), `mail.modeles.publier` (soumettre/publier/archiver/restaurer), `mail.modeles.themes`, `mail.test.modele`. Invité→login, sans permission→403 (testé).

## 3. Sécurité (testée, §13)
XSS stockée/réfléchie (script, `<b>`, `onclick`, `javascript:`, iframe, form),
variable sensible (`{{code}}`, `{{token}}`, `{{mot_de_passe}}`), protocoles
(`http/ftp/javascript:/data:`), `.exe`, IDOR (versions/rollback scopés au modèle),
conflit optimiste 409, archivés non modifiables, suppression publiée interdite,
allowlisttest, throttle, journaux sans corps ni secret. **Aucun envoi réel en suite**
(`array`/fake ; OTP impossibles structurellement).

## 4. Fichiers, migrations, routes
- Migration `2026_10_14_100000` (4 tables : `themes_courriel`, `modeles_courriel`,
  `versions_modele_courriel` unique(modele,numero), `journal_modeles`) + correctif
  `2026_10_14_110000` (FK journal → `SET NULL`, défaut `NO ACTION` détecté à l'audit).
  SQLite OK ; retour arrière : `migrate:rollback --step=2` (tables studio uniquement,
  journal `courriels` intact).
- Modèles : `ModeleCourriel` (+ `derniereVersion` corrigé : `reorder()`, l'`orderBy`
  hérité faussait le conflit optimiste), `VersionModeleCourriel`, `ThemeCourriel`, `JournalModele`.
- Services : `ValidateurSchemaCourriel`, `MoteurRenduCourriel`, `StudioCourrielService`.
- Contrôleur + 15 routes `admin.studio.*` + 5 vues (`index/voir/editer/themes/envoi-test`) + lien nav `@can`.
- Config : registre variables + allowlist dans `config/courriels.php` (zéro nouvelle var d'env requise).

## 5. Tests réellement exécutés (verts)
- `StudioMoteurTest` 8/8, `StudioCourrielTest` 9/9 (cycle, 409/422, XSS, IDOR, permissions,
  envoi-test allowlist/journal, thèmes, non-régression catalogue).
- Non-régression mail+dossiers+accueil : **186/186** (1508 assertions).
- Défauts corrigés puis rejoués : validation `{{nom}` malformée, FK `SET NULL`, tri
  `derniereVersion`, compilation Blade `{{ '{{' }}` (passé en `{!! !!}`), règle `sometimes`,
  permission `apercu`, 4 tests `risky` → 0.

## 6. Performance mesurée (réel, à chaud)
Création 21 ms/4 SQL · sauvegarde 4 ms/4 SQL · aperçu serveur 0 ms/0 SQL (HTML 1709 o,
texte 230 o) · bibliothèque paginée 20 sans N+1 (aucune relation chargée). Aucun
rechargement complet côté éditeur (fetch débouncé) ; pile undo bornée.

## 7. Limites clients e-mail (matrice à valider humainement)
HTML : tables + inline, pas de JS/formulaire/police externe — compatible Gmail/Apple Mail
théorique ; **Outlook (Word) : conditionnel non fourni, à tester** ; mode sombre et
`border-radius` partiels selon clients. Un aperçu navigateur ≠ rendu client.

## 8. Réserves (explicites)
1. Parcours navigateur réel de l'éditeur non exécuté ici (aucun Dusk) — validation humaine requise.
2. Rendu Gmail/Outlook/Apple Mail non testé (matrice §7 à jouer).
3. Envoi de test SMTP réel du studio non effectué (allowlist vide par défaut ; procédure : renseigner `COURRIEL_TEST_ALLOWLIST`, publier un brouillon de test, envoi 2-temps, contrôler `admin.courriels`).
4. Upload d'images non implémenté (URL validées uniquement).
5. Worktree partagé : rejouer les suites mail après chaque merge.

## 9. Validation humaine (check-list)
Créer un brouillon → aperçu desktop/mobile + texte → sauvegarde v2 → conflit (2 onglets)
→ soumettre → publier → archiver → restaurer v1 → comparer → dupliquer → supprimer
brouillon → envoi de test (adresse allowlist) → vérifier `admin.courriels` (masqué).
