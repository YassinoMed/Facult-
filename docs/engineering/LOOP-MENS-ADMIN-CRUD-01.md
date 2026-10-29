# LOOP-MENS-ADMIN-CRUD-01 — Manipulation directe des références MENS (`/admin/mens`)

> Boucle : audit (LAN) → conception → implémentation → tests → non-régression → rapport.
> Méthode : audit white-box de l'existant (contrôleur, modèle, définition d'import,
> service d'identification, permissions, vues, tests) puis développement incrémental
> aligné sur les patterns du projet (`SalleController`/`GroupeController`).
>
> Contraintes respectées : aucun `migrate:fresh`/`db:wipe`, aucune donnée existante
> supprimée, aucune règle métier d'inscription modifiée, import MENS idempotent
> jamais contourné ni altéré, identification fail-closed intacte, aucun
> commit/push/déploiement.

Verdict : **PASS_WITH_RESERVATIONS** — voir §6 (réserves) et §7 (verdict).

---

## 1. Objectif & synthèse

Rendre la page `/admin/mens` exploitable **sans dépendre uniquement de l'import** :
ajouter / consulter / modifier / supprimer une référence MENS, avec les mêmes
permissions (`data.import`), le même périmètre facultaire que l'index, les mêmes
règles que l'import (CIN 8 chiffres, clé `[CIN normalisée, année]`), une
suppression protégée par pré-contrôle des dépendances et un audit sans PII.

| # | Exigence | Implémentation | Preuve | Statut |
|---|---|---|---|---|
| 1 | Ajouter une référence (formulaire, validation) | `create`/`store` + `admin/mens/form.blade.php` | Tests 1-3 (§4) | ✅ |
| 2 | Consulter (« afficher les détails ») | `show` + `admin/mens/show.blade.php` | Test 5 | ✅ |
| 3 | Modifier (correction contrôlée, unicité préservée y compris en modification) | `edit`/`update` (unicité auto-exclue) | Test 4 | ✅ |
| 4 | Supprimer (action explicite + confirmation + retour utilisateur) | `destroy` + modale Alpine + pré-contrôles | Tests 6-8 | ✅ |
| 5 | Rechercher/filtrer : CIN, année, statut | Filtres existants conservés (`q` CIN/nom/prénom, année, appariement) | Test 9 + suite baseline | ✅ |
| 6 | Permissions réutilisées + périmètre + requêtes forgées | `can:data.import` + `verifierPerimetre`/`verifierRattachement` | Tests 10-11 | ✅ |
| 7 | Journalisation d'audit sans PII | Actions `mens.*` + CIN masquée + champs modifiés | Tests 1, 4, 6 | ✅ |
| 8 | Non-régression de l'import idempotent | `MensImportDefinition` **non modifié** + test dédié | Test 13 ; suite MENS 121/121 | ✅ |
| 9 | Non-régression identification & parcours public | Test d'intégration service + endpoint public | Test 12 | ✅ |
| 10 | Atomicité multi-écritures | `DB::transaction` (écriture + audit) | Tests 1, 4, 6 | ✅ |

**Statut « statut »** : le schéma `mens_references` **ne contient aucune colonne
`statut`** ; le seul état réel est l'appariement local, déjà filtrable
(`appariement` = appariés / non appariés). Décision documentée en §6 — aucun
filtre fictif ajouté, aucun enregistrement dénaturé.

---

## 2. Fichiers & routes

### 2.1 Fichiers modifiés / créés

