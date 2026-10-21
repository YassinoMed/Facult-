# SPEC — Modification administrative d'un étudiant

| Champ | Valeur |
|---|---|
| Ticket | [#1 — feat(admin): modification d'un étudiant depuis le registre (tous les critères)](https://github.com/YassinoMed/Facult-/issues/1) |
| Branche | `feature/admin-modifier-etudiant` (depuis `main` @ `907bf0c`) |
| Périmètre | Back-office `/admin/etudiants/` (module CORE) |
| Langue | Code, commentaires, messages et tests en **français** (`AGENTS.md`) |
| Statut | Approuvée — prête pour implémentation |

---

## 1. Contexte

Le registre `/admin/etudiants/` permet de **créer** un dossier étudiant complet
(`admin.etudiants.creer` → `admin.etudiants.store` → `CreationEtudiantAdminService`)
mais **pas de le modifier**.

La fiche (`admin.etudiants.show`) n'expose aucune action d'édition. Les seuls ajustements
possibles passent par des workflows dédiés et **non substituables** :

| Action existante | Route | Workflow |
|---|---|---|
| Statut du dossier | `admin.etudiants.updateStatut` | transition contrôlée + `situations_etudiant` + notification (`StudentStatusService`) |
| Groupe | `admin.etudiants.affectations.changer` | affectation **historisée**, simulation, audit, notification (`AffectationService`) |
| Mot de passe | `admin.etudiants.reinitialiserMotDePasse` | version de mot de passe, invalidation des sessions |
| Inscription année suivante | `admin.inscriptions.progresser` | création d'une inscription annuelle |
| Champs d'une inscription | écran `admin.archives.show` | `InscriptionAcademiqueController::update` (`niveau`, `filiere`, `groupe`, `statut`, `observation`) |

Conséquence : une correction d'identité (faute de frappe sur le CIN, email erroné, téléphone,
adresse) ou un rattachement LMD erroné (faculté / parcours / niveau) se fait **hors produit**
(SQL, import) — sans validation serveur, sans historique, sans audit.

**Objectif** : permettre à un agent habilité de modifier un étudiant selon l'intégralité des
critères couverts par le formulaire de création, depuis le registre et la fiche, avec
validation serveur, périmètre de faculté, traçabilité et historique.

---

## 2. Décisions de conception

### 2.1 Unité modifiée

La modification porte sur **deux tables**, dans une seule transaction :

- `etudiants` — la fiche de l'étudiant (identité, contact, bac, rattachement LMD).
- `inscriptions_academiques` — **l'inscription courante** de l'étudiant (type, régime, paiement)
  + propagation du LMD.

L'**inscription courante** est l'inscription de l'étudiant la plus récente
(`annees_universitaires.date_debut` décroissant). C'est la seule inscription éditable via ce
formulaire : les inscriptions antérieures sont de l'historique et relèvent de l'écran
`admin.archives.show` ; l'année suivante se crée via `admin.inscriptions.progresser`.

### 2.2 Ce qui est éditable

| Bloc | Champs | Table |
|---|---|---|
| Identité | `nom`, `prenom`, `cin`, `identifiant_national`, `date_naissance`, `sexe` | `etudiants` |
| Contact | `email`, `telephone`, `adresse`, `ville`, `code_postal` | `etudiants` |
| Scolarité | `annee_bac`, `mention_bac` | `etudiants` |
| Rattachement LMD | `faculte_id`, `programme_id`, `niveau_id` (→ `niveau` dérivé) | `etudiants` |
| Inscription courante | `type_inscription`, `regime`, `paye` | `inscriptions_academiques` |
| Justification | `motif_modification` | — (audit + situation) |

### 2.3 Ce qui est délibérément exclu (délégué, jamais dupliqué)

| Champ | Raison | Workflow de substitution |
|---|---|---|
| `statut` (dossier) | transition contrôlée + notification | `admin.etudiants.updateStatut` |
| `groupe_id` | affectation historisée, simulation, audit | `admin.etudiants.affectations.changer` |
| `password` | trappe « mot de passe perdu » | `admin.etudiants.reinitialiserMotDePasse` |
| `num_inscription` | généré, unique, immuable | — |
| `annee_academique` | dérivé de l'année d'inscription courante | — |
| inscription `niveau`/`filiere`/`groupe`/`statut`/`observation` | déjà couverte par `admin.archives.show` | `InscriptionAcademiqueController::update` |
| nouvelle inscription annuelle | acte administratif distinct | `admin.inscriptions.progresser` |

