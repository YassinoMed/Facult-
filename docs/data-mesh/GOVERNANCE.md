# Gouvernance Fédérée et Sécurité des Données — Data Mesh

Ce document définit les règles universelles de gouvernance, de sécurité, de confidentialité et de conformité qui s'imposent à tous les producteurs et consommateurs de données de la plateforme.

---

## 1. Principes Fondamentaux de Gouvernance

1. **Aucun accès direct aux tables d'autrui pour les consommateurs** :
   Un module consommateur ne doit jamais exécuter de requête SQL brute ou de relation Eloquent directe sur une table d'un autre domaine si un produit de données ou un service officiel existe pour cet usage.
2. **Minimisation des Données Personnelles (RGPD / Droit applicable)** :
   Chaque produit n'expose que les champs strictement indispensables à sa finalité déclarée. Les identifiants sensibles sont masqués ou tokenisés.
3. **Traçabilité Immuable (Audit Trail)** :
   Toute consultation sensible ou action d'altération de données fait l'objet d'un journal immuable en écriture seule (`historique_academique`).
4. **Non-prolifération des sources de vérité** :
   Les domaines analytiques ne doivent jamais recréer des tables d'état opérationnelles.

---

## 2. Contrôle d'Accès, RBAC et Isolation Facultaire

### Matrice des Rôles Universitaires
L'accès aux produits s'appuie sur la matrice RBAC centrale (`App\Support\Permissions`) :
- `super_admin` : Accès global à l'ensemble des produits et de toutes les facultés.
- `admin_scolarite` : Accès complet aux produits de scolarité, affectations et référentiel.
- `agent_scolarite` : Accès opérationnel restreint, sans autorisation d'export global ni modification de structure.
- `admin_faculte` : Accès strictement cantonné à sa propre faculté de rattachement (`faculteRestreinteId()`).
- `consultation` : Lecture seule sur les produits documentaires et analytiques non sensibles.

### Règles d'Isolation Facultaire Strictes
- **Règle absolue** : Lorsqu'un utilisateur a le rôle `admin_faculte`, la méthode `ProduitSocle::perimetreFaculte()` force automatiquement la restriction :
  ```php
  $restreinte = $utilisateur->faculteRestreinteId();
  if ($restreinte !== null) {
      return $restreinte; // Aucune possibilité de bypasser vers une autre faculté
  }
  ```
- **Rejet d'intrusion** : Si un gestionnaire demande explicitement un `faculte_id` qui ne lui est pas assigné, une `AuthorizationException` (« Faculté hors périmètre ») est immédiatement levée et journalisée.

---

## 3. Minimisation et Protection des PII

### Masquage des Identifiants
- **Carte d'Identité Nationale (CIN)** :
  - Dans les contrats publics et les produits de données ordinaires, le numéro est obligatoirement masqué sous la forme `12***678`.
  - L'accès au CIN non masqué est réservé aux procédures régaliennes sous permission spéciale `exports.personal`.
- **Adresses Email et Numéros de Téléphone** :
  - Les adresses email sont obfusquées (`m***e@universite.tn`) dans les produits transverses.
  - Les téléphones et adresses postales privées sont exclus des contrats `InscriptionsValidees` et `AffectationsGroupes`.

### Protection des Notes et Délibérations
- **Secret des Notes** :
  - Les notes d'examens sont confidentielles tant que la délibération du jury n'a pas été officiellement clôturée et publiée.
  - Le système d'anonymat par compostage (`EvaluationCompostage`) dissocie le numéro d'anonymat de l'identité de l'étudiant. La résolution d'identité n'est jamais accordée aux correcteurs.

---

## 4. Traçabilité des Accès et Journalisation

- **Service d'audit centralisé** : `App\Services\AuditService`
- **Règles d'audit** :
  - **Zéro PII/Secret dans les logs** : Aucun mot de passe, clé API, jeton de session ou CIN en clair n'est écrit dans `historique_academique` ou dans les logs Laravel.
  - **Contexte complet** : Chaque entrée d'audit enregistre l'utilisateur authentifié, l'action normalisée, l'entité concernée, l'adresse IP et le `User-Agent`.
  - **Immuabilité** : La table `historique_academique` ne possède pas de colonne `updated_at`. Aucune API ne permet de mettre à jour ou supprimer un enregistrement d'audit.

---

## 5. Gestion des Versions et Évolution des Contrats

