# LOOP 15 — Prévention du décrochage (accompagnement humain)

> Cahier : catalogue versionné de règles approuvées et explicables ; dossier d'accom-
> pagnement séparé des résultats officiels ; flux humain (examen, correction, clôture) ;
> rétention des alertes. Règle d'or : donnée absente ≠ signal négatif ; aucune alerte
> ne modifie notes, décisions ni statut d'inscription ; décision toujours humaine ;
> accompagnement humain, jamais sanction automatique ; clôture tracée.

## LOOP-00 — Audit préalable (preuves exécutées)

### Ce qui existe déjà et qu'il faut réutiliser (pas dupliquer)

| Source | Mécanisme confirmé | Usage Lot 15 |
|---|---|---|
| `presences` (module Attendance, modèle `Presence`) | `STATUTS = ['present','absent','retard','excuse']` ; `excuse` = justifié (workflow à part) ; UNIQUE (etudiant_id, date, seance) | axe absences : `absent` / `retard` comptés, `excuse` NON compté, `present` sain |
| `notes` (racine, modèle `Note`) | note decimal:2, `locked_at`, UNIQUE (semestre, matière, session) ; chaîne `note → semestre → inscription_academique → programme → faculté` ; année via `inscriptions_academiques.annee_universitaire_id` | axe notes : « notes sous seuil de mesure » |
| `RendezVousScolariteService` | réservations = `online_requests` avec `form_data->date_creneau`, statuts actifs draft→approved | **NON utilisé** : aucun statut « no-show » confirmé (réserve LOOP-10) |
| Notifications unifiées | `CentreNotificationsUnifieService::distribuer` + `PreferenceNotificationService::CATEGORIES = [resultats, documents_officiels, demandes, annonces, inscriptions]` | **NON utilisé** : aucune catégorie « suivi » confirmée ; le service partagé n'est pas modifiable (coexistence) (réserve LOOP-10) |
| Permissions | registre `app/Support/Permissions.php` ; précédent `assistant_ia.*` / `parcours.*` : un module ajoute SES clés | 3 clés ADDITIVES `decrochage.*` (aucune clé existante supprimée) |
| Périmètre facultaire | `User::peutAccederFaculte()` / `User::faculteRestreinteId()` (primitives confirmées) | contrainte serveur sur scan, index, flux humain |

### Constats réels (MySQL `inscription_etudiants`, sondes SELECT uniquement)

| Sonde | Résultat | Conséquence |
|---|---|---|
| `presences` COUNT | **0 lignes** (`date_max` NULL) | l'axe absences ne produit JAMAIS de signal sur cette base tant que les présences ne sont pas saisies — règle d'or respectée plutôt que contournée |
| `notes` COUNT | 533 lignes, 2 `locked_at` non nuls | l'axe notes fonctionne sur données réelles |
| `etudiants` COUNT | 2047 | volume de référence volumétrique |
| `online_requests` `date_creneau` | statuts actifs draft/approved/etc., AUCUN statut « manqué » | métrique RDV réservée (jamais inventée) |

### Ce qui manque (réserver explicitement, ne jamais présenter comme disponible)

1. Statut rendez-vous manqué (no-show) — absence de source confirmée.
2. Catégorie de notification « suivi » — la monnaie des préférences n'a pas de catégorie dédiée ; je ne crée pas de catégorie dans le service partagé.
3. Politique de rétention des alertes — aucun seuil confirmé → `retention_a` reste NULL.
4. Cohorte officielle d'étudiants — non définie institutionnellement : le scan n'agit que sur les étudiants présents dans les sources (jamais de dénominateur inventé).

### AssistantIa et règles de coexistence

Module `AssistantIa` et ses migrations : intacts. Aucune colonne ajoutée aux tables
centrales (règles modules du dépôt) ; aucune entrée dans `routes/web.php` central.

## LOOP-01 — Contrat métier