> **Règle « pas de duplication » (`AGENTS.md`)** : le formulaire affiche le groupe et le statut
> en **lecture seule** (avec un lien vers le workflow correspondant) au lieu de réimplémenter
> une deuxième voie d'écriture.

### 2.4 Propagation LMD (anti-dérive) — **conditionnelle**

`etudiants` porte une copie dénormalisée du LMD (`faculte_id`, `programme_id`, `niveau_id`,
`niveau`) et `inscriptions_academiques` porte la même chose (`programme_id`, `niveau_id`,
`niveau`, `filiere`). Les deux **doivent** rester alignées.

Règle : **lorsque le LMD change dans le formulaire**, la modification est **répliquée sur
l'inscription courante** (`programme_id`, `niveau_id`, `niveau`, `filiere`) dans la même
transaction. Sans cela, la fiche et l'inscription divergeraient silencieusement.

> **La propagation est conditionnelle, pas inconditionnelle.** Les colonnes `niveau` et
> `filiere` de `inscriptions_academiques` sont du **texte libre** édité par
> `InscriptionAcademiqueController::update` (écran `admin.archives.show`). Les réécrire à
> chaque passage dans le formulaire de l'étudiant ferait que les deux écrans se marchent
> dessus. Donc : si le LMD ne change pas, l'inscription n'est **pas** touchée sur ces
> colonnes ; l'écran archives en reste le propriétaire. Seuls `type`, `regime` et `paye` —
> non modifiables ailleurs — sont toujours écrits.

### 2.5 Incompatibilité de groupe — **conditionnelle**

Le groupe appartient à un triplet (faculté, parcours, niveau) et à une année. Si l'étudiant a un
groupe courant (`etudiant->groupe_id`) **et que le LMD change** dans le formulaire, et que le
groupe courant est **incompatible** avec le **nouveau** LMD, la modification est **refusée**
avec un message qui renvoie vers le workflow d'affectation. Même invariant que
`CreationEtudiantAdminService` (lignes 71-99).

> **Le refus ne se déclenche que si le LMD a réellement bougé.** Une incompatibilité
> *préexistante* entre le groupe et le LMD (dérive de données) ne doit pas bloquer la
> correction d'un simple email : ce n'est pas le fait de ce formulaire. En revanche, un
> changement de LMD qui rendrait le groupe incompatible est refusé — l'invariant
> « groupe ∈ (faculté, parcours, niveau) » tient.

### 2.5.b Champs de l'inscription absents (défense en profondeur)

`type_inscription`, `regime` et `paye` sont des champs **de l'inscription courante**, pas de la
fiche. Si l'étudiant **n'a aucune inscription**, le bloc correspondant est absent du formulaire :
les trois champs ne sont pas transmis et le formulaire reste valide.

Si l'étudiant **a** une inscription, un POST partiel (champ absent) ne doit **jamais** écraser
la valeur courante : les valeurs de repli sont celles de l'inscription, pas des constantes
(`premiere` / `initiale` / `false`). Une absence de champ est traitée comme « inchangé »,
jamais comme « remit à zéro ».

### 2.6 Unicité

`cin` et `email` sont uniques en base. La règle de création (`StoreEtudiantAdminRequest`)
utilise une closure `exists()` **globale** : elle rejetterait la valeur courante de l'étudiant
modifié. La requête de modification utilise `Rule::unique(...)->ignore($etudiant->id)`.

`identifiant_national` **n'a pas** d'unicité en base ni dans le formulaire de création
(commentaire `Etudiant::$fillable` : « sans unique »). La modification **n'en introduit pas** :
parité stricte avec la création, pas de divergence de comportement entre les deux écrans.

### 2.7 Motif de modification

`motif_modification` est **obligatoire** (≥ 10 caractères, ≤ 255), exactement comme
`motif_validation` à la création. Il est journalisé dans la `situation_etudiant` et n'est jamais
écrit en clair dans les logs.

### 2.8 Absence de PII dans l'audit

`historique_academique` ne doit pas devenir un second stockage de données personnelles.
Conformément à `AGENTS.md` (« No PII in logs ») et au précédent de
`CreationEtudiantAdminService` (qui ne stocke que des **codes** : faculté, parcours, niveau,
statut, motif) :