| Fichier | Nature | Contenu |
|---|---|---|
| `app/Http/Controllers/Admin/MensReferenceController.php` | modifié | `create/store/show/edit/update/destroy` + helpers privés (`valider`, `verifierPerimetre`, `verifierRattachement`, `donneesCanoniques`, `champsModifies`, `blocagesSuppression`, `aTracesCreationDossier`, `etudiantsRapproches`, `masquerCin`) ; `creerDossier` refactoré pour partager le comparateur d'appariement (comportement identique) |
| `routes/web.php` | modifié | 6 routes administratives (voir 2.2), déclarées sous `can:data.import`, `mens/creer` avant `mens/{reference}` (ordre critique) |
| `app/Models/HistoriqueAcademique.php` | modifié | Constantes `ACTION_MENS_CREATION/MODIFICATION/SUPPRESSION` + libellés humains (même pattern qu'en `enseignant.*`) |
| `resources/views/admin/mens/index.blade.php` | modifié | Bouton « Ajouter une référence », lien « Détails » par ligne, textes mis à jour (plus de « lecture seule ») |
| `resources/views/admin/mens/form.blade.php` | **créé** | Formulaire ajout/correction (composants `x-input`/`x-select`/`x-btn`/`x-alert` maison) |
| `resources/views/admin/mens/show.blade.php` | **créé** | Fiche détail : identité, rattachement, provenance, dossiers rapprochés, traçabilité, suppression protégée (modale) |
| `tests/Feature/MensCrudTest.php` | **créé** | 13 tests dédiés (§4) |

### 2.2 Routes (groupe admin existant : `auth`, `admin`, `mfa.obligatoire`, `secondaire.verrou`)

| Méthode | URI | Nom | Middlewares |
|---|---|---|---|
| GET | `admin/mens` | `admin.mens.index` | `can:data.import` (existant) |
| GET | `admin/mens/creer` | `admin.mens.create` | `can:data.import` |
| POST | `admin/mens` | `admin.mens.store` | `can:data.import`, `throttle:20,1` |
| GET | `admin/mens/{reference}` | `admin.mens.show` | `can:data.import` |
| GET | `admin/mens/{reference}/modifier` | `admin.mens.edit` | `can:data.import` |
| PUT | `admin/mens/{reference}` | `admin.mens.update` | `can:data.import`, `throttle:20,1` |
| DELETE | `admin/mens/{reference}` | `admin.mens.destroy` | `can:data.import`, `throttle:10,1` |
| POST | `admin/mens/{reference}/creer-dossier` | `admin.mens.creer-dossier` | `can:data.import`, `throttle:10,1` (existante, **inchangée**) |

`php artisan route:list --name=admin.mens` → 8 routes, aucune collision de nom.

---

## 3. Détails d'implémentation & décisions

### 3.1 Validation — mêmes règles que l'import, aucune voie parallèle

- CIN : `required|string|max:30` + `NormalisationService::REGLE_CIN`
  (exactement 8 chiffres ASCII, **règle unique** déjà appliquée par l'import
  `MensImportDefinition::regles` et le parcours public) — ni conversion
  silencieuse, ni tolérance au format local.
- Champs : `nom`/`prenom` requis (100), `nom_ar`/`prenom_ar` (100),
  `date_naissance` nullable date, `sexe` ∈ `M,F`, `nationalite` (50),
  `matricule_national` (30), `etablissement` (150), `annee` requis (20),
  `programme_code` (30), `niveau` ∈ L1…D3.
- **Unicité `[cin_normalise, annee]`** vérifiée côté serveur en s'excluant
  elle-même en modification (callback `after`, erreur portée sur `cin`) ;
  en cas de course, la contrainte BD `uniq_mens_cin_annee` lève
  `UniqueConstraintViolationException`, rattrapée → message utilisateur propre
  (`withErrors`), jamais de 500.

### 3.2 Périmètre facultaire — « lisible ⇔ agissable »

- Règle identique à l'index : une ligne est dans le périmètre d'un
  `admin_faculte` si la CIN est appariée à un étudiant local de SA faculté OU
  si `programme_code` appartient à un programme de SA faculté.
- `show`/`edit`/`update`/`destroy` hors périmètre → **404** (l'existence n'est
  jamais révélée — pattern maison `SalleController`).
- Création : un compte restreint ne peut créer qu'une ligne **rattachable**
  (sinon 404, rien créé).
- Modification : le périmètre est re-vérifié sur **l'état cible** (miroir du
  contrôle « faculté destination » de `GroupeController`) — une correction ne
  peut donc pas exfiltrer une ligne hors de son périmètre. État inchangé en cas
  de refus (testé).

### 3.3 Suppression protégée — pré-contrôle des dépendances

Suppression **refusée** (redirect vers la fiche + `withErrors(['reference' => …])`,
pattern maison des blocages) si :

1. **Dossier(s) local(aux) rapproché(s)** : comparateur strictement identique à
   `creerDossier` (CIN normalisée, CIN d'origine, forme espacée + filtre
   applicatif sur la normalisation exacte) — l'identification et le
   préremplissage en dépendent ;
