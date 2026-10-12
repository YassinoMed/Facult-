# Affectation de groupe et changement de groupe — règles documentées

Document LAN (2026-10-11) : règles métier **réellement implémentées** dans le dépôt, servira de référence pour la LOOP B (changement de groupe) sans les réinventer.

## 1. Affectation initiale (existant, prouvé)

### Conditions obligatoires (toutes vérifiées serveur)
1. Inscription **validée** (transiter→valide passe la matrice documents `exigerPourValidation` + blocages `BlocageInscriptionService` + période re-vérifiée dans la transaction).
2. Étudiant actif, non suspendu ; CIN sans conflit (`IDENTITY_CONFLICT` sur CIN normalisée).
3. Programme/niveau de l'inscription **exacts** — jamais de niveau inventé ni d'ajustement pour combler une capacité.
4. Groupe : même programme + même niveau + actif + capacité disponible.
5. Pas d'affectation active existante (idempotence `EXISTING`).

### Stratégie (déterministe)
- `AffectationAutomatiqueService::tenter` : parmi les groupes compatibles, **le moins rempli, tie-break par id** ; fallback capacité par boucle ; échec explicite `NO_COMPATIBLE_GROUP` (audit + notif, jamais de solution inventée).
- `AffectationService::affecter` : 10 contraintes codées + verrous ordonnés **étudiant → groupe** dans la transaction (anti-deadlock, double tenter = EXISTING).
- Simulation avancée : `AssignmentSimulationService` (contraints-first, seed déterministe `hash(seed:id)`, alternatives scorées `{group_id, code, score}`, plan capacitaire, pureté sans écriture) ; confirmation re-valide (obsolescence + idempotence).
- Lots asynchrones : `AffectationMasseLot` (tranches 500, reprise `traites_ids`, annulation coopérative, auteur re-vérifié `archives.update` à l'exécution).

### Historique
- `AffectationGroupe.statut` : `active` / `cloturee` (+ `verrouille` interdit la mutation, `confirme_le/par`, `motif`, `auteur_id`).
- Transfert = cloture l'ancienne + création de la nouvelle (jamais de suppression de l'ancienne avant la nouvelle).
- Audit : `affectation.auto_assignee` / `auto_non_assignee` / `assignment.*` (sans PII) + notifications dédupliquées.

## 2. Changement de groupe (IMPLÉMENTÉ 11/10/2026 — LOOP B)

### Implémentation réelle (`ChangementGroupeService`)
- **Demandes d'origine** : administration (décision) — route `POST etudiants/{etudiant}/affectations/changer` (`can:archives.update`, throttle 20/1, Gate `update` + scope `peutAccederFaculte` ×2 → 403) ; canal étudiant = décision métier en attente (§3).
- **Éligibilité au changement** (les 10 contraintes existantes de `AffectationService::controler` font foi — AUCUNE nouvelle règle ; re-vérifiées sous verrous ordonnés dans la transaction `affecter`) :
  1. L'étudiant possède une affectation active sur l'inscription visée.
  2. Le planning/blocage ne gèle pas le groupe d'origine ni le groupe cible (verrou affectation = `verrouille`).
  3. Groupe cible : même faculté (ou transfert inter-facultés via le workflow de transfert dédié — jamais direct), même programme/niveau, actif, capacité disponible **au moment de l'application**.
  4. Dossier non bloqué (`BlocageInscriptionService`), étudiant non suspendu.
- **Simulation avant application** (`simuler`) : contraintes BLOQUANTES seules (les contraintes `info` de `controler` décrivent le transfert attendu — ex. « l'affectation active sera clôturée »), impact capacités origine/cible (effectif actif vs capacité) ; SANS écriture (prouvé par test).
- **Application** (`changer`) : délègue au transfert atomique prouvé `AffectationService::affecter` (verrous ordonnés étudiant→groupe→affectation, re-vérification `controler` sous verrous, `cloturee` origine + `active` cible dans UNE transaction) ; refus DomainException → retour structuré {effectue: false, raison} sans aucune écriture ; trace `affectation.changee` (ids groupes + motif, sans PII) ; notification `affectation.changee` APRÈS commit, dédupliquée par affectation.
- **Idempotence** : cible = groupe courant → refus explicite « déjà affecté » (aucune écriture, aucun doublon d'historique) ; annulation d'un changement = nouveau changement inverse (jamais de suppression d'historique).
- **Masse** : réutilisation du moteur de simulation + lots (reprise), jamais de contournement des règles unitaires.

### Couverture de tests (12 tests `ChangementGroupeServiceTest`)
Transfert complet + historique cloturee conservé · demande identique répétée (idempotence, zéro doublon) · sans affectation active · niveau L1→L2 refusé état intact · destination inactive · capacité 0 (GROUP_FULL) état intact · simulation sans écriture + impact capacités · simulation bloquée + raisons · notification post-commit sans PII + trace audit (source/cible/motif) · HTTP autorisé · HTTP cross-faculté 403 sans mutation · HTTP sans permission 403.

### Décisions métier en attente (humain)
1. **Changement après début des cours** : aucune règle existante trouvée dans le dépôt (aucune date de début de cours sur les groupes) → à décider avant implémentation du cas.
2. **Période de gel** : aucun concept existant → NOT_APPLICABLE documenté sauf règle fournie.
3. **Origine de la demande étudiant** : passer par les types de demandes OnlineRequests (workflow existant) ou formulaire dédié → à arbitrer en LOOP B.
