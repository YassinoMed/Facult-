# LOOP GLOBAL 03 — Exécution contrôlée des tests — Rapport et verdict

**Date :** 2026-10-25 · **Runner :** `php artisan test` (PHPUnit, sqlite
`:memory:`, `MAIL=array`, `QUEUE=sync` — porte de sécurité `03-00` : PASS).
**Aucune modification de code, migration, donnée ou configuration pendant
LOOP-03. Aucun commit/push/destructif. Référence Git de départ :**
`loop-global/.reference-git-03.txt` (747 entrées).

## 1. Synthèse des exécutions

### 1.1 Groupes ciblés séquentiels (T0, arbre stable)

| Gr | Périmètre | Fichiers | Tests | Assertions | Échecs | Skips | Durée |
|---|---|---|---|---|---|---|---|
| G1 | Sécurité/Auth/MFA/PIN/Rôles | 33 | **287 pass** | 1 138 | 0 | 0 | 11,3 s |
| G2 | Inscriptions/Dossiers/Portails | 98 | **1 043 pass** | 6 135 | 0 | 3 | 42,5 s |
| G3 | Référentiel/Maquettes/Compétences/Imports/Exports | 64 | **504 pass** | 2 400 | 0 | 5 | 24,7 s |
| G4 | Évaluations/Examens/Notes/Délibérations | 39 | **390 pass** | 1 548 | 0 | 1 | 19,8 s |
| G5 | Documents/Coffre/Attestations/Archives | 44 | **321 pass** | 1 363 | 0 | 4 | 17,7 s |
| G6 | EDT/Salles/Groupes/Affectations/Charges | 45 | **423 pass** | 1 726 | 0 | 6 | 45,4 s |
| G7 | Notifications/Campagnes/Data Mesh/Intégrations | 27 | **225 pass** | 2 349 | **1** | 0 | 7,2 s |
| G8 | IA/Simulateur/Décrochage/Assurance qualité | 10 | **187 pass** | 735 | 0 | 0 | 7,3 s |
| G9 | Cockpit/Workflows/Finances/Sauvegardes/Sentinel/Versioning/divers+Unit | 182 | **1 421 pass*** | 6 063 | **3*** | 14 | 168,5 s |
| | **Total T0** | **542** | **4 801 pass** | **23 457** | **4** | **33** | ≈ 344 s |

\* G9 exécuté 3 fois : 4 échecs (passage 1), puis 3 échecs (passages 2-3) —
un 4ᵉ échec non reproduit ensuite (instable ponctuel, non identifié, cf. §4).

### 1.2 Suite complète (`php artisan test`, T1 ≈ 6 min plus tard)

**4 790 pass / 15 échecs / 33 skips — 23 422 assertions — 362 s.**
Couverture identique aux groupes (4 838 tests = somme des groupes ✓).

## 2. Les 4 échecs STABLES (présents à T0, T1, T2 — reproduction faite)

| # | Test | Message clé | F-xx | Cause racine (lecture seule) | Classe | Gravité |
|---|---|---|---|---|---|---|
| 1 | `DataMeshContractsTest::instantane_des_schemas…` | « Schéma modifié pour [analytique_faculte] : monter la version et documenter » — champ réel `snapshot_kpi` absent du contrat attendu | F-22 | `app/DataMesh/` ET son test sont NON SUIVIS (travail local) : champ ajouté au produit `AnalytiqueFaculte` (l.56) sans mise à jour de l'instantané du contrat ni bump de version | CODE_FAILURE | P3 |
| 2 | `DesignSystemTest::alert_roles_et_icones` | icône `✕` attendue absente du rendu `x-alert` | Socle UI | `resources/views/components/alert.blade.php` MODIFIÉ localement (116 vues en travail) ; test épinglant l'ancien markup | CODE_FAILURE (dérive markup/test du travail local) | P3 |
| 3 | `DesignSystemTest::modal_accessible` | `aria-label="Fermer"` attendu absent | Socle UI | idem — `modal.blade.php` modifié localement | CODE_FAILURE | P3 |
| 4 | `LoopPopupHomeOnly04Test::parcours_sans_navigation…` | `action="/inscription/existant/recherche"` absent du dialogue d'accueil | F-08/F-10 | vue popup d'accueil modifiée localement ; le test (lui-même M) épinglait les formulaires attendus | CODE_FAILURE | P3 |

Les 4 partagent le même profil : **dérive entre le travail local non commité
et les tests gardes-fous** — aucun risque de sécurité, de fuite ou de
corruption identifié. Corrections à décrire en LOOP-09 (sans les appliquer
ici) : mettre à jour contrat+version (n°1) et aligner les 3 tests de markup
sur les composants actuels (ou documenter le retour arrière des composants).

## 3. Les 11 échecs APPARUS SEULEMENT à T1/T2 — contamination par écriture CONCURRENTE

**Fait établi par comparaison avec la référence Git sauvegardée :**

1. À T0, `ImportTemplateTest` (8), `PopulationTest` (2),
   `SeparationProfilsTest` (1) **passaient** au sein de G3/G9.
2. À T1 (suite complète) et T2 (ré-isolation de ces 3 fichiers seuls :
   11 échecs / 42 pass), ils échouent — signature : `UrlGenerationException`
   « Route [admin.imports.templates.erreurs] … Missing parameter: rapport »
   + `ErrorException` sur les parcours d'import/export.
