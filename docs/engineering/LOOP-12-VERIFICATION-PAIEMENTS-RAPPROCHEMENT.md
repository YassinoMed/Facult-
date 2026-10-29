# LOOP 12 — Vérification · Paiements & rapprochement

**Date d'exécution :** 27/10/2026
**Mode :** audit + test négatif + correctif minimal. Aucune écriture officielle, aucun paiement confirmé, aucune donnée réelle modifiée.
**Périmètre :** `app/Modules/Finances/` (factures, paiements, reçus, dossiers annuels, remboursements, rapprochement) et ses tests.

## 1. Verdict

**`PASS_WITH_RESERVATIONS`.**

Le noyau financier est solide : montants en centimes entiers, machine à états explicite,
verrouillage transactionnel, confirmation unique, dépôt de reçu qui ne vaut jamais
paiement, rapprochement strictement en lecture seule. **Un** défaut de cloisonnement a
été prouvé et corrigé : le compteur « Remboursements en attente » du tableau de bord
était global alors que **toutes** les autres statistiques étaient déjà scopées par
faculté. Les réserves portent sur l'absence de passerelle de paiement en ligne, de
rapprochement bancaire et d'écran de gestion des remboursements — des manques
fonctionnels, pas des régressions.

> **Cycle de complément du 2026-10-27 :** l'écran de remboursements existe désormais
> (liste, demande, traitement), le contrôle facultaire de `payer()` est renforcé, les
> agrégats du tableau de bord sont calculés en SQL, et la suite de vérification passe
> de 1 à **5 tests (27 assertions)**. Réserves R-12/2, R-12/3 et R-12/6 traitées ;
> R-12/1, R-12/4 et R-12/5 restent ouvertes (décisions métier ou environnements non
> exécutés). Détail en §6 et §6bis.

## 2. Audit préalable — état vérifié

| Brique | Emplacement | Constat |
|---|---|---|
| Factures | `Models/Facture.php`, `FinanceController::factures/show/emettre/annuler` | Cycle `draft → issued → partially_paid → paid / overdue / cancelled / refunded`, transitions contrôlées |
| Paiements | `FinanceService::enregistrerPaiement/confirmerPaiement` | Confirmation idempotente, verrou `lockForUpdate`, événement `PaiementConfirme` |
| Reçus d'inscription | `DossierPaiementService` + `PaiementInscriptionAdminController` | `NON_PAYE → RECU_DEPOSE → EN_VERIFICATION → VALIDE / REFUSE`, fichier privé, validation profonde, anti-doublon |
| Remboursements | `FinanceService::demanderRemboursement/traiterRemboursement`, `Models/Remboursement.php` | FSM `requested → approved → processed → completed` (+ `rejected`), plafond cumulé contrôlé |
| Rapprochement | `FinancialReconciliationService` + commande `finances:audit-rapprochement` | 10 anomalies (reçu validé incohérent, dossier/reçu orphelin, fichier réutilisé, métadonnées dupliquées, indicateur payé contradictoire…), lecture seule |
| Permissions | `FinancePolicy`, `DossierPaiementPolicy` | `finances.view`, `finances.manage`, `finances.confirm`, `finances.receipts.verify` — périmètre facultaire sur `view`/`confirm` et sur les dossiers |

**Ce qui n'existe pas (vérifié) :** aucune passerelle de paiement (Stripe/PayPal/CMI),
aucun webhook, aucun flux bancaire, aucune écriture comptable en partie double, aucun
rapprochement automatique facture ↔ paiement. Le module enregistre des **déclarations**
et des **preuves déposées**, jamais des encaissements bancaires.

## 3. Défaut prouvé — D12-1 : compteur de remboursements non scopé

**Cause.** Dans `FinanceController::dashboard()`, toutes les statistiques étaient
scopées par `faculteRestreinteId()` — factures (`$base`), impayées (`$base`), reçus à
vérifier (`DossierPaiement::whereHas('inscription.etudiant')`) — **sauf** :

```php
'remboursements' => Remboursement::where('statut', 'requested')->count(),
```

Un `admin_faculte` recevait donc le nombre global de demandes de remboursement en
attente, toutes facultés confondues. Donnée agrégée, sans PII, mais **fuite
d'activité inter-facultés** dans un écran déjà cloisonné.

**Preuve.** `tests/Feature/Loop12FinancesVerificationTest.php` :
un remboursement `requested` est créé sur un paiement confirmé d'une facture de la
faculté B ; un `admin_faculte` de la faculté A ouvre `admin.finances.index`.
Échec initial : `Failed asserting that [remboursements] matches the expected value.
Failed asserting that 1 matches expected 0.`

## 4. Correctif appliqué (minimum strict)

`app/Modules/Finances/Controllers/FinanceController.php` — le compteur suit désormais le
même périmètre que le reste de l'écran :

```php
'remboursements' => Remboursement::query()
    ->where('statut', 'requested')
    ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->whereHas(
        'paiement.facture.etudiant',
        fn ($e) => $e->where('faculte_id', $request->user()->faculteRestreinteId())
    ))
    ->count(),
```

- Un `super_admin` (`faculteRestreinteId() === null`) conserve la vue globale.
- Aucun changement de permission, de route, de modèle ni de service.
- Aucun `join` ajouté : `whereHas` réutilise les relations existantes
  (`Remboursement → Paiement → Facture → Etudiant`), déjà utilisées par `FinancePolicy::view()`.

