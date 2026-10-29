# Module Schedule — emplois du temps réactifs

Quatrième module vertical : plannings hebdomadaires par groupe,
créneaux réactifs (sondage versionné, broadcast-ready), conflits,
exceptions, historique, notifications.

## §1. Données

Nouvelles tables : `groups` (faculté + niveau + filière), `teachers`,
`rooms`, `schedules` (UNIQUE groupe/année/semestre — semestre
académique 1/2 en tinyint, aucun modèle Semestre créé), `schedule_slots`
(matière = `Matiere` du cœur réutilisée), `schedule_exceptions`
(UNIQUE slot/date), `schedule_changes` (avant/après JSON).
Seule touche au cœur : colonne additive nullable
`etudiants.groupe_id` + relation `Etudiant::groupe()`.

## §2. Règles métier

Conflits (`ScheduleConflictService`, chevauchement strict) :
groupe, enseignant (tous plannings), salle. Heures `fin > début`,
jours enum. Statuts scheduled/cancelled/modified/completed, types
course/td/tp/exam/meeting/workshop/other (constantes modèle — pas
d'Enums PHP, le projet n'en utilise pas).

## §3. Cycle de vie

`ScheduleService` : création (conflits → transaction → historique →
event → notification → invalidation cache), modification (statut auto
`modified`, qualification room/time/teacher_changed), annulation,
restauration, suppression, exceptions. Chaque mutation historisée
(`schedule_changes` : acteur, avant/après, motif) + `ScheduleChanged`
(payload ids+type, sans PII) + `ScheduleNotificationService` (log +
compte destinataires).

## §4. Permissions et isolation

`schedules.view/manage` — scolarité complète, agent/consultation
lecture, faculté gère son périmètre (via groupe). Étudiant : groupe
propre uniquement (`groupe_id` session, jamais client) ; sans groupe
ni planning → 404. `SchedulePolicy` (viewAny/view/manage/manageSlot).

## §5. Interfaces

Admin : registre (filtres), fiche (grille + ajout + exception +
historique 30), édition créneau. Étudiant : semaine navigable
(?semaine=±N), grille desktop + liste mobile, carte créneau partagée
(normal/modifié/annulé + badges texte, jamais couleur seule).

## §6. Réactivité et performance

`GET actualisations?depuis=&semaine=` → `{version, updated, slots}`
(slots seulement si changement). Alpine sonde toutes
`schedule.poll_interval` secondes (défaut 45) sans recharger, badge +
`aria-live`. Version = max(updated_at) cachée (`schedule:version:{id}`,
TTL 1h, invalidée à chaque mutation). Eager loading systématique,
pagination admin, index composites.

## §7. Notifications et temps réel

`ScheduleNotificationService::notifier()` + `Log schedule.*` (ids
uniquement). `ScheduleChanged` volontairement non-broadcast :
migration WebSocket = implémenter `ShouldBroadcast` + canal privé
`groupe.{id}`, sans toucher les émetteurs. `AuditSchedule` journalise.

## §8. Seed et tests

`ScheduleSeeder` (local/testing, idempotent) : 5 enseignants, 5 salles,
3 groupes, planning L1 avec cas normal/modifié/annulé/exception,
rattachement d'étudiants. 19 tests (conflits groupe/enseignant/salle,
horaires, CRUD, annulation/restauration, exception+unicité,
version/updates, isolation groupes/facultés, permissions, invités,
module off, période, statut `moved`, portée date vs semestre,
valeurs normales exposées).

## §9. Semestriel fixe — précisions

- `schedules.starts_at/ends_at` (nullables) : période affichée en
  admin et côté étudiant (« valable du … au … »).
- Statuts d'exception : scheduled/cancelled/modified/**moved**.
- Page d'édition : choix explicite **tout le semestre** (défaut) vs
  **uniquement une date** (crée une exception ; matière/type/jour
  refusés en portée date pour éviter toute ambiguïté).
- JSON et cartes étudiantes exposent les valeurs normales
  (`salle_normale`, horaires normaux) : « Salle normalement : C02 ».

`ScheduleSeeder` (local/testing, idempotent) : 5 enseignants, 5 salles,
3 groupes, planning L1 avec cas normal/modifié/annulé/exception,
rattachement d'étudiants. 15 tests (conflits ×3, horaires, CRUD,
annulation/restauration, exception+unicité, version/updates,
isolation groupes/facultés, permissions, invités, module off).

