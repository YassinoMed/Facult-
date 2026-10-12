# Secure Prompts LOOP --- Portails Enseignant et Étudiant

## Application universitaire Laravel --- Implémentation, audit de conformité et boucles correctives

**Objectif :** fournir un prompt LOOP opérationnel pour chaque
fonctionnalité des portails enseignant et étudiant. Chaque LOOP doit
d'abord auditer le code réel, implémenter uniquement ce qui manque,
exécuter les tests, puis réaliser un audit de conformité. **Il est
interdit de passer à la fonctionnalité suivante tant que l'audit de la
fonctionnalité courante n'est pas entièrement satisfaisant.** Si l'audit
détecte un manque, lancer une boucle corrective distincte, puis
réauditer jusqu'à conformité.

> Ce document est destiné à un agent de développement qui peut lire et
> modifier le dépôt du projet. Ne jamais prétendre qu'un changement est
> réalisé sans l'avoir effectivement effectué et vérifié.

------------------------------------------------------------------------

# 1. Protocole maître obligatoire pour toutes les LOOP

Copier ce protocole au début de chaque LOOP, ou demander à l'agent de
l'appliquer comme règle permanente pendant toute la séquence.

``` text
Tu travailles sur une application universitaire Laravel existante. Tu dois intervenir dans le dépôt réel, lire le code avant de modifier quoi que ce soit, et conserver l'architecture et les conventions existantes.

RÈGLE ABSOLUE : AUDIT → PLAN → IMPLÉMENTATION → TESTS → AUDIT FINAL → CORRECTIONS → NOUVEL AUDIT.
Ne passe jamais à la fonctionnalité suivante si la fonctionnalité actuelle n'est pas conforme.

ÉTAPE A — AUDIT AVANT MODIFICATION
1. Confirme la racine du dépôt, la version de Laravel/PHP, le moteur de base de données et l'état Git.
2. Examine les routes, contrôleurs, Form Requests, policies, permissions, services, modèles, migrations, factories, tests, vues Blade, composants front-end et navigation concernés.
3. Recherche les fonctionnalités équivalentes déjà présentes. Ne crée pas de doublon.
4. Cartographie les relations entre User, Teacher, Student, Group, inscriptions, ScheduleSlot, Présences, Évaluations, Notes, Documents et Notifications selon les modèles réellement présents.
5. Exécute les tests ciblés existants avant modification et consigne les résultats.
6. Identifie les risques d'IDOR, de fuite inter-faculté, d'élévation de privilèges, d'écritures non atomiques, de doublons, de N+1 et de rupture de compatibilité.
7. Présente un inventaire : EXISTANT ET CONFORME / EXISTANT MAIS INCOMPLET / ABSENT / À RISQUE. Le plan doit réutiliser l'existant et ne proposer des migrations que si elles sont justifiées.

ÉTAPE B — PLAN DE CHANGEMENT
1. Énumère les fichiers à créer/modifier et explique pourquoi.
2. Définis les règles métier et les états autorisés.
3. Définis les permissions par rôle et le périmètre des données.
4. Définis les migrations rétrocompatibles et la stratégie de rollback.
5. Définis les tests fonctionnels, sécurité, intégrité, performance et régression.
6. Attends-toi à devoir ajuster le plan si le code réel diffère des hypothèses du cahier des charges. Ne présume jamais qu'un modèle ou un champ existe sans l'avoir vérifié.

ÉTAPE C — IMPLÉMENTATION
1. Implémente la fonctionnalité dans les modules existants.
2. Réutilise les services, policies, permissions, composants UI, conventions de nommage et mécanismes d'audit déjà en place.
3. N'ajoute pas de dépendance externe sans justification, évaluation de sécurité et validation de compatibilité.
4. N'effectue pas de refonte non nécessaire.
5. Ne supprime pas de données historiques et ne réalise aucune migration destructive sans plan de conversion testé.
6. N'expose aucune donnée privée via une URL publique, un stockage public ou un endpoint non autorisé.
7. Toutes les autorisations sont vérifiées côté serveur à chaque requête ; masquer un bouton dans Blade n'est jamais une mesure de sécurité suffisante.
8. Utilise des transactions pour les opérations multi-écritures et des contraintes SQL pour les invariants de données.
9. Évite N+1, charge uniquement les données nécessaires, utilise pagination et validation de taille pour les listes/payloads volumineux.
10. Maintiens l'interface cohérente avec le design system existant, accessible au clavier et adaptée aux écrans mobiles.

ÉTAPE D — VALIDATION
1. Lance les tests ciblés et les tests de sécurité.
2. Lance les tests du module impacté et la suite complète lorsque possible.
3. Exécute les outils de formatage/qualité déjà configurés.
4. Vérifie les migrations sur la base de test et contrôle la compatibilité avec les données existantes.
5. Inspecte le diff final : pas de secrets, fichiers .env, dumps de données, dépendances inutiles, debug résiduel, routes de test ou TODO critiques.
6. Fournis les commandes exécutées et les résultats réellement observés. Ne fabrique jamais de résultats.

ÉTAPE E — AUDIT FINAL DE CONFORMITÉ
Construis une matrice critère → preuve → résultat PASS/FAIL/N/A. La preuve doit être concrète : fichier, méthode, route, contrainte, test exécuté ou capture/résultat vérifiable.
Contrôle les exigences fonctionnelles, les autorisations, les validations, les migrations, la compatibilité, l'accessibilité, les performances et les tests.

RÈGLE DE BLOCAGE
- Si tous les critères obligatoires sont PASS : marque la fonctionnalité « CONFORME », indique les preuves, puis seulement ensuite autorise la fonctionnalité suivante.
- Si au moins un critère obligatoire est FAIL, absent ou non prouvé : ne passe PAS à la suivante.
- Crée une « LOOP CORRECTIVE — [nom de la fonctionnalité] » limitée aux écarts détectés. Corrige-les, relance les tests concernés et l'audit complet.
- Répète la boucle corrective jusqu'à conformité. Si un blocage externe empêche de conclure, indique « BLOQUÉ — NON CONFORME », décris le blocage et n'affirme pas que la fonctionnalité est terminée.
- Ne transforme jamais une absence de preuve en PASS.

LIVRABLE DE CHAQUE LOOP
1. Résumé des changements réels.
2. Inventaire des fichiers créés/modifiés.
3. Routes et permissions ajoutées/modifiées.
4. Migrations et stratégie de rollback, ou justification de l'absence de migration.
5. Tests exécutés avec résultats exacts.
6. Matrice d'audit PASS/FAIL/N/A avec preuves.
7. Risques restants et décision explicite : CONFORME / BLOQUÉ — NON CONFORME.
8. Si CONFORME, inscrire le nom de la prochaine LOOP autorisée.
```

## Règles transversales de sécurité et de qualité

Toutes les fonctionnalités doivent respecter les points suivants, sauf
justification technique documentée :

-   **Identité et périmètre :** dériver l'utilisateur courant de
    l'authentification ; ne pas faire confiance aux `user_id`,
    `teacher_id`, `student_id`, `faculty_id`, `group_id` transmis par le
    client.
-   **IDOR/BOLA :** vérifier que chaque ressource appartient au
    périmètre autorisé de l'utilisateur, pas uniquement qu'elle existe.
-   **Isolation de faculté :** réutiliser les mécanismes du projet et
    appliquer le scope avant d'exposer les données.