## 5. Tests exécutés (preuves)

| Commande | Résultat réel |
|---|---|
| `php artisan test --compact tests/Feature/Loop12FinancesVerificationTest.php` (avant) | **échec** — `1` au lieu de `0` (message ci-dessus) |
| idem (après) | **1/1 PASS, 2 assertions** |
| `php artisan test --compact tests/Feature/PaiementsInscriptionsTest.php tests/Feature/RapprochementFinancierTest.php tests/Feature/GedFinancesBiTest.php` | **34/34 PASS, 290 assertions** |

Détail de la non-régression : `PaiementsInscriptionsTest` (22 tests : dossier unique par
inscription, reçus non réutilisés, dépôt ≠ paiement, isolation étudiant/faculté,
validation profonde), `RapprochementFinancierTest` (rapport sans mutation),
`GedFinancesBiTest` (11 tests : exports/BI financiers).

## 6. Réserves

- **R-12/1 — Aucun encaissement en ligne.** Pas de passerelle, pas de webhook, pas de
  rapprochement bancaire. Le module est un registre déclaratif : la « confirmation »
  est humaine. Toute promesse de paiement automatique serait mensongère. **(ouverte)**
- **R-12/2 — Remboursements sans écran.** → **CORRIGÉE le 2026-10-27** : trois routes
  (`finances.remboursements.index/store/traiter`), un écran de liste filtrable, les
  formulaires de demande (depuis un paiement) et de traitement, les liens depuis le
  tableau de bord et les badges `requested`/`processed`. La permission reste
  `finances.manage`/`finances.confirm` selon l'acte.
- **R-12/3 — `payer()` sans contrôle facultaire interne (défense en profondeur).**
  → **CORRIGÉE** : `FinanceController::payer()` vérifie désormais que la facture
  appartient au périmètre facultaire de l'utilisateur avant confirmation, en plus de
  `Gate::authorize('manage', Facture::class)`. Le test de périmètre simule une
  permission future via `Gate::before(fn () => true)` et prouve le `404` hors périmètre.
- **R-12/4 — Pas de comptabilité en partie double.** Les montants sont des
  instantanés déclarés ; le rapprochement détecte des incohérences, il ne produit pas
  d'écriture comptable opposable. **(ouverte — décision métier)**
- **R-12/5 — Pas de test MySQL ni navigateur.** SQLite `:memory:` uniquement.
  `NOT_EXECUTED`, pas `PASS`. Le `whereHas` imbriqué sur 3 niveaux est standard mais
  non rejoué sur MySQL. **(ouverte)**
- **R-12/6 — Aucune revue de charge.** → **CORRIGÉE** : les agrégats du tableau de
  bord sont calculés en SQL par `FinanceService::agregatsFactures()` (une requête
  agrégée, sémantique exacte de `netCentimes`/`payeCentimes`/`resteCentimes`,
  échéance `<= today` pour reproduire `isPast()`) au lieu de charger toutes les
  factures et d'agréger en PHP. La mesure de charge université reste à faire.
- **R-12/7 — Couverture historique du tableau de bord : nulle avant ce lot.**
  → **PARTIELLEMENT CORRIGÉE** : `Loop12FinancesVerificationTest` couvre désormais
  les agrégats SQL, le cloisonnement des remboursements, la demande/traitement et le
  contrôle facultaire de `payer()` — **5 tests, 27 assertions**. Les autres compteurs
  historiques restent couverts par `PaiementsInscriptionsTest` et
  `RapprochementFinancierTest` (58 tests au total sur le filtre finances).

## 6bis. Cycle de complément du 2026-10-27 — preuves

| Commande | Résultat réel |
|---|---|
| `php artisan test --compact tests/Feature/Loop12FinancesVerificationTest.php` | **5/5 PASS, 27 assertions** |
| `php artisan test --compact --filter="Finance\|Paiement\|Rapprochement\|Facture"` | **58/58 PASS, 424 assertions** |
| `vendor/bin/pint` sur les fichiers touchés | **PASS** |

Correctifs vérifiés par ces tests :

- `agregatsFactures()` reproduit exactement les anciens totaux PHP (montants en
  centimes, remise, facture `paid`, paiements `confirmed`, reste plancher à 0) ;
- un `admin_faculte` ne voit que les remboursements de sa faculté et reçoit `404`
  sur un paiement d'une autre faculté ;
- la demande de remboursement est idempotente/validée (paiement confirmé, montant
  positif et ≤ montant encaissable) ;
- le traitement applique la machine à états (`requested → processed/rejected`) sans
  double traitement ;
- le tableau de bord n'expose plus de compteur global.

## 7. Fichiers touchés par ce lot

| Fichier | Nature |
|---|---|
| `app/Modules/Finances/Controllers/FinanceController.php` | modifié (1 expression) |
| `tests/Feature/Loop12FinancesVerificationTest.php` | **créé** (1 test négatif) |

**Précision sur le diff :** `FinanceController.php` porte aussi des modifications
**antérieures et non revendiquées** par ce lot (import `DossierPaiement` + statistique
`recus_a_verifier`, issues du lot « paiements d'inscription »). Seule l'expression
`remboursements` a été touchée ici ; `recus_a_verifier` était déjà correctement scopée.

Aucun commit, aucun push, aucune migration, aucune donnée officielle modifiée.