3. Pendant la fenêtre T0→T2, **aucun fichier suivi n'a changé** (M reste
   343 ; la vue en cause date de la veille 22:21) — seuls sont APPARUS :
   `app/Modules/HubIntegration/` (Models créés 08:06→08:10, Services 08:16),
   `database/migrations/2026_10_25_020000_creer_tables_hub_integration.php`
   (08:08), `docs/engineering/LOOP-07-COFFRE-FORT-DOCUMENTAIRE-QR.md`.
4. **Conclusion : un autre acteur (session concurrente) écrit actuellement
   le Lot 18 « HubIntegration » dans l'arbre pendant l'audit.** La
   corrélation temporelle est démontrée (timestamps ci-dessus) ; le mécanisme
   exact de propagation vers les tests d'import n'a pas été isolé plus avant
   (volontairement — l'arbre est mouvant et toute manipulation des fichiers
   concurrents est interdite par le mandat de préservation).

**Classement : `BLOCKED_ENVIRONMENT` (contamination par mutation concurrente
du dépôt)** — ces 11 échecs NE SONT PAS comptés comme défauts du code audité
à T0, et ne sont PAS non plus des défauts du travail concurrent (qui est
simplement inachevé : `HubIntegration` n'a pas encore ses
Routes/Provider complets).

## 4. Skips (33) et instable

- 33 skips = gardes d'environnement VOLONTAIRES vérifiées en 03-00
  (concurrence MySQL ×5, backup roundtrip, concurrence examens réelle,
  autres gardes internes) — jamais comptés comme réussites.
- 1 échec ponctuel (G9 passage 1 uniquement, non reproduit 2×, identité non
  capturée — sortie tronquée) : `TEST_FAILURE` (non-déterminisme à
  surveiller, possiblement lié lui aussi au début de l'écriture concurrente
  vers 08:06).

## 5. Résultats par famille (A→K)

| Famille | Tests T0 | Verdict |
|---|---|---|
| A. Socle/accès | 287 (G1) | PASS |
| B. Référentiel LMD | 504 (G3) | PASS |
| C. Inscriptions/dossiers | 1 043 (G2) | PASS |
| D. Portails étudiant/enseignant | inclus G2 | PASS |
| E. EDT/groupes/charges | 423 (G6) | PASS |
| F. Examens/évaluations | 390 (G4) | PASS |
| G. Documents/archives | 321 (G5) | PASS |
| H. Finances | inclus G9 (partiels) | PASS dans le périmètre testé |
| I. Notifications/Data Mesh | 225 (G7) + G9 | PASS_WITH_RESERVATIONS (contrat DataMesh n°1) |
| J. Data Mesh/échanges | idem | PASS_WITH_RESERVATIONS |
| K. Transverses/IA/lots 14-20 | 187 (G8) + G9 | PASS (baselines confirmées) |
| UX navigateur | 0 | NOT_EXECUTED (aucun navigateur automatisé installé — respect du mandat) |

## 6. Limites de couverture

1. Concurrence MySQL réelle, restauration de sauvegarde, concurrence examens :
   NON EXÉCUTÉS (opt-in non demandé) — réservés à un environnement dédié.
2. UX/a11y navigateur : NOT_EXECUTED.
3. La suite complète n'est pas reproductible à l'instant T : arbre en
   mutation concurrente (Lot 18 en cours d'écriture).
4. Les 4 échecs stables P3 documentés en §2 sont hérités du travail local
   non commité — ils existaient avant LOOP-03.

## 7. Intégrité et conformité

- État Git en fin de LOOP-03 : 343 M / 405 ?? / 2 A — soit la référence
  + les livrables de cette mission + **les fichiers du travail concurrent
  (préservés intégralement, jamais touchés)** + `.reference-git-03.txt`.
- Aucun commit, push, merge, déploiement, seed, migration, wipe ; aucun
  e-mail/webhook/paiement/job réel émis ; base MySQL jamais contactée
  (garde TestCase + phpunit.xml confirmées par les 4 838 exécutions).
- Le « +1 M non attribué » de la 03-00 s'explique désormais : mutation
  d'un fichier déjà modifié par la session concurrente (les compteurs M
  ne distinguent pas les ré-écritures).

## 8. Verdict LOOP-03

**PASS_WITH_RESERVATIONS** — pour l'exécution de référence T0 : 4 801/4 805
tests applicatifs réussis (99,9 %), 4 échecs stables classés P3 (dérive
contrat/markup du travail local, corrections décrites non appliquées),
33 skips environment-gated honnêtement exclus. **Réserves majeures :**
(a) 11 échecs à T1/T2 attribués à l'écriture CONCURRENTE du Lot 18 dans le
dépôt pendant l'audit — à re-vérifier après stabilisation de ce travail ;
(b) suite complète momentanément non reproductible pour la même raison ;
(c) UX navigateur NOT_EXECUTED.

## 9. Prochaines actions ordonnées (NON appliquées)

1. **Coordonner les sessions** : attendre la fin de l'écriture du Lot 18
   `HubIntegration` (fichiers encore incomplets : pas de `Routes/`), puis
   re-exécuter `ImportTemplateTest`, `PopulationTest`,
   `SeparationProfilsTest` et la suite complète pour ré-attribuer les 11
   échecs (code vs contamination).
2. LOOP-04 sécurité : IDOR/isolement sur la base des 287 tests G1 verts +
   revue ciblée des modules désactivés par défaut.
3. LOOP-09 corrections candidates (P3) : contrat `analytique_faculte`
   (bump version + instantané), alignement des 3 tests de markup sur les
   composants modifiés — chacune UNE À UNE avec tests de régression.
4. Tracer l'instable G9-passage-1 lors du re-run global.
