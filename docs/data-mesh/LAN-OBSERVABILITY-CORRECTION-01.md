# LAN — Inventaire et preuves (LOOP correction 01, lecture seule)

Date : 16/10/2026. Aucune modification à ce stade.

## 1. Source de chaque indicateur (vérifié en base dev + code)

| Indicateur page | Valeur dev | Source réelle |
|---|---|---|
| 6 produits enregistrés | 6 | `Catalogue::codes()` (6 classes) |
| 4 opérationnels | 4 | `fraicheur() !== null` : catalogue, inscriptions, aff_enseignement, analytique (MAX updated_at non null) |
| 1 en erreur | 1 | Overlay qualité : `bloquants_par_produit[inscriptions_validees] > 0` |
| 2 non vérifiés | 2 | `fraicheur() === null` : affectations_groupes (0 affectation active), resultats_examens (0 décision publiée) |
| 2 005 anomalies | 2005 | `ControlesDonnees::controler` : 2000 `inscriptions_sans_programme` + 3 `capacites_inconnues` + 2 `fraicheur_produits_depassee` |
| Outbox 0/0/0 | 0/0/0 | `data_mesh_outbox` vide (2037 étudiants, 2060 inscriptions, 0 écriture outbox : aucun producteur dans `app/`) |
| Dernière exécution flux | 16/10 19:43 | `historique_academique.action = data_mesh.flux_vertical_execute` (2 traces) |
| Rôle acteur « — » | null | Colonne `utilisateur_id` NULL sur les traces ; l'acteur réel (id=3, super_admin) est dans `nouvelle_valeur.utilisateur_id` |
| Publisher non planifié | absent | `routes/console.php` : 18 événements planifiés au runtime, 0 contenant `datamesh` |

## 2. Anomalie A — somme 4+1+2 = 7 > 6 (cause démontrée)

`operationnels`/`non_verifies` (base fraîcheur, exclusifs : 4+2=6 ✓) et
`en_erreur` (overlay qualité, **non exclusif**) sont juxtaposés comme s'ils
étaient exclusifs. `inscriptions_validees` est comptée deux fois
(opérationnelle par fraîcheur ET en erreur par qualité). Le code ne définit
aucune taxonomie d'état unique. → DEV : état final unique par produit avec
priorité documentée ERREUR > NON_VERIFIE > OPERATIONNEL.

## 3. Anomalie B — 2 000 bloquantes (origine démontrée)

Règle `inscriptions_sans_programme` : 2060 inscriptions valides dont 2000
sans `programme_id` valide (données seedées). Chaque règle compte 1
violation par entité listée (pas de doublon intra-règle), mais un même
enregistrement peut être compté dans plusieurs règles (pas de déduplication
transverse — jamais annoncée, à afficher explicitement).

## 4. Anomalie C — automatisation (état mesurable, pas codé en dur)

`Schedule::events()` expose 18 événements au runtime, aucun `datamesh` →
état mesuré `NON_CONFIGURE`. Heartbeat `supervision:scheduler-dernier`
(cache 600 s) disponible pour distinguer ACTIF / INCONNU si planifié.
Dernière exécution et dernier succès du publisher : aucune table → INCONNU
(prouvé par l'absence de stockage, pas supposé).

## 5. Anomalie D — dates

Bandeau `produits.date_reference = now()` = date de calcul, pas de mesure
(trompeur). Contrats : `fraicheur_attendue` textuelle, aucun SLA numérique →
jamais PERIME, seulement NON_VERIFIE. Fuseau DATE_ATOM = +00:00 (UTC),
à étiqueter.

## 6. Anomalie E — acteur (cause démontrée)

`FluxVerticalDataMesh` trace avec `auditService->tracer($etudiant, …)` qui
lit `auth()->user()` (NULL hors HTTP) ; l'acteur passé en paramètre n'est
persisté que dans `nouvelle_valeur.utilisateur_id`. Le service ne lit que la
colonne → null. → DEV : repli `nouvelle_valeur.utilisateur_id` → `users.role`
(rôle seul, sans identité), « compte supprimé » si introuvable.
