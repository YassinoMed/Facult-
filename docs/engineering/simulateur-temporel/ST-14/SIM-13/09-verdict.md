# SIM-13 — 09 · Verdict final

**Scénario :** SIM-13 — Crise académique combinée  
**Campagne :** ST-14 — scénarios avancés  
**Date :** 2026-11-05  
**Mode :** AUDIT_ONLY / SIMULATION / TESTING  

---

## J.1 Verdict

# ⛔ BLOCKED_BUSINESS

**Portée :** SIM-13 ne peut être ni spécifié, ni conçu, ni implémenté, ni
testé en l'état. Le blocage ne vient pas du moteur, de l'environnement ou des
tests : il vient de l'**absence de définition métier** de la combinaison.

---

## J.2 Justification

La seule définition disponible est la ligne de catalogue :

> « Combiner plusieurs dégradations dans les limites du modèle. »

Elle ne précise ni les axes, ni les sens, ni le nombre minimal de
dégradations, ni leur strictness, ni leur temporalité, ni les limites
exactes, ni la restitution attendue, ni le véhicule technique. Les
dépendances SIM-07 → SIM-12 sont des contrats **mono-axe** : aucune ne
définit une combinaison. La fiche CR-02, plus précise, est un autre scénario,
d'une autre famille, qui **dépend** de SIM-13 ; l'utiliser comme source
inverserait la dépendance.

Deux options ont été examinées :

1. **Réutiliser `taux_degrade` (SIM-08), moteur inchangé.** Le moteur sait
   déjà simuler un jeu de taux complet où plusieurs paramètres changent.
   Mais SIM-08 ne restitue que `baisse_passage` ; sans règle supplémentaire,
   SIM-13 serait un doublon. Avec une règle (« au moins deux dégradations »),
   il faudrait décider des axes, des sens et de la strictness — des règles
   non fournies.
2. **Créer un descripteur multi-axes et passer le moteur en v1.9.** Cette
   option exige un schéma d'entrée, des règles de validation, plusieurs clés
   de restitution, une politique de version et de hash, et une mesure
   d'impact complete. Aucune de ces décisions n'est fournie.