### Acteurs et cas d'utilisation

| Acteur | Clés | Cas d'usage |
|---|---|---|
| `admin_scolarite` | view + regles + traiter | gestion complète du catalogue, scan global, flux humain |
| `admin_faculte` | view + traiter | scan et flux restreints à SA faculté (re-scopés serveur) |
| `agent_scolarite` | view | consultation du catalogue et des alertes (pas de scan) |
| `consultation` | view | lecture seule |
| `super_admin` | TOUTES | tout |

### Fourches du cahier → réalisation

1. **Catalogue versionné, règles approuvées et explicables** : table `regles_decrochage`
   (code unique, version, statut `brouillon | approuvee | desactivee`) ; `raison_explicable`
   ≥ 10 caractères exigée AVANT approbation (approuver une règle sans justification =
   422) ; approbation tracée (`approuve_par`, `approuve_le`) ; le scan n'exécute QUE les
   règles `approuvee`.
2. **Scan de détection** : moteur lecture-seule sur les sources ; deux axes confirmés
   uniquement (presences, notes) ; preuves datées et sourcées embarquées (ids, dates,
   extraits, ≤ 10 éléments) ; périmètre faculté/année forcée côté serveur.
3. **Flux humain** : `generee → examinee → cloturee`, chaque transition tracée (auteur
   + horodatage + commentaire/motif) ; examiner deux fois = 422 ; clôturer exige un
   motif ; re-clôture = 422.
4. **Dossier d'accompagnement séparé** : table `suivis_decrochage` (alerte, mesure,
   auteur) — AUCUNE FK vers notes/décisions/historique académique.
5. **Rétention non inventée** : colonne `retention_a` créée mais laissée UNIQUEMENT
   NULL (clôture incluse) tant qu'aucune politique n'est approuvée.

### Règles confirmées (issues du cahier et de la mission)

- Donnée absente ≠ signal négatif : un étudiant sans présence n'est jamais « à
  risque » par défaut (test dédié) ; scan n'agit que sur présents-dans-sources.
- Aucune alerte ne modifie note, décision ou statut d'inscription (test snapshot).
- Idempotence : une seule alerte OUVERTE par (règle, étudiant, année) ; une alerte
  FERMÉE peut renaître au scan suivant (nouveau flux humain, trace complète).
- `excuse` (justifié) ne compte PAS comme absence non traitée (test dédié).

### Critères d'acceptation (matrice cahier → réalisation)

| Critère cahier | Réalisation | Test |
|---|---|---|
| Catalogue versionné avec approbation | statut + version + approuve_par/le | 4 tests catalogue |
| Règle sans justification = non approvable | garde 422 dans `regleApprouver` | test_approbation_exige_justification_ecrite |
| Alerte jamais décisionnelle | aucune écriture hors 3 tables du module | test_donnees_officielles_non_modifiees |
| Donnée absente ≠ signal | étudiants sans source jamais alertés | 3 tests (sans présence / excuse / sans notes) |
| Flux humain tracé | examiner/cloturer + motifs + 422 transitions | 3 tests flux |
| Clôture tracée | motif obligatoire, auteur, date | test_cloturer_exige_un_motif |
| Rétention non inventée | `retention_a` forcé NULL à la clôture | assert dans le même test |
| IDOR | alerte hors périmètre = 403, statut inchangé | test_admin_faculte_bloque_sur_alerte_hors_perimetre |

## LOOP-02 — Architecture

### Nouveau module (conventions du dépôt)

```
app/Modules/PreventionDecrochage/
├── Http/Controllers/PreventionDecrochageController.php  (10 actions)
├── Models/{RegleDecrochage,AlerteDecrochage,SuiviDecrochage}.php
├── Routes/web.php  (10 routes POST/GET)
└── Services/ServiceAlertes.php  (moteur de détection lecture-seule)
database/migrations/2026_10_24_130000_creer_tables_prevention_decrochage.php
```

