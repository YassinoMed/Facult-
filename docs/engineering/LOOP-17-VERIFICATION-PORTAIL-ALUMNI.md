# LOOP 17 — Vérification · Portail alumni

**Date d'exécution :** 27/10/2026
**Mode :** audit préalable + implémentation additive + tests exécutés.
**Référence :** `LOOP-00-AUDIT-DIRECTEUR-UNIREGISTRE.md`, `LOOP-14-20-AUDIT-DIRECTEUR.md` §Lot 17 et règles transverses §3 (ligne 101).

## 1. Verdict

**`PASS_WITH_RESERVATIONS`**

Le registre officiel de diplomation, le consentement opt-in par lien signé, l'annuaire
sous consentement et les agrégats k-anonymes sont livrés et prouvés par **24 tests,
196 assertions**. Aucun diplôme n'est calculé depuis les notes, crédits ou délibérations :
seul l'import officiel écrit dans `diplomes`. Le module reste **désactivé par défaut**
(`MODULE_ALUMNI=false`) et répond 404 quand il est inactif, y compris sur les liens signés.

Les réserves portent sur la source officielle réelle (aucun fichier de diplomation n'a été
fourni), l'absence d'envoi automatique du lien, la rétention et la surface back-office
(JSON uniquement). Aucune n'autorise une activation en production sans arbitrage.

## 2. Arbitrages appliqués

| # | Arbitrage | Application |
|---|---|---|
| D17-1 | La source de diplomation est **officielle**, jamais déduite | `diplomes` n'est écrite que par `ServiceImportDiplomes` (CSV/API). Aucun calcul depuis notes, crédits, résultats ou délibérations. |
| D17-2 | Aucune règle de diplomation inventée | Une ligne sans CIN connu, sans type actif, sans programme de la faculté ou avec une date impossible/future est **rejetée**, jamais corrigée. |
| D17-3 | Consentement **opt-in explicite**, sans création de compte | Lien temporaire signé (`URL::temporarySignedRoute`) ; case non pré-cochée pour un nouveau profil ; journal `consentements_alumni` append-only. |
| D17-4 | Annuaire sur consentement, contacts privés protégés | Visibilités `agregats` / `annuaire` / `annuaire_contact` ; contacts rendus uniquement si `alumni.manage` **et** visibilité `annuaire_contact`. |
| D17-5 | Agrégats protégés contre la réidentification | Seuil `k` configurable (`MODULE_ALUMNI_SEUIL_K`, défaut 5) ; aucun effectif `< k` ; dimension à plusieurs compartiments exige ≥ 2 compartiments publiables ; reste `< k` jamais détaillé. |
| D17-6 | Employeurs sans accès aux dossiers académiques | Aucune route employeur n'existe. Aucune route n'expose notes, crédits, décisions, CIN ou date de naissance. |

## 3. Livré

### Base de données (additive, réversible)

`database/migrations/2026_10_27_120000_creer_tables_alumni.php` :

- `diplomes` — numéro officiel unique, `etudiant_id` (identité non dupliquée),
  faculté, programme, type, date de délivrance, mention, source, référence d'import.
- `imports_diplomes` — référence lisible, nom de fichier, **SHA-256 unique**
  (rejeu = un seul registre), statut, compteurs, rapport sans PII, auteur.
- `profils_alumni` — coordonnées volontairement communiquées, visibilité,
  consentement (date, version, retrait). Aucune donnée académique.
- `consentements_alumni` — journal **append-only** (`opt_in` / `opt_out`),
  clé d'idempotence unique, version du texte, canal, diplôme support.

### Code applicatif

- Modèles : `Diplome`, `ImportDiplomes`, `ProfilAlumni`, `ConsentementAlumni`.
- Services : `ServiceImportDiplomes` (validation, idempotence, rapport sans PII),
  `ServiceAlumni` (lien signé, consentement, retrait, annuaire, agrégats k-anonymes).
- Contrôleurs : `DiplomeController` (back-office), `ConsentementAlumniController`
  (public, lien signé).
