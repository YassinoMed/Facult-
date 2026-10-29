# LOOP 16 — Assurance qualité et accréditation

> Cahier : catalogue versionné référentiels/critères ; chaîne critère relié à
> preuve, constat et plan d'action ; workflow d'approbation des rapports ; export
> sous permissions. Interdits : inventer des critères d'accréditation ; présenter
> un rapport incomplet comme conforme.

## LOOP-00 — Audit préalable (preuves exécutées)

### Ce qui existe déjà et qu'il faut réutiliser (pas dupliquer)

| Source | Mécanisme confirmé | Usage Lot 16 |
|---|---|---|
| Socle anomalies (`AnomalieQualite`, `QualitePolicy`, `AgregateurQualite`) | GRAVITES (info→critique), STATUTS avec TRANSITIONS serveur, 4 sources détectrices (population, referentiel, pedagogie, verification_dossier), `synchroniser()` | type de preuve `anomalie` — CONSULTÉ en lecture, jamais modifié |
| Coffre `StudentDossiers` | `student_documents` (sha256, status validated/rejected, versions via `student_document_versions`, `GeneratedDocument` versionné) | type de preuve `document` — liens vers artefacts RÉELS |
| Journaux `HistoriqueAcademique` | journal d'action horodaté (etudiant, utilisateur, action, entite, valeurs) | type de preuve `journal` |
| Permissions | `qualite.view` / `qualite.manage` DÉJÀ distribuées (admin_scolarite view+manage ; agent_scolarite/consultation/admin_faculte view) ; `export.create` (pas à consultation) | réutilisation TOTALE — aucune clé nouvelle |

### Constats réels (MySQL `inscription_etudiants`, sondes SELECT uniquement)

| Sonde | Résultat | Conséquence |
|---|---|---|
| `anomalies_qualite` COUNT | **0 lignes** | l'agrégateur n'a pas encore été synchronisé sur cette base ; l'axe preuve-anomalie restera vide sur données réelles (réserve LOOP-10) |
| `historique_academique` COUNT | **97 lignes** | journal réel disponible pour preuves typées |
| Tables accréditation | **aucune existante** | l'écart du cahier est entièrement à construire |
| `QualiteController` routes | `admin/qualite` (index, assigner, transiter) | frontière anti-fusion : le socle reste intact, routes Lot 16 sous `admin/assurance-qualite/*` |

### Ce qui manque (réserver explicitement, ne jamais présenter comme disponible)

1. Référentiels/critères d'accréditation institutionnels — AUCUNE saisie réelle
   disponible : le catalogue livré est VIDE par défaut (interdit d'inventer).
2. Format d'export officiel autre que JSON (PDF/CSV non confirmés).
3. Politique de délai des plans d'action (`echeance` reste optionnelle, jamais
   imposée par le système).

### AssistantIa et règles de coexistence

Module `AssistantIa` et ses migrations : intacts (aucun fichier touché).
Aucune colonne ajoutée aux tables centrales ; aucune route dans `routes/web.php`.

## LOOP-01 — Contrat métier

### Acteurs et permissions (réutilisées, aucune inventée)

| Acteur | Clés | Cas d'usage |
|---|---|---|
| `admin_scolarite` / `super_admin` | qualite.view + qualite.manage | saisie catalogue, figage, constats, preuves, plans, soumission, approbation (≠ soumetteur), export |
| `agent_scolarite` | qualite.view | lecture des rapports |
| `admin_faculte` | qualite.view | lecture des rapports de SA faculté (re-scopés serveur) |
| `consultation` | qualite.view | lecture — SANS export.create |

### Exigences du cahier → réalisation

1. **Catalogue versionné** : `referentiels_accreditation` (organisme SAISI) →
   `versions_referentiel_accreditation` (numero incrémenté, `brouillon` →
   `approuvee` avec motif de figage ≥ 10 caractères → `archivee`) →
   `criteres_accreditation` (code unique par version, portée globale ou faculté).
   Version approuvée = FIGÉE : plus aucun ajout de critère (422).