- Enregistrement : `config/modules.php` (drapeau `MODULE_PREVENTION_DECROCHAGE`,
  défaut **false**) + `ModuleServiceProvider` (require routes, pattern Lot 14).
- Routes : `admin/prevention-decrochage/*` sous middleware
  `['auth','admin','secondaire.verrou','module:prevention-decrochage']` + `can:decrochage.*`
  ; mutations POST uniquement ; `throttle` sur toute écriture.
- Migrations : 3 tables ADDITIVES réversibles (down() drop), référence FK vers
  `regles_decrochage`/`alertes_decrochage`/`users`/`etudiants`/`annees_universitaires`
  uniquement — aucune table centrale modifiée.

### Contrats inter-modules (lecture confirmée)

- Import inter-module : `App\Modules\Attendance\Models\Presence` (précédent
  Sentinel/AssistantIa). `Note` est racine (`App\Models\Note`).
- Chaîne notes→périmètre : `notes.semestre_id → semestres.inscription_academique_id →
  inscriptions_academiques.programme_id → programmes.faculte_id` ; année filtrée par
  `inscriptions_academiques.annee_universitaire_id`. Présences : faculté via
  `etudiants.faculte_id`, année via plage `date_debut…date_fin` (AUCUN FK année sur
  presences — la fenêtre est explicite dans les preuves).

### Déterminisme et version

- Catalogue `version_regles = '1.0'` dans l'index ; chaque règle porte un champ
  `version` (entier, incrémentation applicative à la modification).
- Le scan est déterministe pour des données figées (aucune entrée temporelle ni
  aléatoire dans les requêtes).

## LOOP-03 — Implémentation réalisée

### Fichiers créés / modifiés

| Fichier | Contenu |
|---|---|
| `database/migrations/2026_10_24_130000_…decrochage.php` | 3 tables (`regles_decrochage` + `seuil`/`seuil_mesure`, `alertes_decrochage` (flux + preuve JSON + rétention), `suivis_decrochage`) |
| `app/Support/Permissions.php` | clés ADDITIVES : `decrochage.view`, `decrochage.regles`, `decrochage.traiter` (TOUTES + 4 rôles) ; aucune clé existante touchée |
| `config/modules.php` | drapeau module (défaut false) |
| `app/Providers/ModuleServiceProvider.php` | require des routes du module |
| `app/Modules/PreventionDecrochage/Services/ServiceAlertes.php` | `detecter()` : règles approuvées uniquement ; `scanPresences()` (statuts `absent`/`retard`, `excuse` jamais compté) ; `scanNotes()` (notes < `seuil_mesure`) ; dedup ouvert ; preuves bornées (≤ 10) |
| `app/Modules/PreventionDecrochage/Http/Controllers/PreventionDecrochageController.php` | reglesIndex/Creer/Approuver/Desactiver ; detecter ; alertesIndex ; examiner ; cloturer ; ajouterSuivi/suivisIndex ; `perimetre()` + `garantirPerimetreAlerte()` + `verifierPermission()` |
| `app/Modules/PreventionDecrochage/Routes/web.php` | 10 routes (5 POST catalogue/détection + 5 GET/POST flux) |
| `tests/Feature/PreventionDecrochageTest.php` | 26 tests, 110 assertions |

### Décisions d'implémentation vs LOOP-01/02

