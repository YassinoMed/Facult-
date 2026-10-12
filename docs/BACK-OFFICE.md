# Back-office universitaire — couverture réelle

> Principe §51 : une fonctionnalité est « terminée » uniquement si
> UI + backend + validation + permissions + BDD + tests + sécurité
> sont cohérents. Ce document distingue terminé / partiel / non fait.

## Terminé (cœur + modules)

- **Dashboard adaptatif** : stats agrégées (1 requête), référentiels,
  demandes en attente, imports récents, notifications, timeline
  d'activité, actions rapides, widgets modules. Périmètre faculté ;
  section maintenance réservée super-admin.
- **Étudiants** : registre (recherche LIKE neutralisée, filtres,
  pagination), fiche (identité, académique, groupe, modules liés,
  suppression protégée + cascade fichiers).
- **Inscriptions** : création publique validée, statuts
  (en_attente/valide/rejete) via `definirStatut()`, anti-doublons
  UNIQUE. Pas de suspension/transfert (voir Reste à faire).
- **Structure** : facultés CRUD, **groupes CRUD** (scopés faculté),
  années universitaires + matières (configuration académique).
- **Enseignants / salles** : CRUD, recherche, protection contre
  suppression si affectés, capacité suivie.
- **Emplois du temps** : semestriel fixe, créneaux, exceptions,
  conflits (groupe/enseignant/salle/**capacité**), import
  CSV/JSON/XML/XLSX + API, historique, notifications, réactivité.
- **Notes** : saisie bornée 0-20, verrouillage, recalcul moyennes,
  audit ancienne/nouvelle valeur, concurrence via `lockForUpdate`.
- **Absences** : module Attendance (présences, taux, isolation).
- **Documents** : académiques + dossiers étudiants versionnés
  (privé, SHA-256, audit, PDF générés).
- **Demandes** : module OnlineRequests (workflow 9 statuts, pièces,
  PDF, délais, audit).
- **Notifications** : centre + badge + isolation par destinataire.
- **Recherche globale** : 7 sections scopées + permission-gated.
- **Imports/Exports** : orchestrateur 2 phases + rapports + jobs.
- **Utilisateurs/Rôles/Permissions** : matrice RBAC, 5 rôles,
  permissions granulaires (dont `enseignants/salles/groupes.*`).
- **Audit** : journal immuable + centre de consultation filtrable.
- **Maintenance/Santé** : contrôles DB/stockage/queue/cache/sessions,
  purge cache (super_admin, throttle).
- **Erreurs** : pages 403/404/419/429/500/503 au Design System.

## Partiel (fonctionnel, périmètre volontairement restreint)

- Fiche étudiant : liens modules + groupe (pas d'onglets
  Identité/Parcours/Audit dédiés).
- Fiche enseignant : CRUD uniquement (pas de fiche profil
  départements/disponibilités).
- Statistiques : KPI + listes (pas de graphiques — aucune lib
  chart imposée).
- Anciennes vues : certaines pages gardent leur style d'origine
  (migration DS progressive, documentée).

## Non implémenté (documenté, pas de code factice)

- **Examens** (sessions, surveillants, planification).
- **Départements/Filières** (entités dédiées — `filiere` reste un champ).
- **Annonces** (publication ciblée).
- **Paramètres établissement** (UI — config files uniquement).
- **Réinscriptions/transferts/suspensions** (workflow inscriptions).
- **Exports** CSV/XLSX/PDF génériques par entité (moteurs existants
  : OpenSpout, dompdf, serializers Io).
- **Temps réel WebSocket** (events prêts), **canaux email/SMS**.
- **Soft deletes** (suppressions protégées par usage, pas de corbeille).