- l'audit enregistre la **liste des champs modifiés** (noms de colonnes) ;
- plus les valeurs **non nominatives** : code de faculté, code de parcours, code de niveau,
  type / régime / paiement, statut ;
- **jamais** l'ancienne ni la nouvelle valeur d'un champ nominatif (CIN, email, nom, prénom,
  adresse, téléphone, date de naissance, identifiant national).

---

## 3. Fichiers concernés

| Action | Fichier |
|---|---|
| Créer | `app/Http/Requests/UpdateEtudiantAdminRequest.php` |
| Créer | `app/Services/ModificationEtudiantAdminService.php` |
| Modifier | `app/Models/InscriptionAcademique.php` (scope `courante()` — source unique) |
| Modifier | `app/Http/Controllers/Admin/EtudiantController.php` (+ `edit()`, `update()`) |
| Modifier | `routes/web.php` (+ 2 routes) |
| Créer | `resources/views/admin/etudiants/edit.blade.php` |
| Modifier | `resources/views/admin/etudiants/show.blade.php` (bouton « Modifier ») |
| Modifier | `resources/views/admin/etudiants/index.blade.php` (action de ligne « Modifier ») |
| Créer | `tests/Feature/AdminModificationEtudiantTest.php` |
| Modifier | `docs/students-groups/student-lists.md` ou `docs/referentiel/permissions.md` (voir §8) |

**Aucune migration.** Le schéma ne change pas.

---

## 4. Contrat HTTP

### 4.1 Routes

```php
// routes/web.php — bloc admin/étudiants (à côté de `etudiants.creer` / `etudiants.store`)

Route::get('etudiants/{etudiant}/modifier', [EtudiantController::class, 'edit'])
    ->middleware(['can:archives.update', 'module:referentiel'])
    ->name('etudiants.modifier');

Route::patch('etudiants/{etudiant}', [EtudiantController::class, 'update'])
    ->middleware(['can:archives.update', 'module:referentiel', 'throttle:20,1'])
    ->name('etudiants.update');
```

> `PATCH etudiants/{etudiant}` est sans collision avec `POST etudiants` (store) ni avec
> `PATCH etudiants/{etudiant}/statut` (segments distincts). La route `GET etudiants/{etudiant}`
> reste déclarée avant : `modifier` est un segment de plus, il n'est jamais capté par elle.

### 4.2 Formulaire

- `GET /admin/etudiants/{etudiant}/modifier` → `admin.etudiants.edit`
- `POST` (avec `@method('PATCH')`) vers `/admin/etudiants/{etudiant}` → `admin.etudiants.update`
- Redirection de succès : `route('admin.etudiants.show', $etudiant)` avec message flash `success`.

### 4.3 FormRequest — `UpdateEtudiantAdminRequest`

```php
authorize(): bool
    // archives.update + périmètre de faculté de l'étudiant visé (anti-IDOR, 403)
    // → $this->user()->hasPermission('archives.update')
    //   && $this->user()->peutAccederFaculte((int) $etudiant->faculte_id)
```

`prepareForValidation()` — **copie de `StoreEtudiantAdminRequest`** (normalisation
`cin` en majuscules sans espaces, `email` en minuscules, `trim` des textes) :
la ligne 53 (`motif_validation`) devient `motif_modification`.

`rules()` — **miroir de `StoreEtudiantAdminRequest`** avec les adaptations suivantes :