1. **Deux seuils distincts sur une règle** : `seuil` = seuil de DÉCLENCHEMENT de la
   valeur mesurée (nb d'absents, nb de notes sous seuil) ; `seuil_mesure` = seuil de la
   mesure sous-jacente (pour `notes` : compte `note < seuil_mesure`) ; obligatoire pour
   les règles `notes`, ignoré pour `presences`. Une règle `notes` sans `seuil_mesure`
   est explicitement ignorée par le scan (aucune valeur par défaut inventée).
2. **Déduplication ouverte** : clé (règle, étudiant, année — NULL correctement gérée).
   Une alerte clôturée n'empêche pas un signal nouveau : le risque peut réapparaître,
   et le flux humain le retrace (2e alerte distincte).
3. **Preuves datées** : chaque alerte embarque `source`, `fenetre` (plage ou
   « toutes-dates-enregistrees »), `elements` (≤ 10), et pour les notes
   `seuil_generation` (« comptage >= N notes sous seuil ») : le suivi reste vérifiable
   sans recréer la requête.
4. **Scan sans définition de cohorte** : le moteur ne compte jamais « X étudiants sans
   données » (dénominateur inventé) ; il liste ce qu'il a trouvé dans les sources et
   répète la règle d'or dans chaque réponse `detecter` (`perimetre.note`).

### Incidents corrigés pendant la boucle (traçabilité LOOP-03)

| # | Incident | Correction |
|---|---|---|
| 1 | Corruption de nom de méthode `clatureePar` dans `AlerteDecrochage` | renommé `clotureePar` (scan signature) |
| 2 | Charabia « iluminačně » + clé inventée « au_attribuee_cohorte » dans ServiceAlertes (1re version) | fichier réécrit propre ; scans cyrillique/CJK/suspects verts |
| 3 | `->get([...colonnes])` écrasant `selectRaw` (COUNT perdu) | `->get()` sans colonnes après `selectRaw` |
| 4 | **Bug de design** : `seuil` (seuil de note) comparé à la VALEUR (compteur) → jamais d'alerte si < 10 notes sous seuil | séparation `seuil` (déclenchement) / `seuil_mesure` (measure) — migration amendée AVANT tout déploiement (module non déployé : aucune donnée à migrer) |
| 5 | `where('annee_universitaire_id', $annee?->id)` avec NULL → `= NULL` ne matche jamais → re-scan sans année exigeait `doublons_ignores=0` mais dupliquait | `whereNull` explicite quand année NULL |
| 6 | Scaffold tests : UNIQUE (etudiant_id, annee_universitaire_id) sur inscriptions et UNIQUE (inscription_academique_id, numero) sur semestres violés par le scaffold initial | helpers `note()` réutilisant inscription/semestre existants (first-or-create) |
| 7 | Preuve de présence avec date formatée « 2024-10-09 00:00:00 » | format `Y-m-d` via cast date (`->format('Y-m-d')`) |
| 8 | `notes.coefficient` NOT NULL (scaffold notes) | helper note() fournit `coefficient` + `credits` |
| 9 | Scan admin_faculte avec `faculte_id` étrangère : le contrat Lot 14 (FacultePerimetre) REFUSE (403), mon test présumait « ignore silencieux » | test aligné sur le pattern du dépôt : 403 sur demande explicite hors périmètre, ancrage automatique sinon |

## LOOP-04 — Résultats réels des tests

Exécution : `php artisan test tests/Feature/PreventionDecrochageTest.php`
Résultat réel : **« passed, 26 tests, 26 passed, 110 assertions »** (runner).

| # | Thème | Preuve clé |
|---|---|---|
| 1 | catalogue : création consultateur refusée | 403 (decrochage.regles absent de `consultation`) |
| 2 | règle créée en brouillon ne scanne pas | `regles_executees = 0`, 0 alerte |
| 3 | approbation exige justification écrite | 422, statut reste brouillon |
| 4 | approbation tracée | approuvee + auteur + daté |
| 5 | re-approbation refusée | 422 |
| 6 | désactivation stoppe la génération | scan après désactivation : 0 exécutées, 0 alerte nouvelle |
| 7 | absents atteignant seuil → alerte, preuve datée, tri desc | éléments `['2024-10-09','2024-10-02']` |
| 8 | `excuse` jamais compté | 2 excuses ≠ alerte |
| 9 | étudiant sans présence ≠ signal | Une SEULE alerte ; l'autre étudiant absent des sources jamais alerté |
| 10 | mesure `retards` compte les retard | valeur 2.0 (absent non compté dans la même règle) |
| 11 | plage d'année borne les presences | scan 2024-2025 → 1 ; scan 2025-2026 → +1 (triplet distinct) |
| 12 | notes sous seuil → alerte, chaîne confirmée | preuve note_id + année portée par le périmètre |
| 13 | note ÉGALE au seuil de mesure ≠ signal (strict) | 0 alerte |
| 14 | scan notes borné par l'année d'inscription | années suivantes → 0 ; année de la note → 1 |
| 15 | étudiant sans notes ≠ signal | `regles_sans_donnees = 1` explicite |
| 16 | re-scan sans doublon ; alerte FERMÉE peut renaître | scan 2 → `doublons_ignores=1` ; après clôture, scan 3 → nouvelle alerte (2 total) |
| 17 | examiner trace le flux | examinee + auteur + date ; 2e examen = 422 |
| 18 | clôture exige motif ; rétention jamais inventée | 422 sans motif ; cloturee + `retention_a` NULL |
| 19 | suivi = table module uniquement | 201 + GET 1 suivi |
| 20 | IDOR hors périmètre | 403 sur examiner/cloturer/suivis par admin faculté B ; statut inchangé |
| 21 | scan/index ancrés à SA faculté | faculté B demandée explicitement = 403 ; scan sans paramètre → alertes de la seule faculté A |
| 22 | filtre statut réduit l'index | examinee → 1 ; generee → 0 |
| 23 | agent_scolarite : voir OK, scan interdit | 403 sur POST detecter, index 200 |
| 24 | module désactivé → 404 | GET et POST |
| 25 | données officielles immobiles | counts + valeur de note + verrous identiques avant/après flux complet |
| 26 | performance synthétique | 120 étudiants × 240 présences, scan HTTP < 3 s |

## LOOP-05 — Sécurité et périmètre (récap seuils appliqués)

- **Permissions** : 3 clés additives `decrochage.*` au registre (aucune régression des
  bundles existants — éditions ancrées, 4 rôles étendus), routage `can:` + double contrôle
  contrôleur (`verifierPermission()`).
- **Périmètre facultaire** : jamais le navigateur — `perimetre()` (scan/index) et
  `garantirPerimetreAlerte()` (flux), sur les primitives `User::peutAccederFaculte()`
  / `faculteRestreinteId()` ; l'IDOR est testé sur la RECHERCHE de l'alerte par la
  faculté de SON étudiant (pas seulement le paramètre).
- **Validation entrée** : raison ≥ 10 chars avant approbation ; motif de clôture
  obligatoire ; `mesure` contraignant par type ; `seuil_mesure` requis si `notes` ;
  bornes numériques 0..max.
- **Module gate** : middleware `module:prevention-decrochage` → 404 si désactivé ;
  activation uniquement par `MODULE_PREVENTION_DECROCHAGE` (défaut false — production
  inchangée sans action explicite).
- **Aucune écriture officielle** : le moteur ne génère que des SELECT agrégés ; le
  flux n'écrit que `alertes_decrochage`/`suivis_decrochage`/`regles_decrochage` (test 25).

## LOOP-06 — UX (verdict partiel : différée)

- Toutes les réponses sont **JSON uniquement** ; aucune vue Blade
  (`resources/views/modules/prevention-decrochage/…`) ; aucune entrée de navigation
  ajoutée au registre. La passe UI (écrans agent, bouton de scan, dossier de suivi)
  reste **différée explicitement** (réserve LOOP-10).

## LOOP-07 — Coexistence et non-écriture (récap)

- Module `AssistantIa` et ses migrations : **intacts**.
- Sources lues : `presences` (Attendance), `notes`/`semestres`/`inscriptions_academiques`/
  `programmes`/`etudiants` — **AUCUNE écriture** sur ces tables (test 25).
- Aucune colonne ajoutée aux tables centrales ; aucune écriture dans
  `PreferenceNotificationService` (service partagé).
- Base MySQL `inscription_etudiants` : **jamais touchée** ; développement/test sur
  SQLite `:memory:` + RefreshDatabase.

## LOOP-08 — Performance et plafonds

- Plafonds moteur : `PLAFOND_CANDIDATS = 5000` (limite SQL par règle) ;
  `MAX_PREUVES = 10` éléments de preuve par alerte.
- Suite complète du lot : 26 tests en ~1.5 s (SQLite synthétique) ; test dédié
  120 étudiants / 240 présences < 3 s.
- Coût connu documenté : la preuve de chaque candidat requiert 1 requête par
  élément (N+1 borné par le plafond candidats et par alertes réellement sous seuil)
  — vu comme réserve volumétrique sur volumes réels (LOOP-00 : presences réelles = 0).

## LOOP-09 — Non-régression et outillage

- `vendor/bin/pint --dirty` → **passed** (1 fichier rétabli : `array_indentation`
  tests) ; re-run post-pint → 26/26.
- Scan python3 (cyrillique 0x0400-0x04FF, CJK >0x4DFF, suspects, signatures) sur les
  11 fichiers touchés → **0 corruption**.
- Suite IA non-régression
  `--filter="AiAssistantFondations|AiAssistantGateway|AiAssistantSecurite|AiAssistantChat|AiAssistantRag|AiAssistantOutils|AiAssistantOllama"`
  → **111 tests, 356 assertions, passed** (baseline identique).
- `php artisan route:list --path=prevention-decrochage` → **10 routes** enregistrées
  (interdiction GET sur route POST respectée dans les tests : 405s jamais provoqués).

## LOOP-10 — Verdict

**PASS_WITH_RESERVATIONS**

Tous les critères du cahier sont réalisés et prouvés (26/26, 110 assertions), la
règle d'or est testée plusieurs fois, la suite IA reste à sa baseline, pint et les
scans anti-corruption sont propres, et aucune donnée officielle n'est écrite. Mais
des réserves explicitement NON couvertes subsistent :

| # | Réserve | Nature |
|---|---|---|
| 1 | UI différée : sémantique livrée en JSON, aucune vue agent ni navigation | Passe UI future |
| 2 | Notifications automatiques NON implémentées : aucune catégorie « suivi » dans `PreferenceNotificationService::CATEGORIES` ; le service partagé n'est pas modifiable (coexistence). Aucune notification étudiant n'est envoyée du flux | Réserve fonctionnelle |
| 3 | Métrique rendez-vous « no-show » ABSENTE : aucun statut de manquement confirmé dans `online_requests.form_data->date_creneau` | Source manquante (jamais inventée) |
| 4 | Politique de rétention non définie : `retention_a` reste NULL (y compris à la clôture) | Politique institutionnelle manquante |
| 5 | Fenêtre temporelle des présences : « toutes dates enregistrées » quand aucune année n'est demandée (aucune fenêtre glissante confirmée institutionnellement) | Réserve documentée |
| 6 | Cohorte non définie : le scan n'agit que sur les étudiants présents dans les sources ; « étudiants sans données » n'est JAMAIS évalué numériquement | Règle d'or (choix, pas un défaut) |
| 7 | Performance prouvée sur SQLite synthétique (presences réelles = 0) — pas sur les volumes MySQL réels | Réserve de portée |
| 8 | Preuve par candidat = 1 requête (N+1 borné par MAX_PREUVES et plafond candidats) | Réserve volumétrique |

Le module reste un **accompagnement humain** : les alertes appellent au suivi humain —
jamais un retrait de note, jamais une décision de statut, jamais une sanction
automatique ; chaque réponse `detecter` répète la règle d'or.
