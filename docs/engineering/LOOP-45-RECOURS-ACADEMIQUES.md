# LOOP 45 — RECOURS ACADÉMIQUES

**Date :** 31 octobre 2026
**Périmètre :** circuit de recours contre une décision académique publiée
**Statut :** implémenté, testé, **non exposé** et **inactif** par défaut

---

## 1. Objectif

Ouvrir une voie de recours traçable contre une décision de délibération publiée, sans inventer
aucune règle officielle : ni délai, ni liste de décisions contestables, ni autorité, ni effet
automatique sur les résultats.

Le circuit réutilise le support existant `online_requests` (pièces, messages, historique, audit)
plutôt que de créer une table parallèle. Il possède en revanche **sa propre définition de
workflow** (`recours` v1), ses **propres permissions** et sa **propre règle de quatre yeux**.

---

## 2. Principe fail-closed

Le circuit est fermé tant que trois éléments institutionnels ne sont pas fournis :

| Élément | Clé de configuration | Valeur livrée | Conséquence |
|---|---|---|---|
| Interrupteur maître | `recours_academiques.actif` | `false` | Tout dépôt ou acte est refusé |
| Référence du texte officiel | `recours_academiques.reference_reglementaire` | `null` | Le circuit reste bloqué même si `actif=true` |
| Types de décisions contestables | `recours_academiques.types_decisions_contestables` | `[]` | Aucun dépôt possible : aucun type n'est déclaré contestable |

Deux clés supplémentaires encadrent les effets :

| Clé | Valeur livrée | Effet |
|---|---|---|
| `delai_depot_jours` | `null` | Aucun délai inventé ; la recevabilité est **toujours** un acte humain |
| `application_automatique_autorisee` | `false` | Une décision de recours ne modifie jamais automatiquement une note ou une délibération |

Le type de demande support est référencé par `type_code = RECOURS_ACADEMIQUE`. Il **n'est jamais
créé implicitement** : s'il est absent ou inactif, le circuit refuse de fonctionner.

---

## 3. Architecture

### 3.1 Fichiers

| Rôle | Fichier |
|---|---|
| Configuration | `config/recours_academiques.php` |
| Définition de workflow | `app/Services/Workflow/Definitions/WorkflowRecoursAcademiqueDefinition.php` |
| Enregistrement moteur | `app/Services/Workflow/RegistreWorkflow.php` (définition `recours` v1) |
| Service métier | `app/Modules/OnlineRequests/Services/RecoursAcademiqueService.php` |
| Garde du transitionneur générique | `app/Modules/OnlineRequests/Services/OnlineRequestService.php` |
| Permissions | `app/Support/Permissions.php` (`recours.*`) |
| Tests | `tests/Feature/OnlineRequests/RecoursAcademiqueLoop45Test.php` |

Aucune migration, aucune table, aucune route, aucune vue, aucun seed, aucune modification de colonne.

### 3.2 Réutilisation du support `online_requests`

| Besoin du recours | Support existant |
|---|---|
| Dépôt, motif, référence | `online_requests` (`objet`, `description`, `form_data.recours`) |
| Pièces justificatives | `request_attachments` |
| Fil d'échange | `request_messages` |
| Statut serveur | `online_requests.status` (les 9 statuts existants) |
| Version du circuit | `online_requests.version_workflow` (épinglée à 1) |
| Traçabilité | `historique_academique` via `AuditDemandes` + audit du moteur |

Le détail du recours est stocké dans `form_data.recours` : décision visée, délibération, motif,
référence réglementaire, recevabilité, délai calculé, instruction, décision, application, annulation.

### 3.3 Topologie (workflow `recours` v1)

```
draft ─┬─> submitted ─┬─> received ──> in_review ─┬─> approved ──> completed
       │              │                          └─> rejected
       └─> cancelled  └─> cancelled
                                    waiting_student ─┬─> in_review
                                                     └─> cancelled
```

- Aucun saut : `submitted → approved` et `received → approved` sont interdits.
- Aucun SLA n'est inventé : `delaiMaxHeures()` renvoie `null` pour toutes les transitions.
- `rejected` et `cancelled` sont terminaux ; `completed` l'est aussi.

### 3.4 Permissions séparées

| Permission | `admin_scolarite` | `direction_etudes` | `super_admin` |
|---|---|---|---|
| `recours.deposer` | ✅ | ❌ | ✅ |
| `recours.recevoir` | ✅ | ❌ | ✅ |
| `recours.instruire` | ✅ | ❌ | ✅ |
| `recours.decider` | ❌ | ✅ | ✅ |
| `recours.appliquer` | ✅ | ❌ | ✅ |
| `recours.annuler` | ✅ | ❌ | ✅ |
| `recours.consulter` / `auditer` | ✅ | ✅ | ✅ |

`admin_scolarite` instruit mais ne décide pas ; `direction_etudes` décide mais n'instruit ni ne
reçoit. Cette répartition est un **paramétrage technique par défaut**, à confirmer par l'institution
avant toute activation (voir `LOOP-45-VERDICT.md`).

---

## 4. Cycle de vie

### 4.1 Dépôt étudiant

`deposer(Etudiant, DecisionDeliberation, motif)` :