| Champ | Règle de modification |
|---|---|
| `cin` | `required`, `string`, `regex:/^[A-Z0-9-]{5,30}$/`, `Rule::unique('etudiants','cin')->ignore($etudiant->id)` |
| `email` | `required`, `email`, `max:254`, `Rule::unique('etudiants','email')->ignore($etudiant->id)` |
| `identifiant_national` | `nullable`, `string`, `max:30` (parité création) |
| `telephone` | `nullable`, `string`, `max:30`, `regex:/^\+?[0-9\s\-()]{7,30}$/` |
| `date_naissance` | `required`, `date_format:Y-m-d`, `before:today`, `after:1920-01-01` |
| `sexe` | `required`, `in:M,F` |
| `faculte_id` | `required`, `integer`, `Rule::exists('facultes','id')->where('active', true)` |
| `programme_id` | `required`, `integer`, `Rule::exists('programmes','id')->where(...)` (actif **+** même faculté) |
| `niveau_id` | `required`, `integer`, `Rule::exists('niveaux','id')->where(...)` (actif **+** même parcours) |
| `type_inscription` | `required` **si** l'étudiant a une inscription courante, sinon `sometimes` + `nullable` — `in:premiere,reinscription,transfert,mutation,reprise,equivalence,autre` |
| `regime` | `required` **si** l'étudiant a une inscription courante, sinon `sometimes` + `nullable` — `in:initiale,continue,alternance` |
| `adresse` | `required`, `string`, `min:5`, `max:500` |
| `ville` | `required`, `string`, `min:2`, `max:100` |
| `code_postal` | `required`, `string`, `max:20` |
| `annee_bac` | `required`, `string`, `regex:/^\d{4}$/` |
| `mention_bac` | `nullable`, `in:Passable,Assez Bien,Bien,Très Bien,Excellent` |
| `paye` | `required` **si** l'étudiant a une inscription courante, sinon `sometimes` + `nullable` — `boolean` |
| `motif_modification` | `required`, `string`, `min:10`, `max:255` |

`withValidator()` — **copie de `StoreEtudiantAdminRequest`** (cohérence
faculté → parcours → niveau → groupe, `peutAccederFaculte`), **sans** la règle d'unicité
globale (remplacée par `Rule::unique(...)->ignore()`).

`messages()` — copier ceux de `StoreEtudiantAdminRequest` ; ajouter `motif_modification.*`.

### 4.4 Contrôleur

```php
public function edit(Request $request, Etudiant $etudiant): View
{
    Gate::authorize('update', $etudiant);

    // référentiels scopés à la faculté de l'agent (anti-IDOR dès le formulaire)
    // → facultes, programmes, niveaux, groupes : même code que create()
    // → inscription courante + années (AnneeUniversitaire::orderByDesc('date_debut'))
    return view('admin.etudiants.edit', [...]);
}

public function update(UpdateEtudiantAdminRequest $request, Etudiant $etudiant): RedirectResponse
{
    Gate::authorize('update', $etudiant);

    try {
        app(ModificationEtudiantAdminService::class)->modifier($etudiant, $request->validated(), $request->user());
    } catch (\DomainException $e) {
        return back()->withInput()->withErrors(['modification' => $e->getMessage()]);
    } catch (\Throwable $e) {
        report($e);
        return back()->withInput()->withErrors(['modification' => 'La modification a échoué. Aucune donnée n’a été enregistrée.']);
    }

    return redirect()->route('admin.etudiants.show', $etudiant)
        ->with('success', "Dossier de {$etudiant->nom_complet} mis à jour.");
}
```

---

## 5. Service — `ModificationEtudiantAdminService`

Emplacement : `app/Services/ModificationEtudiantAdminService.php`
(miroir structurel de `CreationEtudiantAdminService`).

```php
public function modifier(Etudiant $etudiant, array $donnees, User $auteur): Etudiant
```

### Étapes

1. **Résolution des référentiels** : `Faculte::findOrFail`, `Programme::findOrFail`,
   `Niveau::findOrFail`. Inscription courante =
   `InscriptionAcademique` de l'étudiant sur l'année la plus récente
   (`InscriptionService::inscriptionAnnuelle` ou jointure sur
   `annees_universitaires.date_debut` — **une seule** source, pas de doublon).

2. **Défense en profondeur** (même logique que `CreationEtudiantAdminService` lignes 59-99,
   qui revalide ce que le FormRequest a déjà validé) :
   - `$auteur->peutAccederFaculte($faculte->id)` sinon `DomainException` ;
   - programme actif **et** de la faculté ;
   - niveau actif **et** du programme.

3. **Calcul du diff** : comparer les attributs `etudiants` cibles aux valeurs courantes, et les
   attributs de l'inscription courante. Si **aucun** changement → `DomainException`
   « Aucune modification à enregistrer. » (pas d'entrée d'audit vide).

4. **Propagation LMD conditionnelle** (§2.4) : si le diff montre que le LMD de la fiche a
   bougé (faculté, parcours ou niveau), alors `inscriptions_academiques.programme_id`,
   `niveau_id`, `niveau` et `filiere` sont alignés sur la cible. Sinon ces colonnes ne sont
   **pas** touchées. `type`, `regime` et `paye` sont toujours alignés sur la cible (valeur
   transmise, sinon valeur courante de l'inscription — jamais une constante, §2.5.b).

5. **Incompatibilité de groupe** (§2.5) : **uniquement si le LMD a changé**. Si
   `$etudiant->groupe_id` n'est pas null, charger le groupe et vérifier (faculté, parcours,
   niveau) face à la **nouvelle** cible. Incompatible → `DomainException` :
   > « Le groupe actuel (G-01) n'appartient pas au nouveau rattachement. Changez ou retirez
   > d'abord le groupe avant de modifier le parcours. »

