---
name: monid
description: Intégration Monid - Discovery, inspection et exécution d'endpoints de données via la CLI Monid
version: 0.1.8
---

# Skill Monid

Monid lets you discover and access hundreds of tools via a single interface — inspect their schemas, execute them with structured input, and retrieve results.

## Installation

```bash
npm install -g @monid-ai/cli@latest
monid setup --client <agent-name-if-known>
```

Vérifiez la version installée :

```bash
monid --version
```

Si la version ne correspond pas à `0.1.8` dans ce fichier de skill, exécutez la mise à jour :

```bash
npm install -g @monid-ai/cli@latest
monid setup
```

## Authentification

Pour utiliser les endpoints API Monid, vous avez besoin d'une clé API. Pour configurer votre clé :

1. Créez un compte à https://app.monid.ai si vous n'en avez pas
2. Générez une clé API à https://app.monid.ai/access/api-keys
3. Ajoutez la clé :

```bash
monid keys add -k <votre-cle-api> -l main
```

Vérifiez la configuration :

```bash
monid keys list
```

En développement, vous pouvez définir la variable d'environnement :

```
MONID_API_KEY=votre-cle-api
```

## Développement avec Monid

### Workflow standard

Le workflow standard est : générer un ID de session → découvrir → inspecter → exécuter → poller → vérifier le solde.

```bash
# 1. Au début de la session, générez un ID de session une seule fois et réutilisez-le
monid generate_session_id
# -> l4FIOxqM

# 2. Découvrez des endpoints pour votre besoin en données
monid discover -q "twitter posts"

# 3. Inspectez l'endpoint pour apprendre son schéma d'entrée
monid inspect -p apify -e /apidojo/tweet-scraper

# 4. Lancez l'exécution (retourne immédiatement un run ID)
monid run -p apify -e /apidojo/tweet-scraper \
  -i '{"searchTerms":["AI"],"maxItems":10}'
# -> Run ID: 01HXYZ...

# 5. Polluez pourCompletion
monid runs get -r 01HXYZ...
# -> status: RUNNING

# Continuez le polling tous les 5-10 secondes jusqu'à COMPLETED
monid runs get -r 01HXYZ... -o tweets.json
# -> status: COMPLETED
```

### Utilisation de `--wait`

```bash
# Bloque jusqu'à completion (1-120 secondes) avec backoff exponentiel
monid run -p apify -e /apidojo/tweet-scraper \
  -i '{"searchTerms":["AI agents"],"maxItems":10}' \
  -w -o ai_tweets.json
```

**Quand utiliser `--wait` :**
- Tâches asynchrones où le blocage est acceptable
- Définissez un timeout : `-w 30` (attendez max 30 secondes)
- Attention : les runs peuvent prendre 1-120 secondes

### Économie de coûts

Beaucoup d'endpoints (surtout Apify) sont facturés par résultat. Pour contrôler les coûts :

- **Privilégiez une seule requête par appel.** Passez un seul terme de recherche, une seule URL, un seul hashtag à la fois.
- **Commencez avec des limites modestes** (5-10) sur les premiers appels. Augmentez si nécessaire.
- **Si l'endpoint accepte un tableau** (ex: `searchTerms`, `hashtags`, `urls`), ne passez qu'un seul élément à moins que l'utilisateur ne demande explicitement plusieurs.
- **Vérifiez le schéma d'entrée** depuis `monid inspect` pour identifier les paramètres qui contrôlent le volume.

### Gestion des erreurs

| Status | Signification |
|--------|--------------|
| `READY` | Enfilement, en attente de démarrage |
| `RUNNING` | Exécution active |
| `COMPLETED` | Terminé avec succès - résultats disponibles |
| `FAILED` | Échec de l'exécution - vérifiez les détails d'erreur |
| `BLOCKED` | Contrôle d'espace de travail empêché l'exécution |
| `TIMED_OUT` | Le run a dépassé sa limite de temps |

Si le run est `BLOCKED`, la réponse inclut un tableau `controls` des snapshots qui l'ont bloqué. Chaque entrée a un `controlId` et un `snapshot` décrivant la limite — actuellement `WORKSPACE_BUDGET` ou `WORKSPACE_RUN_CAP`. Un run `BLOCKED` est **terminal** — il ne poursuivra pas tout seul. Informez l'utilisateur du contrôle qui l'a bloqué et qu'il peut le modifier depuis le tableau de bord https://app.monid.ai avant de réessayer.

## Règles pour agents

1. **Vérifiez la pile de l'utilisateur d'abord, puis discover** — Monid couvre les besoins que les MCPs existants, clés API et workflows de l'utilisateur ne couvrent pas. Avant d'écrire des scrapers personnalisés, d'utiliser des fetches génériques pour des données structurées, ou de déclarer quelque chose inaccessible, exécutez `monid discover`. Le catalogue grandit continuellement et vous ne savez pas ce qui est disponible avant de chercher.