-   **Validation :** utiliser Form Requests ou le mécanisme existant ;
    valider types, tailles, enums, relations et transitions d'état.
-   **Intégrité :** transactions pour les opérations multi-tables, clés
    étrangères/index/contraintes uniques appropriées, idempotence sur
    les actions répétables.
-   **Audit :** tracer les changements sensibles à l'aide du système
    existant ; ne pas créer un deuxième système de journalisation.
-   **Confidentialité :** stockage privé pour les documents,
    téléchargement via contrôleur autorisé, aucune fuite dans les logs.
-   **Performance :** eager loading, pagination, index justifiés, tests
    de régression sur les grands volumes.
-   **UX :** erreurs explicites, états vides, chargement,
    réussite/échec, navigation clavier, contrastes et responsive.
-   **Compatibilité :** préserver les anciennes données, les routes
    utilisées et les conventions existantes.
-   **Pas de faux succès :** ne pas déclarer une fonctionnalité conforme
    sur la seule base de l'interface ou de tests unitaires isolés.

------------------------------------------------------------------------

# 2. Ordre d'exécution recommandé

Exécuter les LOOP une par une dans cet ordre. L'agent doit s'arrêter
après chaque LOOP, remettre son audit et ne démarrer la suivante
qu'après une décision explicite `CONFORME`.

1.  Socle d'accès et profils des deux portails
2.  Tableau de bord enseignant
3.  Emploi du temps enseignant
4.  Appel manuel intelligent et présences
5.  Évaluations et saisie des notes côté enseignant
6.  Ressources et supports de cours
7.  Communication, demandes et notifications
8.  Charge de travail et statistiques enseignant
9.  Tableau de bord étudiant
10. Emploi du temps et calendrier étudiant
11. Notes, résultats et progression LMD
12. Consultation des présences et justificatifs étudiant
13. Dossier étudiant, documents et inscription
14. Ressources pédagogiques et travaux
15. Démarches administratives et réclamations
16. Accompagnement et progression étudiant
17. Services communs et cohérence inter-portails
18. Recherche globale autorisée
19. Exports et rapports
20. Mode hors ligne (si retenu)
21. Appel QR temporaire (si retenu)
22. Assistant IA pédagogique (si retenu)
23. Audit transversal final, sécurité et performance

Les fonctionnalités P2/P3/P4 peuvent être reclassées après l'audit du
dépôt, mais aucune ne doit être déclarée terminée sans son audit final.

------------------------------------------------------------------------

# 3. LOOP 01 --- Socle d'accès, rôles et profils

## Objectif

Établir les accès sécurisés des enseignants et étudiants sans casser le
système d'authentification, les rôles et permissions existants.

## Prompt à exécuter

``` text
LOOP 01 — SOCLE D'ACCÈS ET PROFILS ENSEIGNANT/ÉTUDIANT

Applique intégralement le protocole maître.

AUDIT
- Inspecte User, Teacher, Student, les relations entre ces modèles, les rôles/permissions, policies, middleware, guards, routes de connexion, réinitialisation de mot de passe, navigation et layouts.
- Vérifie si un utilisateur peut être lié à plusieurs profils, si les enseignants et étudiants utilisent le même guard et quelles règles de désactivation existent.
- Confirme les méthodes d'isolation par faculté et les scopes existants.
- Vérifie qu'il n'existe pas déjà un portail ou une page de profil équivalente.

IMPLÉMENTATION
- Réutilise l'authentification existante ; ne crée pas une deuxième table d'utilisateurs ni un deuxième mécanisme de login.
- Met en place des routes protégées distinctes pour /enseignant et /etudiant selon les conventions du projet.
- Implémente le profil enseignant à partir du Teacher lié à l'utilisateur authentifié et le profil étudiant à partir du Student lié à l'utilisateur authentifié.
- Si le lien utilisateur-profil est absent, propose une migration minimale et rétrocompatible après avoir vérifié les règles de cardinalité et les données existantes.
- Autorise la consultation et la modification uniquement des champs explicitement modifiables ; les identifiants, rôles, faculté, statut d'inscription et affectations ne doivent pas être modifiables librement par l'utilisateur.
- Utilise les policies et permissions existantes, protection CSRF, validation, limitation des tentatives et messages génériques pour les informations sensibles.
- Prévois les états : profil manquant, compte désactivé, profil incomplet, session expirée.
- Ne permets pas à un étudiant d'accéder aux routes enseignant en modifiant l'URL, et inversement.

TESTS
- Authentification, compte désactivé, profil absent, profil lié, autorisation de lecture/modification.
- IDOR entre deux enseignants, deux étudiants et entre facultés.
- Tentative de modification de role, teacher_id, student_id, faculty_id et statut.
- Régression des routes de connexion existantes.

AUDIT FINAL
- Vérifie chaque route, middleware, policy, relation et champ modifiable.
- Produis une matrice d'accès par rôle et preuve par test.
- Si un écart existe, boucle corrective obligatoire.
Ne démarre pas LOOP 02 avant statut CONFORME.
```

## Critères d'acceptation

-   Les deux portails sont protégés.
-   Le profil est dérivé de l'utilisateur authentifié.
-   Aucun IDOR critique n'est détecté.
-   Les rôles et données d'affectation ne sont pas modifiables depuis le
    profil.
-   Les tests existants d'authentification passent.

------------------------------------------------------------------------

# 4. LOOP 02 --- Tableau de bord enseignant

## Objectif

Fournir une synthèse opérationnelle sans recopier les données métier
dans des tables dédiées.

``` text
LOOP 02 — TABLEAU DE BORD ENSEIGNANT

Applique le protocole maître. Ne commence qu'après conformité de LOOP 01.

AUDIT
- Identifie les routes et vues du portail enseignant, les composants UI, les services de calendrier, d'évaluations, de présences et de notifications.
- Vérifie les index et le volume des données ; repère les requêtes N+1.
- Détermine quelles données sont réellement accessibles à l'enseignant connecté.

FONCTIONNALITÉS
- Afficher les séances du jour et les prochaines séances à partir de l'emploi du temps officiel.
- Afficher les appels à terminer ou reprendre, sans compter comme absence les étudiants non renseignés.
- Afficher les évaluations à corriger ou notes à soumettre, si le module existant expose ces états.
- Afficher les annonces, demandes en attente et notifications non lues.
- Présenter des liens vers les modules sources ; ne pas recréer les données métier.
- Ajouter des états vides, chargement, erreurs récupérables et affichage responsive.
- Chaque compteur doit être calculé à partir des données sources et du périmètre autorisé.
- Si une carte dépend d'un module non encore conforme, masquer la carte ou afficher un état indisponible explicite, sans données fictives.

SÉCURITÉ/PERFORMANCE
- Tous les agrégats doivent respecter l'enseignant et la faculté.
- Éviter les chargements complets de listes et les requêtes répétées.
- N'ajouter du cache que si l'invalidation est fiable et que les données privées ne peuvent pas fuiter entre utilisateurs.

TESTS
- Données correctes pour l'enseignant A et absence de données de B.
- Compteurs exacts avec états vides.
- Permissions, isolation par faculté, N+1 et régression visuelle/fonctionnelle.

AUDIT FINAL
- Vérifie chaque widget contre sa source de vérité et chaque compteur par test.
- Corrige toute carte trompeuse ou requête non scopée avant de passer à LOOP 03.
```

## Critères d'acceptation