2. **Traces historiques** : entrée `inscription.dossier_cree_mens` du journal
   central dont le payload `mens_id` référence la ligne — filtrage applicatif
   exact sur le JSON décodé (indépendant du SGBD, insensible au formatage) ;
   la traçabilité reste résoluble.

Sinon : suppression explicite (modale de confirmation dédiée) + journal
`mens.supprime` dans une transaction. Contrôle négatif testé : une trace visant
une **autre** référence ne bloque pas.

### 3.4 Audit sans PII (règle projet)

- `AuditService::tracerReferentiel` (journal central existant — aucune 2ᵉ table) ;
- payloads : `annee`, `cin_masquee` (`12345678` → `12***678`), `champs_modifies`
  (noms de champs uniquement), `saisie: manuelle` ; **jamais** CIN complète,
  nom, prénom ni date de naissance — vérifié par assertions string sur le
  journal (`assertStringNotContainsString('55667788'|'Nouvelle', payload)`).
- Actions et libellés ajoutés à `HistoriqueAcademique` (`mens.cree`,
  `mens.modifie`, `mens.supprime`) — mêmes conventions que `enseignant.*`.

### 3.5 Provenance & empreinte (cohérence import)

- `imported_by`/`imported_at` : **réservés à l'import** — une saisie manuelle
  les laisse nuls (la provenance d'une correction est l'audit) ; une correction
  d'une ligne importée ne les efface **pas** (provenance conservée).
