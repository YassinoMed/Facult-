# LOOP — UX/UI Premium & Fluidité (01)

> Date : 23/10/2026 · Branche `feature/admin-modifier-etudiant` · Protocole : INVENTAIRE → AUDIT →
> SPÉCIFICATION → PLAN → IMPLÉMENTATION → TESTS → SÉCURITÉ → PERFORMANCE → NON-RÉGRESSION → RAPPORT.
> Phase 0 en lecture seule ; aucun développement avant le critère de sortie (§7).

## 1. État réel du dépôt (LOT 0.1 + 0.2)

| Élément | Valeur mesurée |
|---|---|
| PHP / Laravel / Node | 8.5.11 / 13.33.0 / v26.0.0 |
| Front | Tailwind CSS **v4** (`@import "tailwindcss"`, `@source ../**/*.blade.php`, variant `dark` par classe), Vite 8, Alpine 3.17 (via Livewire), GSAP 3.15, Lenis 1.3.27 |
| Livewire | 6 classes, **4 seulement** branchées en vue (campagnes, designer courriel, attendance builder) — le reste est Blade + Alpine + POST/reload |
| Git | **530 entrées** : 279 suivis modifiés + 251 non suivis, **0 stash** — WIP massif préexistant, préservé intégralement |
| Programmes concurrents actifs | `ADVANCED-02` (Notes & Évaluations — rapport mis à jour à 22:13 pendant la session) + WIP thème V2 portail étudiant + WIP Harmonisation file de traitement |
| Vues | 435 vues Blade, **toutes** sur `layouts/app.blade.php` (pas de layout admin/enseignant dédié) ; navigation partagée `layouts/_navigation.blade.php` branchée par rôle via `ModuleRegistry::elementsNavigation()` |
| Tests UX existants | `EtudiantThemesTest` (audit 20 pages), `NavigationTest`, `EspaceEtudiantTest`, `MotionEtudiantTest`, `EtudiantPagesV2Test` (59 tests / 442 assertions) ; `NotesEvaluationsSecuriteTest`, `EvaluationAdvanced02IntegrationTest` |

## 2. Cartographie UX par rôle (LOT 0.3)

### Étudiant (8 vues cœur)
Dashboard, notes, documents, notifications — session-scopés, thèmes V1/V2. **Anomalies** : aucune des
8 vues n'utilise `x-page-header` (0 fil d'Ariane, `<h1>` manuels) ; états vides bruts
(`etudiant/notes.blade.php:22`, `documents.blade.php:22`) alors que `x-empty-state` existe ; filtres
GET = rechargement complet.

### Enseignant
Dashboard riche (**6 `x-empty-state`** — bonne base), notes listées paginées, saisie par épreuve
(`ma-saisie-notes.blade.php`) en POST complet ; surveillances avec page-header + `x-stat-card`.
Filtres GET rechargés sur demandes/réclamations/présences.

### Admin / Responsable pédagogique
Dashboard `ds-card` brutes ; registre étudiants : filtre GET **12 colonnes** (rechargement complet),
pagination OK, mais états vides bruts et badge statut en HTML brut depuis le modèle
(`Etudiant.php:255` `statut_badge`) ; délibérations conformes (page-header+fil, empty-state) ;
cockpit sans auto-refresh ; **36 formulaires de filtre GET** dans `resources/views/admin/**` =
toute interaction = navigation pleine page.

### Super-admin
Paramètres, rôles, MFA inventoriés — conformes au socle (page-header/ds-card), hors priorité UX.

## 3. Audit des fonctionnalités ADVANCED-02 (LOT 0.4) — état réel vérifié dans le code

