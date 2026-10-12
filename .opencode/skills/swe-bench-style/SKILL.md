---
name: swe-bench-style
description: Style SWE-agent / SWE-bench pour les corrections multi-fichiers sur le projet inscription-etudiants.
---

# SWE-bench Style Skill

Approche structurée inspirée de SWE-agent/SWE-bench pour traiter les issues impliquant plusieurs fichiers dans le projet Laravel inscription-etudiants.

## Principe

Lorsqu'une issue nécessite des modifications dans plusieurs fichiers (modèle, contrôleur, demande de validation, routes, tests, vue), suivre une procédure pas-à-pas garantissant la cohérence et le passage des tests.

## Étapes

### 1. Analyser l'issue et identifier tous les fichiers impactés

- Lire l'issue complète.
- Repérer les fichiers concernés (modèles, controllers, requests, routes, tests, vues).
- Consulter `docs/` pour les règles métier liées (§N).
- Vérifier les tests existants qui pourraient être affectés.

### 2. Créer un plan de modifications

Pour chaque fichier identifié, noter :
- **Changement exact** à apporter (ajout, modification, suppression).
- **Raison** du changement (règle métier, correction de bug, nouvelle fonctionnalité).
- **Dépendances** avec les autres fichiers.
- **Test(s) à faire passer** après la modification.

### 3. Mettre en œuvre par fichier (ordre recommandé)

**Ordre suggéré** (adaptable selon le cas) :

1. **Modèles/Eloquent** — modifier la structure si nécessaire (ajout de colonnes, relations, scopes).
2. **Requêtes de validation** — mettre à jour les `FormRequests` si les règles changent.
3. **Controllers** — adapter la logique pour utiliser les nouveaux/modifiés modèles ou règles.
4. **Routes** — ajouter/modifier les routes si de nouveaux endpoints sont nécessaires.
5. **Vues** — mettre à jour les vues Blade si l'interface change.
6. **Tests** — écrire ou modifier les tests feature/unit pour couvrir le nouveau comportement.
7. **Documentation** — si pertinent, mettre à jour les docs `docs/`.

**Important** : Ne jamais modifier un fichier sans avoir au moins identifié tous les autres fichiers impactés. La cohérence entre tous les fichiers est primordiale.

### 4. Vérifier la cohérence

Après avoir apporté toutes les modifications :

- Exécuter les tests concernés : `php artisan test --filter="NomDuTest"` ou `vendor/bin/phpunit --filter="testName"`.
- Vérifier `vendor/bin/pint --dirty` pour le formatage PHP.
- Lancer `php artisan route:list` si des routes ont été modifiées.
- S'assurer qu'aucun test existant n'a régressé.

### 5. Valider et committer

- S'assurer que tous les tests passent.
- Faire un commit claire décrivant l'ensemble des modifications.
- Si l'issue ferme un ticket, référencer le numéro dans le message de commit.

## Exemple de workflow pour ce projet

Pour une issue sur les "périodes d'inscription" (existant dans les LOOPs du projet) :

1. Identifier les fichiers : `config/periodes.php`, `routes/inscriptions.php`, `Http/Controllers/InscriptionController.php`, `Http/Requests/InscriptionRequest.php`, `database/migrations/...`, tests `PeriodeInscriptionTest*`.
2. Planifier les changements dans chaque fichier en respectant les règles Phase 3/4/5 du projet.
3. Appliquer les modifications fichier par fichier.
4. Exécuter `php artisan test --filter="PeriodeInscription"` pour valider.
5. Vérifier Pint et les routes.
6. Committer avec description claire de tous les changements.

## Bonnes pratiques spécifiques à ce projet

- **Toujours suivre les conventions existantes** : même structure, approche et nommage que les fichiers voisins.
- **Non-duplication permanente** : avant de créer, rechercher l'existant, identifier responsabilité, vérifier réutilisabilité, justifier abstraction, éviter doublons (preuves : audit §6, `docs/architecture/source-of-truth.md`).
- **Tests d'abord** : écrire/adapter les tests avant ou pendant les modifications, pas après.
- **Documentation** : si comportement métier modifié, mettre à jour la doc correspondante (`docs/ARCHIVES.md`, `docs/API.md`, `docs/AUDIT-SECURITE.md`, etc.).
- **Pint** : `vendor/bin/pint --dirty` après toute modification PHP importante.
- **OCR fail-soft** : aucun moteur ne doit lever d'exception vers l'utilisateur — repli = saisie manuelle + alertes.
- **Pièces d'identité** : fichier scanné = temporaire privé, supprimé immédiatement après extraction.
- **Sécurité** : validation serveur profonde (extension + MIME réel via finfo + image ré-exécutable + taille max), pas de confiance navigateur.

## Checklist avant validation

- [ ] Tous les fichiers impactés ont été identifiés et modifiés
- [ ] Tests concernés passent (feature + unit)
- [ ] `vendor/bin/pint --dirty` OK (PHP propre)
- [ ] `php artisan route:list` OK (si routes modifiées)
- [ ] Pas de régression sur les tests existants
- [ ] Documentation mise à jour si comportement métier changé
- [ ] Commit claire avec description de l'ensemble des modifications

---

**Note** : Ce skill est conçu pour être chargé avec l'outil `skill` d'OpenCode et utilisé comme guide structuré lorsqu'on travaille sur des issues impliquant plusieurs fichiers dans ce projet Laravel complexe.