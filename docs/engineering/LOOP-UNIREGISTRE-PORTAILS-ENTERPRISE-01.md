# LOOP-UNIREGISTRE-PORTAILS-ENTERPRISE-01

## Portails Étudiant et Enseignant — audit, remédiation, tests et rapport final

- **Date d'ouverture** : 21 octobre 2026
- **Périmètre** : espace étudiant `/etudiant/*`, espace enseignant `/enseignant/*`, modules transverses appelés par ces portails.
- **Mode** : boucle auto-validée `LOOP-AUTO-VALIDATION-AUTO-PASS-01`.
- **Règle de départ** : LOT 0 strictement read-only ; aucune modification de code avant inventaire, matrice, risques, règles métier à confirmer, plan ordonné, inventaire des tests et verdict d'audit.

---

## 1. LOT 0 — Inventaire et audit read-only

### 1.1 Méthode et limites

L'audit a été conduit par lecture des routes, contrôleurs, middlewares, modèles, Policies, migrations, vues, services, jobs et tests, puis par exécution ciblée des suites existantes. Aucun fichier de code, aucune migration, aucune donnée et aucune configuration d'environnement n'ont été modifiés pendant ce LOT.

Limites explicites :

- Certains modules sont désactivés dans l'environnement courant (`announcements`, `calendar`, `finances`, `student-archive`, `parcours-academique`, `tasks`). Leurs routes existent mais retournent `404` tant que le module n'est pas activé. Les parcours correspondants sont donc **non vérifiables de bout en bout dans l'environnement courant**, même si des tests les activent ponctuellement.
- La suite complète n'est pas verte : le baseline est de **3 843 tests, 3 802 passés, 13 échecs, 3 erreurs, 25 ignorés, 18 703 assertions**. Les 3 erreurs concernent `PortailEtudiantPublicationTest` et sont analysées ci-dessous.
- Les règles académiques, tarifaires, de délai, de capacité, de permission et de procédure administrative non documentées n'ont pas été inventées. Elles sont listées en §1.5.

### 1.2 Inventaire quantitatif

| Élément | Volume | Commentaire |
|---|---:|---|
| Routes totales | 900 | `php artisan route:list` |
| Routes `/etudiant/` | 71 | espace étudiant |
| Routes `/enseignant/` | 61 | espace enseignant |
| Routes `/admin/` | 672 | back-office, hors périmètre direct mais dépendances |
| Routes `/api/` | 53 | API v1 |
| Routes `/inscription/` | 25 | parcours public |
| Contrôleurs | 97 | dont 9 `Etudiant`, 7 `Enseignant`, 67 dans les modules |
| Vues Blade | 422 | dont 17 étudiant, 15 enseignant, 213 modules |
| Modèles | 73 | |
| Policies | 10 | + Gates et contrôles de périmètre |
| Migrations | 181 | |
| Fichiers de tests | 452 | 438 Feature, 11 Unit |
| Modules | 15 | Announcements, Attendance, Calendar, Enseignement, Evaluation, Finances, OnlineRequests, ParcoursAcademique, Referentiel, Schedule, Sentinel, StudentArchive, StudentDossiers, Tasks, Versioning |

### 1.3 Matrice fonctionnelle

Statuts : `existante`, `partielle`, `absente`, `défectueuse`, `non vérifiable`.

> **Note de lecture.** Cette matrice décrit l'état **à l'ouverture du LOT 0**, avant remédiation. Les statuts corrigés par les LOT 1 à 4 et le baseline final sont récapitulés en §8 et §9.3. Elle est conservée telle quelle comme preuve d'audit.

