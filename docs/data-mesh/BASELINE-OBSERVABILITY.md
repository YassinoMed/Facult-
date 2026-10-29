# BASELINE — Data Mesh Observability Center (LAN, lecture seule)

Date : 16/10/2026. Aucune modification effectuée.

## 1. Socle Data Mesh existant (vérifié)

| Élément | Fichier | Constat |
|---|---|---|
| Catalogue | `app/DataMesh/Catalogue.php` | 6 produits : `catalogue_academique` v1.0, `inscriptions_validees` v1.1, `affectations_groupes` v1.0, `affectations_enseignement` v1.0, `resultats_examens` v1.0, `analytique_faculte` v1.0 ; `codes()`, `produit()`, `liste()`, `parDomaine()`, `schema()` |
| Contrat | `app/DataMesh/Contrats/ProduitDonnees.php` | `code/domaine/version/decrire/schema/donnees/fraicheur` ; lecture seule, DTO versionnés |
| Socle gouvernance | `app/DataMesh/Produits/ProduitSocle.php` | `consommateur()` (User requis), `perimetreFaculte()` (refus hors périmètre, forçage faculté restreinte), `roleBackOfficeRequis()`, `masquerCin/masquerEmail()`, fraîcheur ISO-8601 |
| Qualité | `app/DataMesh/Qualite/ControlesDonnees.php` | 8 règles (6 bloquant, 1 avertissement, 1 info) → `{regle, perimetre, anomalies, gravite, exemples}` ; `synchroniserVersAnomalies()` ; lecture seule |
| Flux | `app/DataMesh/Flux/FluxVerticalDataMesh.php` | `executer(User, ?faculte, ?annee)` → 5 bilans avec `etat` (`terminee`/`terminee_sans_resultat`) + diagnostic ; **écrit 1 ligne d'audit** (`data_mesh.flux_vertical_execute`) — pas de déclenchement métier |
| Outbox | `OutboxService` + `OutboxMessage` + migration | statuts pending/published/failed, dedup atomique, `publierEnAttente(50)`, `retenterEchecs(3)`, `purgerPublies(30j)`, commande `datamesh:outbox-publier` (non planifiée dans `routes/console.php`) |
| Tests | `tests/Feature/DataMesh/` (7 fichiers) + `tests/Feature/DataMeshTest.php` | contrats, produits, qualité, sécurité/isolation, flux, outbox/idempotence, perfs |

## 2. Conventions réutilisées (ne pas réinventer)

- Routes : `Route::prefix('admin')->name('admin.')` + groupe `middleware(['auth','admin','secondaire.verrou'])` (`routes/web.php:433,498`) ; ex. `bi.index` + `can:statistiques.view` (`:1149`)
- Contrôleur : `App\Http\Controllers\Admin\*`, `abort_unless($request->user()->can(...), 403)`, filtres validés (`exists`), périmètre `faculteRestreinteId() ?? filtre`, vues `admin.*.index` (modèle : `BiController`)
- JSON : pattern `View|JsonResponse` + `response()->json()` (modèle : `AffectationController@analyserMasse`)
- Nav : section Pilotage, liens `@can(...)` + `routeIs('admin.x.*')` actif (`_navigation.blade.php:13-52`)
- Frontend : Alpine.js 3.17 + Tailwind 4 + Livewire ; **aucune lib de graphe installée** → graphe SVG sur mesure (pas de framework ajouté)
- Permissions existantes réutilisables : `statistiques.view`, `cockpit.view`, `qualite.view` (pas de nouveau privilège)

## 3. Points d'attention relevés
1. `FluxVerticalDataMesh::executer()` **écrit** (1 audit) : la page dashboard ne doit l'appeler que sur action explicite, jamais au simple affichage — sinon, construire la vue Flux depuis les produits/audits existants sans exécuter.
2. Outbox : commande existante mais **non planifiée** ; statut d'automatisation = non mesuré → afficher « inconnu », pas de faux sain.
3. `resultats_examens` : données sensibles (moyennes publiées uniquement) — panneau détails sans PII brute, exemples anonymisés.
4. Périmètre admin_faculte : forçage serveur systématique ; aucun paramètre navigateur ne fait foi.
5. N+1 : produits déjà eager-loadés ; endpoint graphe = 1 appel agrégé, pas de recalcul par nœud.

## 4. Fichiers à créer / modifier (plan)
**Créer** : `app/Http/Controllers/Admin/DataMeshController.php`, `app/Services/DataMeshGrapheService.php` (agrégation graphe + statuts), `resources/views/admin/datamesh/index.blade.php` (+ partiels onglets), routes `admin.datamesh.*`, `tests/Feature/DataMeshObservabilityTest.php`, `docs/data-mesh/ARCHITECTURE-OBSERVABILITY.md`.
**Modifier** : `routes/web.php` (groupe admin, ~10 lignes), `resources/views/layouts/_navigation.blade.php` (1 lien Pilotage).
**Ne pas toucher** : contrats/produits/services métier existants, migrations, capacités, règles d'éligibilité.