2. **Chaîne critère → preuve → constat → plan d'action** : constat par
   (critère, périmètre) en upsert tracé (évaluateur + date) ; preuves TYPÉES
   (document/anomalie/journal) avec existence RÉELLE vérifiée à l'ajout
   (lien mort = 422) et idempotentes (firstOrCreate) ; plans d'action avec
   responsable/échéance optionnels, clôture tracée, re-clôture refusée.
3. **Workflow d'approbation des rapports** : soumission EXIGE un constat
   EXPLICITE pour chaque critère du périmètre (manquants listés en 422) ;
   totaux FIGÉS (conformes/partiels/non conformes/non évalués) — `non_evalue`
   est un constat EXPLICITE compté comme TEL, jamais comme conforme ;
   approbateur ≠ soumetteur (les deux sens : approbation ET rejet) ; rejet
   motivé ≥ 10 caractères ; rapport approuvé IMMUABLE ; rejeté re-soumissible.
4. **Export sous permissions** : double barrière `can:qualite.view` (route) +
   `can:export.create` (contrôleur) — JSON attaché avec totaux figés.

## LOOP-02 — Architecture

### Nouveau module (conventions du dépôt)

```
app/Modules/AssuranceQualite/
├── Http/Controllers/AssuranceQualiteController.php  (17 actions JSON)
├── Models/{ReferentielAccreditation,VersionReferentielAccreditation,
│           CritereAccreditation,ConstatAccreditation,
│           PreuveConstatAccreditation,PlanActionAccreditation,RapportAccreditation}.php
├── Routes/web.php  (17 routes POST/GET)
└── Services/ServiceAccreditation.php  (périmètre, décompte, complétude, figage, preuves)
database/migrations/2026_10_24_140000_creer_tables_assurance_qualite.php
tests/Feature/AssuranceQualiteTest.php  (24 tests, 158 assertions)
```

- Enregistrement : `config/modules.php` (drapeau `MODULE_ASSURANCE_QUALITE`,
  défaut **false**) + `ModuleServiceProvider` (pattern Lots 14/15).
- Routes : `admin/assurance-qualite/*` sous middleware
  `['auth','admin','secondaire.verrou','module:assurance-qualite']` + `can:*` ;
  mutations POST ; `throttle` sur toute écriture.
- Clé UNIQUE portable avec NULL : colonne textuelle `perimetre`
  (`'global'` | `'faculte:{id}'`) sur constats ET rapports — SQLite traite les
  NULL comme distincts, une contrainte (colonne nullable) serait contournée.

## LOOP-03 — Implémentation réalisée

### Décisions d'implémentation vs LOOP-01/02

1. **Périmètre des critères d'un rapport** : rapport global (faculte_id NULL)
   = TOUS les critères de la version ; rapport de faculté = critères globaux +
   critères de cette faculté. Un critère à portée faculté F n'est évaluable QUE
   sur F (cohérence refusée en 422 sinon).
2. **Évaluable après figage** : brouillon ET approuvée sont évaluables (on
   évalue une version figée — c'est le sens même d'un référentiel approuvé) ;
   seule une version ARCHIVÉE ne l'est plus.
3. **Preuves = LIENS, jamais des copies binaires** : `cible_libelle` est une
   copie datée du libellé (lisible si la source disparaît), les sources restent
   intactes (test dédié).
4. **Séparation des pouvoirs par identité** : `soumis_par ≠ approbateur`
   (contrôle par id utilisateur, aucune politique de rôle institutionnelle
   inventée — qualite.manage suffit aux deux rôles).

### Incidents corrigés pendant la boucle (traçabilité LOOP-03)