- Vue publique accessible : `resources/views/modules/alumni/consentement.blade.php`.
- Routes : `app/Modules/Alumni/Routes/web.php` — **10 routes** vérifiées par
  `php artisan route:list --name=alumni`.
- Config : `modules.alumni` (`active=false`, `seuil_k=5`, `lien_jours=30`,
  `lignes_max=5000`) + chargement dans `ModuleServiceProvider`.
- Permissions : `alumni.view`, `alumni.import`, `alumni.manage` déclarées dans
  `Permissions::TOUTES` et attribuées explicitement (`consultation`/`agent_scolarite`/
  `admin_faculte`/`auditeur` : lecture ; `admin_scolarite`/`super_admin` : import et
  gestion des liens).

### Surface HTTP

| Route | Accès |
|---|---|
| `GET admin/alumni/diplomes` | `alumni.view`, périmètre facultaire |
| `POST admin/alumni/diplomes/import` | `alumni.import`, extension + MIME + taille vérifiés |
| `GET admin/alumni/imports`, `GET admin/alumni/imports/{import}` | `alumni.import` |
| `POST admin/alumni/diplomes/{diplome}/lien-consentement` | `alumni.manage`, périmètre facultaire |
| `GET admin/alumni/annuaire`, `GET admin/alumni/agregats` | `alumni.view`, périmètre facultaire |
| `GET/POST alumni/consentement/{diplome}`, `POST .../retrait` | signé + temporaire, sans compte |

## 4. Invariants prouvés

1. **Registre officiel uniquement.** Un étudiant sans import officiel n'a aucun
   diplôme : le test crée un étudiant, importe le diplôme d'un autre, et vérifie
   qu'aucune ligne n'est calculée pour le premier.
2. **Identité non dupliquée.** `diplomes` ne contient ni `cin`, ni `nom`, ni `prenom` ;
   l'identité reste dans `etudiants`, résolue par `etudiant_id`.
3. **Idempotence de l'import.** Le même fichier (même SHA-256) retourne 200 avec la
   trace existante : un seul import, aucun diplôme supplémentaire.
4. **Rejets tracés sans PII.** Huit motifs sont couverts (`cin_inconnu`,
   `date_invalide`, `date_future`, `numero_duplique`, `numero_deja_importe`,
   `programme_hors_faculte`, `programme_inconnu`, `type_inconnu`, `nombre_colonnes`) ;
   le rapport ne contient que `{ligne, motif}` — le test vérifie l'absence du CIN,
   du nom et de l'e-mail dans la réponse.
5. **Consentement sans compte.** Le formulaire est rendu et soumis via un lien signé,
   sans utilisateur authentifié (`assertGuest()`), avec une case explicite. Le rejeu
   de la même clé ne crée ni second profil ni seconde ligne de journal.
6. **Retrait effectif et idempotent.** Le profil est marqué `retire_le`, sa visibilité
   repasse à `agregats` et il disparaît de l'annuaire ; le second retrait n'ajoute
   aucune ligne.
7. **Annuaire minimal.** Seuls les consentants visibles apparaissent. Les coordonnées
   ne sortent que pour `alumni.manage` + `annuaire_contact` ; un simple `alumni.view`
   ne les voit jamais. Aucun `etudiant_id` ni CIN dans la réponse.
8. **Isolation facultaire.** Registre, annuaire et agrégats sont bornés par
   `faculteRestreinteId()`. Un admin de faculté A ne voit ni les diplômes ni les
   profils de B ; ses agrégats ne comptent que son périmètre.
9. **K-anonymat.** Quatre cas prouvés : compartiment unique `< k` masqué ;
   compartiment unique `≥ k` publié ; dimension à un seul compartiment publiable
   masquée (soustraction impossible) ; deux compartiments `≥ k` publiés avec reste
   `< k` non détaillé et reste `≥ k` annoncé globalement.
10. **Permissions distinctes.** `alumni.view` (lecture), `alumni.import` (écriture du
    registre), `alumni.manage` (liens et contacts) sont testées séparément : un agent
    de scolarité est refusé (403) sur l'import et la génération de lien ; un admin de
    faculté aussi ; un profil `consultation` ne fait que lire.