Les produits de données suivent le versionnage sémantique formel :

```
VERSION = MAJEUR . MINEUR
```

- **Incrémentation MINEURE (ex. 1.0 → 1.1)** :
  - Ajout d'un nouveau champ optionnel dans le tableau de sortie.
  - Ajout d'un nouveau filtre de recherche optionnel dans le contexte.
  - Rétro-compatible : les consommateurs existants continuent de fonctionner sans modification.
- **Incrémentation MAJEURE (ex. 1.x → 2.0)** :
  - Renommage ou suppression d'un champ existant.
  - Modification du type de données d'un attribut.
  - Modification du sens métier d'une valeur.
  - Période de préavis et de double exposition de 6 mois minimum avant dépréciation définitive.

---

## 6. Surveillance Continue de la Qualité des Données

La qualité des données est vérifiée de manière récurrente et programmable via `ControlesDonnees` :
- **Principe d'explicabilité** : Tout écart constaté retourne la règle violée, le périmètre, le nombre d'anomalies et des exemples de références stables.
- **Interdiction de modification destructive automatique** : Le moteur de qualité signale et alerte, mais ne prend jamais l'initiative de supprimer des inscriptions ou d'altérer des groupes de façon autonome.
- **Cycle de vie des anomalies** : Les écarts détectés sont intégrés à la table `anomalies_qualite` avec gestion de statut (`ouverte` → `acquittee` → `en_cours` → `resolue`).

---

## 7. Rétention et Suppression des Données

- **Dossiers étudiants temporaires** : Les documents numérisés d'identité (scans de CIN) sont placés en zone privée temporaire (`storage/app/private`) et supprimés immédiatement après extraction OCR (`finally`).
- **Inscriptions et archives académiques** : Les données d'inscription et de diplôme sont conservées sans limitation de durée conformément aux obligations légales de conservation des registres universitaires.
- **Logs et outbox** : Les messages d'outbox traités (`status = 'published'`) font l'objet d'une purge périodique après 30 jours de rétention.

### Conservation : obligations légales vs décisions métier (lot 02)
| Donnée | Régime | Statut |
|---|---|---|
| Registres d'inscription et diplômes | Obligation légale (registres universitaires) | Conservés sans limitation |
| Scans CIN temporaires | Obligation sécurité (minimisation) | Supprimés après OCR |
| Messages outbox `published` | Décision technique (traçabilité 30 j) | Purge périodique — **à valider métier** |
| Propositions d'affectation (`affectation_proposals`) | Décision métier en attente | Conservation inchangée — **à valider** |
| Historique `historique_academique` / `journal_modeles` | Traçabilité d'audit | Conservés — **durée à valider métier** |

## 8. Capacités des groupes : triptyque officiel (lot 02)
| État | Signification | Traitement moteur |
|---|---|---|
| **Finie** (`capacite` renseignée) | Capacité officielle opposable | Blocage `GROUP_FULL` au-delà |
| **Inconnue** (`capacite` NULL) | À confirmer par l'établissement — signalée `capacites_inconnues` (avertissement) | Acceptée sans garde-fou (réserve documentée, jamais de capacité inventée) |
| **Illimitée explicite** (`capacite NULL` + `capacite_illimitee = true`, migration additive réversible) | Choix métier opposable, exclu du signalement | Acceptée sans garde-fou (cohérent avec l'historique moteur) |
| **Règle non modifiée (décision en attente)** | `estComplet()` et contrôles moteur inchangés : un `NULL` reste accepté tant que la bascule « INCONNUE refuse les nouvelles affectations » n'est pas validée métier (5 suites de tests en dépendent) | — |

## 9. Sauvegarde et restauration (lot hardening, démontré le 16/10/2026)
- Création : `php artisan sauvegarde:creer` (base + documents privés + manifest sha256) ; vérification : `sauvegarde:creer --verifier` (« intègre »).
- Restauration démontrée sur base isolée `inscription_restore_test` (créée, importée, comptes identiques sur 6 tables clés, puis supprimée).
- Reprise outbox après restauration : les `pending` repartent via `datamesh:outbox-publier` ; doublons impossibles (`cle_deduplication` UNIQUE + déduplication atomique).
- RPO/RTO **proposés** (à valider métier/technique) : RPO ≤ 24 h (sauvegarde quotidienne), RTO ≤ 2 h (restauration + vérification sur matériel équivalent).