| Fonctionnalité | État | Problèmes UX observés (preuves) |
|---|---|---|
| Centre d'anomalies | **Présent** (`AnomalieController`, `anomalies-index` 212 l., actions groupées Alpine) | Filtres `session_id`/`epreuve_id` lus par le contrôleur mais **absents du formulaire** ; `limit(200)` silencieux par détecteur ; `kpis()` relance la détection complète (double charge) ; faux positifs hors barème >20 (`EvaluationQualityService:224`) |
| Import + aperçu | **Présent** (2 étapes, anti-TOCTOU, table erreurs ligne/champ/message) | **Aucun état de progression** ; import en file = flash sans lien vers le rapport ; erreurs preview → `$errors->first()` seul ; **permission incohérente** : vues gate `notes_eval.import` vs routes `notes_eval.enter` (403 possible après affichage) |
| Saisie rapide / collage Excel | **Présent** (`notes-saisie` : nav clavier, collage multi-lignes, autosave `localStorage` + reprise explicite, 4 badges de sauvegarde, `aria-invalid`) | Résumé d'erreurs **absent** (`$errors->first()` seul pour des erreurs par ligne) ; rechargement complet à l'enregistrement (perte filtre/scroll) ; nav clavier cible aussi les lignes masquées ; KPI non recalculés après frappe |
| Workflow / transitions | **Partiel** | Pas de compteur quorum visible (présents/total) ; **Publier sans confirmation** (irréversible) ; erreurs `$errors->first()` ; bouton « Soumettre » absent côté admin (transition `entered→submitted` non exposée) ; `EvaluationWorkflowService` jamais branché sur les routes |
| Documents par lots | **Présent** (2 voies) | Voie A (batch) : aperçu fetch + spinner + historique ✓ ; voie B (relevés) : flash sans suivi de progression (`ImportReport` non listé) |
| Historique / traçabilité | **Présent** (journal + timeline différentielle) | Comparateur 2 versions (endpoint JSON `evaluations.notes.comparer`) **non câblé** dans l'UI ; pas de lien depuis la saisie vers l'historique |

## 4. Design system existant (préparation LOT 1)

- **25 composants Blade** ; socle vivant : `x-page-header` (216), `x-btn` (202, variantes + 44 px),
  `x-empty-state` (123), `x-alert` (105), `x-status-badge` (89), `x-input/select/textarea` (27/15/11,
  label+erreur+ARIA auto), `x-modal` (17, `x-trap`+Échap), `x-dropdown` (Échap). **0 usage** :
  `skeleton`, `verrouillage-surveillance`, `palette`.
- **Contournement massif** : ~40+ vues admin écrivent `class="ds-card"` en brut ; alertes brutes
  (~123 fichiers, ~20 signatures) vs `x-alert` ; badges bruts (~84 fichiers, ≥15 familles) vs
  `x-status-badge` ; boutons bruts (139 fichiers) vs `x-btn` ; **54 fichiers** avec « Aucun… » sans
  `x-empty-state` ; **53 tableaux** sans composant tableau partagé.
- **Tokens** : deux générations coexistent — `--ds-*` global (§4-6 app.css) et `--e2-*` portail V2
  (~70 variables) ; pas de `.btn-*`/`.input` globaux ; police déclarée Instrument Sans (Vite) ≠
  `--font-sans: Inter` (@theme).
- **A11y** : 1 243 attributs ARIA/228 fichiers, `:focus-visible` global, `prefers-reduced-motion`,
  Échap sur modal/dropdown partagés ; **mais** modales ad-hoc sans `x-trap`, retour de focus non
  systématique ; anti double-soumission artisanal et local (`x-data submitting` sur 2 écrans seulement).

## 5. Défauts démontrés — matrice priorisée

| # | Gravité | Défaut (preuve) | Lot cible |
|---|---|---|---|
| U1 | Élevée | Anti double-soumission absent sur ~97 % des formulaires (POST/reload) | 4.3 |
| U2 | Élevée | Erreurs de saisie par ligne invisibles (`notes-saisie:52` : `$errors->first()` seul) | 5.1 |
| U3 | Élevée | Permission incohérente import (vues `notes_eval.import` vs routes `notes_eval.enter`) → 403 après affichage | 5.2 |
| U4 | Moyenne | Publication délibération sans confirmation proportionnée (irréversible) | 5.2 |
| U5 | Moyenne | Quorum non visible avant validation (`deliberations-show:47` statique) | 5.2 |
| U6 | Moyenne | Filtres = rechargement complet partout (36 formulaires GET admin) — friction structurelle | 2.2 (partiel) |
| U7 | Moyenne | Espace étudiant sans fil d'Ariane / états vides bruts — **verrouillé** : toucher le markup c'est altérer la V1 préservée du thème | suspendu (décision) |
| U8 | Faible | Comparateur historique non câblé ; progression relevés invisible | 5.3 (réservé ADVANCED-02) |
| U9 | Faible | Contournement composants (cards/badges/alertes bruts ×3) — refactor global interdit sans nécessité | 1.2 (futur) |
| U10 | Faible | Tokens `--ds-*` vs `--e2-*` divergents ; police Inter ≠ Instrument Sans | 1.1 (décision) |
| U11 | Faible | Centre d'anomalies : filtres morts, `limit(200)` silencieux, double charge `kpis()` | **concurrent ADVANCED-02** |
| U12 | Faible | Modales ad-hoc sans `x-trap`/retour focus (dropdown partagé OK) | 7.1 (checklist) |

## 6. Risques et périmètre protégé

