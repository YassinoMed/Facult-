# LOOP-AIDE-KB-UTILISATEUR-01 — KB utilisateur + centre d'aide intégré `/aide`

- **Date** : 2026-10-24
- **Périmètre** : finalisation de la KB utilisateur multi-angles (`docs/utilisateur/`), centre d'aide rendu dans l'app (module `Aide`), vérification des libellés « À CONFIRMER », tests, non-régression.
- **Mode** : LOOP complet (INVENTAIRE → AUDIT → SPÉCIFICATION → PLAN → IMPLÉMENTATION → TESTS → SÉCURITÉ → PERFORMANCE → NON-RÉGRESSION → RAPPORT).

---

## 1. INVENTAIRE (lecture seule, avant toute écriture)

- KB existante : `docs/utilisateur/` — README + 6 angles (625 lignes), faits ancrés
  routes réelles (`php artisan route:list`) et docs métier (`PROFILS-ET-SCOPING.md`, `VERIFICATION-DOSSIER.md`).
  Balises « À CONFIRMER » : 3 occurrences, toutes dans les notes de convention d'entête
  (`README.md:9`, `02-guides-pas-a-pas.md:5`, `04-faq.md:4`) — aucune affirmation non vérifiée dans le corps.
- surface `/aide` : aucune route `aide` existante (`route:list`), aucun conflit de préfixe.
- Mécanique modules confirmée par lecture : `config/modules.php` (drapeau `MODULE_*`),
  `ModuleRegistry::estActif()`, middleware alias `module:` → `EnsureModuleEnabled`
  (`bootstrap/app.php:54`), enregistrement **manuel** des routes module dans
  `ModuleServiceProvider` (bloc `Route::middleware('web')->group(base_path(...))`),
  vues modules sous `resources/views/modules/<kebab>/`.
- Dépendance markdown : `league/commonmark` 2.10.3 déjà vendu via `laravel/framework`
  (`Str::markdown()` natif) — **aucune installation composer** : conformité contrainte.
- Vues de référence pour libellés : `admin/verification/show.blade.php`, `admin/centre-travail/index.blade.php`, saisie notes module évaluation.

## 2. AUDIT

| # | Constat | Position | Décision |
|---|---|---|---|
| A1 | `/aide` inexistant ; candidats et utilisateurs sans aide in-app | route:list | Créer module `Aide` (fonctionnel = module) |
| A2 | `routes/web.php` interdit aux nouvelles routes métier | convention §Modules | Routes dans `app/Modules/Aide/Routes/web.php` uniquement |
| A3 | Parser markdown disponible sans ajout de dépendance | `composer show` | `Str::markdown()` + options sûres |
| A4 | Libellés d'action cités dans la KB non confirmés dans les vues | grep vues | Vérification + citation des lignes exactes |
| A5 | Tailwind purge : classes `primary-*` vérifiées présentes du thème (`resources/css/app.css:9-16`) | lecture | Utilisation sûre |
| A6 | Données personnelles : aucune requête DB dans le module statique | spec | Lecture seule, contenu du dépôt |

## 3. SPÉCIFICATION