-   Le tableau de bord n'affiche que les données autorisées.
-   Aucun compteur n'est simulé ou calculé à partir d'une source
    dupliquée.
-   Les erreurs d'un module ne font pas échouer inutilement tout le
    tableau de bord.

------------------------------------------------------------------------

# 5. LOOP 03 --- Emploi du temps enseignant

``` text
LOOP 03 — EMPLOI DU TEMPS PERSONNEL ENSEIGNANT

Applique le protocole maître.

AUDIT
- Inspecte ScheduleSlot, Teacher, Group, Room, matières/modules, éventuels modèles de récurrence, exceptions, annulations et changements.
- Détermine si ScheduleSlot représente une occurrence datée ou un créneau récurrent. Ne suppose pas qu'une date de séance peut être déduite d'un identifiant seul.
- Vérifie les conflits existants et les règles de publication de l'emploi du temps.

IMPLÉMENTATION
- Ajouter ou compléter les vues jour/semaine/mois, en utilisant les données de planning officielles.
- Afficher matière, groupe, salle, date et horaires seulement si disponibles.
- Respecter les changements, reports, annulations et fuseau horaire configuré.
- Permettre les filtres utiles sans accepter de filtres qui élargissent le périmètre autorisé.
- Prévoir export ou ajout au calendrier uniquement si le projet dispose d'une convention fiable et que la donnée exportée est limitée à l'enseignant.
- Ne pas permettre de modifier le planning depuis le portail enseignant sauf permission métier explicite existante.

TESTS
- Séances affectées/non affectées, annulation, déplacement, conflits, dates limites et changements de semaine.
- IDOR sur ScheduleSlot, fuite inter-faculté, fuseau horaire et données manquantes.
- Vérifier les performances et l'absence de N+1.

AUDIT FINAL
- Comparer l'affichage à la source officielle sur des cas de référence.
- Vérifier que les événements annulés ou déplacés sont correctement signalés.
- LOOP corrective si le planning est incohérent ; ne pas passer à LOOP 04 avant conformité.
```

## Critères d'acceptation

-   L'enseignant ne voit que ses créneaux autorisés.
-   Les dates réelles sont fiables.
-   Les annulations et modifications ne sont pas masquées.
-   L'interface ne crée pas une deuxième source de planning.

------------------------------------------------------------------------

# 6. LOOP 04 --- Appel manuel intelligent et gestion des présences

## Objectif

Permettre à l'enseignant de faire l'appel depuis une séance officielle,
de sauvegarder de manière atomique et de reprendre un appel, en
réutilisant le module Présences.

``` text
LOOP 04 — APPEL MANUEL INTELLIGENT ET PRÉSENCES

Applique le protocole maître. Cette LOOP est bloquante pour toute fonctionnalité qui dépend des présences.

AUDIT OBLIGATOIRE
- Inspecte le module Présences complet : modèles, migrations, services, interfaces, contrôleurs, policies, routes, statuts et tests.
- Inspecte ScheduleSlot, Teacher, Group, Student, les inscriptions actives et la relation User → Teacher.
- Vérifie si la table des présences référence un créneau structuré ou uniquement une chaîne libre comme seance.
- Identifie les clés uniques et le comportement d'upsert existant.
- Détermine si une entité représentant une occurrence d'appel existe déjà. Ne crée pas attendance_sessions si une entité équivalente existe.
- Exécute les tests de présence existants avant modification.

FONCTIONNEL
- L'enseignant choisit une séance qui lui est réellement affectée.
- Le backend résout la séance, la date, le groupe et les étudiants depuis les relations officielles.
- Afficher uniquement les étudiants inscrits au groupe à la date de séance, selon les règles métier.
- Statuts compatibles avec l'existant : présent, absent, retard, excusé/justifié si le workflow l'autorise, et non renseigné comme état de travail non encore soumis.
- Ajouter recherche par nom/prénom/identifiant, action « Tout marquer présent », corrections individuelles, compteurs et sauvegarde explicite.
- « Tout marquer présent » est une action explicite, annulable avant sauvegarde ; elle ne doit pas être déclenchée par défaut.
- Les étudiants non renseignés ne sont jamais automatiquement transformés en absents.
- Permettre de reprendre un appel ouvert et de voir les données précédemment enregistrées.
- Clôture explicite ; avertir si des étudiants restent non renseignés. La politique de clôture doit être définie, non implicite.
- Après clôture, refuser la modification sauf workflow d'exception autorisé, avec motif et audit.
- Préserver l'historique et les anciennes présences.

DONNÉES/MIGRATIONS
- N'ajouter une table de sessions d'appel que si l'audit prouve qu'aucune entité équivalente n'existe.
- Ajouter une relation structurée à ScheduleSlot/occurrence si nécessaire, sans casser les anciennes données.
- Toute unicité doit représenter réellement une occurrence et un étudiant ; ne pas supposer que ScheduleSlot seul identifie toujours la date.
- Prévoir des clés étrangères/index utiles et un plan de migration/rollback testé.
- Ne jamais rattacher des données historiques à un créneau par correspondance approximative.

SÉCURITÉ
- Réautoriser l'enseignant à chaque lecture, ouverture, sauvegarde et clôture.
- Vérifier chaque étudiant soumis appartient au groupe autorisé.
- Ne jamais faire confiance à teacher_id, group_id, student_id ou faculty_id transmis par le client.
- Protection IDOR/BOLA et isolation de faculté.
- Transaction atomique pour les écritures d'un appel ; verrouillage adapté pour les opérations concurrentes.
- Idempotence serveur et contraintes SQL contre les doublons.
- Rejeter les statuts inconnus, doublons de payload et changements sur appel clôturé.
- Journaliser auteur, horodatages et corrections via le mécanisme d'audit existant.
- Ne pas envoyer de notification ni exécuter de traitement lent dans la transaction.

TESTS OBLIGATOIRES
- Enseignant autorisé, enseignant étranger, étudiant hors groupe, autre faculté.
- Sauvegarde de chaque statut et conservation de non_renseigne.
- Tout marquer présent, corrections individuelles, reprise et clôture.
- Doublons, idempotence, rollback après erreur, contrainte unique et concurrence.
- Migration sur données historiques, permissions, N+1 et grands groupes.

AUDIT FINAL
- Construire une matrice critère → preuve → PASS/FAIL.
- Vérifier la base de données, pas seulement la réponse HTTP.
- Si un critère échoue, exécuter une LOOP CORRECTIVE limitée aux écarts, puis tous les tests impactés et un nouvel audit.
Ne passe pas à LOOP 05 avant conformité.
```

## Critères d'acceptation

-   Source de vérité unique.
-   Sauvegarde sécurisée et atomique.
-   Pas de fuite inter-enseignant ou inter-faculté.
-   Pas de conversion implicite des étudiants non renseignés en absents.
-   Tests d'intégrité et de régression réussis.

------------------------------------------------------------------------

# 7. LOOP 05 --- Évaluations et saisie des notes enseignant