- **Travaux concurrents intouchables** : tout le module anomalies/qualité et le flux relevés
  appartiennent à `ADVANCED-02` (actif) → U8/U11 non traités ici, remis au programme propriétaire.
- **Thème V1/V2** : tout changement de markup des 8 vues étudiantes altère la V1 (contrainte §20/§23
  du rapport portail) → U7 suspendu, décision requise.
- **Aucune dépendance** à installer ; aucun changement de routes/permissions/métier.

## 7. Plan de changement retenu (spécification)

| Lot | Contenu | Justification |
|---|---|---|
| **A — Garde anti double-soumission** (4.3) | Module JS global `form-guard` (délégation : désactivation des boutons submit à la soumission, aucun changement de markup, réversible) | U1, zéro impact serveur, 100 % réutilisable |
| **B — Résumé d'erreurs saisie notes** (5.1) | `notes-saisie` : liste complète des erreurs par ligne dans une alerte `aria-live`, au lieu de la 1ʳᵉ seule | U2, démontrable par test HTTP |
| **C — Cohérence permission import** (5.2) | Gates de vues `notes_eval.import` → `notes_eval.enter` (la route fait foi) | U3, correctif sécurité/UX minimal |
| **D — Délibération show** (5.2) | Compteur quorum (présents/total) + confirmation proportionnée sur « Publier » (composant `x-confirm` existant) | U4+U5, markup additif côté admin |
| **E — Mesures perf baseline** (8.1) | Comptage SQL sur 3 pages prioritaires (dashboard étudiant, registre admin, saisie notes) — mesure sans optimisation non justifiée | protocole « mesurer avant » |
| **Exclus** (motifs) | U6 (refonte filtres = global), U7 (V1 verrouillée), U8/U11 (ADVANCED-02), U9/U10 (décisions/refactor) | §6 |

## 8. Réalisation

### 8.1 Fichiers modifiés par le LOOP

| Fichier | Nature |
|---|---|
| `resources/js/form-guard.js` | **Nouveau** — garde anti double-soumission globale (Lot A / U1) |
| `resources/js/app.js` | Import du module (1 ligne) |
| `resources/views/modules/evaluation/notes-saisie.blade.php` | Résumé d'erreurs complet (`$errors->all()` + `role` alerte) — Lot B / U2 ; gates `notes_eval.import` → `notes_eval.enter` ×2 — Lot C / U3 |
| `resources/views/modules/evaluation/deliberations-show.blade.php` | Compteur quorum en direct (`X/Y présent(s)` + état atteint/non, texte + couleur) et `onsubmit="return confirm(...)"` proportionné sur « Publier » — Lot D / U4+U5 |
| `tests/Feature/UxUiFluiditeTest.php` | **Nouveau** — 7 tests (B, C, D, E) |
| `docs/engineering/LOOP-UNIREGISTRE-UX-UI-PREMIUM-FLUIDITE-01.md` | Ce rapport |

### 8.2 Composants existants réutilisés (aucune duplication créée)