11. **Journal append-only.** Toute mise à jour ou suppression d'une ligne de
    `consentements_alumni` lève une `DomainException`.
12. **Module désactivé.** 404 sur le registre, les agrégats et le lien signé.

## 5. Preuves exécutées

| Commande | Résultat |
|---|---|
| `vendor/bin/phpunit --configuration=phpunit.xml --filter="AlumniTest"` | **24/24 tests, 196 assertions** |
| `vendor/bin/phpunit --configuration=phpunit.xml --filter="PermissionsCoherenceTest\|RolesPhaseETest\|AttributionRoleTest\|ModuleAideTest\|CourrielModuleTest"` | **46/46 tests, 196 assertions** (non-régression RBAC/modules) |
| `vendor/bin/pint --test database/migrations/2026_10_27_120000_creer_tables_alumni.php app/Modules/Alumni tests/Feature/AlumniTest.php` | **passed** |
| `php artisan route:list --name=alumni` | **10 routes** |
| `php -l` sur tous les fichiers créés/modifiés | **aucune erreur de syntaxe** |

Les tests s'exécutent sur SQLite isolé (`phpunit.xml`, base `:memory:`), sans toucher
MySQL ni la base de production. Aucune migration n'a été appliquée hors test.

## 6. Réserves

- **R-17/1 — Source officielle non fournie.** Aucun fichier réel de diplomation n'a
  été remis. Le format CSV est défini et testé, mais le module ne doit pas être activé
  avant confirmation du fichier, de son émetteur et de sa cadence.
- **R-17/2 — Pas d'envoi automatique du lien.** `alumni.manage` génère l'URL signée ;
  sa transmission au diplômé reste manuelle. Aucune notification e-mail n'est
  revendiquée. Le lien initial vaut 30 jours ; les URLs POST du formulaire valent 2 h.
- **R-17/3 — Pas de journal d'audit pour la génération de lien.** L'import
  (`imports_diplomes.importe_par`) et le consentement (`consentements_alumni`) sont
  tracés ; la génération d'un lien ne l'est pas. À arbitrer avec la gouvernance.
- **R-17/4 — Rétention non arbitrée.** Le retrait retire de l'annuaire mais ne purge
  ni le profil ni les imports. Aucune politique de conservation/suppression n'est
  inventée ; elle doit être validée avant production.
- **R-17/5 — Surface back-office JSON uniquement.** Seule la page publique de
  consentement est un écran. Les routes d'administration renvoient du JSON, sans UI.
- **R-17/6 — Agrégats institutionnels vs consentement.** `par_faculte`,
  `par_programme`, `par_annee` et `par_type_diplome` comptent le registre officiel
  (fait institutionnel) ; seul `par_secteur` ne compte que les profils consentants.
  Si la gouvernance exige le consentement pour tous les agrégats, l'arbitrage est à
  expliciter.
- **R-17/7 — Pas de route employeur.** L'absence d'accès employeur est garantie par
  l'absence de route, pas par une matrice de droits employeur (qui n'existe pas).
- **R-17/8 — Interprétation du compartiment unique.** Un compartiment unique `≥ k`
  représentant tout le périmètre est publié. Une gouvernance plus stricte pourrait
  exiger deux compartiments dans tous les cas ; le comportement est isolé dans
  `ServiceAlumni::ventiler()`.
- **R-17/9 — Connecteur API non exposé.** Le service accepte `source=api`, mais aucune
  route API dédiée n'est livrée ; seule l'import CSV est exposée.

## 7. Statut final

**`PASS_WITH_RESERVATIONS`** — les trois règles transverses Alumni sont prouvées :
coordonnées privées sous consentement opt-in, aucune route employeur vers un dossier
academic, agrégats k-anonymes. Le module reste désactivé par défaut et ne doit pas être
activé en production avant les arbitrages R-17/1, R-17/3 et R-17/4.