``` text
LOOP 05 — ÉVALUATIONS ET SAISIE DES NOTES

AUDIT
- Inspecte les modules Evaluation/Examens, Epreuve, Examen, SessionExamen, matières/ECUE, inscriptions, groupes et rôles.
- Repère les workflows de saisie, validation, publication, verrouillage et correction.
- Réutilise les services existants ; ne crée pas un deuxième système de notes.

FONCTIONNALITÉS
- Afficher uniquement les évaluations auxquelles l'enseignant est affecté.
- Charger les étudiants inscrits au groupe de l'épreuve à la date/règle pertinente.
- Saisir les notes individuellement ou par import si un mécanisme sûr existe.
- Valider les bornes, le barème, les coefficients et la précision selon les règles officielles.
- Séparer brouillon, soumission, validation et publication ; un enseignant ne peut pas publier si le workflow lui donne seulement un droit de saisie.
- Empêcher les modifications après verrouillage, sauf procédure de correction autorisée.
- Enregistrer l'auteur, les timestamps et les changements sensibles dans l'audit existant.
- Prévenir la perte de données en cas d'erreur et gérer les conflits de modification.

SÉCURITÉ
- Vérifier l'affectation à l'épreuve et l'appartenance de l'étudiant.
- Refuser l'accès à une épreuve d'une autre faculté.
- Ne pas accepter un student_id ou evaluation_id non autorisé.
- Protéger les exports de notes et ne pas révéler les notes à des étudiants avant publication.

TESTS
- Notes dans/hors barème, notes manquantes, doublons, import mal formé, épreuve non affectée, étudiant hors groupe, état verrouillé, rôle sans droit de publication, rollback et audit des corrections.

AUDIT FINAL
- Vérifier les transitions d'état, les calculs, les permissions et les tests de régression.
- Si un critère manque, LOOP corrective avant LOOP 06.
```

## Critères d'acceptation

-   Les notes sont liées aux évaluations et étudiants officiels.
-   Le barème et les transitions d'état sont appliqués côté serveur.
-   La publication et la correction sont contrôlées et traçables.

------------------------------------------------------------------------

# 8. LOOP 06 --- Ressources et supports de cours enseignant

``` text
LOOP 06 — RESSOURCES PÉDAGOGIQUES ENSEIGNANT

AUDIT
- Recherche les fonctions existantes de documents, dossiers, pièces jointes et stockage privé.
- Vérifie les politiques de fichiers, la limite de taille, les MIME types, l'antivirus éventuel et les URLs de téléchargement.

FONCTIONNALITÉS
- Autoriser l'enseignant à publier des supports pour une matière/groupe dont il a la charge.
- Métadonnées : titre, description, matière, groupe, visibilité, dates de publication et auteur, uniquement si justifiées.
- Organiser les documents par matière, semestre et groupe.
- Permettre remplacement/versioning uniquement si le système le supporte correctement.
- Gérer brouillon/publié/archivé si utile.
- Les étudiants ne voient que les ressources publiées auxquelles ils ont droit.

SÉCURITÉ
- Stockage privé, noms de fichiers générés côté serveur, contrôle de taille et type réel, pas uniquement l'extension.
- Contrôle d'autorisation à l'upload, la consultation, au téléchargement, au remplacement et à la suppression.
- Empêcher les chemins de fichier arbitraires, les exécutions et les téléchargements publics non autorisés.
- Protéger contre les IDOR, y compris par changement de document_id.

TESTS
- Types autorisés/refusés, fichiers trop grands, document d'une autre matière, groupe non affecté, étudiant non inscrit, téléchargement privé et suppression non autorisée.

AUDIT FINAL
- Vérifier le stockage réel, les règles d'accès et la compatibilité avec le module de documents existant.
- LOOP corrective obligatoire en cas de fuite ou de test manquant.
```

## Critères d'acceptation

-   Aucun document privé accessible sans autorisation.
-   La publication est limitée aux matières/groupes autorisés.
-   Les fichiers sont validés et stockés selon la politique existante.

------------------------------------------------------------------------

# 9. LOOP 07 --- Communication, demandes et notifications enseignant

``` text
LOOP 07 — COMMUNICATION ET NOTIFICATIONS ENSEIGNANT

AUDIT
- Inspecte AppNotification, annonces, calendrier, demandes, réclamations, messagerie et éventuels canaux email.
- Détermine si la plateforme possède déjà un workflow de demandes et des états normalisés.

FONCTIONNALITÉS
- Permettre de publier des annonces ciblées vers des groupes ou matières autorisés.
- Permettre de consulter les demandes qui relèvent de l'enseignant.
- Permettre une réponse ou un changement d'état seulement si les règles métier l'autorisent.
- Afficher les notifications de changement de séance, demandes de justification et tâches d'évaluation.
- Marquer une notification comme lue sans modifier son contenu source.
- Prévoir pagination, filtres, états vides et historique.

SÉCURITÉ
- Vérifier le destinataire et le périmètre à chaque requête.
- Une notification ne doit pas exposer des données d'un autre étudiant dans son aperçu.
- Limiter les annonces aux groupes autorisés et journaliser les actions sensibles.
- Éviter l'envoi multiple de la même notification en cas de retry ; utiliser les mécanismes idempotents existants.

TESTS
- Destinataire autorisé/non autorisé, annonces par groupe, notification lue, doublons, droits de réponse et isolation inter-faculté.

AUDIT FINAL
- Vérifier que les notifications sont des références vers les modules sources, pas des copies incohérentes.
- Corriger tout workflow incomplet avant LOOP 08.
```

## Critères d'acceptation

-   Les communications sont ciblées correctement.
-   Les demandes ne sont visibles que par leurs acteurs autorisés.
-   Les notifications sont cohérentes et idempotentes.

------------------------------------------------------------------------

# 10. LOOP 08 --- Charge de travail et statistiques enseignant

``` text
LOOP 08 — CHARGE DE TRAVAIL ET STATISTIQUES ENSEIGNANT

AUDIT
- Identifie les données fiables pour les heures planifiées, séances réalisées, évaluations et présences.
- Vérifie les définitions métier avant de créer des indicateurs.

FONCTIONNALITÉS
- Afficher les séances planifiées/réalisées selon une définition explicite.
- Afficher des taux de présence par groupe et matière avec dénominateurs transparents.
- Signaler les étudiants potentiellement en difficulté uniquement selon des règles validées et explicables.
- Permettre filtres par semestre, matière, groupe et période, toujours dans le périmètre autorisé.
- Exporter uniquement des agrégats ou données individuelles autorisées.

QUALITÉ
- Ne pas compter une absence non saisie comme une absence confirmée.
- Distinguer données absentes, données en cours de saisie et zéros réels.
- Éviter les calculs métier dupliqués dans les vues.
- Optimiser les agrégations et documenter les définitions des indicateurs.

TESTS
- Jeu de données de référence avec résultat attendu calculé indépendamment.
- Cas sans données, données incomplètes, séances annulées et filtres croisés.
- Vérifier l'isolation entre enseignants/facultés et les exports.

AUDIT FINAL
- Chaque indicateur doit avoir une formule, une source et un test de référence.
- Si les définitions sont ambiguës, ne pas publier l'indicateur avant décision métier.
```

## Critères d'acceptation

-   Les indicateurs sont explicables, testés et limités au bon
    périmètre.
-   Les données manquantes ne sont pas confondues avec zéro ou absence.

------------------------------------------------------------------------

# 11. LOOP 09 --- Tableau de bord étudiant