`x-alert` (résumé d'erreurs), `x-page-header`/`x-btn` (inchangés), convention `onsubmit="return
confirm(...)"` déjà en usage dans `admin/studio` et `admin/affectations`, motif KPI existant du
registre pour les mesures. Aucun composant créé, aucune dépendance ajoutée.

### 8.3 Fonctionnalités ADVANCED-02 préservées

Centre d'anomalies, import multi-format avec aperçu, saisie rapide (nav clavier, collage Excel,
autosave + reprise, badges d'état), workflow délibérations, documents par lots, historique
différentiel : **intégralité vérifiée en place** (suites `NotesEvaluationsSecuriteTest`,
`CompostageAnonymeTest`, `NotesImportMultiTest`… toutes vertes). Les vues co-éditées par le
programme concurrent n'ont reçu que des changements additifs minimaux.

## 9. Tests exécutés (commandes et résultats réels)

| Commande | Résultat |
|---|---|
| `php artisan test --filter=UxUiFluiditeTest` | **7 tests / 7 réussis / 23 assertions — PASS** |
| Batterie complète : 21 suites ciblées (UX + thème V2 + Notes/Évaluations) — commande journalisée | **234 tests / 234 réussis / 1145 assertions — PASS** |
| `vendor/bin/pint` (fichiers du LOOP) | **PASS** |
| `npm run build` | **PASS** (1,26 s ; `form-guard` intégré au bundle `app-*.js` : +0,6 kB) |
| `php artisan view:cache` | **PASS** |

Non-régression couverte : authentification/permissions (NavigationTest, SeparationProfilsTest),
portails étudiant (EtudiantThemesTest 20 pages, EtudiantPagesV2Test), Notes & Évaluations (152 tests
du lot précédent + suites ADVANCED-02).

## 10. Accessibilité et responsive

**Exécuté (automatisé)** : libellés d'alerte avec liste complète et texte explicite ; état du quorum
porté par le **texte** (« atteint / non atteint ») et non par la couleur seule ; contrastes via les
tokens existants (`text-emerald-700`/`text-amber-700` sur fond clair — combinaisons déjà employées
par le design system) ; `aria-busy` posé par la garde de soumission.

**Non exécuté (pas de navigateur automatisé dans cette session)** — checklist manuelle à rejouer :
1. Tab/Maj+Tab sur saisie notes (l'ordre des inputs `note-input` suit le DOM).
2. Échap sur `x-modal`/`x-dropdown` (composants partagés : déjà implémenté, modal :19, dropdown:14).
3. Bfcache : après « Précédent » depuis un POST, les boutons doivent être réactivés
   (`pageshow` + `persisted` dans `form-guard.js`).
4. Double-clic sur « Enregistrer les notes » : un seul POST (garde JS ; l'idempotence serveur reste
   la protection réelle — verrous + statuts).
5. Mobile 390 px : grille de saisie défile horizontalement (`overflow-x-auto`), tiroir mobile OK.

## 11. Performance (mesures avant/après)

Mesure : PHPUnit + sqlite (`phpunit.xml`), thème V2 par défaut, `DB::getQueryLog` sur une seconde
requête (cache applicatif échauffé).

| Page | Requêtes mesurées | Garde anti-explosion |
|---|---|---|
| Dashboard étudiant (sans notes) | **16** | < 120 |
| Registre admin (5 étudiants) | **13** | < 200 |
| Saisie notes (10 candidats) | **13** | < 120 |

**Aucune optimisation entreprise** : les baselines sont saines (les préchargements des travaux
précédents portent leurs fruits). Optimisations candidates documentées pour un futur lot :
filtres GET → paramètres d'URL avec conservation (U6), compteur d'anomalies sans double détection
(U11, programme concurrent).

## 12. Sécurité — contrôles effectués

- **Aucun changement** de routes, middlewares, policies, permissions, règles métier ou workflow.
- Lot C **renforce la cohérence** : les vues n'exposent plus des boutons dont la route exigerait
  une autre permission (`notes_eval.enter` = source de vérité, côté serveur inchangé).
- La garde de soumission est **purement présentationnelle** : aucune donnée lue/stockée, aucune
  autorité ; CSRF, verrous, optimistic locking et permissions serveur restent les seules protections.
- Aucune donnée sensible dans les messages d'erreur ajoutés (libellés métier du service existant).
- Tests de permissions réexécutés verts (`SeparationProfilsTest`, suites évaluation).

## 13. Réserves et tests non exécutés

1. **Comportement JS non exécuté par les tests** (pas de navigateur automatisé) : garde de
   soumission validée par revue de code + checklist §10. Aucun verdict navigateur revendiqué.
2. **U6 (filtres/rechargements), U7 (navigation étudiante), U9/U10 (refonte design system),
   U12 (focus-trap modales ad-hoc)** : non traités — motifs §6-§7 (périmètre global, V1 verrouillée,
   décisions requises).
3. **U8/U11** : propriété du programme concurrent `ADVANCED-02` (actif pendant la session) — remis.
4. Suite complète globale non exécutée (rapports antérieurs contradictoires) — aucun PASS global
   revendiqué.

## 14. Instructions de validation manuelle

1. `npm run dev` → saisie d'une épreuve → double-clic rapide sur « Enregistrer » : un seul POST,
   boutons désactivés pendant l'envoi.
2. « Précédent » depuis une page POSTée : boutons réactivés (bfcache).
3. Saisie avec 2 lignes invalides → le bandeau rouge liste les 2 erreurs (plus seulement la 1ʳᵉ).
4. Délibération avec jury incomplet → « 1/3 membre(s) présent(s) · Quorum non atteint » visible ;
   pointer les membres → compteur en direct.
5. « Publier » → boîte de confirmation système ; Annuler = aucune requête.
6. Compte `admin_scolarite` (sans `notes_eval.import`) : les deux blocs d'import sont visibles et
   leurs routes répondent (fin du 403 après affichage).

## 15. Verdict

**`PASS_WITH_RESERVATIONS`** — défauts U1–U5 corrigés et prouvés par 234 tests ciblés verts ;
ADVANCED-02 et le WIP intégralement préservés ; baselines performance saines et documentées ;
comportement JS couvert par checklist manuelle (non exécuté en navigateur — réserve explicite).
Aucun commit, aucun push.