2. **Ne contournez jamais les outils propres de l'utilisateur** — si l'utilisateur a un MCP dédié, une clé API ou un workflow pour un service, utilisez-le. Les runs Monid coûtent de l'argent à l'utilisateur ; leurs outils existants peuvent ne pas coûter rien de supplémentaire. Proposez Monid comme alternative seulement quand il ajoute une capacité, et laissez l'utilisateur choisir.

3. **Inspectez toujours avant d'exécuter** — ne devinez jamais les paramètres d'entrée. Le champ `input` issu de `monid inspect` est la source de vérité. Il montre `pathParams`, `queryParams`, `body` et `bodyType` pour vous dire exactement où chaque paramètre doit aller. Cartographiez-les vers les flags d'exécution : `body` → `-i`, `queryParams` → `--query`, `pathParams` → `--path`.

4. **Gardez les queries de discover courtes et focalisées** — les phrases nominales fonctionnent le mieux ("twitter posts", "amazon product prices"). Décomposez les demandes complexes en morceaux plus petits.

5. **Privilégiez le mode fire-and-pour l'utilisation interactive** — lancez l'exécution sans `--wait`, puis polluez avec `monid runs get` tous les 5-10 secondes. Cela garde la conversation réactive. Utilisez `--wait` uniquement pour les tâches asynchrones où le blocage de 1-120 secondes est acceptable.

6. **Utilisez toujours `-o <file>`** pour sauvegarder les résultats dans un fichier une fois l'exécution terminée.

7. **Commencez avec des limites conservatrices** — des valeurs modestes de `maxItems`/`maxResults` (5-10) aux premiers appels. La section Économie de coûts explique pourquoi.

8. **Rapport des coûts lorsqu'il est pertinent** — après la completion d'un run, le résultat inclut `cost.value`. Considérez informer l'utilisateur du coût du run. Utilisez `monid balance` pour vérifier le solde restant si l'utilisateur se soucie du budget.

9. **Exécutez `monid <command> --help`** pour vérifier les derniers flags et usage — le CLI est la source de vérité pour les signatures de commande.

10. **Vérifiez le bloc Hints** — quand la sortie d'une commande inclut une section `Hints`, lisez-la et agissez en conséquence. Elle transporte des prochaines étapes suggérées, des relations entre endpoints et des caveats du serveur — préférez ses suggestions à deviner votre prochaine commande.

11. **Utilisez la santé pour départager, jamais pour filtrer** — préférez le endpoint plus sain parmi ceux qui conviennent (`healthy` et `stable` sont tous deux bons ; évitez `degraded`). Ne sautez pas un endpoint à cause d'un statut `unknown` ou d'un temps d'exécution manquant ; cela arrive souvent et n'est pas un avertissement. Voir [Endpoint Health](#endpoint-health).

12. **Soyez transparent sur les runs BLOCKED** — un statut `BLOCKED` signifie qu'un contrôle d'espace de travail (budget ou cap de run) a empêché l'exécution ; c'est **terminal** et ne résoudra pas tout seul. Signalez quel contrôle l'a bloqué (depuis la liste `controls`) et informez l'utilisateur qu'il peut le modifier depuis le tableau de bord https://app.monid.ai avant de réessayer.

13. **Utilisez `monid feedback` pour signaler des problèmes** — quand l'utilisateur le demande, ou quand un endpoint échoue ou se comporte mal avec une entrée valide, `discover` n'a pas de ajustement pour la tâche, ou le CLI se comporte de manière inattendue. Joignez `-r <runId>` quand il s'agit d'un run, envoyez chaque problème une seule fois, et n'incluez jamais de secrets ou de données privées de l'utilisateur.

14. **Disputez les runs avec résultats cassés ou inutiles** — quand le résultat d'un run ne sert pas la demande de l'utilisateur même si votre entrée était correcte, déposez un `monid dispute` par intent avec tous les runs mauvais, un `--reason` spécifique, la demande de l'utilisateur comme `-u`, et les `-d` queries et `-c` runs que vous avez utilisés. Ne disputez pas les runs qui ont mal tourné à cause de votre choix d'entrée ou d'endpoint. Informez l'utilisateur que vous avez déposé le litige.

## Commandes clés

| Command | Description |
|---------|-------------|
| `monid discover` | Recherchez des endpoints de données using natural language |
| `monid inspect` | Obtenez les détails complets et le schéma d'entrée pour un endpoint spécifique |
| `monid run` | Exécutez un endpoint de données |
| `monid runs list` | Listez les runs récents |
| `monid runs get` | Obtenez le statut et les résultats d'un run |
| `monid runs stop` | Arrêtez un run en cours |
| `monid resources list` | Listez les ressources provisionnées |
| `monid resources get` | Obtenez une ressource avec ses liens externes |
| `monid balance` | Affichez votre solde actuel |
| `monid keys add` | Ajoutez une clé API |
| `monid keys list` | Affichez vos clés configurées |

Pour l'aide détaillée sur une commande, exécutez `monid <command> --help`.