Aucune option ne permet d'implémenter SIM-13 sans **inventer une règle
métier**. Conformément à la règle de campagne, le verdict est donc
`BLOCKED_BUSINESS`, et non `FAIL` (le moteur n'est pas fautif) ni
`BLOCKED_ENVIRONMENT` (l'outillage n'est pas en cause).

---

## J.3 Critères de validation

| Domaine | Verdict | Preuves / motif |
|---|---|---|
| **A — Audit** | ✅ PASS | `00-audit.md` : périmètre, moteur, SIM-08, options, questions, risques. |
| **B — Spécification** | ⛔ BLOCKED_BUSINESS | Aucune définition des axes, sens, strictness, temporalité, limites, sorties. |
| **C — Conception** | ⛔ BLOCKED_BUSINESS | Véhicule (`taux_degrade` vs multi-axes) non tranché ; impact version/hash inconnu. |
| **D — Développement** | — Non commencé | Interdit tant que le contrat n'existe pas. |
| **E — Validation mathématique** | — Non applicable | Aucune formule ni cas de référence sans contrat. |
| **F — Sécurité** | — Non applicable | Aucun code, aucune route, aucune permission ajoutés. |
| **G — Tests** | — Non exécuté pour SIM-13 | Aucun test SIM-13 créé ; la suite de non-régression reste au vert. |
| **H — UX** | — Non applicable | Aucun écran ni guide tant que le contrat est bloqué. |
| **I — Documentation** | 🟡 Partielle | `00-audit.md` + `09-verdict.md` ; livrables 01 → 08 non produisibles sans inventer la règle. |
| **J — Verdict** | ⛔ BLOCKED_BUSINESS | Le présent document. |

**Rappel :** « non exécuté » ≠ « réussi ». Aucun test SIM-13 n'est revendiqué.

---

## J.4 Preuves mesurées

| Indicateur | Valeur |
|---|---|
| Tests SIM-13 ajoutés | **0** |
| Assertions SIM-13 ajoutées | **0** |
| Suite module Simulation temporelle (non-régression) | **160 / 160**, **1763 assertions**, 0 échec |
| Fichiers PHP modifiés pour SIM-13 | **0** |
| Migrations créées | **0** |
| Écritures métier | **0** |
| Version moteur | `1.8` (**inchangée**) |
| Catalogue | **45 entrées**, **13 disponibles / 32 planifiés** (inchangé) |
| SIM-13 dans le catalogue | `planifie`, version/schéma `null`, disponibilité `false` |
| Git — commits créés | **0** |
| Git — entrées WIP | **278** (les documents SIM-13 sont dans le dossier `ST-14/` déjà compté comme une entrée non suivie) |
| Branche / push / PR | **Aucun** |

La suite module a été mesurée à la clôture de SIM-12 et reste inchangée :
aucun fichier PHP, aucune route, aucune migration n'a été touché par cet
audit. Le résultat est reporté comme **référence de non-régression**, pas
comme un test de SIM-13.

---

## J.5 Blocages et décisions requises

| ID | Décision requise | Nature | Conséquence si non tranchée |
|---|---|---|---|
| `SIM13-B1` | Axes inclus dans la combinaison | Métier | Impossible de définir le schéma |
| `SIM13-B2` | Sens admissible par axe | Métier | Impossible de valider une « dégradation » |
| `SIM13-B3` | Nombre minimal et strictness des dégradations | Métier | Impossible de distinguer SIM-13 de SIM-08 |
| `SIM13-B4` | Simultanéité ou séquencement temporel | Métier | Impossible de choisir le moteur / l'horizon |
| `SIM13-B5` | Restitution attendue (clé jointe, clés par axe, libellé) | Métier / contrat | Impossible de définir la sortie |
| `SIM13-B6` | Limites exactes (« dans les limites du modèle ») | Métier | Risque d'inventer une contrainte |
| `SIM13-B7` | Confirmation ou reclassement de `configuration` | Métier / audit | Classification non fiable |
| `SIM13-T1` | Véhicule : `taux_degrade` ou descripteur multi-axes | Technique / contrat | Architecture indécidable |
| `SIM13-T2` | Version moteur, hashs, rétrocompatibilité | Technique | Risque de rupture des exécutions existantes |
| `SIM13-T3` | Impact API / UI / permissions | Technique | Surface d'entrée non validée |

**Autorité compétente :** pilotage métier ST-14 / autorité académique
responsable des règles de réussite. Aucun de ces points ne peut être tranché
par le moteur ou par le catalogue.

---

## J.6 Livrables du cycle

| Livrable | État | Motif |
|---|---|---|
| `00-audit.md` | ✅ Produit | Audit complet, options, questions, risques, Gate A. |
| `01-specification.md` | ⛔ Non produit | Aucune règle métier à spécifier ; le produire reviendrait à l'inventer. |
| `02-formules.md` | ⛔ Non produit | Aucune formule ni cas de référence sans contrat. |
| `03-architecture.md` | ⛔ Non produit | Véhicule et version non tranchés. |
| `04-implementation.md` | ⛔ Non produit | Aucun code autorisé. |
| `05-securite.md` | ⛔ Non produit | Aucune surface nouvelle à sécuriser. |
| `06-tests.md` | ⛔ Non produit | Aucun test SIM-13 exécutable. |
| `07-resultats.md` | ⛔ Non produit | Aucun résultat SIM-13 à mesurer. |
| `08-guide-utilisation.md` | ⛔ Non produit | Aucun usage possible tant que le scénario est bloqué. |
| `09-verdict.md` | ✅ Produit | Présent document. |

Le cycle est **interrompu au Gate A**. Les livrables 01 → 08 ne sont pas
« oubliés » : ils sont **non produisibles** sans violer l'interdiction
d'inventer une règle. Ils devront être produits après la décision métier et
le nouvel audit.

---

## J.7 Réserves

| ID | Réserve | Gravité | Traitement |
|---|---|---|---|
| `SIM13-R1` | Blocage **métier**, pas technique : aucune règle de combinaison n'existe dans le corpus | **HAUTE** | Verdict `BLOCKED_BUSINESS` ; décisions `SIM13-B1` → `B7` requises. |
| `SIM13-R2` | SIM-13 reste `planifie` dans le catalogue ; le statut `bloque_business` n'est pas appliqué | MOYEN | Évite de modifier le contrat et les compteurs sans autorisation ; le verdict et le registre portent l'information. |
| `SIM13-R3` | La compatibilité `configuration` est préliminaire et non confirmée | MOYEN | À reclasser après décision ; SIM-08 est déjà `analyse_nouvelle`. |
| `SIM13-R4` | `taux_degrade` (SIM-08) permet déjà de simuler un jeu complet ; SIM-13 serait un doublon sans règle ou sortie supplémentaire | **HAUTE** | Option A rejetée en l'état ; aucune duplication créée. |
| `SIM13-R5` | Le moteur v1.8 est mono-axe : une seule clé de réussite par exécution | **HAUTE** | Toute extension multi-axes exige une décision `SIM13-T1/T2` et une mesure d'impact. |
| `SIM13-R6` | Aucun contrat de sortie multi-axes n'existe | **HAUTE** | Question `SIM13-B5` ; aucune clé jointe inventée. |
| `SIM13-R7` | CR-02 et UNC-02 dépendent de SIM-13 ; leur séquencement peut être affecté | MOYEN | Ne pas ouvrir ces scénarios comme si SIM-13 était disponible ; réévaluer leurs dépendances. |
| `SIM13-R8` | L'audit repose sur l'état du dépôt au 2026-11-05 ; une décision métier externe pourrait exister hors dépôt | MOYEN | Si une décision existe, la fournir et rejouer le Gate A. |
| `SIM13-R9` | Aucun test SIM-13 n'a été exécuté (aucun n'existe) | INFO | Ne pas confondre absence de test et succès ; la non-régression est mesurée séparément. |
| `SIM13-R10` | Le statut `bloque_business` du catalogue n'est pas utilisé pour SIM-13 | INFO | Décision de contrat non prise ; documentée, réversible. |

---

## J.8 Décision et suites autorisées

- **Clôture de SIM-13 :** prononcée sous le verdict **⛔ BLOCKED_BUSINESS**.
- **Aucune implémentation** de SIM-13 n'est autorisée tant que les décisions
  `SIM13-B1` → `SIM13-B7` et `SIM13-T1` → `SIM13-T3` ne sont pas écrites et
  validées.
- **Aucune exécution** de SIM-13 n'est possible : le catalogue la refuse
  (`planifie`, `exigerDisponible`).
- **Reprise :** produire une définition métier complète, puis rejouer le
  Gate A (`00-audit.md` mis à jour) avant toute Phase B.
- **Scénarios indépendants :** peuvent être audités et ouverts selon leurs
  propres réserves. La règle du registre s'applique : « un scénario
  `BLOCKED_BUSINESS` ne bloque pas les scénarios indépendants ». Les
  scénarios qui **dépendent** de SIM-13 (CR-02, UNC-02) ne doivent pas être
  traités comme débloqués.
- **Interdits maintenus :** aucune règle inventée, aucun seuil inventé,
  aucune écriture métier, aucun commit / push / branche, aucune migration
  destructive, aucune donnée de production.
