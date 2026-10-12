---
name: auto-fix
description: Boucle Test → Debug → Patch jusqu'à ce que les tests passent. Inspiré de Aider, OpenHands et code-repair-demo.
---

# Auto-Fix Skill

Quand l'utilisateur demande de corriger un bug ou que des tests échouent :

1. **Lire** le code concerné et les fichiers de tests.
2. **Lancer** les tests (PHPUnit) : `php artisan test --filter="NomDuTest"` ou `vendor/bin/phpunit --filter="testName"`.
3. Si échec :
   - Analyser le message d'erreur et la stacktrace.
   - Proposer un patch minimal en ciblant uniquement la cause racine.
   - Appliquer le patch avec l'outil `edit` ou `write`.
   - Relancer les tests.
4. **Répéter** jusqu'à succès (max 5 itérations).
5. Faire un commit clair avec message descriptif (conventionnel si possible).

**Pour ce projet Laravel :**
- Préférer `php artisan test --compact` ou `vendor/bin/phpunit` pour les tests feature/unit.
- Après modification Eloquent, vérifier l'absence de N+1 (eager loading `with([...])`, pas de requêtes en boucle).
- Vérifier `vendor/bin/pint --dirty` après toute modification PHP importante.
- Si route modifiée : `php artisan route:list` pour s'assurer qu'il n'y a pas de régression.

Toujours montrer le diff avant d'appliquer et expliquer chaque correction.