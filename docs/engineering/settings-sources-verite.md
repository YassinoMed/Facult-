# SETTINGS — Sources de vérité (LOOP A, 12/10/2026)

Règle : en cas de conflit, l'ordre effectif constaté est
**env/déploiement > table `parametres` > config Laravel > défaut code**.
Aucune surcharge facultaire n'existe aujourd'hui (tout F est porté par les
modules métiers, pas par un mécanisme générique).

## 1. Table `parametres` (vérité applicative modifiable UI)

- Source : `parametres` (`cle` unique) ; lecture `ParametreService::get()`
  (cache `parametre.{cle}`, `rememberForever`, invalidation au `set()`).
- Écriture : `ParametreController::update()` → `ParametreService::set()`
  (`firstOrFail` : **clé inconnue = 404, jamais de création sauvage**) +
  log `parametre.modifie` (acteur, clé, avant/après — secrets interdits par construction).
- Consommateurs : `BackupSchedulerService` (`sauvegarde.plan.*`),
  seuils assiduité, purge notifications, pagination indicative, fuseau.
- Limites prouvées : pas de versionnage (écrasement), pas de verrou
  concurrentiel, `Cache::rememberForever` sans TTL (panne cache = relecture DB,
  pas d'erreur — `Cache::rememberForever` retombe sur la closure).

## 2. Environnement / déploiement (vérité ops, NC)

- `.env` + `config/*.php` (`env()` avec défauts) : secrets, connexions,
  `MODULE_*`, throttles, timeouts, binaires OCR, `SANCTUM_*`, `SESSION_*`.
- Preuve : `php artisan config:show` reflète l'env ; `.env.example` documente
  les défauts (dont `MODULE_*=false` sauf REFERENTIEL/ENSEIGNEMENT/VERSIONING).
- Divergence constatée `.env` dev vs `.env.example` (C4) : 6 `MODULE_*=true`
  en dev (`ATTENDANCE`, `STUDENT_DOSSIERS`, `ONLINE_REQUESTS`, `SCHEDULE`,
  `EVALUATION`, `SENTINEL`) — **contexte dev, jamais une preuve prod**.

## 3. Modules métiers (vérité métier versionnée)

- Règles d'évaluation/délibération, contraintes surveillances (révisions
  append-only + `config_snapshot` sur runs), gabarits notifications
  versionnés, périodes d'inscription, matrice pièces (`config` + GED) :
  chacun son service, sa policy, son audit. SETTINGS ne doit que **lire**
  (diagnostic) ou **déléguer** (lien profond), jamais dupliquer.

## 4. Individuel (vérité utilisateur, hors SETTINGS)

- `user_preferences`, `user_favoris`, `notification_preferences` : périmètre
  session/utilisateur, jamais global ni facultaire. Exclure de SETTINGS.

## 5. Conflits et résolution cible

- `false`/`0` explicites : `valeurTypee()` distingue (`booleen` : liste
  fermée `0/1/vrai/faux/true/false/oui/non` ; `entier` : `(int)` ; absent →
  `$defaut` du `get()`, jamais d'exception).
- Erreur : clé inconnue en écriture = 404 ; type incohérent = 422 (contrôleur).
- Doublons à éviter : `interface.pagination` (indicatif) vs `lignes_par_page`
  des préférences (effectif par utilisateur) — documenter, ne pas fusionner
  sans preuve d'usage.
