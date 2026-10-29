# RAPPORT — LOOP-INSCRIPTION-UNIQUE-ANNUELLE-01

**Objectif** : garantir qu'un étudiant possède AU PLUS une inscription
académique par année universitaire, sur TOUS les points d'entrée, avec
contrainte en base, sûreté de concurrence, tests et verdict.

## Baseline (phase 1 — audit LAN, aucune écriture)

Clé métier réelle : `etudiants.id` + `annees_universitaires.id` sur
`inscriptions_academiques`. Index UNIQUE `uniq_inscription_etudiant_annee`
(`etudiant_id`, `annee_universitaire_id`) présent dans la migration
d'origine `2026_09_24_100005_create_inscriptions_academiques_table` et
dans la base de dev — jamais supprimé depuis. **Aucune suppression
logique** sur cette table (pas de `deleted_at`) : la forme adaptée de la
contrainte est donc l'UNIQUE simple — une suppression est physique et
libère la paire. Base de dev au moment de l'audit : 2 073 inscriptions /
2 037 étudiants / **0 doublon** ; statuts possibles
`valide|en_cours|ajourne|abandon` (répartition actuelle : valide 2061,
en_cours 11, ajourne 1) ; années 2021-2022 → 2026-2027. Inventaire
exhaustif : 8 chemins de création directs (+ seeders idempotents
`firstOrCreate`) ; `UpdateInscriptionAcademiqueRequest` et les services de
correction ne touchent jamais `etudiant_id`/`annee_universitaire_id`.

## Règle centralisée (phase 2 — DEV)

`InscriptionService` porte la règle unique :
`MESSAGE_INSCRIPTION_ANNUELLE_EXISTANTE` (« Cet étudiant possède déjà une
inscription pour cette année universitaire. » — message utilisateur
explicite, **jamais de donnée personnelle**), `inscriptionAnnuelle()`
(lecture), `verifierUniciteAnnuelle()` (refus métier),
`creerInscriptionAnnuelle()` (pré-contrôle + `catch
UniqueConstraintViolationException` → `DomainException`, ids
autoritaires). Tous les chemins de création y sont routés :