``` text
LOOP 09 — TABLEAU DE BORD ÉTUDIANT

AUDIT
- Identifie les modèles Student, inscription, groupe, planning, notes publiées, présences, demandes et notifications.
- Vérifie comment le Student est lié à l'utilisateur authentifié et comment les changements de groupe sont historisés.

FONCTIONNALITÉS
- Afficher le prochain cours, le calendrier du jour et les changements publiés.
- Afficher uniquement les notes publiées accessibles à cet étudiant.
- Afficher les présences enregistrées, en distinguant les statuts confirmés et les appels non clôturés si cette distinction est exposée.
- Afficher l'état de l'inscription, les documents manquants et les demandes en attente.
- Afficher annonces et notifications non lues.
- Fournir des liens vers les modules sources plutôt que dupliquer leur logique.
- Prévoir les états : aucune séance, aucune note publiée, profil incomplet, inscription non active.

SÉCURITÉ
- Toujours résoudre l'étudiant via l'utilisateur authentifié.
- N'accepter aucun student_id de requête comme preuve d'accès.
- Exclure les notes non publiées et les données personnelles des autres étudiants.
- Appliquer l'isolation par faculté et les permissions existantes.

TESTS
- Deux étudiants, deux groupes, deux facultés ; vérifier que chaque tableau de bord ne montre que ses données.
- Notes brouillon non visibles, état d'inscription, notification et données vides.
- Tests de régression, permissions et performance.

AUDIT FINAL
- Vérifier chaque carte et compteur par test et requête source.
- LOOP corrective si une donnée non publiée ou étrangère est visible.
```

## Critères d'acceptation

-   Le tableau de bord ne contient aucune donnée d'un autre étudiant.
-   Les notes non publiées ne sont jamais visibles.
-   Les indicateurs correspondent aux sources métier.

------------------------------------------------------------------------

# 12. LOOP 10 --- Emploi du temps et calendrier étudiant

``` text
LOOP 10 — EMPLOI DU TEMPS ÉTUDIANT

AUDIT
- Inspecte ScheduleSlot, groupes, affectations étudiantes, changements de groupe et exceptions de planning.
- Détermine la règle de groupe applicable à la date de la séance.

FONCTIONNALITÉS
- Générer l'emploi du temps selon le groupe/parcours effectif de l'étudiant.
- Afficher matière, enseignant, salle, horaires et changements.
- Distinguer séances annulées, déplacées et passées.
- Afficher le calendrier des examens seulement à partir des évaluations publiées/affectées.
- Prévoir les vues jour/semaine et un rendu mobile lisible.
- Exporter uniquement le calendrier personnel autorisé, si l'export est retenu.

SÉCURITÉ/QUALITÉ
- Ne jamais accepter group_id transmis par le navigateur sans vérification.
- Gérer les changements d'affectation et ne pas afficher un planning obsolète.
- Respecter fuseau horaire et date réelle des séances.
- Ne pas inventer de séances quand les données sont absentes.

TESTS
- Étudiant changé de groupe, séance annulée, déplacement, calendrier vide, autre faculté et IDOR.

AUDIT FINAL
- Comparer avec le planning officiel et les règles d'affectation.
- Ne passe à LOOP 11 qu'après conformité.
```

------------------------------------------------------------------------

# 13. LOOP 11 --- Notes, résultats et progression LMD étudiant

``` text
LOOP 11 — NOTES, RÉSULTATS ET PARCOURS LMD

AUDIT
- Inspecte le référentiel LMD (domaine, mention, spécialité, programme/parcours, niveau, semestre, UE, ECUE/matière), notes, coefficients, crédits, décisions de jury et publication.
- Identifie les calculs officiels existants et ne les reproduis pas dans le portail.
- Vérifie les règles de compensation, validation, rattrapage et arrondis réellement configurées.

FONCTIONNALITÉS
- Afficher les notes uniquement après publication officielle.
- Présenter les résultats par matière/ECUE, UE et semestre selon le référentiel.
- Afficher crédits et décisions uniquement si calculés par le service officiel.
- Distinguer note absente, non publiée, délibération en attente et résultat définitif.
- Présenter l'historique des semestres et décisions publiées.
- Permettre une demande de rectification via le workflow existant ; l'étudiant ne modifie jamais directement une note.
- Les relevés PDF doivent provenir du générateur officiel et inclure uniquement les données de l'étudiant autorisé.

SÉCURITÉ
- Protéger les résultats non publiés et les décisions confidentielles.
- Vérifier que chaque note appartient à l'étudiant connecté.
- Empêcher l'accès par modification d'identifiant ou paramètre d'URL.

TESTS
- Note publiée/non publiée, résultat de rattrapage, note absente, semestre incomplet, étudiant étranger, calcul officiel et PDF autorisé.

AUDIT FINAL
- Vérifier les chiffres contre le service officiel de calcul.
- Toute divergence de calcul bloque la livraison jusqu'à résolution.
```

------------------------------------------------------------------------

# 14. LOOP 12 --- Présences et justificatifs côté étudiant

``` text
LOOP 12 — CONSULTATION DES PRÉSENCES ET JUSTIFICATIFS

AUDIT
- Inspecte le module Présences et les statuts réellement utilisés, les demandes, le stockage des pièces justificatives et les permissions.
- Détermine si une demande de justification existe déjà et quelles personnes peuvent l'approuver.

FONCTIONNALITÉS
- Afficher à l'étudiant son historique de présences par date, séance et matière.
- Distinguer présent, absent, retard, excusé et état en attente/non renseigné selon le modèle réel.
- Permettre de soumettre une demande de justification ou de signaler une erreur de pointage.
- Exiger les pièces uniquement selon les règles configurées.
- Afficher le statut de la demande, la date de soumission et la décision.
- Une demande n'altère pas automatiquement la présence officielle ; seule une décision autorisée met à jour le registre.
- Autoriser l'annulation d'une demande seulement si le workflow le permet.

SÉCURITÉ
- L'étudiant ne peut consulter/modifier que ses propres présences et demandes.
- Valider les fichiers et les stocker en privé.
- Les enseignants/scolarité ne peuvent traiter que les demandes de leur périmètre.
- Journaliser décision et correction sans exposer les pièces dans les logs.

TESTS
- Étudiant A tente d'ouvrir la présence de B, fichier invalide, demande répétée, décision acceptée/refusée, présence modifiée après décision, statut non autorisé.

AUDIT FINAL
- Vérifier que la demande ne modifie pas directement la présence sans workflow.
- Corriger tout IDOR ou fuite documentaire avant LOOP 13.
```

------------------------------------------------------------------------

# 15. LOOP 13 --- Dossier étudiant, documents et inscriptions

``` text
LOOP 13 — DOSSIER ÉTUDIANT ET INSCRIPTION

AUDIT
- Inspecte les dossiers étudiants, StudentDossiers, pièces justificatives, stockage privé, inscriptions, périodes d'inscription, référentiel MENS et services d'identification existants.
- Repère les workflows de dépôt, contrôle, validation et refus.
- Vérifie les règles selon niveau, parcours et type d'inscription.

FONCTIONNALITÉS
- Permettre à l'étudiant de consulter l'état officiel de son inscription.
- Afficher les pièces attendues, reçues, rejetées ou à compléter.
- Permettre le dépôt ou remplacement de pièces uniquement pendant les fenêtres et étapes autorisées.
- Afficher le motif d'un rejet si le workflow le prévoit.
- Permettre le téléchargement d'attestations générées par les services existants.
- Les documents doivent être rattachés au dossier et à l'étudiant de manière structurée.

SÉCURITÉ
- Stockage privé, validation MIME/extension/taille, nommage serveur, autorisation par dossier.
- Aucun accès aux dossiers d'un autre étudiant, même avec UUID ou URL modifiée.
- Respect des périodes d'inscription, statuts verrouillés et contrôles MENS existants.
- Ne jamais exposer CIN, scans ou données sensibles dans les logs/notifications.

TESTS
- Période ouverte/fermée, pièce valide/invalide, dossier d'un autre étudiant, remplacement, rejet, téléchargement, attestation et régression des inscriptions.

AUDIT FINAL
- Vérifier le workflow métier de bout en bout et la compatibilité avec la scolarité.
- Ne pas créer de nouveau module de documents si l'existant suffit.
```

