# Pré-remplissage automatique par CIN — Module d'inscription public

> Fonctionnalité : détection en temps réel d'un CIN déjà enregistré dans la base
> de données lors de la saisie du formulaire public d'inscription, avec
> pré-remplissage sécurisé des champs connus.
>
> Implémentation de référence : `routes/web.php` (route `inscription.verifierCin`),
> `app/Http/Controllers/InscriptionController::verifierCin()`,
> `app/Http/Requests/VerifierCinRequest.php`, `app/Services/StudentLookupService.php`,
> `resources/js/app.js` (composant Alpine `inscriptionForm`),
> `resources/views/inscription/create.blade.php`,
> `tests/Feature/CinPrefillTest.php`.

---

## 1. Comportement

```text
CIN saisi (debounce 600 ms / blur)
    ↓
Validation stricte du format (client : regex ; serveur : FormRequest)
    ↓
Date de naissance saisie ? (second facteur obligatoire — §P0-4)
    ├── NON → état « attente_date » : AUCUNE requête envoyée
    │
    └── OUI → Recherche BD paramétrée (CIN unique + date EXACTE)
    ↓
Correspondance exacte ?
    ├── NON → { "exists": false } → formulaire vierge, saisie complète
    │         (réponse identique que le CIN soit inconnu OU la date erronée)
    │
    └── OUI → { "exists": true, "etudiant": { DTO whitelist } }
                 ↓
             Pré-remplissage des champs (visuellement identifiables)
                 ↓
             Mot de passe TOUJOURS vide (jamais transmis, jamais déduit)
                 ↓
             Validation utilisateur puis soumission
                 ↓
             Inscription finale (règles de validation normales + transaction)
```

Points fondamentaux :

- **La vérification ne crée ni ne modifie jamais de compte** : l'endpoint est
  strictement en lecture ; la création a lieu uniquement lors de la soumission
  du formulaire (`POST /inscription`).
- Si le CIN correspond déjà à un étudiant inscrit, la **création d'un doublon
  est impossible** (contrainte SQL `UNIQUE` + règle `Rule::unique` +
  transaction) et un **message métier** oriente l'utilisateur vers son Espace
  Étudiant ou la scolarité, sans révéler d'informations sensibles.
- Le pré-remplissage n'écrase rien en base : les données ne sont sauvegardées
  qu'après soumission, via `$request->validated()`, `$fillable` strict et une
  transaction (`DB::transaction`).

## 2. Endpoint

```http
POST /inscription/verifier-cin
Content-Type: application/json
X-CSRF-TOKEN: <jeton de la balise meta>
```

Corps de la requête (les DEUX champs sont obligatoires) :

```json
{
    "cin": "BK123456",
    "date_naissance": "2003-05-17"
}
```

Le choix du **POST** (plutôt que GET) est volontaire : le CIN ne transite jamais
dans une URL, donc ni dans les logs d'accès, ni dans l'historique du navigateur,
ni dans les en-têtes `Referer`.

Le **second facteur** (date de naissance exacte, §P0-4) est volontaire : le CIN
est un identifiant semi-public — imprimé sur les documents, connu de
l'administration et de nombreux tiers. Connaître le CIN seul ne doit jamais
suffire à récupérer des données personnelles.

Réponses possibles :

```json
// CIN introuvable
{ "exists": false }

// CIN existant — DTO de pré-remplissage uniquement
{
    "exists": true,
    "etudiant": {
        "cin": "BK123456",
        "nom": "BEN ALI",
        "prenom": "Mohamed",
        "email": "mohamed.benali@example.com",
        "telephone": "+212612345678",
        "date_naissance": "2003-05-17",
        "sexe": "M",
        "adresse": "45 Avenue Hassan II",
        "ville": "Rabat",
        "code_postal": "10000",
        "faculte_id": "3",
        "niveau": "L2",
        "matricule": "MAT-2023-0456"
    }
}
```

CIN invalide → `422` avec erreur de validation `cin`
(`regex:/^[A-Z0-9\-]{5,30}$/` après normalisation majuscules + trim).
Date de naissance absente/invalide/future → `422` avec erreur de validation
`date_naissance` (`required`, `date`, `before:today`).

Le **modèle Eloquent complet n'est jamais retourné** : seul le DTO
`StudentLookupService::donneesPrefill()` (whitelist explicite de 13 clés) est
sérialisé. Le matricule provient du dossier académique (module Archives) le cas
échéant.

## 3. Sécurité