| Chemin | Fichier | Comportement en doublon |
|---|---|---|
| Formulaire admin | `Admin/InscriptionAcademiqueController::store` | erreur de formulaire `annee_universitaire_id`, saisie conservée, message = constante |
| Réinscription | `InscriptionService::reinscrire` | refus métier (verrou étudiant + garde période conservés) |
| Régularisation en masse | `InscriptionService::regulariserInscriptionsManquantes` | insertion groupée + repli par ligne ; conflits comptés dans `erreurs` |
| Archives | `ArchiveService::creerInscription` | refus métier (plus de catch d'import interne) |
| Affectation automatique | `AffectationAutomatiqueService::tenterPourEtudiant` | idempotent : conflit absorbé, ré-lecture, jamais de doublon |
| Import Io legacy | `InscriptionsAcademiquesImporter` | ligne ignorée (doublon) |
| DataExchange inscriptions | `InscriptionAcademiqueDefinition::persister` | mise à jour si existe ; course → `IoException` contrôlée (« relancez l'import ») |
| DataExchange progression | `ProgressionDefinition::persister` | upsert sûr (lecture + création centrale + repli) |

`StoreInscriptionAcademiqueRequest` : message de validation = même
constante (aucune divergence de libellé).

## Base de données (migration de garde)

Nouvelle migration **idempotente**
`2026_10_18_100000_verifier_unicite_inscription_annuelle` : vérifie la
présence de l'index UNIQUE (via `Schema::getIndexes`), échoue
explicitement en listant des paires **anonymisées** si des doublons
existaient, `down()` = no-op. Aucune donnée supprimée, fusionnée ni
réaffectée. **Non encore appliquée à la base de dev** (base laissée en
lecture seule pendant la mission ; l'index, lui, y est déjà actif) — à
exécuter au prochain `php artisan migrate`.

## Concurrence (phase 6)

`tests/Feature/UniciteInscriptionConcurrenceMysqlTest` (base dédiée
`inscription_uniq_test`, opt-in `CONCURRENCE_MYSQL=1`, refus immédiat
toute autre base) : 6 workers simultanés sur la même paire → **exactement
1 succès + 5 refus métier**, 1 seule ligne ; chemin admin complet (audit +
semestres) sur 4 workers → 1 inscription ; deux étudiants différents même
année → tous deux autorisés (pas de sur-blocage).

`tests/TestCase.php` : garde-fou de sécurité inchangé en substance — seul
ajout, une exemption **étroite et explicite** (opt-in `CONCURRENCE_MYSQL=1`
ou `LOOP4_MYSQL=1` + connexion mysql + base `inscription_[a-z0-9_]*_test`
uniquement ; chaque test re-vérifie le nom exact de sa base). La base de
dev reste inatteignable par les tests (blocage immédiat sinon). Toutes les
migrations exécutées pendant la mission l'ont été sur sqlite `:memory:` ou
sur les bases de test dédiées — **jamais** sur la base de dev.

## Performance (phase 3)

`EXPLAIN` MySQL (base de dev, lecture seule) sur une paire réelle —
`SELECT ... WHERE etudiant_id = ? AND annee_universitaire_id = ?` :
`type: const`, `key: uniq_inscription_etudiant_annee`, `key_len: 16`,
`ref: const,const`, `rows: 1`, `Extra: Using index` ; `UPDATE` : même clé
(`possible_keys: uniq_inscription_etudiant_annee`,
`key: uniq_inscription_etudiant_annee`). La vérification d'unicité est un
accès index unique O(1) — aucun scan, aucune table temporaire.

## Données (phase 5)

Doublons détectés : **0** (re-vérifié après intervention : requête
`GROUP BY etudiant_id, annee_universitaire_id HAVING COUNT(*) > 1` → 0
ligne). Aucune suppression / fusion / réaffectation : aucune n'était
nécessaire, et rien n'a été fait sans autorisation. Stratégie pour des
doublons futurs : la migration de garde refuse bruyamment en listant les
paires (anonymisées) ; toute résolution exigerait une autorisation
explicite.

## Fichiers

- Créés : `database/migrations/2026_10_18_100000_verifier_unicite_inscription_annuelle.php`,
  `tests/Feature/UniciteInscriptionAnnuelleTest.php`,
  `tests/Feature/UniciteInscriptionConcurrenceMysqlTest.php`, ce rapport.
- Modifiés : `app/Services/InscriptionService.php`,
  `app/Services/ArchiveService.php`,
  `app/Services/AffectationAutomatiqueService.php`,
  `app/Services/Io/InscriptionsAcademiquesImporter.php`,
  `app/Services/DataExchange/Definitions/InscriptionAcademiqueDefinition.php`,
  `app/Services/DataExchange/Definitions/ProgressionDefinition.php`,
  `app/Http/Controllers/Admin/InscriptionAcademiqueController.php`,
  `app/Http/Requests/StoreInscriptionAcademiqueRequest.php`,
  `tests/TestCase.php`, `docs/inscriptions/rules.md`.

## Tests exécutés

| Suite | Résultat |
|---|---|
| `UniciteInscriptionAnnuelleTest` (12 : service, HTTP, année suivante, statuts, physique/logique, index réel + insertion brute refusée, import, régularisation, affectation, DataExchange) | **12/12, 43 assertions** |
| `UniciteInscriptionConcurrenceMysqlTest` (base dédiée) | **3/3, 12 assertions** |
| `AffectationConcurrenceMysqlTest` (LOOP 4 préexistant, débloqué par l'exemption) | **3/3, 20 assertions** |
| Non-régression ciblée cœur inscriptions (13 classes) | **161/161, 650 assertions** |
| Non-régression ciblée imports/affectation (15 classes) | 117/120 exécutés, 1 skip — 3 échecs **transitoires** `MensCrudTest` (voir réserves), rejoués 13/13 |
| Suite complète (3574 tests) | **3536 réussis, 13 échecs préexistants, 25 skips** |
| Mêmes 10 classes SANS les modifications du loop | **les mêmes 13 échecs** → **aucun échec introduit** |
| `pint` (fichiers PHP modifiés/créés) | conforme |

Les 13 échecs préexistants (accessibilité/parcours public, saisie des
dates, budgets N+1 enseignant/dashboard/fiche, FAQ) sont reproduits sans
les modifications de ce loop : **hors périmètre**.

## Réserves

1. Migration de garde non appliquée à la base de dev (lecture seule
   pendant la mission) — l'index UNIQUE y est déjà actif ; à exécuter au
   prochain `php artisan migrate`.
2. 13 échecs de tests préexistants hors périmètre (reproduits sans les
   modifications) — à traiter par leurs loops respectifs.
3. `MensCrudTest` : 3 échecs transitoires observés pendant des exécutions
   simultanées/chargées (assertions throttle/auth 403/429/session),
   **non reproductibles** au rejeu (13/13 en isolation, 0 échec en suite
   complète propre) — flakiness d'infrastructure de test, sans lien avec
   l'unicité des inscriptions.
4. Aucun commit/push (aucune autorisation) : livraison locale uniquement.

## Verdict

**PASS_WITH_RESERVATIONS** — la règle « au plus une inscription par
(étudiant, année) » est garantie de bout en bout : règle centralisée dans
le service métier, refus explicite sans donnée personnelle sur tous les
points d'entrée, contrainte UNIQUE vérifiée en base, concurrence réelle
MySQL arbitrée (1 succès + refus métier, jamais 2 lignes), 0 doublon en
base, non-régression prouvée sur 3 574 tests. Les réserves sont externes
au périmètre ou opérationnelles (déploiement de la migration de garde,
échecs préexistants d'autres chantiers, flakiness d'infrastructure de
test, absence d'autorisation de commit).
