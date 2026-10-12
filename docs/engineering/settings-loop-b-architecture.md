# SETTINGS — Architecture cible (LOOP B, 12/10/2026)

Principe : **réutiliser, ne pas dupliquer**. Correspondance propositions (§4
mission) → existant :

| Proposé | Existant réutilisé | Manquant (LOOP C/F) |
|---|---|---|
| `SettingDefinition` | `Parametre` (`cle` unique, `type`, `categorie`, `description`) + catalogue en seed | Typage strict par clé (registre §2) |
| `SettingsService` | `ParametreService` (`get`/`set`/`valeursParCategorie`, cache + invalidation) | `lockForUpdate` + 409 |
| `SettingsValidator` | Match par `type` dans `ParametreController::update` | Registre de règles par clé |
| `SettingsPolicy` | `ParametrePolicy` (`settings.view/manage`, super_admin seul) | Inchangée |
| `SettingsAuditService` | Log `parametre.modifie` + `updated_by` | Table `parametre_versions` append-only (LOOP F) |
| `SettingsCache` | `Cache::rememberForever` + `forget` | Rien (suffisant, repli DB) |

## 1. Définitions par clé (registre, portée A par défaut)

| Clé | Type | Portée | Défaut | Règles | Propriétaire |
|---|---|---|---|---|---|
| `etablissement.nom` | texte | A | `Université` | string max:2000 | Direction |
| `etablissement.fuseau` | texte | A | `Africa/Casablanca` | timezone valide | Direction |
| `academique.seuil_validation` | entier | A | `10` | integer 0..20, indicatif | Scolarité |
| `assiduite.seuil_alerte` | entier | A | `5` | integer ≥0 | Scolarité |
| `notifications.purge_jours` | entier | A | `180` | integer ≥30 | Exploitation |
| `interface.pagination` | entier | A | `15` | integer 5..100, indicatif | Exploitation |
| `sauvegarde.plan.*` | mixte | A | cf. seeds | selon clé | Exploitation |

Aucune clé B : toute surcharge facultaire exige preuve métier + policy +
tests (matrice LOOP A). Clés C/D/E/F : jamais dans `parametres`.

## 2. Résolution et accès

Lecture : `get(cle, defaut)` → cache → DB → défaut ; absent = défaut, jamais
d'exception. Écriture : clé whitelistée (404 sinon), type validé (422),
`lockForUpdate` + 409 (LOOP F), audit avant/après sans secret, invalidation cache.
Accès : lecture `settings.view`, écriture `settings.manage` (super_admin seul
aujourd'hui ; tout élargissement = décision + matrice de tests).

## 3. Versions et restauration (design, LOOP F)

`parametre_versions` : `id`, `parametre_id`, `valeur`, `acteur_id`, `portee`,
`motif`, `created_at` (append-only, jamais d'UPDATE). Restauration = `set()`
vers une version antérieure = **nouvelle version** (traçabilité totale).
Concurrence : relecture verrouillée + 409 si `updated_at` a bougé.

## 4. Non-objectifs confirmés

Pas de pilotage DB des `MODULE_*` (flags env, doc dépendances) ; pas de
kill-switch sécurité ; pas de secrets ; pas de B sans preuve ; pas de blob
JSON (schéma par clé).