| Protection | Mise en œuvre |
|---|---|
| Validation stricte du CIN | `VerifierCinRequest` : `required`, `string`, regex `[A-Z0-9-]{5,30}`, normalisation serveur |
| **Second facteur (§P0-4)** | `date_naissance` obligatoire et comparée EXACTEMENT en base (`whereDate`) — un CIN seul ne retourne jamais de données ; un étudiant sans date de naissance connue n'est JAMAIS retourné (fail-closed) ; réponse identique si la date est erronée ou si le CIN est inconnu |
| Requêtes paramétrées | Eloquent / Query Builder uniquement — **aucune concaténation SQL** |
| CSRF | Route dans le groupe `web` (`PreventRequestForgery`) — tout POST sans jeton est rejeté (419) |
| Rate limiting | `throttle:10,1` — anti-énumération massive des CIN |
| Anti-énumération | Requête POST (CIN jamais dans les URLs/logs), throttle, aucune donnée affichée avant validation du format |
| Mot de passe | Jamais récupéré, affiché ni transmis ; `password` est `hidden` sur le modèle ; absent du DTO et du JSON |
| Hash du mot de passe | Jamais exposé (testé par `test_la_reponse_ne_contient_jamais_le_mot_de_passe_ni_son_hash`) |
| Données inutiles | `password`, `password_confirmation`, `remember_token`, `statut`, `id`, `num_inscription`, tokens de session — tous absents de la réponse |
| Mass assignment | `$fillable` strict + `$request->validated()` : `statut`/`id` injectés sont ignorés (testé) |
| Journalisation | Aucun log dédié à la recherche (données sensibles) ; aucun secret dans les logs — les 429 sont journalisés par le throttler standard |
| Client | Jeteur CSRF, `AbortController`, anti-course (le CIN a changé pendant la requête → réponse ignorée) |

Règle fondamentale : le **CIN est un identifiant métier unique**. Il n'est jamais
utilisé comme mot de passe, token, clé API ou identifiant de session. Le mot de
passe est toujours saisi séparément et stocké exclusivement sous forme de hash
bcrypt (cast `hashed`).

## 4. Recherche dynamique (UX)

Composant Alpine `inscriptionForm` (`resources/js/app.js`) :

- **Jamais une requête par caractère** : la recherche part au `blur` ou après un
  debounce de 600 ms sur saisie d'un CIN au format valide (≥ 5 caractères) ;
- **second facteur requis** : sans date de naissance saisie, aucun appel réseau —
  l'état `attente_date` invite à compléter le champ ; la saisie (ou modification)
  de la date relance la recherche (debounce partagé) ;
- garde anti-redondance : un couple CIN + date déjà résolu ne redéclenche pas
  de requête ;
- une seule requête en vol (`AbortController`) ;
- états visuels (messages conformes au §8 du cahier des charges) :
  - `Pour retrouver vos informations, saisissez également votre date de naissance` (`attente_date`) ;
  - `Recherche du CIN en cours…` (spinner) ;
  - `✓ Informations existantes trouvées. Les informations connues ont été pré-remplies.` + avertissement métier anti-doublon avec lien Espace Étudiant ;
  - `Aucun étudiant trouvé avec ce CIN. Vous pouvez continuer avec une nouvelle inscription.` ;
  - message de rate limiting en cas de 429 ;
- les champs pré-remplis sont **visuellement identifiables** (bordure/fond
  primaire via le tableau `preliles`) ; toute retouche utilisateur retire la
  marque ;
- les champs `password` / `password_confirmation` ne sont jamais pré-remplis ;
- si un nouveau CIN est introuvable après un précédent pré-remplissage, seuls
  les champs encore marqués « pré-remplis » sont vidés — jamais ceux saisis
  manuellement.

## 5. Compatibilité avec le module Archives académiques

Le DTO inclut le `matricule` du `dossierAcademique` de l'étudiant (relation
`Etudiant::dossierAcademique()`, table `archives_etudiants`) lorsqu'il existe.
Ainsi, une réinscription d'un étudiant archivé réutilise son identité existante :

- la recherche expose les informations nécessaires au formulaire sans dupliquer
  l'étudiant ;
- l'unicité du CIN garantit qu'un même individu ne peut jamais être créé deux
  fois : la réinscription est redirigée vers l'Espace Étudiant (message métier)
  ou traitée par la scolarité via le module Archives (dossier académique,
  inscriptions précédentes, notes, moyennes, crédits, documents).

## 6. Tests

`tests/Feature/CinPrefillTest.php` — 27 tests :

- CIN inexistant → `exists = false` (réponse exacte, normalisation testée) ;
- CIN existant → `exists = true` + valeurs exactes du DTO (dont matricule via
  le dossier académique, et `matricule: null` sans dossier) ;
- **second facteur (§P0-4)** : date manquante/invalide/future → 422 ; date
  erronée → même réponse exacte qu'un CIN inconnu ; étudiant sans date de
  naissance en base → jamais retourné (fail-closed) ;
- **mot de passe** : clés `password`, `password_confirmation`, `password_hash`,
  `remember_token`, `statut`, `id`, `num_inscription` absentes ; whitelist
  exacte des 13 clés ; hash bcrypt et mot de passe en clair absents du corps ;
- CIN invalides : vide, trop court, trop long, caractères invalides, bornes
  limites acceptées ;
- SQL injection : charges classiques rejetées en 422, base intacte ;
- mass assignment : `statut`/`id`/`password` injectés ignorés côté endpoint de
  recherche ET côté inscription finale ;
- §6 : la vérification ne crée ni ne modifie aucun dossier ;
- doublon : inscription refusée avec le même CIN (insensible à la casse),
  aucun doublon en base, message métier ;
- rate limiting : 10 requêtes OK, 11ᵉ → 429 ;
- GET refusé (405) ; route dans le groupe `web` avec `PreventRequestForgery` ;
- page d'inscription : présence du branchement JS (`rechercherCin`,
  `cinRecherche`, meta CSRF).