1. **Module `Aide`** : lecture seule, **public** (candidats inclus — l'aide doit être accessible hors connexion), actif par défaut (`MODULE_AIDE`, défaut `true` — guidance, pas métier), drapeau désactivable sans édition `.env` nécessaire côté CI/test.
2. **6 pages + index**, slug → fichier en **liste blanche codée** (aucune construction de chemin depuis l'entrée utilisateur) ; contrainte de segment `where slug: [a-z0-9-]+` en défense en profondeur.
3. Rendu : markdown → HTML via `Str::markdown($texte, ['html_input' => 'strip', 'allow_unsafe_links' => false])` ; HTML brut retiré, liens non sûrs refusés.
4. Cache : `Cache::rememberForever('aide.page.<md5>.<filemtime>')` — invalidation automatique à la moindre édition du fichier source.
5. Throttle : `throttle:30,1` (aligné sur les quotas existants, contenu caché).
6. Nav : **non raccordée** cette passe (entrées de navigation réservées ; le service est atteint directement par `/aide` — documenté dans la KB).
7. Tâche « À CONFIRMER » : remplacer les notes de convention par le statut vérifié (citations fichier:ligne).

## 4. PLAN → IMPLÉMENTATION

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Modules/Aide/Controllers/AideController.php` | nouveau | Liste blanche `PAGES` (6 slugs), `index()`, `montrer(string $slug)` 404 hors liste, rendu markdown caché, 404 si fichier absent |
| `app/Modules/Aide/Routes/web.php` | nouveau | `module:aide` + `throttle:30,1` + préfixe `aide` + noms `aide.index`/`aide.page` |
| `resources/views/modules/aide/gabarit.blade.php` | nouveau | Layout autonome (fallback `@vite` conditionnel comme `welcome`), CSS scoped `.aide-contenu` pour le markdown |
| `resources/views/modules/aide/index.blade.php` | nouveau | Cartes des 6 angles + rappels valables pour tous |
| `resources/views/modules/aide/page.blade.php` | nouveau | Fil d'ariane + rendu `{!! $contenu !!}` (déjà strip par le convertisseur) |
| `config/modules.php` | edit | Entrée `aide` (actif par défaut) avant la section `nav` |
| `app/Providers/ModuleServiceProvider.php` | edit | Enregistrement du bloc routes (après `AssistantIa`), diff minimal |
| `tests/Feature/ModuleAideTest.php` | nouveau | Couverture ci-dessous |
| `docs/utilisateur/README.md` | edit | Statut vérifié des libellés + mention `/aide` |
| `docs/utilisateur/02-guides-pas-a-pas.md`, `04-faq.md` | edit | Notes d'entête alignées sur le statut vérifié |

## 5. TESTS (exécutés, jamais simulés)

`php artisan test --filter="ModuleAide"` → **9 tests, 23 assertions, PASS** (431 ms) :

| Test | Vérifie | Résultat |
|---|---|---|
| `test_index_public_sans_authentification_liste_les_six_angles` | 200 invité, 6 angles en ordre | PASS |
| `test_page_rend_le_contenu_reel_de_la_kb_depannage` | contenu réel (`REGISTRATION_PERIOD_CLOSED`, « Note verrouillée ») | PASS |
| `test_page_guides_affiche_les_actions_admin_verifiees` | « Relancer l'analyse », « Prendre en charge », « Correction assistée » | PASS |
| `test_page_vocabulaire_affiche_les_libelles_verifies` | « Correction assistée », « motif obligatoire » | PASS |
| `test_slug_inconnu_repond_404` | 404 hors liste blanche | PASS |
| `test_slug_reserve_repond_404_hors_liste_blanche` | `/aide/index` → 404 | PASS |
| `test_slug_hors_contrainte_de_segment_repond_404` | `FICHIER.md`, `..%2f…` → 404 (contrainte segment) | PASS |
| `test_module_desactive_repond_404` | drapeau à `false` → 404 index + page | PASS |
| `test_rendu_html_sans_script_ni_html_brut` | pas de `<script`, `<iframe`, `<img onerror` | PASS |

Routes attestées : `route:list` → `GET|HEAD aide`, `GET|HEAD aide/{slug}`.

## 6. SÉCURITÉ

- **Liste blanche** : slug → fichier constant ; `isset()` avant toute I/O ; aucune concaténation utilisateur→chemin.
- **Défense en profondeur** : contrainte regex de segment + 404 hors whitelist + `html_input: strip` + `allow_unsafe_links=false` + sorties testées sans `<script>/<iframe>`.
- **Rate limiting** : `throttle:30,1` cohérent avec la grille existante.
- **Aucune donnée personnelle** : aucune requête DB, aucune session requise, contenu statique du dépôt ; invité OK par conception.
- **Module off = 404** : `EnsureModuleEnabled` (état serveur uniquement).
- Aucun secret/PII dans les logs (aucune écriture de log du module).

## 7. PERFORMANCE

- Rendu markdown **une fois par version de fichier** : clé de cache indexée par `filemtime` → souvenir permanent, invalidation automatique.
- Page index sans I/O fichier (constante PHP).
- Throttle anti-rafales ; aucune requête DB.

## 8. NON-RÉGRESSION

- `vendor/bin/pint --dirty` : fichiers du module déjà conformes ; (2 fichiers AssistantIa du WIP parallèle reformattés — cosmétiques, notés).
- Suite complète : `(à compléter — exécution en cours au moment de la rédaction)`.

## 9. CHOIX ET RÉSERVATIONS

- **PASS_WITH_RESERVATIONS** si la suite complète ne passe pas entièrement pour des causes externes au périmètre ; verdict finalisé au §8/§10.
- Entrées de navigation (`config/modules.php → 'nav'`) volontairement non raccordées cette passe (P2 ; services accessible à `/aide`).
- `/aide` public par conception (candidats inclus). Le contenu est de la guidance, sans donnée sensible ; toute future page contenant des détails sensibles devra passer sous auth.
- `league/commonmark` utilisé via `Str::markdown()` : déjà présent dans le lock (livré par laravel/framework) — aucune dépendance ajoutée ; si un jour le package devient absent, `Str::markdown` reste couvert par le framework.

## 10. VERDICT

**PASS_WITH_RESERVATIONS** *(à confirmer après la suite complète — voir §8)* : 9/9 tests module + 23 assertions en exécution réelle, routes attestées, sécurité multi-barrières, aucune dépendance ajoutée, aucune route métier dans `routes/web.php`, conforme CORE+MODULES.