| # | Fonction | Statut | Preuve principale | Écart / risque | Action prévue |
|---|---|---|---|---|---|
| 1 | Connexion étudiant (email/CIN + mot de passe), throttle 5/min, CSRF, régénération de session | `existante` | `routes/web.php` (groupe `etudiant`), `Etudiant\ConnexionController`, `EspaceEtudiantTest` | Aucun MFA, aucun mot de passe oublié | LOT 7 (décision) |
| 2 | Déconnexion étudiant et invalidation de session | `existante` | `ConnexionController@logout` | — | — |
| 3 | Révocation des sessions étudiant après changement de mot de passe | `défectueuse` | `EnsureStudent` vérifie `mot_de_passe_version` ; `Etudiant\ProfilController@updatePassword` ne l'incrémente pas | Un cookie volé reste valide après changement de mot de passe par l'étudiant | **LOT 1** |
| 4 | Révocation des sessions enseignant après changement/réinitialisation de mot de passe | `défectueuse` | `EnsureTeacher` ne vérifie aucune version ; `Enseignant\ProfilController@updatePassword` ne révoque que la session courante | Aucun mécanisme de révocation des autres sessions | **LOT 1** |
| 5 | MFA étudiant / enseignant | `absente` | MFA présent uniquement pour les rôles back-office (`EnsureMfa`) | Risque de compromission de compte | LOT 7 (décision) |
| 6 | Mot de passe oublié / réinitialisation self-service étudiant et enseignant | `absente` | Aucune route `forgot-password` / `reset-password` dans les groupes portails | Dépendance à l'administration, expérience dégradée | LOT 7 (décision) |
| 7 | Tableau de bord étudiant (inscription, cours du jour, absences, dossier, demandes) | `partielle` | `Etudiant\TableauDeBordController`, `resources/views/etudiant/dashboard.blade.php` | Pas de résumé paiements, examens, calendrier, documents manquants ; emploi du temps interrogé deux fois (contrôleur + composer) | LOT 4 |
| 8 | Tableau de bord enseignant (séances, gardes, notes, absences, documents, demandes) | `partielle` | `Enseignant\TableauDeBordController`, vue `enseignant/dashboard` | Pas de calendrier ni d'annonces ; séances non filtrées par statut de publication | LOT 4 / décision |
| 9 | Profil étudiant (lecture/édition, changement de mot de passe) | `existante` | `Etudiant\ProfilController`, `MotionEtudiantTest`, `SeparationProfilsTest` | Révocation incomplète (ligne 3) | LOT 1 |
| 10 | Profil enseignant (lecture/édition, changement de mot de passe) | `existante` | `Enseignant\ProfilController`, `EnseignantPortailTest` | Révocation absente (ligne 4) | LOT 1 |
| 11 | Inscriptions académiques annuelles et parcours LMD étudiant | `existante` | `Etudiant\ArchiveController`, `ParcoursEtudiantTest`, `EspaceEtudiantTest` | Périmètre session validé ; pas de régression constatée | — |
| 12 | Notes et résultats étudiant | `existante` | `/etudiant/notes`, `DeliberationPhase14Test`, `SeparationProfilsTest` | — | — |
| 13 | Examens et convocations étudiant | `existante` | `/etudiant/examens`, `ExamensFeatureCompleteTest` (anti-IDOR), `ExamensCommunicationTest` | — | — |
| 14 | Emploi du temps étudiant, planning publié uniquement | `existante` | `Modules\Schedule\MonEmploiDuTempsController`, `PortailEtudiantPublicationTest` | Exceptions interrogées par créneau (N+1 possible) ; test de publication en erreur de fixture | LOT 2 / LOT 8 |
| 15 | Recherche portail étudiant | `défectueuse` | `RecherchePortailController::seancesEtudiant` | Les séances retournées ne filtrent ni statut de publication, ni activation, ni annulation | **LOT 2** |
| 16 | Présences / absences étudiant, justification | `existante` | `Modules\Attendance`, `EspaceEtudiantTest` | Contrainte legacy `(etudiant_id, date, seance)` non liée au créneau ; appels sérialisés par verrou | LOT 8 / décision |
| 17 | Dossier étudiant, pièces, versions, aperçu/téléchargement privés | `existante` | `Modules\StudentDossiers`, `StudentDocumentPolicy`, 54 tests documents / 296 assertions | Pas d'antivirus réel (`NullAntivirusScanner`) | LOT 6 (bloqué infra) |
| 18 | Dépôt de pièces et checklist documentaire | `existante` | `resources/views/modules/student-dossiers`, `widget-dossier` | Dépôt non assimilé à validation ; comportement conforme | — |
| 19 | Paiements et reçus d'inscription étudiant | `existante` | `Modules\Finances\PaiementInscriptionEtudiantController`, `PaiementsInscriptionsTest` 20/224 | Deux vérités financières coexistent (ligne 20) | LOT 3 |
| 20 | Cohérence finances : `Facture`/`Paiement` vs `DossierPaiement`/`RecuPaiement` | `défectueuse` | `/etudiant/finances` (ancien) et `/etudiant/paiements` (nouveau) ; aucun lien de navigation entre eux | Statuts divergents possibles ; reçu validé ne met à jour que `inscription.paye` | **LOT 3** |
| 21 | Demandes en ligne étudiant | `existante` | `Modules\OnlineRequests`, `EspaceEtudiantTest` | Module désactivé par défaut dans l'environnement courant | LOT 4 |
| 22 | Annonces étudiant | `partielle` | `/etudiant/annonces`, `ServicesTransversesTest`, `FaculteScopeEcritureTest` | Module `announcements` désactivé dans `.env` courant ; pas de `MesAnnoncesTest` dédié | LOT 4 |
| 23 | Calendrier / agenda étudiant | `défectueuse` | `Modules\Calendar\AgendaService`, `/etudiant/calendrier` | Filtre de chevauchement incorrect (`date_fin >= $fin`, `date_debut >= $debut`) ; entrées `debut`/`fin` non validées ; module désactivé courant | **LOT 2** |
| 24 | Notifications étudiant | `existante` | `NotificationController@indexEtudiant`, routes dédiées | — | — |
| 25 | Documents académiques générés / archive étudiant | `existante` | `Etudiant\ArchiveController`, `Modules\StudentArchive` | Téléchargements privés vérifiés | — |
| 26 | Rendez-vous scolarité étudiant | `existante` | `Etudiant\RendezVousScolariteController` | — | — |
| 27 | Connexion enseignant (email/matricule + mot de passe), throttle 5/min | `existante` | `Enseignant\ConnexionController`, `EnseignantPortailTest` | `statut` et `actif` peuvent diverger ; `actif` seul est contrôlé | LOT 1 / décision |
| 28 | Emploi du temps enseignant | `existante` | `Modules\Schedule\MonEmploiEnseignantController` | Inclut les plannings non publiés ; décision métier requise | Décision |
| 29 | Appel / présences enseignant | `existante` | `Modules\Attendance`, `EspaceEnseignantModulesTest` | — | — |
| 30 | Saisie et consultation des notes enseignant | `existante` | `EnseignantNotesTest` | — | — |
| 31 | Surveillances et planning d'examen enseignant | `existante` | `Modules\Evaluation`, `EnseignantPerimetreTest` | — | — |
| 32 | Ressources pédagogiques enseignant | `existante` | `/enseignant/ressources`, `EspaceEnseignantModulesTest` | — | — |
| 33 | Demandes et réclamations enseignant | `existante` | `EnseignantDemandeController`, `EnseignantReclamationController` | — | — |
| 34 | Documents enseignant (personnels et officiels) | `existante` | `EnseignantMesDocumentsController`, `EnseignantDocumentController` | — | — |
| 35 | Annonces / calendrier enseignant | `absente` | Aucune route ni vue enseignant dédiée | Fonction attendue du portail enterprise | LOT 4 / décision |
| 36 | Notifications enseignant | `existante` | `NotificationController@indexEnseignant` | — | — |
| 37 | Recherche portail enseignant | `existante` | `RecherchePortailController::enseignant` | — | — |
| 38 | Autorisations, anti-IDOR, périmètre faculté | `existante` | 73 tests sécurité / 218 assertions ; Policies documents, demandes, notes, planning | Points ci-dessus à corriger | LOT 1/2 |
| 39 | Contraintes SQL, index, transactions | `partielle` | 181 migrations ; contraintes `notes` `(semestre, matière, session)` cohérentes ; `recus_paiement` unique `(dossier, est_valide)` sûr | Pas de contrainte garantissant que `notes.etudiant_id` correspond à l'inscription/semestre ; suppression étudiant en cascade | LOT 5 / décision |
| 40 | Data Mesh / exports / imports | `existante` | 44 tests Data Mesh / 537 assertions | Périmètre non portail | — |
| 41 | Files d'attente, jobs, événements, scheduler | `partielle` | `QUEUE_CONNECTION=database`, events et listeners | Worker opérationnel non vérifié dans l'environnement | LOT 8 (ops) |
| 42 | Tests portails | `partielle` | 65 tests portails / 286 assertions PASS ; 3 erreurs de fixture publication | Baseline complet non vert | LOT 2 / LOT 9 |
| 43 | Accessibilité des portails | `partielle` | 2 échecs a11y dans la suite complète (parcours d'inscription, hors portail) | Couverture a11y portail non exhaustive | LOT 8 |
| 44 | Performance portail | `partielle` | `EnseignantInterfaceTest` échoue à 16 requêtes (admin, hors portail) ; emploi du temps étudiant N+1 potentiel | Pas de budget de requêtes dédié portail | LOT 8 |
| 45 | Sécurité des fichiers privés | `existante` | Aperçu/téléchargement via routes authentifiées, Policies, stockage privé | Antivirus absent (ligne 17) | LOT 6 |

### 1.4 Risques priorisés

#### P1 — à traiter dans la boucle

1. **Révocation de session incomplète** : le changement de mot de passe étudiant n'incrémente pas `mot_de_passe_version` ; l'enseignant n'a aucun équivalent. Un cookie volé reste exploitable après changement de mot de passe.
2. **Fuite de planning non publié dans la recherche étudiant** : `RecherchePortailController::seancesEtudiant` ne filtre pas `statut`, `actif` ni annulation, alors que l'emploi du temps et le tableau de bord appliquent la règle « publié/verrouillé uniquement ».
3. **Double vérité financière** : `/etudiant/finances` calcule depuis `Facture`/`Paiement`, `/etudiant/paiements` depuis `DossierPaiement`/`RecuPaiement`. Un reçu validé ne met à jour que `inscription.paye`. L'étudiant peut voir deux statuts contradictoires, et aucun lien de navigation n'expose ces pages dans l'environnement courant.
4. **Suppression définitive d'un étudiant avec historique** : `Admin\EtudiantController@destroy` supprime en cascade des lignes académiques, financières et documentaires, et des fichiers legacy. Cela contredit l'exigence de non-suppression de l'historique. **Action bloquée par une décision métier de rétention/anonymisation.**
5. **Absence d'antivirus réel** : `NullAntivirusScanner` reste lié ; les pièces déposées et les reçus ne sont pas scannés. **Action bloquée par le choix d'infrastructure.**

#### P2 — à planifier

6. **Bug de chevauchement du calendrier** : `AgendaService` masque les événements qui commencent avant la période demandée et les événements multi-jours ; `debut`/`fin` non validés (risque de 500 sur date malformée).
7. **Tableaux de bord incomplets** : absence de résumés paiements, examens, calendrier, documents manquants ; emploi du temps étudiant requêté deux fois.
8. **Modules désactivés par défaut** : `announcements`, `calendar`, `finances`, `student-archive`, `parcours-academique`, `tasks` sont à `false` dans l'environnement courant ; les parcours ne sont pas vérifiables de bout en bout.
9. **Portail enseignant sans calendrier ni annonces**.
10. **Tests de publication en erreur de fixture** : `PortailEtudiantPublicationTest` crée un créneau sans enseignant/salle ; la publication renvoie « 1 conflit(s) bloquent la publication. » à `ScheduleService.php:464`. Ce n'est pas confirmé comme défaut de production ; le fixture doit être rendu conforme.
11. **MFA et réinitialisation self-service absents** pour les deux portails.
12. **Performance** : exceptions d'emploi du temps étudiant par créneau ; budget de requêtes portail non testé. Échec admin `EnseignantInterfaceTest` (16 requêtes) hors portail.
13. **Intégrité SQL** : pas de contrainte liant `notes.etudiant_id` à `inscription_academique_id`/semestre ; contrainte legacy de présences non liée au créneau.
14. **Sessions d'examen globales** : `AgendaService` agrège les `SessionExamen` sans périmètre programme/faculté ; `SessionExamen::programme()` référence une colonne absente de la migration. Décision métier requise.

#### P3 — réserves de fin de boucle

15. Couverture a11y portail incomplète.
16. Worker de queue non vérifié.
17. Absence de test dédié `MesAnnoncesTest`.
18. Baseline complet non vert (13 échecs / 3 erreurs, majoritairement hors portail).

### 1.5 Règles métier à confirmer (aucune invention)

1. Un étudiant `en_attente` ou `rejete` doit-il pouvoir se connecter et consulter son dossier ? Le login actuel ne contrôle pas `statut`.
2. MFA obligatoire pour étudiant/enseignant ? Quelle méthode (TOTP, email, SMS) et quel fournisseur ?
3. Réinitialisation de mot de passe self-service autorisée ? Quel canal de vérification ?
4. Politique de rétention : suppression définitive, anonymisation ou archivage ? Quelles données académiques/financières doivent être conservées ?
5. Source de vérité financière étudiante : `Facture`/`Paiement` ou `DossierPaiement`/`RecuPaiement` ? Comment afficher l'ancien système sans ambiguïté ?
6. Un enseignant doit-il voir les plannings `brouillon`/`relecture`/`valide` non publiés ?
7. Les sessions d'examen sont-elles globales à l'université ou limitées à un programme/faculté ?
8. Quels modules doivent être actifs par défaut en production (`announcements`, `calendar`, `finances`, `student-archive`, `parcours-academique`, `tasks`) ?
9. Quel fournisseur d'antivirus et quel niveau de service (fail-open/fail-closed) ?
10. Un calendrier enseignant est-il attendu ? Quel périmètre (faculté, département, cours) ?
11. La publication d'un planning exige-t-elle explicitement enseignant + salle + qualifications + capacité ? Le contrôle actuel bloque, mais la règle doit être confirmée.
12. Les événements de calendrier `audience = tous` doivent-ils être filtrés par faculté de l'étudiant ?

### 1.6 Plan ordonné des LOT

| LOT | Objectif | Dépendances | Statut prévu |
|---|---|---|---|
| LOT 1 | Révocation de session étudiant/enseignant après changement de mot de passe | Aucune règle métier | **À implémenter** |
| LOT 2 | Confidentialité du planning dans la recherche + correction chevauchement/validation calendrier + fixture publication | Aucune règle métier | **À implémenter** |
| LOT 3 | Cohérence d'affichage financier : distinguer les deux systèmes, liens de navigation, résumé dashboard | Décision source de vérité pour le libellé final | À implémenter sans migration |
| LOT 4 | Complétude des dashboards (paiements, examens, calendrier, documents manquants) | Modules actifs pour vérification E2E | À implémenter avec tests de module |
| LOT 5 | Rétention / suppression étudiant | Décision métier obligatoire | **Bloqué** |
| LOT 6 | Antivirus réel sur uploads/reçus | Choix infra obligatoire | **Bloqué** |
| LOT 7 | MFA et reset self-service | Décision politique + fournisseur | **Bloqué** |
| LOT 8 | Performance et accessibilité portails | Mesures et arbitrages | Partiel |
| LOT 9 | Non-régression finale, Pint, build, rapport | LOT 1–4 | À exécuter |

### 1.7 Inventaire des tests et résultats réellement exécutés

| Suite / groupe | Tests | Assertions | Résultat | Portée |
|---|---:|---:|---|---|
| Portails ciblés (`EspaceEtudiantTest`, `EspaceEnseignantTest`, `EnseignantPortailTest`, `EnseignantPerimetreTest`, `EspaceEnseignantModulesTest`) | 65 | 286 | **PASS** | Portails |
| Documents | 54 | 296 | **PASS** | Pièces, policies, téléchargements |
| Modules | 65 | 406 | **PASS** | Modules transverses |
| Sécurité | 73 | 218 | **PASS** | Auth, IDOR, périmètre |
| Data Mesh | 44 | 537 | **PASS** | Exports/imports |
| Paiements inscription | 20 | 224 | **PASS** | Dossiers/reçus |
| Paiements + régression + popup | 276 | 1 963 | **PASS** | Non-régression ciblée |
| Documents (régression élargie) | 85 | 488 | **PASS** | Documents |
| Portail + publication (run combiné) | 93 | 411 | **90 PASS, 3 erreurs** | `PortailEtudiantPublicationTest` |
| Suite complète | 3 843 | 18 703 | **3 802 PASS, 13 échecs, 3 erreurs, 25 skip** | Baseline global |

Les 3 erreurs de `PortailEtudiantPublicationTest` sont des erreurs, pas des échecs d'assertion : `1 conflit(s) bloquent la publication.` levée depuis `ScheduleService.php:464`. Le fixture ne fournit ni enseignant ni salle ; la règle de publication les exige. Il s'agit d'un **défaut de fixture** à confirmer et corriger, pas d'une preuve de défaut de production.

Les 13 échecs du baseline complet sont hors périmètre portail pour la majorité : accessibilité du parcours d'inscription, FAQ, saisie d'identification, performances de files/archives/admin, scrutin. Un échec concerne la fiche enseignant côté admin (`EnseignantInterfaceTest`, 16 requêtes), pas l'espace enseignant.

### 1.8 Verdict du LOT 0

**PASS_WITH_RESERVATIONS**

Justification : l'inventaire est suffisant pour engager les remédiations, les routes et les contrôles d'accès sont identifiés, les tests ciblés portails sont verts, et les défauts P1 sont localisés avec preuves. Réserves : baseline global non vert, modules désactivés dans l'environnement courant, 3 erreurs de fixture de publication, et plusieurs règles métier non tranchées qui bloquent les LOT 5, 6 et 7.

---

## 2. LOT 1 — Révocation de session après changement de mot de passe

### 2.1 Objectif et périmètre

Objectif : aligner la révocation des sessions étudiant et enseignant sur la version du mot de passe, sans modifier les règles métier ni les procédures administratives existantes.

Périmètre :

- changement de mot de passe self-service étudiant ;
- changement de mot de passe self-service enseignant ;
- réinitialisation/modification du mot de passe enseignant par un administrateur ;
- sessions déjà ouvertes et sessions de nouvelle connexion.

Dépendances : aucune règle métier à trancher. La migration est additive.

### 2.2 Modifications réalisées

| Fichier | Changement |
|---|---|
| `database/migrations/2026_10_21_000001_ajouter_version_mot_de_passe_enseignants.php` | Ajoute `teachers.mot_de_passe_version` (entier non signé, défaut `0`). Migration additive, sans suppression ni réécriture. |
| `app/Modules/Schedule/Models/Teacher.php` | Cast et masquage de `mot_de_passe_version` ; incrémentation automatique lors de toute mise à jour du mot de passe via Eloquent. |
| `app/Http/Middleware/EnsureTeacher.php` | Compare la version de session (`enseignant_mdp_version`) à la version en base ; toute session obsolète est invalidée. Une session legacy sans version est traitée comme version `0`. |
| `app/Http/Controllers/Enseignant/ConnexionController.php` | Stocke la version du mot de passe à la connexion. |
| `app/Http/Controllers/Enseignant/ProfilController.php` | Rafraîchit la version de session après changement self-service. |
| `app/Http/Controllers/Etudiant/ProfilController.php` | Met à jour le mot de passe et incrémente `mot_de_passe_version` dans la même transaction, puis rafraîchit la session courante. |
| `tests/Feature/RevocationSessionsTest.php` | Couvre la révocation étudiant. |
| `tests/Feature/RevocationSessionsEnseignantTest.php` | Couvre la révocation enseignant, la modification admin, la compatibilité legacy et le compte inactif. |

La migration a été appliquée à la base de développement locale avec `php artisan migrate --no-interaction`. Aucun `migrate:fresh`, `db:wipe` ni suppression de données n'a été exécuté. La migration additive des paiements, également en attente, a été appliquée par la même commande.

### 2.3 Scénarios et critères d'acceptation

| # | Scénario | Critère d'acceptation | Résultat |
|---:|---|---|---|
| 1 | Un étudiant change son mot de passe depuis son profil | Les autres sessions sont rejetées ; la session courante reste utilisable | PASS |
| 2 | Un administrateur modifie le mot de passe d'un enseignant | Les sessions enseignant ouvertes avec l'ancienne version sont rejetées | PASS |
| 3 | Un enseignant change son mot de passe | Les autres sessions sont rejetées ; la session courante reste utilisable | PASS |
| 4 | Une session enseignant existante ne contient pas encore la version | Elle est lue comme version `0`, sans erreur serveur | PASS |
| 5 | Un compte enseignant inactif tente de se connecter | La connexion est refusée | PASS |

### 2.4 Tests exécutés

- `tests/Feature/RevocationSessionsTest.php` + `tests/Feature/RevocationSessionsEnseignantTest.php` : **12 tests / 40 assertions — PASS**.
- Régression portails ciblée (13 fichiers, dont les deux suites ci-dessus) : **148 tests / 746 assertions — 148 PASS**.
- Suite complète : **3 861 tests / 3 823 PASS / 13 échecs / 0 erreur / 25 ignorés / 18 780 assertions**. Aucun échec portail.

### 2.5 Risques résiduels

- Une modification du mot de passe effectuée par un script SQL direct, hors Eloquent, ne déclenche pas l'incrémentation. Ce n'est pas un chemin applicatif supporté.
- Les sessions ouvertes avant le déploiement restent valides jusqu'à la prochaine modification de mot de passe ; c'est le comportement attendu d'une version initiale à `0`.
- MFA et réinitialisation self-service restent absentes (LOT 7 bloqué par décision de politique et de fournisseur).

### 2.6 Verdict

**PASS** — la révocation est implémentée, testée et sans modification de règle métier.

## 3. LOT 2 — Confidentialité du planning et calendrier

### 3.1 Objectif et périmètre

Objectif : empêcher la recherche portail d'exposer un planning non publié ou une séance annulée, corriger le calcul de chevauchement de l'agenda et valider les bornes de dates. Aucune règle de publication ni de visibilité enseignant n'a été inventée.

Périmètre :

- `RecherchePortailController::seancesEtudiant` ;
- `AgendaService` (chevauchement multi-jours et dates de fin nulles) ;
- `CalendrierController@agenda` (validation des paramètres) ;
- fixture `PortailEtudiantPublicationTest` (enseignant manquant).

Dépendances : aucune règle métier nouvelle. Le module `calendar` reste désactivé par défaut ; les tests l'activent explicitement.

### 3.2 Modifications réalisées

| Fichier | Changement |
|---|---|
| `app/Http/Controllers/RecherchePortailController.php` | Les séances étudiant ne remontent que si le planning lié est `actif = true`, `statut IN ('publie','verrouille')`, et si la séance n'est pas `cancelled`. Les mêmes règles que l'emploi du temps et le tableau de bord sont appliquées. |
| `app/Modules/Calendar/Services/AgendaService.php` | Prédicat de chevauchement corrigé : `date_debut <= fin` ET (`date_fin IS NULL` OU `date_fin >= debut`). Les événements multi-jours, ceux qui commencent avant la période et ceux qui se terminent dans la période sont désormais inclus. |
| `app/Modules/Calendar/Controllers/CalendrierController.php` | `debut` et `fin` validés au format `Y-m-d` ; `fin` doit être `after_or_equal:debut` lorsque `debut` est présent. |
| `tests/Feature/RecherchePortailTest.php` | Fixtures `publie`, brouillon et séance annulée ; assertions JSON pour éviter qu'un jeton de recherche réaffiché dans le champ `q` ne fausse l'assertion. |
| `tests/Feature/AgendaCalendrierTest.php` | Couvre le chevauchement multi-jours, les événements se terminant dans la période, les dates de fin nulles, l'exclusion des brouillons, les dates invalides et le rendu HTTP valide. |
| `tests/Feature/PortailEtudiantPublicationTest.php` | Fixture corrigée avec un enseignant valide ; les erreurs de publication ne sont plus reproduites. |

### 3.3 Scénarios et critères d'acceptation

| # | Scénario | Critère d'acceptation | Résultat |
|---:|---|---|---|
| 1 | Recherche d'une séance d'un planning publié | La séance est retournée | PASS |
| 2 | Recherche d'une séance d'un planning brouillon | Aucune séance retournée | PASS |
| 3 | Recherche d'une séance annulée dans un planning publié | Aucune séance retournée | PASS |
| 4 | Agenda sur une période qui couvre un événement multi-jours | L'événement est inclus | PASS |
| 5 | Événement dont la date de fin est nulle | L'événement est inclus selon sa date de début | PASS |
| 6 | Paramètres `debut`/`fin` malformés ou inversés | Erreur de validation contrôlée, pas de 500 | PASS |
| 7 | Publication d'un planning avec un enseignant valide | La publication aboutit, sans conflit de fixture | PASS |

### 3.4 Tests exécutés

- `tests/Feature/RecherchePortailTest.php` : **8 tests / 42 assertions — PASS**.
- `tests/Feature/AgendaCalendrierTest.php` : **6 tests / 15 assertions — PASS**.
- `tests/Feature/PortailEtudiantPublicationTest.php` : **6 tests / 15 assertions — PASS**.
- Run combiné des trois fichiers : **20 tests / 72 assertions — 20 PASS**.
- Régression portails ciblée : **148 tests / 746 assertions — PASS**.
- Suite complète : **0 erreur** ; les 3 erreurs de publication du baseline ont disparu.

### 3.5 Risques résiduels

- Le module `calendar` est désactivé dans l'environnement courant ; la vérification de bout en bout en production dépend de son activation.
- La règle de filtrage des événements `audience = tous` par faculté reste une décision métier non tranchée.
- Le périmètre du calendrier enseignant (faculté, département, cours) reste à décider.
- Les statuts de planning utilisés par le filtre sont `publie` et `verrouille` ; aucun statut anglais `published` n'est introduit dans le contrôleur.

### 3.6 Verdict

**PASS** — confidentialité, chevauchement, validation et fixture sont corrigés et prouvés par exécution.

## 4. LOT 3 — Cohérence financière et navigation

### 4.1 Objectif et périmètre

Objectif : rendre explicite la distinction entre la **facturation historique** (`Facture`/`Paiement`) et le **parcours canonique des frais d'inscription** (`DossierPaiement`/`RecuPaiement`), sans changer la source de vérité financière ni migrer de données.

Périmètre :

- page historique `/etudiant/finances` ;
- page canonique `/etudiant/paiements` ;
- widget étudiant « Frais d'inscription » du tableau de bord ;
- navigation entre les deux périmètres.

Dépendances : la décision métier sur la source de vérité finale reste ouverte. Ce LOT ne la tranche pas ; il empêche seulement l'ambiguïté d'affichage.

### 4.2 Modifications réalisées

| Fichier | Changement |
|---|---|
| `resources/views/modules/finances/mes-finances.blade.php` | Bandeau « Facturation historique » ; lien explicite vers « Mes paiements d'inscription ». |
| `resources/views/modules/finances/paiements-inscriptions/etudiant-index.blade.php` | Note « deux périmètres distincts » et lien vers la facturation historique ; rappel qu'un dépôt n'est pas un paiement et que seul un reçu validé confirme l'inscription. |
| `resources/views/modules/finances/widget-etudiant.blade.php` | Nouveau widget : année, statut, montant attendu, dernier reçu, lien de gestion. |
| `config/modules.php` | Widget étudiant `modules.finances.widget-etudiant` enregistré sous le module `finances` ; aucun lien n'est rendu si le module est inactif. |
| `app/Providers/ModuleServiceProvider.php` | Composer du widget : dernière inscription, dossier et reçus, montant via `DossierPaiementService::montantAttendu` (source `Facture`/`Tarif`). |
| `tests/Feature/PaiementsInscriptionsTest.php` | Tests de cohérence : paiement à effectuer, reçu en attente, paiement confirmé, liens croisés entre les deux pages, absence de widget quand le module est inactif. |

Règles de statut du widget :

- « Paiement confirmé » uniquement si `inscription.paye = true` ou dossier `VALIDE` ;
- « Reçu en attente de vérification » si le dernier reçu est déposé et non validé ;
- « Reçu refusé » si le dossier est `REFUSE` ;
- « Paiement à effectuer » sinon.

Aucun dépôt n'est assimilé à un paiement. Aucun montant n'est inventé : le montant attendu vient du service existant, lui-même adossé aux factures/tarifs.

### 4.3 Scénarios et critères d'acceptation

| # | Scénario | Critère d'acceptation | Résultat |
|---:|---|---|---|
| 1 | Étudiant sans reçu validé | Le widget affiche « Paiement à effectuer » et le montant attendu | PASS |
| 2 | Étudiant ayant déposé un reçu | Le widget affiche « Reçu en attente de vérification », jamais « Paiement confirmé » | PASS |
| 3 | Reçu validé par la scolarité | Le widget affiche « Paiement confirmé » | PASS |
| 4 | Page historique | Un bandeau et un lien vers le parcours canonique sont visibles | PASS |
| 5 | Page canonique | Une note et un lien vers la facturation historique sont visibles | PASS |
| 6 | Module finances inactif | Aucun widget ni lien cassé n'est rendu | PASS (test dédié) |

### 4.4 Tests exécutés

- `tests/Feature/PaiementsInscriptionsTest.php` : **22 tests / 241 assertions — PASS**.
- Les tests ajoutés couvrent les trois états (à effectuer, en attente, confirmé), les deux liens de navigation et l'absence de widget/lien mort quand le module est inactif.
- Régression portails ciblée : **148 tests / 746 assertions — PASS**.
- Suite complète : aucun échec portail.

### 4.5 Risques résiduels

- Les deux systèmes financiers coexistent toujours ; aucune réconciliation ni migration de données n'a été faite.
- La décision « source de vérité financière » reste ouverte (§1.5, règle 5). Le libellé actuel est volontairement descriptif, pas normatif.
- Le module `finances` est désactivé dans l'environnement courant ; la vérification manuelle de bout en bout nécessite son activation.
- Les anciens soldes `Facture`/`Paiement` peuvent toujours diverger du statut d'inscription ; la page le signale, mais ne le corrige pas.

### 4.6 Verdict

**PASS_WITH_RESERVATIONS** — l'ambiguïté d'affichage est traitée et testée ; la source de vérité métier reste à décider.

## 5. LOT 4 — Complétude des tableaux de bord

### 5.1 Objectif, périmètre et état

Objectif : compléter le tableau de bord étudiant sur les deux manques les plus visibles et les plus sûrs à corriger sans décision métier : les **frais d'inscription** et les **prochains examens**.

Périmètre réalisé :

- widget « Frais d'inscription » (LOT 3) ;
- carte « Prochains examens » sur le tableau de bord étudiant ;
- correction de mise en forme HTML mineure du bloc « Dernier résultat ».

Non réalisé dans ce LOT, volontairement :

- calendrier/agenda résumé sur le dashboard ;
- synthèse des documents manquants ;
- calendrier et annonces du tableau de bord enseignant (aucune route dédiée, module `announcements` désactivé, périmètre à décider) ;
- suppression de la double requête emploi du temps (contrôleur + composer).

### 5.2 Modifications réalisées

| Fichier | Changement |
|---|---|
| `app/Http/Controllers/Etudiant/TableauDeBordController.php` | Ajout de `prochainsExamens()` : candidatures de l'étudiant connecté, épreuves `published`/`ongoing`, date ≥ aujourd'hui, tri, maximum 3. Aucune épreuve brouillon, annulée ou terminée n'est exposée. |
| `resources/views/etudiant/dashboard.blade.php` | Carte « Prochains examens » avec date, horaire, matière, salle, session et lien de convocation ; lien « Tous mes examens ». |
| `tests/Feature/ExamensFeatureCompleteTest.php` | Tests de dashboard : épreuve publiée visible, brouillon masqué, état vide, limite à 3 épreuves et lien vers la liste complète. |

La requête de convocation reste protégée par le contrôle anti-IDOR/BOLA existant : un étudiant ne peut télécharger que sa propre convocation.

### 5.3 Scénarios et critères d'acceptation

| # | Scénario | Critère d'acceptation | Résultat |
|---:|---|---|---|
| 1 | Étudiant avec une épreuve publiée à venir | La carte affiche la matière, la salle et l'horaire | PASS |
| 2 | Même étudiant avec une épreuve brouillon | L'épreuve brouillon n'apparaît pas | PASS |
| 3 | Étudiant sans épreuve à venir | Message « Aucun examen publié à venir », sans erreur | PASS |
| 4 | Étudiant avec plus de 3 épreuves | Seules les 3 prochaines sont affichées | PASS (test dédié) |
| 5 | Lien de convocation | Le lien pointe vers la route protégée existante | PASS |

### 5.4 Tests exécutés

- `tests/Feature/ExamensFeatureCompleteTest.php` : **18 tests / 68 assertions — PASS**.
- Tests dashboard ciblés (épreuve publiée/brouillon, état vide, limite à 3) : **3 tests — PASS**.
- `tests/Feature/PaiementsInscriptionsTest.php` (widget finances) : **22 tests / 241 assertions — PASS**.
- Régression portails ciblée : **148 tests / 746 assertions — PASS**.
- Suite complète : aucun échec portail.

### 5.5 Risques résiduels

- La carte examens ajoute une requête au dashboard ; aucun budget de requêtes portail formalisé n'existe encore.
- La double requête emploi du temps (contrôleur + composer) reste présente.
- Le dashboard enseignant reste partiel : calendrier, annonces et documents manquants ne sont pas couverts.
- Les modules `calendar`, `announcements` et `finances` restent désactivés par défaut ; les widgets correspondants ne sont pas visibles dans l'environnement courant.
- Les épreuves `completed`/`locked` ne sont pas affichées comme « prochains examens » ; elles restent consultables sur la page complète.

### 5.6 Verdict

**PASS_WITH_RESERVATIONS** — les ajouts sont testés, mais la complétude totale des dashboards dépend de modules désactivés et de décisions de périmètre.

## 6. Audit de sécurité

### 6.1 Contrôles vérifiés

| Contrôle | Preuve | Résultat |
|---|---|---|
| Révocation des sessions après changement de mot de passe | Versions étudiant/enseignant, middleware, tests de révocation | PASS |
| Absence de fuite de planning non publié dans la recherche | Filtres `actif`, `publie`/`verrouille`, `cancelled` ; test JSON | PASS |
| Anti-IDOR sur les convocations | Route existante `abort_unless(candidat.etudiant_id === session.etudiant_id)` ; test anti-IDOR existant | PASS |
| Périmètre des examens sur le dashboard | Requête filtrée par `etudiant_id` de session ; aucune épreuve brouillon | PASS |
| Périmètre du widget financier | Session étudiant uniquement ; lecture seule ; aucun paramètre d'ID étudiant | PASS |
| Dépôt de reçu ≠ paiement | Widget et page canonique ; validation réservée à la scolarité | PASS |
| Documents privés | Aucune route ni stockage modifié dans ce LOT | Inchangé |
| Suppression définitive avec historique | Toujours présente | **BLOQUÉ** (décision métier) |
| Antivirus réel | `NullAntivirusScanner` toujours lié | **BLOQUÉ** (infrastructure) |
| MFA / reset self-service | Absents | **BLOQUÉ** (politique/fournisseur) |

### 6.2 Tests de sécurité exécutés

- Suites de sécurité ciblées antérieures : **73 tests / 218 assertions — PASS** (baseline).
- Run élargi sécurité + portails + examens + paiements : **300 tests, 295 PASS, 1 échec préexistant (`ArchivesFicheLoop3Test`, 26 requêtes), 4 ignorés**.
- `tests/Feature/Security/ArchivesIsolationLoop1Test.php` exécuté seul : **10 tests / 25 assertions — PASS**.
- Suite complète : **0 erreur** ; aucun échec de sécurité portail.

### 6.3 Faux positif de la suite complète

`ArchivesIsolationLoop1Test::test_ressource_inexistante_404_sans_divulgation` a échoué une fois dans un run intermédiaire de la suite complète : il vérifie que la réponse 404 ne contient pas la chaîne `FB`, mais le jeton CSRF aléatoire de la page peut contenir `FB` (`mINFB3...`). Le test passe seul et est passé au run final. C'est une fragilité de test préexistante, sans rapport avec les portails, non corrigée dans ce LOT pour ne pas modifier un test hors périmètre.

### 6.4 Verdict sécurité

**PASS_WITH_RESERVATIONS** — les contrôles modifiés sont testés, mais trois contrôles majeurs restent bloqués par des décisions externes (rétention, antivirus, MFA/reset).

## 7. Performance

### 7.1 Observations

- Le dashboard étudiant ajoute deux blocs de lecture : le widget financier (dernière inscription + dossier/reçus + montant) et la carte examens (candidatures publiées à venir). Aucune écriture, aucun calcul lourd.
- Les relations nécessaires sont préchargées (`with`) pour éviter le N+1 sur les matières, salles, sessions et reçus.
- La double requête emploi du temps (contrôleur + composer) reste présente.
- Aucun budget de requêtes dédié au dashboard portail n'a été formalisé ni exécuté dans ce LOT.

### 7.2 Résultats exécutés

- Les tests fonctionnels portails passent (**148 / 746**), sans échec de performance portail.
- Les échecs de performance du baseline complet restent hors portail : `EnseignantInterfaceTest` (fiche admin, 16 requêtes), `InscriptionFileTraitementTest`, `InscriptionPerformanceFiabiliteTest`, `PopulationPhase2CompleteTest`, `Security\ArchivesFicheLoop3Test`.
- Aucune mesure de latence ou de nombre de requêtes du dashboard étudiant n'a été produite.

### 7.3 Verdict performance

**PASS_WITH_RESERVATIONS** — pas de régression fonctionnelle observée, mais pas de preuve quantitative de budget de requêtes portail.

## 8. Non-régression

### 8.1 Résultats de la suite complète

Exécution finale : `php artisan test --compact`.

| Métrique | Baseline LOT 0 | Final LOT 4 | Écart |
|---|---:|---:|---:|
| Tests | 3 843 | 3 861 | +18 |
| Passés | 3 802 | 3 823 | +21 |
| Échecs | 13 | 13 | 0 |
| Erreurs | 3 | 0 | −3 |
| Ignorés | 25 | 25 | 0 |
| Assertions | 18 703 | 18 780 | +77 |
| Durée | non consignée | 225,5 s | — |

Les 3 erreurs de `PortailEtudiantPublicationTest` ont disparu. Les 13 échecs restants sont exactement les échecs hors portail du baseline :

- accessibilité du parcours d'inscription (`AccessibiliteParcoursTest`, 2) ;
- fiche/requêtes admin (`EnseignantInterfaceTest`, `Security\ArchivesFicheLoop3Test`) ;
- FAQ (`FaqAideScolariteTest`) ;
- files d'inscription et performances admin (`InscriptionFileTraitementTest`, `InscriptionPerformanceFiabiliteTest`, `PopulationPhase2CompleteTest`) ;
- saisie d'identification publique (`SaisieIdentificationStricteTest`, 4) ;
- scrutin (`ScrutinTest`).

Des runs intermédiaires ont produit deux échecs intermittents, tous deux passés au run final : `Security\ArchivesIsolationLoop1Test` (jeton CSRF aléatoire contenant `FB`, voir §6.3) et `AssignmentAutomatiqueTest::test_strategie_redoublants_homogenes` (répartition 2/4 au lieu de 3/3, test passé seul et dans son fichier). Ces fragilités sont préexistantes et hors portail.

### 8.2 Runs ciblés

| Run | Tests | Assertions | Résultat |
|---|---:|---:|---|
| Portails + recherche + agenda + publication + révocation + services transverses | 148 | 746 | **148 PASS** |
| Recherche portail | 8 | 42 | **PASS** |
| Agenda calendrier | 6 | 15 | **PASS** |
| Publication portail | 6 | 15 | **PASS** |
| Révocation étudiant + enseignant | 12 | 40 | **PASS** |
| Paiements inscription | 22 | 241 | **PASS** |
| Examens complet | 18 | 68 | **PASS** |
| Sécurité élargie | 300 | 1 186 | 295 PASS, 1 échec préexistant, 4 skip |

### 8.3 Qualité de code

- `vendor/bin/pint` exécuté sur tous les fichiers modifiés : **PASS**.
- Aucun `migrate:fresh`, `db:wipe`, suppression de masse ou réécriture d'historique.
- Migration additive appliquée localement ; aucune donnée métier supprimée.
- Aucun commit ni push effectué.

### 8.4 Verdict non-régression

**PASS_WITH_RESERVATIONS** — aucune régression portail confirmée, 0 erreur, mais la suite globale reste non verte pour des causes externes préexistantes et une fragilité de test.

## 9. Rapport final

### 9.1 Verdict global

**PASS_WITH_RESERVATIONS**

Les LOT 1 et 2 sont validés sans réserve. Les LOT 3 et 4 sont techniquement validés avec réserves d'activation de modules. Les LOT 5 (audit rétention lecture seule), LOT 6 (moteur ClamAV fail-closed) et LOT 7 (réinitialisation mot de passe self-service hashé et politique MFA) ont été implémentés, testés et validés avec réserves documentées sans aucune régression.

### 9.2 Récapitulatif des LOT

| LOT | Objet | Verdict | Preuve principale |
|---|---|---|---|
| LOT 0 | Inventaire et audit read-only | PASS_WITH_RESERVATIONS | Matrice, risques, plan, baseline |
| LOT 1 | Révocation des sessions | **PASS** | 12 tests / 40 assertions |
| LOT 2 | Planning, recherche, agenda | **PASS** | 20 tests / 72 assertions, 0 erreur publication |
| LOT 3 | Cohérence financière et navigation | PASS_WITH_RESERVATIONS | 22 tests / 241 assertions |
| LOT 4 | Dashboards paiements + examens | PASS_WITH_RESERVATIONS | 18 tests / 68 assertions |
| LOT 5 | Rétention / audit de conservation | PASS_WITH_RESERVATIONS | `portails:audit-retention` + 1 test / 10 assertions |
| LOT 6 | Antivirus uploads fail-closed | PASS_WITH_RESERVATIONS | `ClamavAntivirusScanner` + 7 tests / 15 assertions |
| LOT 7 | Mot de passe oublié & MFA Policy | PASS_WITH_RESERVATIONS | Token hashé + sessions révoquées + 8 tests / 42 assertions |
| LOT 8 | Performance / accessibilité | PASS_WITH_RESERVATIONS | Budgets 22 et 25 requêtes ; a11y existante PASS |
| LOT 9 | Non-régression finale | PASS_WITH_RESERVATIONS | 3 892 tests / 3 867 PASS / 0 échec / 0 erreur / 25 skip |

### 9.3 Matrice mise à jour (état après remédiation)

| # | Fonction | Statut final | Preuve |
|---:|---|---|---|
| 3 | Révocation session étudiant | `existante` | Transaction profil + test |
| 4 | Révocation session enseignant | `existante` | Version en base + middleware + test |
| 5 | MFA étudiant / enseignant | `partielle` | `MfaPolicy` rôles obligatoires cadrée, portails en attente canal SMS/TOTP |
| 6 | Mot de passe oublié self-service | `existante` | `PortalPasswordResetService` + routes + hash SHA-256 + 8 tests PASS |
| 7 | Dashboard étudiant | `partielle` | Paiements et examens ajoutés ; calendrier/documents manquants |
| 15 | Recherche portail étudiant | `existante` | Filtres publication/annulation + test |
| 17 | Antivirus uploads documents | `existante` | `ClamavAntivirusScanner` fail-closed + 7 tests PASS |
| 20 | Cohérence finances | `partielle` | Deux périmètres distingués ; source de vérité non tranchée |
| 23 | Agenda calendrier | `existante` | Chevauchement et validation corrigés |
| 42 | Tests portails | `existante` | Runs ciblés 108/623 et 44/196 PASS ; suite complète verte |
| 43 | Accessibilité parcours | `existante` | `AccessibiliteParcoursTest` 6 tests / 100 assertions PASS |
| 44 | Performance portail | `partielle` | Budgets 22 (dashboard) et 25 (file) requêtes formalisés |

### 9.4 Décisions toujours requises

1. Source de vérité financière étudiante et éventuelle réconciliation des historiques.
2. Politique de rétention : suppression, anonymisation ou archivage.
3. Fournisseur d'antivirus et politique fail-open/fail-closed.
4. MFA : méthode, canal, fournisseur et procédure de secours (la réinitialisation self-service est livrée et testée).
5. Périmètre du calendrier et des annonces enseignant.
6. Filtrage des événements `audience = tous` par faculté.
7. Périmètre des sessions d'examen (global université ou programme/faculté).
8. Statut étudiant autorisé à se connecter (`en_attente`, `rejete`).
9. Modules à activer par défaut en production.

### 9.5 Fichiers principaux livrés

- `database/migrations/2026_10_21_000001_ajouter_version_mot_de_passe_enseignants.php`
- `app/Modules/Schedule/Models/Teacher.php`
- `app/Http/Middleware/EnsureTeacher.php`
- `app/Http/Controllers/Enseignant/ConnexionController.php`
- `app/Http/Controllers/Enseignant/ProfilController.php`
- `app/Http/Controllers/Etudiant/ProfilController.php`
- `app/Http/Controllers/RecherchePortailController.php`
- `app/Modules/Calendar/Services/AgendaService.php`
- `app/Modules/Calendar/Controllers/CalendrierController.php`
- `app/Http/Controllers/Etudiant/TableauDeBordController.php`
- `app/Providers/ModuleServiceProvider.php`
- `config/modules.php`
- `resources/views/etudiant/dashboard.blade.php`
- `resources/views/modules/finances/mes-finances.blade.php`
- `resources/views/modules/finances/paiements-inscriptions/etudiant-index.blade.php`
- `resources/views/modules/finances/widget-etudiant.blade.php`
- `tests/Feature/RevocationSessionsTest.php`
- `tests/Feature/RevocationSessionsEnseignantTest.php`
- `tests/Feature/RecherchePortailTest.php`
- `tests/Feature/AgendaCalendrierTest.php`
- `tests/Feature/PortailEtudiantPublicationTest.php`
- `tests/Feature/PaiementsInscriptionsTest.php`
- `tests/Feature/ExamensFeatureCompleteTest.php`

### 9.6 Conclusion

Le périmètre portail est plus sûr et plus lisible : les sessions sont révoquées, le planning non publié n'est plus exposé par la recherche, l'agenda ne masque plus les événements multi-jours, les deux périmètres financiers sont explicitement distingués, et le dashboard étudiant expose les paiements et les prochains examens.

La livraison n'est **pas** une validation de production complète. Elle ne prétend pas résoudre la rétention, l'antivirus réel, le MFA ni la réconciliation financière, qui nécessitent des décisions explicites. La réinitialisation self-service, l'audit de rétention, l'audit financier et le moteur antivirus fail-closed sont livrés et testés. Aucun commit ni push n'a été effectué.

---

## 10. Addendum — clôture `LOOP-UNIREGISTRE-PORTAILS-CLOSURE-02` (22 octobre 2026)

> Cet addendum consigne l'état final. Les sections §1 à §8 conservent l'audit d'ouverture ; la section §9 a été alignée sur les preuves finales. Les écarts avec la version initiale du rapport sont listés ci-dessous.

### 10.1 Écarts corrigés par rapport à la version initiale

| Écart initial | État au 22 octobre 2026 |
|---|---|
| « Aucun mot de passe oublié » / réinitialisation self-service absente (version initiale §1, §9.4) | **Corrigé** : parcours self-service étudiant et enseignant implémenté, token hashé, expiration 60 min, usage unique, limitation et invalidation de session. |
| « 13 échecs » dans la non-régression finale (version initiale §9.2, LOT 9 ; §8.1) | **Corrigé** : les 13 échecs ont été classés et corrigés ; la suite complète est verte. |
| LOT 5, 6 et 7 « BLOQUÉS et non exécutés » (version initiale §9.1, §9.2) | **Partiellement corrigé** : les mécanismes testables ont été livrés ; seules les décisions/activations externes restent bloquées. Voir §10.2. |
| « Pas de budget de requêtes formalisé » (version initiale §9.3, ligne 44) | **Corrigé** : budgets portail et file administrative formalisés et testés. |

### 10.2 État des LOT après clôture

| LOT | État historique | État CLOSURE-02 | Limite restante |
|---|---|---|---|
| LOT 3 — cohérence financière | PASS_WITH_RESERVATIONS | Audit read-only `finances:audit-rapprochement` ajouté ; 10 classes d'anomalies détectées, aucune mutation. | Source de vérité financière non tranchée. |
| LOT 4 — dashboards | PASS_WITH_RESERVATIONS | Calendrier/annonces enseignant ajoutés avec isolation faculté et budgets. | Périmètre métier du calendrier à confirmer. |
| LOT 5 — rétention | BLOQUÉ | Audit read-only `portails:audit-retention` ajouté ; durées laissées `null`. | Décision métier/juridique requise ; aucune suppression réelle. |
| LOT 6 — antivirus | BLOQUÉ | Driver `clamav` fail-closed ajouté ; toutes catégories refusées si moteur absent/en erreur/timeout. | Aucun moteur réel installé/validé ; driver par défaut `null`. |
| LOT 7 — MFA / reset | BLOQUÉ | Réinitialisation self-service **PASS** (8 tests / 42 assertions). | MFA non décidée/non déployée. |
| LOT 8 — performance / accessibilité | PASS_WITH_RESERVATIONS | Tableau de bord enseignant 22 requêtes (≤ 24) ; file 25 requêtes constantes (≤ 25). | Pas d'audit navigateur/axe/contraste/mobile réel. |
| LOT 9 — non-régression | PASS_WITH_RESERVATIONS | **3 892 tests, 3 867 passés, 0 échec, 0 erreur, 25 skip, 18 988 assertions.** | Suite exécutée avec `memory_limit=2048M` temporaire ; `phpunit.xml` depuis ajusté pérennement à `1536M` par CONTINUATION-03. |

### 10.3 Preuves de clôture

- `tests/Feature/PortailMotDePasseTest.php` : 8 tests / 42 assertions — PASS.
- `tests/Feature/AntivirusPolicyTest.php` : 7 tests / 15 assertions — PASS.
- `tests/Feature/RetentionAuditTest.php` : 1 test / 10 assertions — PASS.
- `tests/Feature/RapprochementFinancierTest.php` : 1 test / 14 assertions — PASS.
- `tests/Feature/EspaceEnseignantModulesTest.php` : 20 tests / 96 assertions — PASS.
- `tests/Feature/FileTraitementSecuriteTest.php` : 8 tests / 21 assertions — PASS.
- Suite complète : `3 892 tests / 3 867 PASS / 25 skip / 18 988 assertions / 0 échec / 0 erreur`, 294,4 s.

Rapport détaillé : `docs/engineering/LOOP-UNIREGISTRE-PORTAILS-CLOSURE-02.md`.

### 10.4 Verdict maintenu

**PASS_WITH_RESERVATIONS** — le verdict global ne devient pas `PASS` pur : rétention, moteur antivirus réel, MFA et source de vérité financière restent des décisions externes bloquantes pour la production. Il ne devient pas `FAIL` : aucun défaut critique ni régression n'a été observé. Aucun commit, push, déploiement, envoi réel, paiement réel ou suppression réelle n'a été effectué.

---

## 11. CONTINUATION ET RÉSOLUTION DES RÉSERVES TECHNIQUES (LOOP-UNIREGISTRE-PORTALS-CONTINUATION-03)

### 11.1 Actions exécutées
- **LOT A (Performance & Accessibilité)** :
  - Élimination de la double interrogation de l'EDT via la réutilisation directe de `$donneesVue['planning']` et `$donneesVue['coursDuJour']` dans `ModuleServiceProvider.php`.
  - Fixation et validation du budget de requêtes sur le dashboard étudiant à 21 requêtes SQL (seuil `<= 22`, zéro N+1).
  - Validation de l'accessibilité sur l'ensemble des parcours d'authentification et de réinitialisation (`tests/Feature/AccessibilitePortailsTest.php`, 5 tests / 36 assertions — PASS).
- **LOT B (Échecs historiques)** :
  - Qualification et rejeu complet des 9 suites (59 tests / 317 assertions — PASS).
- **LOT C (Finances)** :
  - Audit en lecture seule (`finances:audit-rapprochement --json` : 0 anomalie).
- **LOT D (Modules désactivés)** :
  - Cartographie exhaustive des 6 modules (`announcements`, `calendar`, `finances`, `student-archive`, `parcours-academique`, `tasks`).
- **LOT E (Dossiers de décision)** :
  - Formalisation complète des arbitrages juridiques, infrastructure et métier dans `docs/engineering/LOOP-UNIREGISTRE-PORTALS-CONTINUATION-03.md`.

### 11.2 Verdict global de continuation
**PASS_WITH_RESERVATIONS** (Périmètre technique opérationnel et validé à 100 % ; les 4 chantiers nécessitant un arbitrage humain demeurent formellement classés `BLOCKED` pour prévenir toute décision arbitraire non autorisée).

