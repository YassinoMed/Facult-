---
name: code-review
description: Code review expert pour Laravel — conventions, sécurité, patterns, maintenabilité.
---

# Code Review Skill

Utilisez ce skill pour revoir du code Laravel en suivant les conventions du projet :

## Conventions du projet Laravel (inscription-etudiants)

### Nommage
- **Français** pour toutes les variables, méthodes, commentaires, messages utilisateur.
- Variables descriptives : `isRegisteredForDiscounts` vs `discount()`.
- Noms de classes, méthodes et fichiers en anglais pour les interfaces/API, mais commentaires en français.

### Structure
- **Modèles** : `app/Models/` avec préfixes métiers (`Etudiant`, `Inscription`, `CIN`, etc.).
- **Controllers** : namespaced par domaine (`App\Http\Controllers\Admin\...`, `App\Http\Controllers\Api\V1\...`, `App\Http\Controllers\Etudiant\...`).
- **FormRequests** : `app/Http/Requests/*Request.php` pour validation/normalisation ; pas de validation lourde dans le contrôleur.
- **Services métier** : `app/Services/` — OCR dans `Services/Identity`, IO/exports dans `Services/Io`.
- **Policies** : `app/Policies/` pour autorisations/Admin.
- **Routes** : préfixées par domaine, nommées (`admin.*`, `etudiant.*`, `api.v1.*`), con Sanctum + throttle dédié.

### Architecture
- **Pas de duplication** : before creating anything (Model, Service, Policy, etc.) : 1. rechercher l'existant ; 2. identifier sa responsabilité ; 3. vérifier réutilisabilité ; 4. justifier toute nouvelle abstraction ; 5. éviter les doublons fonctionnels.
- **Modules** : via `config/modules.php` + `ModuleRegistry` + `ModuleServiceProvider`. Nouveau module = dossier `app/Modules/Xxx/` + vues `resources/views/modules/xxx/`. JAMAIS de colonne en table centrale ni de route dans `routes/web.php`.
- **Architecture CORE + MODULES** : le cœur ne dépend d'aucun module en dur.

### Tests
- Après modification notable : `vendor/bin/pint --dirty`, `php artisan test --compact` ou ciblés (`--filter`).
- Scan CIN/OCR : `php artisan test --filter="IdentityScanTest|PpocrEngineTest|TesseractEngineTest|HfVlmOcrEngineTest"`.
- Si modification comportementale décrite dans docs/ : mettre à jour la doc correspondante et AJOUTER/ADAPTER les tests.

### Sécurité
- **OCR fail-soft** : aucun moteur ne doit lever d'exception vers l'utilisateur — repli = saisie manuelle + alertes.
- **Pièces d'identité** : fichier scanné = TEMPORAIRE privé en `storage/app/private`, supprimé immédiatement après extraction.
- **Validation serveur** : extension + MIME réel via finfo/signature + ré-exécution image + taille max — pas de confiance navigateur.
- **CSRF** : routes web publiques protégées ; API v1 = tokens Sanctum à scopes restreints, webhooks HMAC+timestamp.
- **Journalisation** : AUCUNE donnée OCR/PII dans les logs ; audit admin centralisé.

## Procédure de Review

1. **Lire** le code modifié et les fichiers concernés (modèles, controllers, requests, migrations).
2. **Vérifier** le respect des conventions ci-dessus (nommage français, structure, architecture).
3. **S'assurer** qu'il n'y a pas de duplication fonctionnelle (audit §6, `docs/architecture/source-of-truth.md`).
4. **Vérifier** la présence de tests ciblés et leur passage.
5. **S'assurer** des vérifications Pint (`vendor/bin/pint --dirty`) et des routes (`php artisan route:list`).
6. **Commenter** chaque point soulevant une question ou une non-convention, avec suggestion de correction.
7. **Valider** ou **demander des corrections** avant merge.

**Toujours suivre les conventions existantes** — même structure, approche et nommage que les fichiers voisins.
**Non-duplication (permanent)** : avant de créer quoi que ce soit, rechercher l'existant et justifier toute nouvelle abstraction.