| # | Incident | Correction |
|---|---|---|
| 1 | Méthode Eloquent INVENTÉE `firstNew()` (critères + preuves) | `create()` avec garde d'unicité 422 (code critère) ; `firstOrCreate` idempotent (preuves) |
| 2 | Parse error : `]` de fermeture de tableau avalé dans une chaîne (message d'erreur constat) | chaîne extraite dans `$message` séparé |
| 3 | Collision helper de test `get()` avec `TestCase::get()` (fatal « must be public ») | helpers renommés `appelPost`/`appelGet` |
| 4 | `Undefined array key "faculte_id"` (clé absente du payload, `?? null` manquant) dans `constatEnregistrer` ET `rapportSoumettre` (500) | résolution unique `$faculteId = $donnees['faculte_id'] ?? null` en tête des deux actions |
| 5 | FK journal : `Etudiant::first()?->id ?? 1` produisait un id fantôme | helper `journal()` créant un étudiant synthétique si aucun n'existe |
| 6 | Condition de figage mal composée (`estFigee() && statut==='archivee'` = seulement archivee, accidentellement correct mais illisible) | explicite `statut === 'archivee'` |
| 7 | Relation parasite `faculte()` sur `VersionReferentielAccreditation` (colonne inexistante) | relation retirée |

## LOOP-04 — Résultats réels des tests

Exécution : `php artisan test tests/Feature/AssuranceQualiteTest.php`
Résultat réel : **« passed, 24 tests, 24 passed, 158 assertions »** (runner).

| # | Thème | Preuve clé |
|---|---|---|
| 1 | catalogue vide par défaut (aucun critère inventé) | `referentiels == []`, Critere count = 0 |
| 2 | création refusée sans qualite.manage | consultateur → 403 |
| 3 | numéros de version incrémentés | 1 puis 2 |
| 4 | code critère dupliqué refusé | 422, jamais d'écrasement |
| 5 | version approuvée FIGÉE | ajout critère → 422 ; approbation tracée (auteur + date) |
| 6 | motif de figage ≥ 10 exigé | absent/court → 422, statut inchangé |
| 7 | re-approbation refusée ; archivage exigé approuvée | 422 / OK |
| 8 | version archivée plus évaluable | constat → 422 |
| 9 | constat upsert (critère, périmètre) | 2 appels → 1 ligne, évaluateur à jour |
| 10 | critère à portée faculté : cohérence | autre faculté → 422 ; SA faculté → OK |
| 11 | preuve document réelle + cible inexistante | 201 + libellé daté ; id 999999 → 422 |
| 12 | preuves anomalie + journal réelles | 201, libellés contiennent les ids réels |
| 13 | preuve idempotente | même lien → même id, count = 1 |
| 14 | plan d'action clôturé tracé ; re-clôture refusée | termine + auteur + date ; 422 |
| 15 | soumission exigée COMPLÈTE | 422 listant « C2 », 0 rapport créé |
| 16 | totaux figés exacts (1/1/1/1 sur 4) | `non_evalue` compté non évalué, JAMAIS conforme |
| 17 | version brouillon non rapportable | 422 |
| 18 | approbateur ≠ soumetteur (approbation ET rejet) | même user → 422 deux fois, statut inchangé |
| 19 | rejet motivé, re-soumission, approbation définitive, immuabilité | cycle complet tracé ; approuvé → re-soumission 422 |
| 20 | export double permission | consultateur (sans export.create) → 403 ; admin → attachment + totaux |
| 21 | admin_faculte : lecture seule + périmètre forcé | POST constats → 403 ; index = SA faculté uniquement |
| 22 | module désactivé → 404 | GET et POST |
| 23 | sources de preuves INTACTES | counts + status document inchangés après liaisons |
| 24 | performance : 50 critères + 50 constats + soumission | < 3 s (constaté ~2 s, middleware inclus) |

## LOOP-05 — Sécurité et périmètre (récap seuils appliqués)

- **Permissions 100% RÉUTILISÉES** : `qualite.view` / `qualite.manage` (routage
  `can:` + double contrôle contrôleur) et `export.create` pour l'export —
  AUCUNE clé ajoutée au registre ce lot (point fort).
- **Périmètre facultaire** : `peutAccederFaculte` sur toute écriture à faculté
  donnée (constats, rapports) ; lecture re-scopée par `faculteRestreinteId`
  (rapports de SA faculté seulement pour un agent à faculté restreinte).
- **Validation entrée** : organisme/nom/code requis ; motif de figage et motif
  de rejet ≥ 10 caractères ; évaluations enum stricte ; types de preuve enum ;
  `exists:` sur facultés/versions/responsables.
- **Module gate** : middleware `module:assurance-qualite` → 404 si désactivé ;
  activation par `MODULE_ASSURANCE_QUALITE` (défaut false).
- **Aucune écriture hors module** : 7 tables du module uniquement ; les
  artefacts cités en preuve sont des LIENS (test 23).

## LOOP-06 — UX (verdict partiel : différée)

- Réponses **JSON uniquement** ; aucune vue Blade, aucune navigation. La passe
  UI (écrans référentiel, saisie de critères, dossier de constat, écran
  d'approbation) reste **différée explicitement** (réserve LOOP-10).

## LOOP-07 — Coexistence et non-écriture (récap)

- Socle qualité existant (`QualiteController`, `AgregateurQualite`,
  `AnomalieQualite`, `QualitePolicy`) : **intacts** — consultés en lecture via
  le type de preuve `anomalie`.
- Module `Referentiel` (LMD) : **distinct** — tables nommées
  `referentiels_accreditation` / `versions_referentiel_accreditation` /
  `criteres_accreditation`, aucune table LMD touchée.
- Module `StudentDossiers` : **intact** — `student_documents` lu uniquement.
- Module `AssistantIa` : **intact**.
- Base MySQL `inscription_etudiants` : **jamais touchée** ; SQLite `:memory:`
  + RefreshDatabase pour tout le lot.

## LOOP-08 — Performance et plafonds

- Aucun plafond explicite de lignes (les volumes réels du domaine : 0
  anomalies, 97 journaux) ; les listes sont bornées (`limit(200)` index).
- Test dédié : 50 critères + 50 constats enregistrés + soumission complète
  avec middleware HTTP < 3 s (constaté ~2 s).
- Coût connu : `decompte()` exécute 2 requêtes de critères (liste + keyBy) par
  appel — borne acceptable à l'échelle du domaine, documentée ici.

## LOOP-09 — Non-régression et outillage

- `vendor/bin/pint --dirty` → **passed** (aucun fichier à corriger ce lot,
  AssistantIa non retouché).
- Scan python3 (cyrillique/CJK/suspects) sur les 14 fichiers du lot → **VERT**.
- Suite IA non-régression → **111 tests, 356 assertions, passed** (baseline
  identique).
- Suite Lot 15 re-exécutée après ce lot → **26/26, 110 assertions** (aucune
  régression croisée).
- `php artisan route:list --path=assurance-qualite` → **17 routes**.

## LOOP-10 — Verdict

**PASS_WITH_RESERVATIONS**

Tous les critères du cahier sont réalisés et prouvés (24/24, 158 assertions) :
catalogue versionné figé, chaîne complète constat→preuve→plan d'action,
rapport complet exigé avec totaux figés, approbateur ≠ soumetteur, export sous
double permission — avec zéro permission inventée et aucun fichier existant
modifié. Réserves explicitement NON couvertes :

| # | Réserve | Nature |
|---|---|---|
| 1 | UI différée : sémantique livrée en JSON, aucun écran agent | Passe UI future |
| 2 | Catalogue VIDE par défaut : les référentiels/critères réels restent à SAISIR par l'institution (interdit d'inventer) | Saisie institutionnelle requise |
| 3 | `anomalies_qualite` réelles = 0 : l'axe preuve-anomalie n'a pas encore de cibles réelles à lier (l'agrégateur n'a pas été synchronisé sur cette base) | Réserve de données |
| 4 | Export JSON uniquement : PDF/CSV non confirmés institutionnellement | Réserve de format |
| 5 | Rapports globaux (périmètre `global`) invisibles aux `admin_faculte` (lecture re-scopée à leur faculté) — choix prudent documenté | Réserve de portée |
| 6 | Après rejet, la re-soumission RECALCULE les totaux : le premier jeu figé n'est pas versionné par soumission (trace du rejet conservée, pas d'historique des totaux intermédiaires) | Réserve de traçabilité fine |
| 7 | Pertinence d'une preuve vis-à-vis d'un critère : contrôle HUMAIN (le système vérifie l'existence de la cible, jamais sa pertinence) | Choix assumé |
| 8 | Performance prouvée sur SQLite synthétique, pas sur volumes MySQL réels | Réserve de portée |

Le module reste un **appui à l'auto-évaluation** : aucun critère n'est inventé,
aucun rapport incomplet n'est présenté comme conforme, chaque évaluation reste
humaine et traçable.