------------------------------------------------------------------------

# 16. LOOP 14 --- Ressources pédagogiques et travaux côté étudiant

``` text
LOOP 14 — RESSOURCES ET TRAVAUX ÉTUDIANT

AUDIT
- Inspecte le module de ressources de LOOP 06, les groupes, inscriptions, matières et éventuel système de devoirs.
- Si les devoirs n'existent pas, sépare clairement les supports téléchargeables de la remise de travaux ; ne prétends pas que le dépôt existe sans le construire.

FONCTIONNALITÉS
- Lister les supports publiés pour les matières et groupes de l'étudiant.
- Vérifier l'accès au moment du téléchargement, pas seulement lors de l'affichage de la liste.
- Si le système de devoirs est inclus : afficher consignes, dates limites, tentatives autorisées et statut de remise.
- Permettre le dépôt de fichiers ou de texte selon le contrat de devoir.
- Afficher confirmation de remise, horodatage et statut de correction.
- Refuser ou signaler les remises hors délai selon les règles de l'enseignant.
- Ne pas permettre de modifier une remise verrouillée sans politique explicite.

SÉCURITÉ
- Fichiers privés et validation complète.
- Étudiant limité à ses propres remises ; enseignant limité aux devoirs qu'il gère.
- Protéger contre l'IDOR sur devoir, remise et pièce jointe.
- Utiliser stockage et audit existants.

TESTS
- Ressource publiée/non publiée, étudiant hors groupe, remise avant/après délai, fichier invalide, double soumission, téléchargement non autorisé et verrouillage.

AUDIT FINAL
- Vérifier le workflow étudiant/enseignant et les règles de délai.
- Si la remise de travaux est hors périmètre de cette phase, le documenter comme non implémentée et ne pas l'afficher comme disponible.
```

------------------------------------------------------------------------

# 17. LOOP 15 --- Démarches administratives et réclamations étudiant

``` text
LOOP 15 — DEMANDES ADMINISTRATIVES ET RÉCLAMATIONS

AUDIT
- Inspecte OnlineRequests, workflows de demandes, états existants, numérotation, documents, notifications, permissions et service d'audit.
- Réutilise les types de demandes déjà disponibles ; ne duplique pas un type existant.

FONCTIONNALITÉS
- Permettre à l'étudiant de créer uniquement les demandes ouvertes pour son profil et sa situation.
- Afficher référence, date, statut, historique et pièces demandées.
- Permettre réclamation de note ou présence via un type de demande existant ou une extension compatible.
- Fournir les transitions d'état officielles et les messages de décision.
- Empêcher la suppression d'une demande déjà traitée si le workflow impose l'historique.
- Les attestations sont produites par les générateurs officiels et non par des données saisies librement.

SÉCURITÉ/INTÉGRITÉ
- Identité étudiant dérivée de la session.
- Référence unique/idempotente si la convention existante l'exige.
- Autorisation par type de demande, période et statut.
- Transaction pour création de demande et pièces liées.
- Documents stockés en privé ; audit des décisions.

TESTS
- Création autorisée/refusée, demande dupliquée, mauvais état, accès à demande d'un autre étudiant, pièce invalide, traitement, clôture et notification.

AUDIT FINAL
- Vérifier les états, permissions et liens avec les services existants.
- LOOP corrective si une demande contourne la scolarité.
```

------------------------------------------------------------------------

# 18. LOOP 16 --- Accompagnement et progression étudiant

``` text
LOOP 16 — ACCOMPAGNEMENT PÉDAGOGIQUE ET PROGRESSION

AUDIT
- Identifie les données officielles de notes, crédits, absences, UE/ECUE, décisions et historique de parcours.
- Vérifie si des seuils d'alerte sont définis par la faculté ou s'ils doivent être validés par le métier.

FONCTIONNALITÉS
- Présenter la progression académique à partir des données officielles.
- Afficher les UE/crédits validés et les éléments en attente uniquement si le calcul officiel les fournit.
- Proposer des rappels d'échéances et des alertes explicables.
- Si des alertes de risque sont utilisées, afficher les facteurs observables et éviter toute conclusion opaque.
- Ne pas prédire l'échec, classer ou pénaliser automatiquement un étudiant.
- Permettre de masquer ou contester une donnée erronée via les workflows existants.

SÉCURITÉ/ÉTHIQUE
- Données personnelles visibles uniquement par l'étudiant et les personnels autorisés.
- Pas de décision académique automatisée sur la base d'un score non validé.
- Pas d'inférence sensible ni d'exposition d'indicateurs à d'autres étudiants.

TESTS
- Parcours incomplet, crédits validés/non validés, données manquantes, alertes déclenchées/non déclenchées et séparation des profils.

AUDIT FINAL
- Chaque indicateur doit être explicable, fondé sur des règles validées et testé.
- Ne publie aucun score opaque sans validation métier.
```

------------------------------------------------------------------------

# 19. LOOP 17 --- Services communs : notifications, calendrier et audit

``` text
LOOP 17 — SERVICES COMMUNS AUX DEUX PORTAILS

AUDIT
- Inspecte les services existants de notifications, calendrier, audit, recherche et préférences utilisateur.
- Vérifie les canaux actifs, la stratégie de retry et la déduplication.

IMPLÉMENTATION
- Mutualiser les composants communs sans mélanger les droits des portails.
- Chaque notification possède un destinataire autorisé, une catégorie, une référence à la source et un état de lecture si nécessaire.
- N'envoyer que les notifications utiles et pertinentes.
- Dédupliquer les notifications lors des retries.
- Respecter les préférences et canaux configurés.
- Le calendrier doit utiliser les événements métier réels.
- L'audit conserve les changements sensibles sans stocker inutilement les pièces, secrets ou contenus excessifs.

TESTS
- Destinataire, canal désactivé, retry, doublon, notification lue, accès au lien cible et permissions.
- Vérifier qu'une notification ne sert pas de contournement à une policy du module source.

AUDIT FINAL
- Vérifier les liens, le périmètre, la déduplication et les données sensibles.
- Corriger toute fuite ou duplication avant LOOP 18.
```

------------------------------------------------------------------------

# 20. LOOP 18 --- Recherche globale autorisée

