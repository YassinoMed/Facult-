# Journal — Registre Étudiants `/admin/etudiants` (consolidation)

Pas de dépôt Git — traçabilité = ce journal. Tests sur SQLite (prod MySQL).
État initial : BackOffice 9/9, StudentDossier 6/6, InscriptionEtudiants 12/12.

## LOOP 0 — État initial — FAIT

### Matrice action / route / autorisation / validation / tests
| Action | Route | Auth | Validation | Tests existants |
|---|---|---|---|---|
| index | GET etudiants (sans `can:`) | Gate viewAny + scope faculté forcé | 12 filtres bornés | BackOffice, Productivite |
| sansGroupe | GET sans-groupe (sans `can:`) | Gate viewAny + scope | 5 filtres, `sans_groupe=true` serveur | partiel |
| show | GET {etudiant} | Gate view (policy+faculté) | — | BackOffice, Dossier |
| updateStatut | PATCH statut (`can:archives.update`) | Gate update | in:3 + motif 255, DomainException→erreur | Productivite |
| reinitialiserMotDePasse | POST (`can:archives.update`, throttle 10) | Gate update | accepted | InscriptionEtudiants? |
| bulkStatut | POST bulk (`can:archives.update`, throttle 10) | Gate update **par objet** + transaction | ids 1..200, confirmation | Productivite (5 tests) |
| exportDossier | GET (throttle 10) | Gate view | — | Phase8? |
| destroy | DELETE (`can:archives.delete`) | Gate delete | — | à couvrir |

Anomalies confirmées : L1 pagination 25 en dur (sans-groupe) ; L2 double `loadMissing` (:165/:175).
Bulk déjà couvert : transaction, confirmation, périmètre sans écriture partielle, 403, JSON.

---

## LOOP 1 — Pagination « Sans groupe » — FAIT

- TESTS RED 4/4 (`EtudiantsRegistreTest`, 25 en dur), IMPL : `paginate(PreferencesService::lire()->lignes_par_page)` (même source que l'index, clamp 5..100, `sans_groupe=true` toujours forcé serveur). Vert 4/4.

## LOOP 2 — `loadMissing` redondant — FAIT

- `:175` sous-ensemble strict de `:165` → no-op prouvé (39 requêtes avant ET après, 0 N+1). Appel supprimé, relations verrouillées par test (`relationLoaded` ×7), borne < 60.

## LOOP 3 — Audit de sécurité — FAIT + 4 corrections

- A : invité redirigé partout ; consultation lit / écrit 403 sans trace ; IDOR A/B 403 sans mutation ; 404 sur fiche inexistante. Lectures sans `can:` conservées (Gates effectives, pattern du module).
- B : `changer()` central confirmé ; **ajout `lockForUpdate` + re-vérification sous verrou + no-op sans redispatch** (double validation concurrente = 1 transition) ; **throttle 20/min sur `updateStatut`** (double-clic).
- C : **bulk dédupliqué** (`array_unique`, 1 seul historique) ; mixte/confirmation/422/JSON/périmètre déjà couverts (Productivite).
- D : mot de passe haché (cast), flash consommé, audit `ETU-id` sans PII, throttle route — verrouillé par tests.
- E : **fuite réelle trouvée et corrigée : `exportDossier` exposait `synthese.etudiant` complet (CIN, téléphone, date de naissance, adresse)** via `overview()` — `unset($synthese['etudiant'])`, bloc curé fait foi. Destroy : fichiers-puis-DB conservé (jamais de PII orpheline, `purger-orphelins` en compensation) + **trace `IntegrationLog etudiant.supprime` ajoutée** (miroir mot de passe).

## LOOP 4 — Non-régression — FAIT

- 18/18 nouveaux. Voisins : BackOffice 9, StudentDossier 6, InscriptionEtudiants 12, Productivite 10, ExperienceInscriptionLoop 9, VerificationBoucle 11, Phase8 6, ArchiveAuthorization 32, SeparationProfils 17 — **130/130 verts, 0 échec.**

## LOOP 5 — Qualité/perf — FAIT

- Pint vert (imports test auto). Routes intactes. Fiche 39 requêtes avant/après, < 60 en test. Aucune dépendance, aucune migration, aucune donnée réelle touchée.

---

## Vérification indépendante (3e main, 2026-10-11 08:30) — CONFIRMÉ

Co-édition détectée (2e main active 08:13→08:23 sur ce LOOP) : aucune écriture concurrente de ma part pendant l'implémentation. Passe de vérification par exécution réelle sur l'état livré :

| Contrôle | Résultat réel |
|---|---|
| `EtudiantsRegistreTest` (état livré du cycle) | ✅ **18/18, 91 assertions** — mesure embarquée : fiche show 39 requêtes |
| Régressions imposées (`BackOffice` + `StudentDossier` + `InscriptionEtudiants` + `Productivite`) | ✅ **37/37, 174 assertions** |
| Voisins (`ExperienceInscriptionLoop` + `VerificationBoucle` + `StudentDossierPhase8` + `ArchiveAuthorization` + `SeparationProfils`) | ✅ **51/51, 215 assertions** |
| `vendor/bin/pint --test` (contrôleur, `StudentStatusService`, test, routes) | ✅ vert |
| `route:list` (routes etudiants) | ✅ intactes (index, sans-groupe, show, destroy, bulk-statut…) |
| Correctif PII `exportDossier` | ✅ vérifié en code : `unset($synthese['etudiant'])` + bloc `etudiant` curé (id/num_inscription/nom/prenom/email/faculte/statut seuls) |

**Cumul vérifié par exécution : 106 tests, 480 assertions, 0 échec.** Verdict du rapport (« validé ») confirmé par preuve d'exécution. Parcours navigateur : non exécuté (aucun navigateur piloté).

*Note : la suite `EtudiantsRegistreTest` a depuis été étendue par le cycle suivant (voir `docs/engineering/student-registry-*.md`) — les chiffres ci-dessus valent pour l'état livré de ce cycle.*