- `source_hash` (NOT NULL) : recalculé à chaque écriture via
  `MensReference::empreinte` — même fonction que l'import — sur le contenu de
  données (les colonnes de provenance d'import sont exclues : une saisie
  manuelle n'en a pas).
- Aucune modification : l'`update` sans changement réel ne réécrit rien et ne
  journalise rien (`info` « la référence est inchangée »).

### 3.6 UI (composants et thème existants)

- Index : bouton primaire « Ajouter une référence » ; lien « Détails » par
  ligne ; textes corrigés (la page n'est plus « lecture seule ») ; état vide mis
  à jour.
- Formulaire ajout/correction : composants `x-page-header/x-input/x-select/
  x-btn/x-alert` ; contraintes client indicatives (`pattern`, `maxlength`) —
  la validation serveur reste seule garante.
- Fiche détail : cartes identité / rattachement / provenance & écriture /
  dossiers rapprochés (liens vers les fiches étudiants **dans le périmètre du
  demandeur uniquement**) / traçabilité (journal) ; modale de confirmation de
  suppression ; blocage expliqué en amont (encart ambre) et en retour de POST.

### 3.7 Refactor minimal de `creerDossier`

Le comparateur d'appariement (lignes 127-132 d'origine) est extrait dans
`etudiantsRapproches()` et **réutilisé** par `creerDossier` (aucune duplication,
comportement identique — couvert par `MensDossierCreationTest` et
`MensReferenceTest` inchangés, 121/121 ✅).

---

## 4. Tests — résultats exacts

### 4.1 Nouvelle suite `tests/Feature/MensCrudTest.php` (13 tests, 135 assertions)

| # | Test | Ce qui est prouvé |
|---|---|---|
| 1 | `test_ajout_valide_avec_journal_sans_pii` | création valide, redirect fiche, `source_hash`, provenance nulle, journal `mens.cree` sans PII (CIN masquée `55***788`, ni nom ni date) |
| 2 | `test_ajout_champs_invalides_refuse` | CIN non-8-chiffres (`ABC123`, `1234 5678`), champs requis vides, date impossible, sexe invalide → erreurs, 0 ligne créée |
| 3 | `test_doublon_cin_annee_refuse` | doublon CIN+année refusé ; même CIN autre année acceptée (clé composite) |
| 4 | `test_correction_conserve_unicite_et_journalise` | collision refusée / état inchangé ; clé conservée (auto-exclusion) acceptée + journal `champs_modifies:["nom"]` ; sans changement → `info` sans écriture ; changement d'année (clé) contrôlé |
| 5 | `test_consultation_details` | fiche détail (données, lien édition), formulaires ajout et correction rendus |
| 6 | `test_suppression_autorisee_et_journalisee` | suppression sans dépendance → redirect index + journal `mens.supprime` (payload masqué `66***433`) |
| 7 | `test_suppression_bloquee_par_dossier_local` | dossier local rapproché → refus + erreur `reference` + ligne conservée + **aucun** journal de suppression |
| 8 | `test_suppression_bloquee_par_trace_historique` | trace `inscription.dossier_cree_mens` (`mens_id`) → refus ; contrôle négatif : trace visant une autre référence → autorisé |
| 9 | `test_recherche_filtres_pagination` | 30 lignes : pagination 25/page (page 2 = Rang26-30), recherche CIN (`q`), filtre année (état vide explicite), filtre appariement (statut calculé réel) |
| 10 | `test_non_autorise_403` | `agent_scolarite` → 403 sur create/store/show/update/destroy ; invité → redirect connexion scolarité ; aucune écriture |
| 11 | `test_perimetre_faculte_restreint` | requêtes forgées hors périmètre (show/edit/update/delete/creation) → 404, état inchangé ; créations légitimes (programme de la faculté, dossier local apparié) acceptées ; correction sortant du périmètre → 404 |
| 12 | `test_identification_et_parcours_public_apres_crud` | après CRUD : service d'identification `MENS_ET_LOCAL` + divergence `nom` reflétant la valeur corrigée ; endpoint public `POST /inscription/identifier` (invité) : `EXISTING_STUDENT` → `MENS_FOUND` (préremplissage) → `UNKNOWN` après suppression |
| 13 | `test_import_idempotent_non_regression` | import CSV via `DataImportService::preparer/confirmer` (`creer` puis `upsert` du même fichier) → 1 ligne, valeurs intactes, `imported_at` posé — l'import reste la voie de masse, non contournée |

### 4.2 Commandes & résultats (exécutés)

```text
php artisan test --filter="MensCrud"
→ 13 passed (135 assertions) — ~1,7 s

php artisan test --filter="Mens"
→ 121 passed (572 assertions) — ~7,5 s
  (ligne de base avant ce lot : 108 passed / 437 assertions → +13 tests, 0 régression)

php artisan test --filter="MensCrud|DataExchangeContratTest|DoublonsGraduesTest|CinPrefillTest|IdentificationFailClosedTest|InscriptionAccueilParcoursTest|DossierExistantParcoursTest|EnseignantImportExportTest"
→ 119 passed (1 221 assertions) — ~3,7 s   (voisins : import, CIN, identification fail-closed, parcours public, audit enseignants)

vendor/bin/pint --test -v app/Http/Controllers/Admin/MensReferenceController.php app/Models/HistoriqueAcademique.php routes/web.php tests/Feature/MensCrudTest.php
→ passed (style conforme, modifications ciblées)
```

---

## 5. Permissions vérifiées (réutilisées, jamais recréées)

| Rôle | `data.import` | Comportement sur le CRUD MENS |
|---|---|---|
| `super_admin` | ✅ (toutes permissions) | CRUD complet, périmètre global |
| `admin_scolarite` | ✅ | CRUD complet, périmètre global |
| `admin_faculte` | ✅ | CRUD restreint aux lignes rattachables à SA faculté (sinon 404) |
| `agent_scolarite` | ❌ (data.export seul) | 403 sur toutes les routes (prouvé par test) |
| Invité | — | redirect connexion scolarité (prouvé par test) |

L'autorisation est appliquée **côté serveur** (middleware `can:data.import` +
contrôles de périmètre dans le contrôleur), jamais par la seule visibilité des
boutons.

---

## 6. Décisions sur ambiguïtés, notes & réserves

**Décisions (documentées et testées)**

1. **Pas de colonne `statut`** en schéma → aucun filtre `statut` inventé ; le
   filtre `appariement` (état calculé réel) tient lieu de statut.