``` text
LOOP 18 — RECHERCHE GLOBALE SÉCURISÉE

AUDIT
- Identifie les recherches existantes, index, moteurs éventuels et règles d'accès.
- Détermine les entités que chaque rôle peut rechercher.

FONCTIONNALITÉS
- Recherche côté serveur avec filtres contrôlés, pagination et résultats limités.
- Enseignant : rechercher ses groupes, étudiants autorisés, séances, évaluations et ressources selon permissions.
- Étudiant : rechercher ses ressources, demandes, événements et informations accessibles.
- Ne pas retourner de champs privés inutiles dans les résultats.
- Ajouter un état vide et une gestion de caractères spéciaux.

SÉCURITÉ/PERFORMANCE
- Appliquer les scopes avant de limiter/retourner les résultats.
- Prévenir l'énumération d'identifiants, les fuites de CIN et les recherches inter-faculté.
- Limiter fréquence, longueur de requête et volume des résultats selon l'architecture.
- Utiliser des index pertinents et des requêtes paramétrées.

TESTS
- Recherche de données autorisées et interdites, termes spéciaux, pagination, limites, injection SQL et fuite inter-faculté.

AUDIT FINAL
- Vérifier les résultats réels et les requêtes SQL ; aucun résultat interdit ne doit être révélé.
```

------------------------------------------------------------------------

# 21. LOOP 19 --- Exports et rapports

``` text
LOOP 19 — EXPORTS PDF/CSV/XLSX ET RAPPORTS

AUDIT
- Inspecte les exports existants, bibliothèques, queues, rapports officiels et autorisations.
- Identifie les limites de volume et le stockage temporaire utilisé.

FONCTIONNALITÉS
- Exporter seulement les colonnes nécessaires à l'usage demandé.
- Enseignant : listes de ses groupes, récapitulatifs de présence et données d'évaluation autorisées.
- Étudiant : documents personnels et résultats publiés.
- Utiliser les générateurs officiels de PDF/relevés si disponibles.
- Pour grands volumes, utiliser traitement asynchrone si le projet le supporte.
- Donner un statut clair de génération et une expiration aux fichiers temporaires.

SÉCURITÉ
- Revalider les permissions au moment de créer et télécharger le fichier.
- Stockage privé, noms générés, expiration et nettoyage.
- Neutraliser les formules de tableur dangereuses dans les exports CSV/XLSX.
- Ne jamais mettre des fichiers privés dans un répertoire public sans contrôle d'accès.

TESTS
- Export autorisé/non autorisé, colonnes, lignes, volume, expiration, téléchargement d'un autre utilisateur et injection de formule.

AUDIT FINAL
- Comparer le fichier produit aux données source, vérifier la confidentialité et les limites de volume.
```

------------------------------------------------------------------------

# 22. LOOP 20 --- Mode hors ligne (optionnel, P4)

``` text
LOOP 20 — MODE HORS LIGNE POUR L'APPEL (OPTIONNEL)

Ne commence cette LOOP qu'après conformité complète du module Présences et décision explicite que le mode hors ligne est nécessaire.

AUDIT
- Évalue les contraintes réseau réelles, les appareils, le framework front-end et les risques de conservation de données personnelles sur appareil.
- Vérifie les solutions de cache existantes et les politiques de sécurité.

CONCEPTION
- Limiter le mode hors ligne à un cas d'usage précis, par exemple préparer localement une saisie d'appel déjà autorisée.
- Ne jamais présenter une sauvegarde locale comme une sauvegarde serveur confirmée.
- Utiliser un indicateur visible : local non synchronisé, synchronisation en cours, synchronisé, conflit, échec.
- Définir une expiration courte, un mécanisme d'effacement et une stratégie en cas de déconnexion.
- À la synchronisation, revalider toutes les permissions, la séance, les inscriptions et l'état de clôture.
- Gérer les conflits explicitement ; ne pas écraser silencieusement les données serveur.
- Évaluer soigneusement le stockage local et ne pas y conserver de documents sensibles inutiles.

TESTS
- Hors ligne, reconnexion, doublon, changement d'autorisation, appel clôturé, conflits, appareil partagé et effacement.

AUDIT FINAL
- Vérifier que le statut de synchronisation est honnête et que les données locales sont minimisées.
- Si le risque dépasse le bénéfice, documenter NO-GO pour le mode hors ligne plutôt que livrer une solution fragile.
```

------------------------------------------------------------------------

# 23. LOOP 21 --- QR code temporaire pour l'appel (optionnel, P4)

``` text
LOOP 21 — POINTAGE PAR QR TEMPORAIRE (OPTIONNEL)

Cette fonctionnalité complète l'appel manuel ; elle ne doit pas remplacer la possibilité de corriger et contrôler l'appel par l'enseignant.

AUDIT
- Vérifie le besoin métier, les appareils étudiants, les contraintes de réseau et les règles anti-fraude.
- Inspecte le module Présences et les mécanismes de sessions/jetons existants.

CONCEPTION SÉCURISÉE
- Générer un jeton aléatoire, à durée de vie courte, lié à une occurrence de séance et à une session d'appel.
- Ne jamais inclure de données personnelles ou d'identifiants prévisibles dans le QR.
- Valider côté serveur le jeton, son expiration, la séance, l'utilisateur connecté, l'inscription au groupe et l'unicité du pointage.
- Prévoir un mécanisme anti-rejeu et une gestion explicite des tentatives répétées.
- Ne pas considérer le QR seul comme une preuve absolue de présence physique.
- L'enseignant conserve la capacité de vérifier et corriger manuellement les statuts.
- Le QR ne doit pas permettre à un étudiant de pointer pour un autre.

TESTS
- Jeton expiré, réutilisé, invalide, séance étrangère, étudiant non inscrit, double pointage, utilisateur non authentifié et séance clôturée.

AUDIT FINAL
- Réaliser une revue de menace et documenter les limites anti-fraude.
- Si les protections ne sont pas démontrées, ne pas activer le QR.
```

------------------------------------------------------------------------

# 24. LOOP 22 --- Assistant IA pédagogique (optionnel, P4)

``` text
LOOP 22 — ASSISTANT IA PÉDAGOGIQUE

AUDIT
- Définis précisément les tâches autorisées et les données nécessaires.
- Vérifie la politique de confidentialité, les services IA approuvés et les limites de conservation.
- N'envoie aucune donnée étudiante sensible à un service externe sans autorisation explicite et cadre de protection validé.

FONCTIONNALITÉS POSSIBLES
- FAQ sur le fonctionnement du portail, procédures d'inscription et calendrier publié.
- Recherche sémantique sur des supports de cours autorisés.
- Résumé de documents auxquels l'utilisateur a droit.
- Aide à la compréhension d'une notion, sans se substituer à une décision officielle.
- L'assistant ne modifie jamais directement une note, une présence, une inscription ou une décision de jury.

SÉCURITÉ
- Vérifier les droits d'accès aux documents avant la récupération et avant la génération.
- Protéger contre prompt injection dans les documents récupérés.
- Séparer les données des utilisateurs et limiter la conservation.
- Ne pas exposer les notes, CIN, justificatifs ou dossiers d'autres étudiants.
- Afficher les limites et sources de l'information ; prévoir une réponse de refus lorsque les données sont insuffisantes.

TESTS
- Demandes autorisées/interdites, document injectant des instructions, tentative d'accès inter-étudiant, réponses sans source et erreurs du service IA.

AUDIT FINAL
- Vérifier confidentialité, permissions, sources et absence d'écriture métier automatique.
- Ne pas déployer si l'isolation des données ou les limites de conservation ne sont pas garanties.
```

------------------------------------------------------------------------

# 25. LOOP 23 --- Audit transversal final