6. **Transaction** (`DB::transaction`) :
   a. `etudiants->update([...])` — uniquement les champs éditables :
      `cin`, `identifiant_national`, `nom`, `prenom`, `email`, `telephone`, `date_naissance`,
      `sexe`, `faculte_id`, `programme_id`, `niveau_id`, `niveau` (code du niveau),
      `adresse`, `ville`, `code_postal`, `annee_bac`, `mention_bac`.
   b. Si inscription courante : `$inscription->update([...])` —
      `programme_id`, `niveau_id`, `niveau`, `filiere` (**seulement si le LMD a changé**, §2.4)
      + `type`, `regime`, `paye` (champs du formulaire).
   c. **Situation** : `SituationEtudiant::create([...])`
      - `type` = `'programme'` si le LMD a changé, sinon `'note'`
        (enum `situations_etudiant.type` = statut, groupe, programme, suspension, transfert,
        reinscription, note — pas de nouvelle valeur d'enum, donc pas de migration) ;
      - `ancienne_valeur` / `nouvelle_valeur` = code de parcours avant / après
        (`'dossier'` / `'dossier'` si le LMD n'a pas changé) ;
      - `motif` = `motif_modification` ;
      - `auteur_id` = `$auteur->id`, `date_effet` = `now()->toDateString()`.
   d. **Audit** : `AuditService::tracer($etudiant, HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER, 'etudiant', $etudiant->id, $avant, $apres, 'web')`
      où `$avant` / `$apres` ne contiennent **que** (§2.8) :
      `champs_modifies` (liste de noms de colonnes), `faculte` (code), `programme` (code),
      `niveau` (code), `type`, `regime`, `paye`.
   e. **Population** (data-mesh) : après commit,
      `PopulationChanged::dispatch('etudiants', 'modification', $etudiant->id, (int) $auteur->id, ['champs' => $champsModifies])`
      — sans PII (contexte : entité + ids + valeurs, sans PII).

6. **Retour** : `$etudiant->fresh()`.

### Source unique de l'inscription courante

La requête « inscription courante » (année universitaire la plus récente) est utilisée à trois
endroits : le contrôleur (`edit()`), la requête de validation et le service. Elle **doit** n'être
écrite qu'**une seule fois** — sous forme d'un scope sur le modèle :

```php
// app/Models/InscriptionAcademique.php
public function scopeCourante(Builder $query): Builder
{
    return $query
        ->join('annees_universitaires', 'annees_universitaires.id', '=', 'inscriptions_academiques.annee_universitaire_id')
        ->orderByDesc('annees_universitaires.date_debut')
        ->select('inscriptions_academiques.*');
}
```

Usage unique : `InscriptionAcademique::courante()->where('etudiant_id', $etudiant->id)->first()`.

### Points d'attention

- `etudiants.niveau` est une colonne texte (`L1`…`D3`) : elle est **dérivée** de
  `niveau_id` → `Niveau::code`. Ne jamais la saisir.
- `etudiants.annee_academique` n'est **pas** modifié (l'année d'inscription ne change pas).
- `paye` est porté par l'inscription (`inscriptions_academiques.paye`, booléen, défaut `false`).
  Conversion : `filter_var($donnees['paye'], FILTER_VALIDATE_BOOLEAN)`.
- `type` et `regime` sont des enums de `inscriptions_academiques`.
- Aucun mot de passe n'est touché. Aucun `statut` n'est touché. Aucune affectation n'est
  créée / modifiée / retirée.

---

## 6. Vue — `resources/views/admin/etudiants/edit.blade.php`

**Miroir de `create.blade.php`** (mêmes classes Tailwind, mêmes blocs, même cascade
faculté → parcours → niveau → groupe en JavaScript). Differences :

| Élément | Création | Modification |
|---|---|---|
| Titre | « Créer un étudiant » | « Modifier l'étudiant » |
| Description | « Dossier complet… » | « Correction de la fiche : identité, contact, rattachement LMD et inscription courante. » |
| Valeurs | `old('champ')` | `old('champ', $etudiant->champ)` / `$inscription?->champ` |
| `faculte_id` | défaut = unique faculté ou vide | défaut = `$etudiant->faculte_id` |
| `programme_id` / `niveau_id` | vide | `$etudiant->programme_id` / `$etudiant->niveau_id` |
| `type_inscription` | défaut `premiere` | valeur de l'inscription courante |
| `regime` | défaut `initiale` | valeur de l'inscription courante |
| `paye` | `0` | valeur de l'inscription courante |
| Bloc « Motif de validation immédiate » | `motif_validation` | **`motif_modification`** (« Motif de la modification », ≥ 10 car., journalisé) |
| Bouton | « Créer l'étudiant et valider » | « Enregistrer les modifications » |
| Mention bas de page | « Un mot de passe provisoire sera généré… » | « Les modifications sont journalisées dans l'historique du dossier. » |
| Alerte warning | « Validation immédiate de l'inscription » | « Le statut, le groupe et le mot de passe ne se modifient pas ici. » |

Blocs **en lecture seule** (information + renvoi vers le workflow, pas de champ éditable) :

- **Statut du dossier** → valeur actuelle + lien vers l'action de statut sur la fiche.
- **Groupe courant** → code + nom + lien vers `admin.etudiants.affectations.changer` /
  la section « Affectation » de la fiche.
- **Année d'inscription courante** → libellé de l'année.

Si l'étudiant **n'a aucune inscription** : le bloc « Inscription courante » est masqué et un
encart indique de passer par le workflow de progression d'inscription. Le reste du formulaire
reste pleinement utilisable.

Cascade JavaScript : **reprendre à l'identique** le script de `create.blade.php`
(`remplir`, `rafraichirProgrammes`, `rafraichirNiveaux`, `rafraichirGroupes`) en l'initialisant
avec les valeurs de l'étudiant, pour que le formulaire reste cohérent quand l'agent change de
faculté / parcours / niveau.

Accessibilité : conserver les `label for=`, les `aria-labelledby` de section, les messages
d'erreur `@error(...)` et les attributs `required` / `minlength` / `maxlength` / `pattern`
du formulaire de création.

---

## 7. Tests — `tests/Feature/AdminModificationEtudiantTest.php`

Conventions de `tests/Feature/AdminCreationEtudiantTest.php` (nommage français
`test_que_le…`, factories `User` / `Etudiant` / `Faculte` / `Programme` / `Niveau` /
`Group` / `AnneeUniversitaire`).

**29 tests / 166 assertions** — tous verts.

| # | Test | Attendu |
|---|---|---|
| 1 | `test_la_page_de_modification_est_accessible_aux_agents_habilites` | 200, champs pré-remplis |
| 2 | `test_un_agent_sans_permission_obtient_un_403` | 403 |
| 3 | `test_un_agent_d_une_autre_faculte_obtient_un_403` | 403 (anti-IDOR) |
| 4 | `test_le_bouton_modifier_est_visible_sur_la_fiche` | lien présent sur `show` |
| 5 | `test_le_bouton_modifier_est_visible_dans_le_registre` | action de ligne présente sur `index` |
| 6 | `test_une_modification_identite_est_enregistree_et_tracee` | champs modifiés + `historique_academique` + `situations_etudiant` |
| 7 | `test_le_motif_de_modification_est_obligatoire` | 422 / erreur de validation, aucune écriture |
| 8 | `test_un_cin_deja_utilise_par_un_autre_etudiant_est_refuse` | erreur, aucune écriture |
| 9 | `test_un_email_deja_utilise_par_un_autre_etudiant_est_refuse` | erreur, aucune écriture |
| 10 | `test_la_valeur_actuelle_du_cin_est_acceptee` | modification OK (non rejetée par l'unicité) |
| 11 | `test_le_rattachement_lmd_est_propage_a_l_inscription_courante` | `etudiants` **et** inscription alignés |
| 12 | `test_un_parcours_d_une_autre_faculte_est_refuse` | erreur |
| 13 | `test_un_niveau_d_une_autre_parcours_est_refuse` | erreur |
| 14 | `test_une_modification_de_groupe_incompatible_est_refusee` | erreur **si le LMD change** et rend le groupe courant incompatible |
| 14b | `test_une_incompatibilite_de_groupe_preexistante_ne_bloque_pas_une_correction_hors_lmd` | une correction d'email **réussit** malgré un groupe déjà incohérent |
| 14c | `test_un_post_partiel_ne_reecrit_pas_le_type_le_regime_et_le_paiement_courants` | POST sans `type`/`regime`/`paye` → valeurs courantes **préservées** |
| 14d | `test_une_correction_hors_lmd_preserve_les_colonnes_texte_de_l_inscription` | `filiere` / `niveau` en texte libre **préservés** hors changement de LMD |
| 14e | `test_un_etudiant_sans_inscription_peut_modifier_sa_fiche` | fiche modifiable, **aucune inscription créée** |
| 15 | `test_le_statut_du_dossier_est_inchange_par_la_modification` | `statut` identique avant / après |
| 16 | `test_le_mot_de_passe_est_inchange` | hash identique |
| 17 | `test_une_modification_inchangee_est_refusee` | « Aucune modification à enregistrer. », aucune écriture |
| 18 | `test_le_type_le_regime_et_le_paiement_sont_modifiables` | inscription mise à jour |
| 19 | `test_le_statut_de_paiement_est_independant_du_module_finances` | aucune facture créée |
| 20 | `test_le_select_groupe_est_en_lecture_seule_et_presente_le_groupe_courant` | select `disabled` + groupe courant présélectionné |
| 21 | `test_un_post_partiel_conserve_les_champs_optionnels_absents` | `identifiant_national` / `telephone` / `mention_bac` absents → **conservés** |
| 22 | `test_un_champ_optionnel_transmis_vide_est_un_effacement_volontaire` | champ transmis vide → **vidé** (absent ≠ vide) |
| 23 | `test_une_derive_du_texte_niveau_ne_declenche_pas_la_propagation_lmd` | dérive du texte `niveau` → inscription **intacte** |
| 24 | `test_une_correction_hors_lmd_preserve_les_identifiants_lmd_de_l_inscription` | inscription **désynchronisée** → `programme_id` / `niveau_id` **préservés** |
| 25 | `test_les_champs_hors_formulaire_sont_ignores_meme_si_transmis` | `statut`, `groupe_id`, `num_inscription`, `password` postés → **aucun modifié** |

> Les tests 14c, 14d, 20, 21, 22, 23, 24, 25 ont été validés par **mutation testing** (chaque
> test échoue bien quand la règle correspondante est enfreinte) — pas d'assertion vacante.

---

## 8. Documentation

- `docs/referentiel/permissions.md` — mentionner que `archives.update` couvre désormais la
  modification de la fiche étudiante (et pas seulement le statut / l'affectation).
- `docs/students-groups/student-lists.md` — mentionner l'action « Modifier » du registre.
- Ne **pas** modifier `docs/IDENTITY-SCAN.md`, `docs/SECURITY.md` : aucun changement de
  comportement de scan d'identité ni de politique de sécurité.

---

## 9. Critères d'acceptation (rappel)

- [ ] Un agent avec `archives.update` ouvre `/admin/etudiants/{id}/modifier` et voit tous les
      champs pré-remplis (identité, contact, bac, LMD, inscription courante).
- [ ] La modification est enregistrée, tracée (`historique_academique` + `situations_etudiant`)
      et visible sur la fiche.
- [ ] Un agent sans `archives.update` → 403. Un agent d'une autre faculté → 403.
- [ ] Un CIN / email déjà pris par **un autre** étudiant est refusé ; la valeur courante est
      acceptée.
- [ ] Le LMD est propagé à l'inscription courante (aucune divergence).
- [ ] Le statut, l'affectation de groupe et le mot de passe ne sont pas modifiables ici.
- [ ] Le motif de modification est obligatoire et journalisé.
- [ ] Aucune donnée n'est écrite si la validation échoue (transaction + rollback).
- [ ] `vendor/bin/pint --dirty` passe, `php artisan test --filter=AdminModificationEtudiantTest`
      est vert.

---

## 10. Hors périmètre

- Changement de groupe (workflow `affectations.changer`).
- Changement de statut (workflow `updateStatut`).
- Création d'une inscription pour une nouvelle année (`inscriptions.progresser`).
- Modification d'une inscription **historique** (écran `admin.archives.show`).
- Unicité de `identifiant_national` (parité avec la création — suivi à traiter séparément).
- Rapports, exports, API, import CSV.