1. vérifie l'activation et la référence réglementaire ;
2. vérifie que la décision appartient à l'étudiant ;
3. vérifie que la décision **et** sa délibération sont publiées ;
4. vérifie que le type de décision figure dans `types_decisions_contestables` ;
5. refuse un second recours ouvert sur la même décision (hors `rejected` / `cancelled`) ;
6. exige un motif non vide (2 000 caractères maximum) ;
7. calcule un **indicateur** de délai (jamais un rejet) ;
8. crée la demande et la soumet via la définition `recours`.

**Hors délai** : le dépôt reste possible, la recevabilité reste `A_VERIFIER`, et l'explication
indique explicitement qu'aucun rejet automatique n'a lieu.

### 4.2 Recevabilité

`declarerRecevable(recours, agent, référence)` est un **acte humain explicite** :

- permission `recours.recevoir` ;
- référence de décision obligatoire ;
- transition `submitted → received` ;
- enregistrement de l'agent, de la date et de la référence.

Aucun calcul ne peut déclarer un recours irrecevable à la place d'un agent.

### 4.3 Instruction

`instruire(recours, instructeur)` :

- permission `recours.instruire` ;
- statut `received` ou `waiting_student` ;
- assignation nominative (`assigned_to`) ;
- transition vers `in_review`.

`demanderPiece()` exige que l'agent soit **l'instructeur assigné** et repasse le dossier en
`waiting_student`. `repondreDemandePiece()` permet à l'étudiant propriétaire de répondre et de
revenir en `in_review`.

### 4.4 Décision (quatre yeux)

`decider(recours, décideur, sens, motif)` :

- permission `recours.decider` ;
- statut `in_review` ;
- **instruction préalable obligatoire** (`assigned_to` non nul) ;
- **l'instructeur assigné ne peut jamais décider**, même `super_admin` ;
- `application_automatique_autorisee=true` fait échouer l'acte ;
- la décision est écrite dans `form_data.recours.decision` avec
  `application = A_APPLIQUER_MANUELLEMENT` et `application_effectuee = false`.

**Aucune note, moyenne ou décision de délibération n'est modifiée.** Le test de non-écriture compare
un instantané complet avant/après.

### 4.5 Application et clôture

`enregistrerApplication(recours, agent, référence)` :

- permission `recours.appliquer` ;
- statut `approved` ;
- référence d'application obligatoire ;
- transaction + verrou pessimiste ; double application refusée.

`cloturer(recours, agent)` exige une application manuelle préalablement enregistrée **et** une
référence non vide, puis passe en `completed`.

### 4.6 Annulation étudiante

`annuler(recours, étudiant, motif)` :

- propriétaire uniquement ;
- statuts `draft` ou `submitted` uniquement (avant réception) ;
- motif obligatoire ;
- transition vers `cancelled`.

---

## 5. Défense en profondeur

### 5.1 Garde du transitionneur générique

`OnlineRequestService::transitionner()` refuse toute demande dont le type porte le code
`RECOURS_ACADEMIQUE`, **même circuit désactivé**. Sans ce garde, un agent pourrait approuver un
recours via la route générique des demandes, en contournant l'instruction, les permissions dédiées
et les quatre yeux.

### 5.2 Corrections apportées au moteur

Les tests ont révélé deux défauts réels :

1. **Le moteur n'appliquait pas le statut** : `MoteurWorkflowService::transiter()` utilisait
   `update()`, or `status` n'est pas mass-assignable sur `OnlineRequest` (choix de sécurité du
   module). La transition était auditée mais le statut restait inchangé. Correction :
   `forceFill(...)->save()` après tous les contrôles, le moteur étant l'autorité serveur.
2. **L'audit des actes agents n'utilisait pas le vocabulaire du module** : le moteur traçait
   `modification_dossier`, sans événement `online_request.*`. Correction : chaque transition agent
   est également tracée par `AuditDemandes` (`online_request.received`, `.in_review`,
   `.waiting_student`, `.approved`, `.rejected`, `.completed`).

### 5.3 Absence de PII

Les audits ajoutés ne recopient ni le motif du recours, ni le contenu des pièces : uniquement le
circuit, l'acteur, son rôle, le sens de la décision et les références techniques.

---

## 6. Ce que le circuit ne fait pas

- Il ne crée pas le type de demande `RECOURS_ACADEMIQUE`.
- Il ne fixe aucun délai de dépôt.
- Il ne déclare aucune décision contestable.
- Il ne désigne aucune autorité institutionnelle réelle.
- Il n'expose aucune route, aucun contrôleur, aucune vue.
- Il ne modifie aucune note, moyenne ou décision de délibération.
- Il n'envoie aucune notification (le circuit n'est pas exposé).
- Il n'applique aucune sanction ni exclusion automatique.

---

## 7. Procédure d'activation (à titre indicatif, non exécutée)

1. L'institution fournit le texte officiel et sa référence.
2. Elle déclare les types de décisions contestables (`evaluation_decisions.decision`).
3. Elle fournit, si elle le souhaite, un délai de dépôt et sa date de référence.
4. Elle confirme les rôles autorisés à recevoir, instruire, décider et appliquer.
5. Le type `RECOURS_ACADEMIQUE` est créé et activé par un acte administratif.
6. `actif` passe à `true` et la référence est renseignée.
7. Les routes et vues sont ouvertes dans une étape ultérieure.

Aucune de ces étapes n'a été franchie : la base réelle ne contient ni type de recours, ni recours.