``` text
LOOP 23 — AUDIT GLOBAL DES PORTAILS

Cette LOOP ne remplace pas les audits individuels ; elle vérifie l'intégration de l'ensemble.

1. Inventorier toutes les routes enseignant/étudiant et les comparer à la matrice de permissions.
2. Tester l'IDOR sur chaque ressource par substitution d'identifiants.
3. Vérifier l'isolation par faculté, groupe, enseignant et étudiant.
4. Contrôler les transitions d'état et les workflows de publication.
5. Vérifier les migrations sur base neuve et sur copie de données historiques.
6. Exécuter toute la suite PHPUnit et relever précisément les échecs.
7. Exécuter les outils de style et d'analyse déjà configurés.
8. Vérifier l'absence de N+1 et mesurer les endpoints critiques.
9. Auditer les fichiers privés, exports, notifications et liens de téléchargement.
10. Vérifier les états vides, erreurs, accessibilité clavier et responsive.
11. Vérifier les journaux : pas de secrets, tokens, CIN ou contenus sensibles excessifs.
12. Vérifier les procédures de rollback et les migrations non destructives.
13. Vérifier que les portails sont des interfaces des modules existants et qu'aucune source de vérité concurrente n'a été créée.
14. Produire une matrice globale :
    - fonctionnalité ;
    - source de vérité ;
    - routes ;
    - permissions ;
    - migrations ;
    - tests ;
    - preuves ;
    - statut PASS/FAIL/N/A.
15. Tout FAIL obligatoire déclenche une LOOP corrective ciblée et un nouvel audit.
16. Ne prononcer GO que si les critères obligatoires sont tous PASS. Sinon, donner GO CONDITIONNEL ou NO-GO avec les bloqueurs exacts.
```

------------------------------------------------------------------------

# 26. Modèle obligatoire de LOOP corrective

Utiliser ce modèle dès qu'un audit de fonctionnalité détecte un manque.
Remplacer les champs entre crochets par les résultats réels de l'audit.

``` text
LOOP CORRECTIVE — [NOM DE LA FONCTIONNALITÉ]

CONTEXTE
L'audit final précédent a identifié les écarts suivants :
- [Écart 1 + preuve]
- [Écart 2 + preuve]
- [Écart 3 + preuve]

RÈGLE
Ne commence aucune autre fonctionnalité. Corrige uniquement les écarts identifiés et leurs effets secondaires nécessaires. N'effectue pas de refonte générale.

ÉTAPE 1 — REPRODUCTION
- Reproduis chaque défaut avec un test ou une procédure vérifiable.
- Confirme que le défaut existe dans le code actuel.
- Identifie la cause racine, pas seulement le symptôme.

ÉTAPE 2 — CORRECTION
- Modifie les fichiers minimaux nécessaires.
- Ajoute ou ajuste les tests qui auraient dû détecter le défaut.
- Préserve les conventions et données existantes.
- Ajoute une migration uniquement si la correction l'exige réellement.
- Pour un défaut de sécurité, ajoute un test de régression qui échoue avant la correction et passe après.

ÉTAPE 3 — VALIDATION
- Relance le test de reproduction.
- Relance tous les tests du module touché.
- Relance les tests de sécurité et d'intégrité associés.
- Relance les outils de qualité applicables.
- Inspecte le diff et la migration.

ÉTAPE 4 — NOUVEL AUDIT
Pour chaque écart d'origine, fournir :
- preuve avant correction ;
- correction appliquée ;
- fichier/méthode/contrainte concerné ;
- test exécuté ;
- résultat exact ;
- statut PASS/FAIL.

DÉCISION
- Si tous les écarts obligatoires sont PASS et aucun nouveau défaut n'est apparu : marquer la fonctionnalité CONFORME et autoriser la prochaine LOOP.
- Sinon : garder le statut BLOQUÉ — NON CONFORME et lancer une nouvelle LOOP corrective.
Ne jamais fermer un écart sur simple affirmation.
```

------------------------------------------------------------------------

# 27. Rapport standard de fin de LOOP

L'agent doit remettre ce rapport après chaque fonctionnalité :

``` text
# Rapport LOOP — [Nom]

## 1. Décision
CONFORME / BLOQUÉ — NON CONFORME

## 2. Audit initial
- Existant conforme :
- Existant incomplet :
- Absent :
- Risques détectés :

## 3. Changements réels
- Fonctionnalités livrées :
- Fichiers créés :
- Fichiers modifiés :
- Routes :
- Services/policies :
- Migrations :

## 4. Sécurité
- Authentification :
- Autorisation/IDOR :
- Isolation faculté :
- Validation :
- Transactions/contraintes :
- Audit des changements :

## 5. Tests réellement exécutés
Pour chaque commande : commande exacte, résultat, nombre de tests réussis/échoués, erreurs restantes.

## 6. Matrice d'audit
| Critère | Preuve | Résultat |
|---|---|---|
| [Critère] | [Fichier/test/contrainte] | PASS/FAIL/N/A |

## 7. Régressions et compatibilité
- Données historiques :
- Routes existantes :
- Modules voisins :
- Performance :

## 8. Écarts restants
- Liste précise, ou « Aucun ».

## 9. Autorisation de poursuivre
- CONFORME : prochaine LOOP autorisée = [nom].
- NON CONFORME : aucune LOOP suivante ; lancer LOOP CORRECTIVE.
```

------------------------------------------------------------------------

# 28. Checklist de suivi globale

Copier cette checklist dans le suivi de projet. Une case ne peut être
cochée que si le rapport de LOOP correspondant prouve la conformité.

-   [ ] LOOP 01 --- Socle d'accès et profils
-   [ ] LOOP 02 --- Tableau de bord enseignant
-   [ ] LOOP 03 --- Emploi du temps enseignant
-   [ ] LOOP 04 --- Appel manuel intelligent
-   [ ] LOOP 05 --- Évaluations et saisie des notes
-   [ ] LOOP 06 --- Supports de cours enseignant
-   [ ] LOOP 07 --- Communication et notifications enseignant
-   [ ] LOOP 08 --- Statistiques enseignant
-   [ ] LOOP 09 --- Tableau de bord étudiant
-   [ ] LOOP 10 --- Emploi du temps étudiant
-   [ ] LOOP 11 --- Notes et progression LMD
-   [ ] LOOP 12 --- Présences et justificatifs étudiant
-   [ ] LOOP 13 --- Dossier étudiant et inscriptions
-   [ ] LOOP 14 --- Ressources et travaux étudiant
-   [ ] LOOP 15 --- Démarches et réclamations
-   [ ] LOOP 16 --- Accompagnement pédagogique
-   [ ] LOOP 17 --- Services communs
-   [ ] LOOP 18 --- Recherche globale
-   [ ] LOOP 19 --- Exports et rapports
-   [ ] LOOP 20 --- Mode hors ligne (si retenu)
-   [ ] LOOP 21 --- QR temporaire (si retenu)
-   [ ] LOOP 22 --- Assistant IA (si retenu)
-   [ ] LOOP 23 --- Audit transversal final

## Décision finale du projet

**GO** uniquement si toutes les fonctionnalités incluses dans le
périmètre ont des audits PASS, que les tests critiques sont exécutés
avec succès et que les migrations sont validées.

**GO CONDITIONNEL** si les fonctions principales sont conformes mais
qu'il reste des limites non critiques explicitement acceptées, avec
responsable et plan de correction.

**NO-GO** si une faille d'autorisation, une fuite de données, une
migration risquée, une incohérence de présence/note ou un échec critique
demeure.

Ne pas confondre « code ajouté », « tests exécutés » et « fonctionnalité
conforme » : la conformité exige une preuve vérifiable.