## §10. Import CSV/JSON/XML

Parsers Io du cœur réutilisés (séparateur auto, BOM, accents ;
XML durci anti-XXE comme `XmlParser`), enveloppes JSON/XML dédiées
au format planning. Contrat `ScheduleImporterInterface` (csv/json/xml ;
xlsx/api = 10 lignes via `IoFactory`), `ScheduleNormalizer` (jours
FR→EN, heures, S1→1, slash→dash), `ScheduleImportValidator`
(entités par code puis nom sans doublons, doublons intra-fichier,
conflits intra-fichier + base hors planning remplacé),
`ScheduleImportService` (prévisualisation stagée en session serveur,
confirmation `creer`/`remplacer` + case explicite, transaction unique,
rapport `schedule_imports`). Sélecteurs du formulaire = contrôle de
cohérence ; périmètre faculté re-vérifié à la confirmation. 14 tests
(`ScheduleImportTest`).

## §11. Finalisation (XLSX, API, notifications)

- XLSX : `XlsxScheduleImporter` (parser Io partagé, limite anti-bombe)
  branché au contrat — même normalisation/validation/transaction.
- API : `POST /api/v1/schedules/import/preview` (dry-run : aucune
  écriture) et `/confirm` (fichier re-transmis + re-validé, scope
  `schedules:import`, throttle `api-imports`, colonne `integration`).
- Notifications : `NotifierPlanning` (groupe), `NotifyDemande`
  (approbation/rejet/clôture/pièce), `NotifyDocumentValide` —
  via `NotificationService` (table `notifications`, centre + badge).

## §10. Exploration multi-axes et disponibilités

`ScheduleExplorerService` (requêtes scopées faculté, cache 10 min
auto-invalidé par `max(updated_at)`, IDs seuls en cache
(`cache.serializable_classes=false` interdit les objets sérialisés) + `ScheduleExplorerController`
(validation stricte, groupe hors périmètre → 404) :

- `explorer` : 9 axes (enseignant, salle, groupe, matière, jour, filière,
  niveau, semestre, année) + vue semaine/jour + navigation ±52 semaines +
  chips + compteur ; grille avec badge **EN COURS** (texte + pastille),
  statuts texte+icône (jamais couleur seule), annulés affichés, panneau
  détail + auto-scroll vers aujourd'hui ; cartes mobile empilées.
- `disponibilites` : libres/occupés par enseignant et salle + occupant ;
  `trouver-salle` intégré (capacité min, tri croissant).
- `trouver-creneau` : grille 08h-18h pas 30 min, durée 1h-4h, contraintes
  réelles (conflits + capacité).
- `charge` : heures/cours/jours par enseignant + lien planning.
- `conflits` : balayage par jour (salle/enseignant/groupe) + filtre +
  lien résolution (tableau déplaçable).
- `occupation` : matrice salle × heure + détail au clic.
- Espace étudiant : badge EN COURS sur `creneau` partiel (semaine de son
  groupe uniquement, inchangée par ailleurs).

Limites honnêtes : `Room` n'a ni type ni équipements (capacité seule
affichée) ; pas de compte enseignant (pas de vue « mes groupes » —
l'exploration back-office couvre le besoin) ; vue mois non implémentée
(les exceptions vivent dans la fiche planning).

## Remplacements : candidatures pédagogiques (LOOP 09)

Volontariat enseignant par matière (`remplacement_candidatures`) : dépôt
au portail (`enseignant/remplacement-candidatures`, session
enseignant, throttle 10/min, qualification exigée, doublon actif
refusé), retrait libre par le propriétaire. Côté administration
(`admin/remplacement-candidatures`, `schedules.view/manage`) :
registre filtrable, acceptation POUR un créneau précis — l'acceptation
ne duplique rien : elle invoque `ScheduleRepairService::remplacerEnseignant`
(transactionnel, gardes qualification/disponibilité/chevauchement,
historisation `ScheduleChange`) ; en cas d'échec la candidature reste
soumise et l'EDT inchangé (transaction commune). `suggererRemplacants`
priorise les candidats déclarés (+30, flag `candidat_declare`). Rollback
migration : drop de la table (l'historique des remplacements vit dans
`schedule_changes`).
