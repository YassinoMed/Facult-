# LOOP 43-BIS — Audit immuable et journalisation

## Objectif
Rendre chaque opération d'administration des droits traçable et vérifiable.

## 1.1 Événements à journaliser

### 1.1.1 Liste minimale d'événements à tracer
Au minimum, les événements suivants doivent être journalisés :
- **Création de rôle** — `role_created` : acteur, rôle créé, date, motif
- **Modification de rôle** — `role_modified` : acteur, rôle précédent, nouveau rôle, modifications de permissions, date, conséquences identifiées
- **Archivage** — `role_archived` : acteur, rôle archivé, stratégie de remplacement, date
- **Attribution** — `permission_attributed` : acteur, bénéficiaire, rôle/permission, périmètre, date, motif
- **Révocation** — `permission_revoked` : acteur, bénéficiaire, rôle/permission, raison, date
- **Demande** — `access_request_created` : acteur (initiateur), bénéficiaire, type de demande, statut, date
- **Approbation** — `access_approved` : acteur (approbateur), demande, décision, date
- **Rejet** — `access_rejected` :acteur (rejetteur), demande, motif de rejet, date
- **Délégation** — `delegation_created` :acteur (délégant), bénéficiaire, permission, périmètre, date, motif, restrictions
- **Expiration** — `access_expired` :acteur (système), ressource, date d'expiration effective
- **Changement de périmètre** — `scope_changed` :acteur, utilisateur, ancien périmètre, nouveau périmètre, date
- **Accès d'urgence** — `breakglass_activated` :acteur (déclencheur), bénéficiaire, permission, motif, date, durée, revue ultérieure
- **Modification du catalogue** — `permission_added` / `permission_removed` :acteur, permission ajoutée/supprimée, raison, date

### 1.1.2 Jamais journaliser
Les éléments suivants ne doivent JAMAIS être journalisés :
- Mots de passe
- Tokens (MFA, accès, session)
- Secrets (clés API, codes confidentiels)
- Contenu sensible sans nécessité avérée (données personnelles excessives)

### 1.1.3 Minimisation des données personnelles
Respecter les règles institutionnelles de conservation des données personnelles. Ne collecter que ce qui est strictement nécessaire à l'audit. Pseudonymiser si possible.

## 1.2 Données d'audit

### 1.2.1 Données à enregistrer pour chaque événement
- Identifiant d'événement (auto-incrémenté ou UUID)
- Acteur (user_id de l'acteur qui a initié l'action)
- Cible (user_id de l'utilisateur affecté, ou NULL si système)
- Action (libellé normalisé : `created`, `modified`, `revoked`, `approved`, etc.)
- Date (horodatage précis)
- Motif (texte libre, obligatoirement présent pour les événements sensibles)
- État avant (représentation légère, sans données secrètes)
- État après (représentation légère, sans données secrètes)
- Périmètre (faculté, programme, ou `global`)
- Approbation (ID de l'approbateur, le cas échéant)
- Demande (référence UUID de la demande, le cas échéant)
- Corrélation (ID lié à un autre événement, pour le chaînage)
- Résultat (succès, échec, motif de l'échec)
- Source de l'action (interface web, API, job, administrateur manuel)

### 1.2.2 Format de stockage
- Stockage dans la table `iam_evenements` (append-only, pas de `updated_at`)
- Empreinte SHA-256 chaînée (`empreinte = h(empreinte précédente | contenu)`) pour détecter altération
- Pas de `updated_at` (table véritablement append-only)
- Les champs `avant` et `apres` sont nettoyés récursivement (pas de mots de passe, tokens, secrets)

## 1.3 Immutabilité

### 1.3.1 Protection côté service
Un administrateur ordinaire ne doit pas pouvoir :
- Modifier un événement existant
- Supprimer un événement
- Falsifier un auteur (l'acteur est lié à l'utilisateur connecté en session)
- Réécrire un ancien état
- Masquer une révocation (la ligne reste, seule la visibilité peut être filtrée)

### 1.3.2 Moyens de protection
- Accès d'écriture réservé au service d'audit (modèle qui crée uniquement, pas de mise à jour/suppression via interfaces ordinaires)
- Append-only : seules des insertions sont possibles
- Empreintes / chaînage cryptographique (détecte toute altération, même si l'on a accès en base)
- Stockage séparé si le niveau de risque le justifie (table `iam_evenements` distinctée des tables opérationnelles)

### 1.3.3 Analyse réaliste
- Ne jamais déclarer un journal réellement immuable uniquement parce que son interface ne permet pas de le modifier
- L'immutabilité doit être techniques (base de données, hooks, triggers) pas seulement UI
- Mais ne pas surestimer : l'hébergeur peut toujours altérer la base, c'est un risque accepté mais documenté

## 1.4 Interface d'audit

### 1.4.1 Recherches possibles
Permettre de rechercher par :
- Utilisateur (tous les événements l'impliquant)
- Rôle (événements liés à des modifications de rôle)
- Permission (événements d'attribution/révocation de cette permission)
- Faculté (événements dans un périmètre donné)
- Programme (événements dans un programme donné)
- Type d'événement (création, modification, révocation, approbation, rejet, délégation, expiration, changement de périmètre)
- Période (date de début à date de fin)
- Statut (initiateur, approbateur, rejetteur)
- Motif (recherche dans les motifs libres)

### 1.4.2 Respect des permissions et isolation facultaire
Les recherches doivent respecter les permissions et l'isolation facultaire :
- Un administrateur de faculté ne voit que les événements dans sa faculté
- Un super_admin voit tous les événements
- L'export de l'audit est protégé selon les permissions de l'utilisateur

### 1.4.3 Export de l'audit
- L'export doit être protégé (filtré selon les permissions de l'utilisateur)
- Formats possibles : CSV, JSON, PDF
- L'export ne doit pas contenir de données sensibles non masquées
- Traçabilité de l'export lui-même (qui a exporté, quand, vers où)

## État du journalisation
- Événements minimal listés et définis
- Données d'audit complètes (acteur, cible, action, date, motif, avant/après, périmètre)
- Immutabilité technique (append-only, empreintes, pas de mise à jour/suppression par interface)
- Interface de recherche flexible (par utilisateur, rôle, permission, période, type)
- Export protégé et tracé

## Livrables
- `LOOP-43BIS-AUDIT-JOURNAL.md`

**Verdict G43BIS-14 :** ✅ Audit journalisation — événements minimal listés, données d'audit complètes, immutabilité technique (append-only, empreintes), interface de recherche flexible, export protégé, respect des permissions d'isolation.