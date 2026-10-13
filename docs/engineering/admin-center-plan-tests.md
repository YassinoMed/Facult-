# Centre d'administration — Plan de tests et critères d'acceptation (13/10/2026)

> Règles : test AVANT la modification quand le comportement est cassé (RED) · suite
> ciblée + voisins + suite complète par boucle · MySQL gated sur scratch UNIQUEMENT
> si le comportement dépend réellement de MySQL · données de test isolées (sqlite
> `:memory:` via `phpunit.xml`) — jamais les bases dev/prod · aucun secret dans les
> fixtures.

## 1. Autorisation (chaque action nouvelle ou touchée)

- Matrice rôle × action : 200/302 attendus vs 403 ; **invité → redirect login** ; route paramétrée d'autrui → **404** (convention scope→404, pas 403).
- Interdits explicites : `admin_faculte` sur toute page du centre hors périmètre ; `consultation` sur toute mutation ; compte suspendu (`statut`) → refus `EnsureBackOffice` à la requête suivante.
- Réutiliser le patron : `ArchiveAuthorizationTest` / `FaculteScopeEcritureTest` / Security 68.

## 2. Validation serveur & UX d'erreur

- Clés registre : bornes de `SettingDefinition` (entier/booléen/texte, `timezone:all`…) ; `attendu` requis → 422 sans écriture ; motif requis sur clé sensible ; no-op = aucune version, aucun audit (test existant à étendre).
- E-mail de test : destinataire = compte appelant (champ libre refusé), échec SMTP = erreur affichée, **jamais de flash « envoyé » sans preuve** (assertion : échec → pas de succès).

## 3. Anti-escalade & isolation

- Auto-attribution de rôle → ignorée ; modification `role`/`faculte_id` via mass assignment → ignorée.
- Dernier super-admin : destroy/reset/statut refusés (422/403), garde sous transaction.
- Sessions : kill-switch super_admin **ne peut pas** toucher ses propres sessions via la voie d'autrui ; self-scope intact ; reset ⇒ sessions cibles purgées, sessions d'autres comptes intactes.
- Inter-comptes : historique sécurité du compte B invisible pour A non super_admin.
- Isolation : toute nouvelle requête porte `faculte_id` en base, jamais en entrée client.

## 4. Messagerie

- Driver : succès/échec/rétry (injection d'erreurs — patron `CanalNotificationExterneSimule`), file `notifications` consommée, `tries` bornés, échec = statut append-only.
- **Secrets/PII** : fixtures avec mots de passe/tokens/CIN factices → `Log::listen` + colonnes `mail_tentatives` : **zéro occurrence** (extension directe du patron `le_journal_ne_contient_jamais_de_secrets`) ; destinataire **haché** en base.
- Défault inaltéré : en dev (`MAIL_MAILER=log`/simule), la suite complète reste **strictement identique** (aucun envoi réseau — assertion : simule consommé).
- Throttle `5,1` testé sur le test d'envoi (429).

## 5. Transactions, concurrence, cache

- Réutiliser les patrons LOOP D/E : course MySQL gated scratch pour tout nouveau chemin d'écriture cache-dépendant ; sinon : transaction + verrou prouvés par tests séquentiels + skip SQLite documenté.
- `parametre_versions` append-only : toute nouvelle écriture ne crée PAS d'UPDATE/DELETE (test de convention étendu aux nouvelles voies).
- Rollback : écriture annulée ⇒ aucune version, aucune notification (fail-soft), cache cohérent.

## 6. Historique & restauration

- Restauration = nouvelle version chaînée + motif ; versions d'un autre paramètre → 404 ; historique jamais modifié (greppable : aucun UPDATE applicatif).
- Audit des nouvelles actions : acteur, cible, résultat — sans PII ni secret (test systématique par boucle).

## 7. Régression des modules voisins

Par boucle : suites du domaine touché + voisins immédiats (ex. mail → `ServicesTransversesTest` + `BackupSentinelTest` + communication ; comptes → auth + Security ; paramètres → 6 suites Settings + course gated). **Suite complète obligatoire avant clôture de boucle** (référence du 13/10 : 3 054 tests, 0 échec).

## 8. MySQL vs sqlite

Suite standard = sqlite `:memory:`. Recours à MySQL (scratch, gated par env dédié type `*_MYSQL=1`, base créée/détruite par le test, garde-fou `assertSame` sur le nom) UNIQUEMENT si la garantie testée n'existe pas en sqlite (course FOR UPDATE, collation) — patron `SettingsCacheRaceMysqlTest`.

## Critères d'acceptation par boucle (généraux)

1. RED→GREEN prouvé pour tout bug corrigé (diff avant/après archivé).
2. Tests ciblés verts + voisins + **suite complète 0 échec** + CI `main` **verte**.
3. Pint propre sur les fichiers touchés.
4. Zéro secret dans : code, fixtures, logs, versions, UI (vérifié par test dédié).
5. Aucune permission/rôle/valeur métier modifiés hors décision enregistrée.
6. Documentation (`docs/engineering/admin-center-*`) mise à jour + commit horodaté.
