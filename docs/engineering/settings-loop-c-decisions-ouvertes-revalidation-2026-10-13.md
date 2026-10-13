# LOOP C — Décisions ouvertes · REVALIDATION (13/10/2026)

> Suite de `settings-loop-c-decisions-ouvertes.md` (12/10 23:34). Statuts
> revérifiés contre le code et les tests du 13/10.

## Statut des décisions d'origine

| ID | Décision | Statut 13/10 | Preuve |
|---|---|---|---|
| D-C-01 | Corriger R-C-01 (`afterCommit`) | **TRANCHÉE : option (a) implémentée et prouvée** | fix présent + `SettingsCacheRaceMysqlTest` 1/1 vert (13/10) |
| D-C-02 | Preuve course MySQL paramètres | **TRANCHÉE : option (a) exécutée** | 2/2 gatés verts sur scratch MySQL (13/10) |
| D-C-03 | Registre vs `sauvegarde.*` | **STATU QUO validé par faits** — registre aux 6 clés institutionnelles, `sauvegarde.plan.*` gérées par l'UI dédiée `SauvegardesController` (validation propre `SauvegardesController:168-200`), repli par type couvert par test | 22/22 suites Settings (13/10) |
| D-C-04 | `sentinel.*` seedés ou non | **TRANCHÉE : seedées** | `2026_10_12_231817_ajouter_parametres_sentinel.php` |
| D-C-05 | Première clé de portée facultaire | **TOUJOURS REFUSÉE (confirmé 13/10)** | aucun consommateur, aucune colonne `faculte_id` dans les migrations `parametres`/`parametre_versions`/`sentinel` |

## Décisions ouvertes NOUVELLES (issues de la revalidation)

| ID | Décision | Options | Recommandation |
|---|---|---|---|
| D-C-06 | Sort des 9 clés legacy `sauvegarde.*` sans usage prouvé (R-C-07) | (a) conserver telles quelles (compat, zéro risque) ; (b) déprécier (documenter « non lues, conservées ») ; (c) supprimer via migration (destructif — exige preuve d'absence totale + accord métier) | (a) court terme, (b) si on documente la dette ; **jamais (c) sans validation humaine** |
| D-C-07 | Aligner `Parametre::CATEGORIES`/`TYPES` avec la donnée (`sauvegardes`, `presences`, `chaine`) (R-C-08) | (a) ajouter les catégories manquantes + type `chaine`→alias ; (b) convertir les lignes legacy vers `texte`/`entier` | (a) documentaire et sans risque ; à trancher avec le chantier Settings UI |
| D-C-08 | Effet métier réel de `plan.alerter_*` | (a) câbler dans Notifications/CommunicationsDispatcher ; (b) retirer des écritures si sans effet | Nécessite décision produit — preuve : lecture uniquement dans `plan()` |
| D-C-09 | `restaurer()` dernier-gagnant (R-C-06) | (a) statu quo (traçabilité totale suffit) ; (b) exiger `attendu` même pour restauration | Statu quo acceptable si documenté (fait) — décision maintenue ouverte pour gouvernance |

## C4 — Registre et validation (synthèse vérifiée)

- Registre (`SettingDefinition::catalogue`) : 6 clés, toutes seedées par
  `2026_09_30_000004` ; types, portée `global`, règles bornées
  (min/max, timezone), sensibilité, propriétaire.
- Divergences identifiées : voir R-C-03/R-C-07/R-C-08/R-C-09
  (risques-revalidation). Aucune clé ajoutée.
- Repli par type pour clés hors registre : **couvert par test**
  (`test_cle_hors_registre_repli_par_type`) — pas de différence cachée
  entre registre et repli au-delà des bornes métier (attendu).

## C5 — Autorisations (code + tests exécutés)

- Routes : `can:settings.view` (index) · `can:settings.manage` (update,
  restaurer ; throttles 20/10) — `routes/web.php:920-925`.
- Policy : `viewAny` = `settings.view`, `update` = `settings.manage`.
- Matrice : `settings.view|manage` **uniquement dans TOUTES** → détenteur
  effectif = `super_admin` seul ; `configuration.manage` = super_admin +
  admin_scolarite (distinction prouvée `Permissions.php:294`).
- Tests observés (13/10) : matrice accès lecture/écriture (B), 404/422 (B),
  sidebar réservée super_admin (D), restauration réservée super_admin (F),
  journal sans secret (B) — 22/22 verts.

## C6 — Portée facultaire : statut confirmé

Aucune surcharge facultaire implémentée (aucune colonne, aucune clé, aucun
consommateur). Prérequis obligatoires AVANT toute première clé de portée
facultaire (rappel mission) : justification métier écrite · propriétaire ·
résolution global→faculté · identification serveur · autorisations+isolation ·
invalidation de cache par portée · tests IDOR/interfacultaires.
**Aucune colonne `faculte_id`, migration ou clé ajoutée (13/10).**

## Critères d'acceptation pour un éventuel prochain incrément

1. R-C-07 tranché humainement (D-C-06) avant tout ajout de clé.
2. Tout ajout de clé = registre + test de validation + consommateur prouvé.
3. Zéro régression : 22/22 suites Settings + 2/2 gatés MySQL + Pint.
4. Jamais de suppression silencieuse de paramètre de sauvegarde.