2. **Créable ⇔ visible** pour les comptes restreints ; hors périmètre → 404
   (jamais 403, pour ne pas révéler l'existence de la ligne).
3. **Suppression** refusée dès qu'un dossier local est rapproché ou qu'une trace
   historique la référence ; jamais de fusion/écrasement automatique.
4. **`cin_original`** = CIN saisie (trim, 30 max) ; `cin_normalise` =
   `MensReference::normaliserCin` — calcul identique à l'import.
5. **`imported_by`/`imported_at`** réservés à l'import ; l'audit trace les
   écritures manuelles.

**Réserves (PASS_WITH_RESERVATIONS)**

1. **Table `mens_references` vide en local** (0 ligne) : toutes les branches ont
   été exercées sur fixtures (création, correction, suppression autorisée et
   bloquée), mais jamais sur un fichier MENS réel — à rejouer au premier import
   réel en recette/production.
2. **CIN historiques non conformes** (hors 8 chiffres) : consultables et
   supprimables, mais une correction via le formulaire exige de les conformer à
   la règle unique (échec de validation sinon) — choix fail-closed cohérent avec
   l'import et le parcours public, à confirmer avec les données réelles.
3. **Fenêtre de course** : l'unicité s'appuie sur la contrainte BD
   (`uniq_mens_cin_annee`) ; la collision concurrente est rattrapée par un
   message explicite, sans rejeu automatique.
4. **Empreinte** : recalculée sur les données à chaque écriture manuelle
   (provenance d'import exclue). Champ aujourd'hui sans consommateur de
   comparaison — cohérence conservée par construction, décision documentée.
5. **Note framework (existante, non introduite)** : le `throttle` Laravel
   numérique indexe son compteur par utilisateur authentifié (ou `domaine|IP`
   pour un invité), partagé entre routes ; les quotas ajoutés protègent la
   surface d'écriture sans introduire de nouveau comportement global.

---

## 7. Verdict

**PASS_WITH_RESERVATIONS**

- Toutes les exigences de la boucle sont implémentées, testées et prouvées
  (13 nouveaux tests, 0 régression sur 121 tests MENS et 119 tests voisins).
- Import idempotent, identification fail-closed et parcours public d'inscription
  **intacts** (aucune modification de `MensImportDefinition`,
  `IdentificationIntelligenteService`, `creerDossier` — hors extraction de
  comparateur à comportement identique).
- Réserves : portée limitée aux réserves opérationnelles du §6 (données réelles
  absentes en local, convention CIN sur les données historiques) — aucune ne
  remet en cause le comportement livré.
- Aucun commit/push/déploiement effectué (hors périmètre d'autorisation).

---

## 8. Addendum — Assistance de saisie : auto-complétion & pré-remplissage

**Demande** : rendre `/admin/mens/creer` « plus fluide » avec auto-complétion de saisie (formulaire partagé création/correction).

**Livré (mêmes contrôles d'accès et de périmètre que le reste du module)**

| Élément | Détail |
|---|---|
| `GET /admin/mens/referentiels` (`admin.mens.referentiels`) | Listes d'auto-complétion bornées : années (universitaires ∪ référentiel), programmes (cloisonnés — un compte restreint ne reçoit que sa faculté), établissements et nationalités distincts du référentiel. Valeurs réelles uniquement, jamais de dossier ni de PII. |
| `GET /admin/mens/assistance` (`admin.mens.assistance`) | Sonde CIN : pré-remplissage d'identité (dossier local apparié — comparateur extrait de `creerDossier` ; sinon référence d'une autre année, cloisonnée) + alerte d'unicité [CIN, année] (même contrôle que `valider()` ; `ignorer` pour la correction). Ambiguïté locale → pré-remplissage bloqué, jamais de choix silencieux. |
| Limiteur nommé `mens-assistance` | 60/min **par utilisateur**, seau isolé (`AppServiceProvider`) : une saisie assistée ne puise jamais dans le compteur partagé des écritures (`throttle:N,1` numérique) — réponse directe à la réserve §6.5 pour cette surface. |
| `form.blade.php` (création + correction) | CIN : chiffres uniquement (pattern maison), autofocus en création, sonde débouncée 400 ms + anti-course (jeton de séquence) ; pré-remplissage **des champs vides uniquement** (jamais d'écrasement) ; datalists (années, programmes, établissements, nationalités) chargées en une requête puis complétion native du navigateur ; bouton verrouillé pendant l'envoi (anti double-soumission) ; statuts en `aria-live`. |
| Refactor minimal | `etudiantsRapproches` délègue à `etudiantsParCin` (comparateur unique) ; bloc de périmètre de l'index extrait en `appliquerPerimetre` — comportements identiques, couverts par les tests existants. |

**Nouveaux tests (4) — `tests/Feature/MensCrudTest.php`**

- `test_assistance_prefill_doublon_et_exclusion_correction` — pré-remplissage local, doublon signalé, `ignorer` en correction, repli référence d'une autre année, sonde courte neutre.
- `test_assistance_perimetre_et_ambiguite` — hors faculté → rien ; dans la faculté → pré-remplissage ; deux dossiers (mêmes CIN sous deux formes) → bloqué ; repli MENS hors périmètre → rien.
- `test_referentiels_autocompletion_et_acces` — structure JSON, valeurs réelles, cloisonnement des programmes, 403 agent, 401 invité.
- `test_formulaire_assiste_embarque_referentiels_et_sonde` — le formulaire (création et correction) embarque le composant et les 4 listes.

**Résultats exécutés (après addendum)**

```text
php artisan test --filter="MensCrudTest"
→ 17 passed (183 assertions)          (13/135 avant addendum)

php artisan test --filter="Mens"
→ 125 passed (620 assertions)         (121/572 avant — 0 régression)

php artisan test --filter="MensDossierCreationTest|DataExchangeContratTest|DoublonsGraduesTest|CinPrefillTest|IdentificationFailClosedTest|InscriptionAccueilParcoursTest|DossierExistantParcoursTest|EnseignantImportExportTest"
→ 116 passed (1 106 assertions)

php artisan test --filter="DossierExistantAutonomeTest|SecuritePhaseFTest"
→ 28 passed (182 assertions)

vendor/bin/pint --test -v (4 fichiers touchés) → passed
node --check (script Alpine du formulaire) → OK
```

**État de la suite complète (mesuré pendant la boucle, avant addendum)** : 3 574 tests, 3 536 passés, 25 ignorés, **13 échecs pré-existants hors périmètre** (accessibilité parcours, validation stricte des dates d'identification, budgets N+1 `fiche`/`file`/`dashboard`/`archives`, FAQ wizard) — reproduits à l'identique **sans** les fichiers de la boucle (mise de côté temporaire de `MensReferenceController` + `HistoriqueAcademique`, arbre restauré ensuite) et zones couvertes par des fichiers modifiés hors boucle (`StoreInscriptionAcademiqueRequest`, `InscriptionService`, `Enseignant/ProfilController`…).

**Notes**

- La sonde exige une forme normalisée ≥ 4 caractères et une **égalité stricte** (aucune recherche partielle) : ni énumération, ni correspondance approximative ; hors périmètre → jamais de révélation.
- L'alerte de doublon est volontairement globale : c'est exactement ce que le message d'unicité de `valider()` révèle déjà au moment d'écrire — aucun canal d'information supplémentaire.
- Les identités pré-remplies sont strictement celles déjà visibles sur la page Détails (mêmes règles de périmètre) ; l'audit reste inchangé (CIN masquée, jamais de PII en journal).
- Réserve opérationnelle : en local, `mens_references` étant vide, les listes établissements/nationalités n'affichent des suggestions qu'à partir de la première ligne saisie (les programmes et années proviennent aussi de leurs tables propres).
- **Correction post-diagnostic (même journée)** : l'autofocus conditionnel était écrit `@if(...) autofocus @endif` **dans** la balise du composant `x-input` — non supporté par le compilateur de composants Blade : la balise restait en texte littéral et le champ CIN n'était pas rendu (élément `<x-input>` inconnu, invisible côté navigateur). Corrigé par l'attribut lié `:autofocus="! isset($reference)"` ; rendu vérifié par dump des pages (input réel avec `autofocus` en création uniquement, `value` pré-remplie en correction, 13/13 champs présents) et garde-fous ajoutés au test de formulaire (`assertSee('name="cin"')`, `assertSee('x-on:input.debounce.400ms=…')`, `assertDontSee('<x-input')`). Compteurs finaux : `MensCrud` 17/17 (189 assertions), `--filter="Mens"` 125/125 (626 assertions).

**Verdict inchangé : PASS_WITH_RESERVATIONS** — réserves du §6 valables, complétées par la note opérationnelle ci-dessus.
