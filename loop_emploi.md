Voici une série de prompts prêts à copier dans ton agent de développement (Antigravity, Claude Code, Cursor, etc.) pour auditer et améliorer tout le module Emplois du temps de ton application Laravel. Ils couvrent les cas fonctionnels, la sécurité, les conflits, les performances, les tests et les corrections, avec une boucle automatique :

`LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE`

L’objectif est de traiter les cas un par un, sans casser les fonctionnalités déjà validées, jusqu’à obtenir un module fiable.

# Prompt 1 — Audit global et initialisation de la boucle

# LOOP SCHEDULE-01 — Audit exhaustif du module Emplois du temps

## Rôle

Agis comme un ingénieur logiciel senior spécialisé en Laravel, architecture modulaire, sécurité applicative, optimisation SQL, qualité logicielle et tests automatisés.

## Contexte

Application universitaire Laravel avec un module Emplois du temps comprenant :

- Gestion des emplois du temps et des séances.
- Gestion des créneaux, enseignants, groupes et salles.
- Détection des conflits.
- Déplacement et modification des séances.
- Exploration des disponibilités, salles libres, créneaux libres et charges des enseignants.
- Import CSV, XLSX, JSON et XML.
- Portails étudiants et enseignants en lecture seule.
- Exports et génération PDF.
- Notifications, journalisation et audit.
- Dashboard et statistiques.

## Objectif

Auditer l'ensemble du module existant avant toute modification. Ne pas réécrire les fonctionnalités déjà correctes. Identifier les défauts réels, les risques, les tests manquants et les améliorations prioritaires.

## Boucle obligatoire

Pour chaque cas identifié, exécuter :

1. **LAN — Lecture et analyse**
   - Localiser les routes, contrôleurs, services, modèles, migrations, policies, Form Requests, vues et tests.
   - Reconstituer le comportement réel à partir du code.
   - Identifier les dépendances et les invariants métier.
   - Rechercher les doublons et les implémentations parallèles.
2. **DEV — Développement**
   - Implémenter uniquement les corrections justifiées.
   - Respecter l'architecture existante et les conventions Laravel du projet.
   - Préserver la compatibilité des routes, permissions, données et interfaces.
   - Ajouter les tests de non-régression.
3. **PERFORMANCE — Mesure**
   - Examiner les requêtes SQL, N+1, index, pagination, mémoire, transactions et traitements volumineux.
   - Mesurer avant/après lorsque cela est pertinent.
   - Ne jamais annoncer une amélioration non mesurée.
4. **AUDIT — Vérification**
   - Vérifier sécurité, autorisations, périmètre facultaire, intégrité des données, journalisation et gestion des erreurs.
   - Examiner les risques de concurrence et de double soumission.
   - Vérifier la cohérence entre interfaces Web, API et tâches asynchrones.
5. **CORRECTION — Remédiation**
   - Corriger les défauts confirmés.
   - Ajouter les tests de régression nécessaires.
   - Ne pas masquer un échec par une désactivation de test, une assertion affaiblie ou une exception ignorée.
6. **VALIDATION — Preuves**
   - Exécuter les tests ciblés, puis les tests plus larges pertinents.
   - Exécuter Laravel Pint sur les fichiers modifiés.
   - Contrôler les routes concernées.
   - Consigner les commandes, résultats, échecs et limitations.
7. **AUTO-PASSAGE — Cas suivant**
   - Passer au cas suivant uniquement si le cas courant est validé ou explicitement bloqué.
   - Ne jamais marquer un cas non exécuté comme réussi.
   - Répéter la boucle jusqu'à épuisement des cas ou apparition d'un blocage nécessitant une décision humaine.

## Règles de sécurité

- Ne pas supprimer de données réelles.
- Ne pas exécuter de migration destructive sans autorisation.
- Ne pas modifier les règles métier uniquement pour faire passer les tests.
- Ne pas désactiver les contrôles d'accès.
- Ne pas exposer les données personnelles dans les logs.
- Ne pas effectuer de déploiement ni de commit automatique.
- Ne pas inventer de fichiers, routes, fonctionnalités ou résultats de tests.

## Première tâche

1. Cartographier le module.
2. Identifier les fonctionnalités et tests existants.
3. Exécuter la suite ciblée initiale.
4. Produire un rapport de référence.
5. Construire un registre de cas avec identifiant, priorité, risque, preuve, statut et prochaine action.
6. Ne commencer les corrections qu'après établissement de cette référence.

## Statuts autorisés

- NOT_STARTED
- IN_PROGRESS
- PASS
- PASS_WITH_WARNINGS
- BLOCKED
- FAIL
- NOT_EXECUTED
- NOT_APPLICABLE

## Livrables

Créer ou mettre à jour :

- `docs/engineering/schedule-audit-report.md`
- `docs/engineering/schedule-audit-cases.md`
- `docs/engineering/schedule-audit-progress.md`

À la fin, présenter les défauts confirmés, les corrections effectuées, les tests exécutés, les risques résiduels et les cas bloqués.

# Prompt 2 — Sécurité et contrôle d'accès

# LOOP SCHEDULE-02 — Sécurité complète du module

## Objectif

Auditer toutes les surfaces d'accès du module Emplois du temps et corriger les vulnérabilités confirmées.

## Cas obligatoires

### Authentification

- Utilisateur non authentifié.
- Session expirée.
- Session invalide.
- Compte désactivé.
- Utilisateur authentifié sans permission.

### Autorisations

- Administrateur autorisé.
- Gestionnaire de scolarité.
- Enseignant.
- Étudiant.
- Utilisateur d'une autre faculté.
- Utilisateur avec rôle incomplet ou permission révoquée.

### IDOR et isolation

- Modifier l'identifiant d'un emploi du temps.
- Modifier l'identifiant d'une séance.
- Accéder à une séance appartenant à un autre groupe.
- Accéder aux informations d'un enseignant non autorisé.
- Accéder aux données d'une autre faculté.
- Télécharger le PDF d'un autre étudiant ou enseignant.

### Sécurité Web et API

- CSRF.
- Mass assignment.
- Validation serveur.
- Injection SQL.
- XSS stockée et réfléchie.
- Manipulation des paramètres de recherche.
- Rate limiting.
- Accès direct aux routes API.
- Réponses JSON qui divulguent des données internes.
- Contrôle des exports et des fichiers générés.

## Travail demandé

Pour chaque cas :

1. Identifier la route et le contrôleur concernés.
2. Vérifier middleware, policy, Gate, Form Request et filtrage des données.
3. Reproduire le comportement avec un test automatisé.
4. Corriger la vulnérabilité sans modifier les autorisations légitimes.
5. Ajouter un test positif et un test négatif.
6. Vérifier qu'une autre faculté ne peut pas être ciblée par simple modification d'identifiant.
7. Rejouer les tests de sécurité existants.

Utiliser des codes HTTP cohérents avec les conventions du projet : 401 ou redirection vers la connexion pour une session absente, 403 pour une action interdite, 404 lorsque la politique du projet masque volontairement l'existence d'une ressource.

## Contraintes

- Ne jamais supprimer une policy pour contourner un échec.
- Ne pas accorder des permissions globales pour simplifier les tests.
- Ne pas utiliser de vraies données personnelles dans les fixtures.
- Vérifier que les exports et PDF appliquent les mêmes restrictions que les pages.

## Boucle

LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

## Validation

Exécuter les tests ciblés de sécurité, les tests fonctionnels liés et Pint sur les fichiers modifiés. Produire un tableau des vulnérabilités, de leur gravité, de leur preuve de correction et du risque résiduel.

# Prompt 3 — Gestion des emplois du temps et des séances

# LOOP SCHEDULE-03 — Exhaustivité fonctionnelle CRUD et cycle de vie

Audite et fiabilise la gestion des emplois du temps, des séances et de leurs états.

## Cas à vérifier

### Création

- Création valide.
- Champs obligatoires absents.
- Dates invalides.
- Date de fin antérieure à la date de début.
- Séance sans enseignant.
- Séance sans groupe.
- Séance sans salle.
- Références inexistantes.
- Références désactivées.
- Ressources appartenant à une autre faculté.
- Double soumission du formulaire.

### Modification

- Modification d'un seul champ.
- Modification simultanée de plusieurs champs.
- Conservation des champs omis lors d'une mise à jour partielle.
- Modification d'une séance déjà publiée.
- Modification d'une séance passée.
- Modification par un utilisateur sans permission.
- Modification concurrente par deux utilisateurs.
- Conflit créé par une modification.

### Suppression et archivage

- Suppression autorisée.
- Suppression interdite par une relation existante.
- Séance déjà publiée.
- Séance ayant des notifications ou un historique.
- Suppression répétée.
- Archivage et restauration si ces fonctionnalités existent.
- Conservation de l'audit après suppression.

### Cycle de vie

- Brouillon.
- Planifié.
- Publié.
- Annulé.
- Terminé.
- Archivé, si pris en charge.

Ne pas imposer ces états si le projet utilise une autre machine à états. Découvrir d'abord les états réellement implémentés.

## Invariants

- Les transitions d'état doivent être explicites et validées côté serveur.
- Un emploi du temps publié ne doit pas devenir incohérent après modification.
- Une annulation doit être distinguée d'une suppression.
- Une opération répétée ne doit pas créer de doublons.
- L'historique doit refléter les changements réellement validés.

## Exécution

Pour chaque cas :

1. Reproduire le comportement existant.
2. Écrire un test qui décrit le comportement attendu.
3. Corriger le service ou contrôleur responsable.
4. Vérifier les transactions et les événements déclenchés.
5. Vérifier les pages Web, réponses JSON et portails.
6. Exécuter les tests ciblés et de non-régression.

## Livrable

Un tableau de couverture CRUD, des transitions autorisées/interdites, les règles métier découvertes et les tests prouvant leur respect.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 4 — Créneaux, dates et horaires

# LOOP SCHEDULE-04 — Créneaux et intégrité temporelle

Audite les créneaux horaires et toutes les opérations qui manipulent leurs dates, heures et durées.

## Cas à couvrir

- Heure de début inférieure à l'heure de fin.
- Heure de début égale à l'heure de fin.
- Heure de début supérieure à l'heure de fin.
- Créneau traversant minuit.
- Durée nulle ou négative.
- Date passée.
- Date très éloignée dans le futur.
- Format de date incorrect.
- Format d'heure incorrect.
- Bornes horaires de l'établissement.
- Jours non ouvrables.
- Jours fériés ou exceptions du calendrier, s'ils existent.
- Changement d'heure et fuseaux horaires si pertinents.
- Deux créneaux adjacents sans chevauchement.
- Deux créneaux partiellement superposés.
- Deux créneaux parfaitement identiques.
- Modification du créneau après affectation d'une salle.
- Modification du créneau après publication.
- Créneau utilisé par plusieurs séances.
- Import d'horaires avec formats différents.
- Écart entre validation JavaScript et validation serveur.

## Règle de chevauchement

Deux intervalles [début, fin) se chevauchent lorsque :\
`debutA < finB && debutB < finA`.

Cette règle autorise deux séances consécutives lorsque la fin de l'une est exactement le début de l'autre. Vérifier toutefois si le projet impose une pause minimale.

## Travail

- Centraliser les validations temporelles dans le service ou composant métier approprié.
- Éviter de dupliquer des règles contradictoires dans les contrôleurs.
- Vérifier le stockage des dates et heures en base.
- Tester les limites exactes, pas uniquement les cas ordinaires.
- Contrôler les conversions entre fuseau applicatif, base de données et interface.

## Validation

Créer des tests unitaires pour les intervalles, des tests fonctionnels pour les formulaires et API, puis des tests de régression pour les déplacements et imports.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 5 — Détection des conflits et capacité des salles

# LOOP SCHEDULE-05 — Moteur de conflits

## Objectif

Garantir qu'aucune opération ne crée de conflit non autorisé entre enseignants, groupes, salles et capacités.

## Conflits à tester

### Enseignants

- Même enseignant sur deux séances qui se chevauchent.
- Même enseignant sur deux séances adjacentes.
- Enseignant inactif.
- Enseignant indisponible.
- Enseignant hors de son périmètre facultaire.
- Deux requêtes concurrentes affectant le même enseignant.

### Groupes

- Même groupe sur deux séances simultanées.
- Groupes différents autorisés simultanément.
- Groupe inactif ou inexistant.
- Groupe appartenant à un autre programme ou niveau.
- Modification du groupe après publication.

### Salles

- Même salle sur deux séances simultanées.
- Salles différentes sur la même plage horaire.
- Salle inactive ou indisponible.
- Salle incompatible avec le type de séance.
- Capacité de salle insuffisante.
- Capacité égale au nombre d'étudiants.
- Nombre d'étudiants supérieur à la capacité.
- Nombre d'étudiants inconnu.
- Plusieurs salles pour une même séance si cette possibilité existe.
- Réservation concurrente d'une même salle.

### Combinaisons

- Conflit enseignant uniquement.
- Conflit groupe uniquement.
- Conflit salle uniquement.
- Plusieurs conflits sur une séance.
- Conflit créé par un déplacement.
- Conflit introduit par un import.
- Conflit entre séances publiées et brouillons.
- Conflit entre ressources de facultés différentes.

## Règles

- Distinguer les erreurs bloquantes des avertissements.
- Exclure correctement la séance courante lors de sa modification.
- Appliquer la même logique dans l'interface, l'API, les imports et les tâches asynchrones.
- Ne jamais se fier uniquement au JavaScript.
- Ne jamais valider définitivement un conflit sur la seule base d'une simulation ancienne.

## Concurrence

Simuler deux transactions qui tentent de réserver simultanément le même enseignant, groupe ou local. Examiner les transactions, verrous et contraintes d'intégrité disponibles. Si une contrainte SQL ne peut pas représenter directement un chevauchement temporel, mettre en place une stratégie transactionnelle adaptée au SGBD et tester les courses critiques.

## Résultat attendu

Un moteur de validation cohérent, des messages explicites, une simulation fidèle à l'application réelle et des tests prouvant que les opérations concurrentes ne produisent pas de conflits silencieux.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 6 — Déplacement, échange et réaffectation

# LOOP SCHEDULE-06 — Déplacement sécurisé des séances

Audite les fonctions de déplacement, réaffectation et échange de séances.

## Cas obligatoires

- Déplacement vers un créneau libre.
- Déplacement vers un créneau occupé.
- Déplacement vers une salle insuffisante.
- Déplacement vers un créneau hors disponibilité de l'enseignant.
- Déplacement d'une séance publiée.
- Déplacement d'une séance annulée.
- Déplacement d'une séance passée.
- Déplacement vers une autre faculté.
- Double clic ou double soumission.
- Requête JSON malformée.
- Identifiant inexistant.
- Modification concurrente de la même séance.
- Échec après validation mais avant écriture.
- Échec après écriture d'une partie des données.
- Échec lors de l'envoi de notifications.
- Échange de deux séances, si disponible.
- Déplacement en masse.
- Annulation et restauration d'un déplacement.

## Règles

- Calculer les conflits sur la destination avant écriture.
- Répéter les contrôles essentiels au moment de la transaction.
- Protéger l'intégrité des écritures avec une transaction adaptée.
- Éviter qu'une erreur laisse une séance partiellement déplacée.
- Rendre les opérations répétées idempotentes lorsque nécessaire.
- Journaliser l'auteur, l'ancien créneau, le nouveau créneau, la date et le résultat.
- Envoyer les notifications après validation de la transaction, idéalement via un mécanisme fiable de traitement après commit ou une outbox si l'architecture le justifie.

## Tests

Pour chaque scénario, vérifier la base de données, la réponse HTTP, l'historique, les notifications et les données visibles dans les portails.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 7 — Explorateurs et disponibilité

# LOOP SCHEDULE-07 — Explorateurs, disponibilité et statistiques

Audite les fonctionnalités d'exploration des emplois du temps.

## Fonctions concernées

- Recherche de disponibilités.
- Recherche de salles libres.
- Recherche de créneaux libres.
- Charge des enseignants.
- Occupation des salles.
- Recherche par groupe, niveau, programme, enseignant ou période.
- Filtres combinés.
- Pagination, tri et recherche textuelle.

## Cas à tester

- Aucun résultat.
- Un seul résultat.
- Très grand volume.
- Paramètre manquant.
- Paramètre invalide.
- Bornes de dates inversées.
- Filtres contradictoires.
- Enseignant sans séance.
- Salle sans réservation.
- Groupe sans emploi du temps publié.
- Séances annulées ou archivées.
- Séances en brouillon.
- Filtrage facultaire.
- Utilisateur sans droit de consulter les statistiques.
- Pagination hors limites.
- Tri sur une colonne non autorisée.
- Cache périmé après modification.
- Calcul de charge incluant ou excluant les états pertinents.
- Résultats différents entre tableau de bord et page détaillée.

## Performance

- Rechercher les requêtes N+1.
- Vérifier les index des colonnes de filtre et de jointure.
- Utiliser des agrégations SQL lorsque cela améliore réellement les performances.
- Limiter les données renvoyées par l'API.
- Ne pas charger toute la base en mémoire pour calculer une disponibilité.
- Mesurer le nombre de requêtes et la durée sur un jeu de données réaliste.

## Livrable

Une matrice des filtres, des états inclus dans chaque calcul, des règles de cache et des mesures de performance avant/après.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 8 — Imports CSV, XLSX, JSON et XML

# LOOP SCHEDULE-08 — Imports robustes et sécurisés

Audite les imports du module Emplois du temps, y compris les imports par interface, API et traitements asynchrones.

## Formats

- CSV.
- XLSX.
- JSON.
- XML.
- Formats ou versions non supportés.

## Validation des fichiers

- Fichier absent.
- Extension autorisée mais contenu invalide.
- Extension interdite.
- MIME incohérent.
- Fichier vide.
- Fichier tronqué.
- Encodage UTF-8 et caractères accentués.
- En-têtes absents, dupliqués ou inconnus.
- Colonnes obligatoires absentes.
- Lignes vides.
- Dates et heures mal formées.
- Identifiants inconnus.
- Enseignant, salle ou groupe inactif.
- Doublons internes.
- Doublons avec les données existantes.
- Capacité insuffisante.
- Conflits temporels.
- Très gros fichier.
- Dépassement de mémoire ou de durée maximale.
- Import interrompu puis relancé.
- Échec au milieu du traitement.

## Cycle de traitement

Respecter le flux existant :

1. Dépôt sécurisé.
2. Détection et normalisation du format.
3. Validation et prévisualisation.
4. Présentation des erreurs par ligne.
5. Confirmation explicite.
6. Application transactionnelle ou par lots contrôlés.
7. Rapport final.
8. Annulation ou compensation selon les garanties du système.

La prévisualisation ne doit pas modifier les données métier. Lors de la confirmation, recalculer les conflits afin de ne pas utiliser un état périmé.

## Sécurité

- Empêcher le XXE pour XML.
- Limiter la taille des fichiers et des archives.
- Éviter les traversées de chemins.
- Protéger les fichiers temporaires.
- Empêcher les formules dangereuses dans les exports CSV.
- Ne pas faire confiance aux extensions ou au MIME seuls.
- Éviter les fuites de données entre facultés.
- Contrôler les droits d'import et la limitation de débit de l'API.
- Nettoyer les fichiers temporaires après succès et échec.

## Idempotence et reprise

- Relancer un même import ne doit pas dupliquer silencieusement les séances.
- Définir la stratégie de reprise et de déduplication.
- Tester les retries de queue.
- Vérifier la cohérence si le processus s'arrête brutalement.
- Ne pas annoncer un import réussi si une partie des lignes a échoué sans que cela soit explicitement indiqué.

## Livrable

Une matrice par format, taille, type d'erreur, comportement attendu et preuve de validation.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 9 — Portails étudiant et enseignant, PDF et exports

# LOOP SCHEDULE-09 — Portails et diffusion des emplois du temps

Audite l'affichage des emplois du temps dans les espaces étudiants et enseignants.

## Étudiants

- Étudiant avec emploi du temps publié.
- Étudiant sans emploi du temps.
- Étudiant appartenant à plusieurs groupes, si possible.
- Étudiant nouvellement affecté.
- Étudiant inactif ou archivé.
- Tentative d'accès à un autre groupe.
- Séance annulée.
- Emploi du temps modifié après publication.
- Téléchargement PDF.
- Consultation sur mobile.
- Filtrage par semaine, jour et période.

## Enseignants

- Enseignant avec séances.
- Enseignant sans séance.
- Enseignant inactif.
- Tentative d'accès à l'emploi du temps d'un collègue.
- Séances déplacées ou annulées.
- Téléchargement et impression.

## PDF et exports

- Fichier généré correctement.
- Aucun résultat.
- Très grand emploi du temps.
- Caractères accentués.
- Sauts de page.
- Informations tronquées.
- Export de données non autorisées.
- Nom de fichier sûr.
- CSV contenant des valeurs commençant par `=`, `+`, `-` ou `@`.
- Téléchargement après expiration de session.
- URL directe du fichier.
- Fichier temporaire orphelin.

## Interface

- États de chargement et d'erreur.
- État vide explicite.
- Affichage des annulations.
- Fuseau horaire et format de date cohérents.
- Accessibilité clavier.
- Contraste et lisibilité.
- Affichage sur écran étroit.

## Règle

Les portails ne doivent afficher que les informations autorisées, même si l'utilisateur modifie les paramètres URL ou appelle directement une route PDF/API.

## Validation

Vérifier le rendu, les droits côté serveur, les réponses HTTP, les téléchargements et les tests de non-régression. Ne pas considérer un PDF généré comme preuve que ses données sont correctement filtrées.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 10 — Notifications et journal d'audit

# LOOP SCHEDULE-10 — Notifications, événements et audit

Audite les événements et notifications associés aux créations, modifications, publications, annulations, déplacements et imports.

## Cas

- Création d'une séance.
- Modification d'une séance.
- Déplacement.
- Publication.
- Annulation.
- Restauration.
- Import réussi.
- Import partiellement réussi.
- Import échoué.
- Notification en double.
- Notification non envoyée.
- Queue indisponible.
- Échec après commit.
- Échec avant commit.
- Utilisateur sans destinataire.
- Destinataire appartenant à une autre faculté.
- Données sensibles dans les messages ou logs.
- Opération répétée.

## Garanties

- Les notifications doivent correspondre aux opérations effectivement validées.
- Une transaction annulée ne doit pas produire de notification trompeuse.
- Les retries ne doivent pas créer des doublons incontrôlés.
- Les erreurs de notification ne doivent pas corrompre l'emploi du temps.
- Les journaux doivent conserver suffisamment de contexte pour l'audit sans exposer de secrets.
- Les changements critiques doivent être attribuables à un acteur.
- Les données historiques ne doivent pas être écrasées sans trace.

## Travail

Inspecter les listeners, events, notifications, queues, jobs, journaux et services. Vérifier l'ordre des opérations et les stratégies de retry. Ajouter des tests pour les échecs de queue et les opérations répétées.

## Livrable

Une matrice événement → destinataires → données envoyées → stratégie de retry → preuve de test.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 11 — Performance, charge et fiabilité

# LOOP SCHEDULE-11 — Performance et résilience

## Objectif

Évaluer le module sur un volume représentatif sans détériorer la qualité des données ni la sécurité.

## Scénarios de charge

- Quelques dizaines de séances.
- Plusieurs milliers de séances.
- Grand nombre de groupes.
- Grand nombre d'enseignants.
- Nombre important de salles.
- Recherche sur une longue période.
- Import volumineux.
- Export PDF volumineux.
- Plusieurs utilisateurs simultanés.
- Déplacements concurrents.
- Multiples calculs de disponibilité.
- Queue saturée.
- Cache indisponible.

Adapter les volumes aux ressources et aux capacités réelles de l'environnement. Documenter les limites du matériel utilisé.

## Mesures

- Durée de réponse p50, p95 et p99 lorsque la charge permet de les calculer.
- Nombre de requêtes SQL.
- Temps SQL.
- Nombre de requêtes N+1.
- Mémoire maximale.
- Temps de génération PDF.
- Débit d'import.
- Temps de calcul des conflits.
- Temps d'attente des jobs.
- Nombre d'erreurs et de timeouts.

## Résilience

Tester les erreurs de base de données, les timeouts, les retries, les transactions annulées, les verrous et les traitements interrompus dans un environnement de test.

## Règles

- Mesurer avant de modifier.
- Optimiser uniquement les goulots d'étranglement démontrés.
- Préserver la lisibilité du code.
- Ne pas supprimer de validations pour gagner du temps.
- Ne pas utiliser un cache qui permet de contourner une vérification de conflit.
- Ne pas confondre un benchmark local avec une garantie de production.

## Livrable

Rapport comparatif avant/après, commandes exécutées, volumes testés, environnement, limites et risques résiduels.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 12 — Audit de la base de données et concurrence

# LOOP SCHEDULE-12 — Intégrité SQL et concurrence

Audite le schéma, les relations et les opérations concurrentes du module Emplois du temps.

## Cas

- Clés étrangères manquantes.
- Références orphelines.
- Index manquants ou inutiles.
- Contraintes uniques absentes.
- Doublons historiques.
- Valeurs nulles inattendues.
- Suppression en cascade non souhaitée.
- Incompatibilité entre migrations et schéma actuel.
- Transactions imbriquées.
- Deadlocks.
- Timeouts.
- Deux utilisateurs modifiant la même séance.
- Deux utilisateurs réservant la même salle.
- Import et édition simultanés.
- Simulation suivie d'une confirmation tardive.
- Échec pendant une opération multi-table.

## Travail

- Comparer migrations, modèles Eloquent et schéma réel.
- Vérifier les contraintes et index réellement pris en charge par le SGBD.
- Contrôler les transactions et l'isolation.
- Identifier les courses entre vérification et écriture.
- Tester les opérations concurrentes de manière reproductible.
- Ajouter les contraintes SQL lorsque c'est approprié et compatible avec les règles métier.
- Traiter les données historiques avant d'ajouter une contrainte qui les rendrait invalides.

## Précautions

Ne pas réinitialiser la base. Ne pas exécuter de migration destructive en production. Pour les modifications de schéma, préparer une migration compatible, une stratégie de retour arrière et une validation sur une base de test représentative.

## Livrable

Un rapport d'intégrité, les risques de concurrence, les migrations proposées et les preuves de test.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 13 — API, validation et contrats

# LOOP SCHEDULE-13 — Contrats API et cohérence des réponses

Audite toutes les routes API liées aux emplois du temps, créneaux, explorateurs, imports et déplacements.

## Cas

- Requête valide.
- JSON malformé.
- Champs absents.
- Types incorrects.
- Identifiants inconnus.
- Identifiants non autorisés.
- Dates invalides.
- Conflit métier.
- Erreur de base de données.
- Trop de requêtes.
- Pagination invalide.
- Tri non autorisé.
- Requête volumineuse.
- Répétition de la même requête.
- Mise à jour partielle.
- Erreur inattendue.
- Utilisateur non authentifié.
- Permission révoquée.
- Ressource appartenant à une autre faculté.

## Exigences

- Respecter les conventions de réponses déjà adoptées.
- Utiliser les Form Requests ou les mécanismes de validation établis.
- Retourner des erreurs compréhensibles et stables.
- Ne pas exposer les traces d'exception, chemins internes ou secrets.
- Vérifier les autorisations au niveau de la ressource.
- Maintenir la compatibilité des consommateurs existants.
- Ajouter ou mettre à jour les tests de contrat.

## Livrable

Un inventaire des routes, méthodes, permissions, validations, codes de réponse, formats JSON et tests associés.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 14 — Tests end-to-end et non-régression

# LOOP SCHEDULE-14 — Tests E2E et validation des parcours complets

Construis ou complète une suite de tests couvrant les parcours complets du module Emplois du temps.

## Parcours

1. Un administrateur autorisé crée un emploi du temps.
2. Il ajoute une séance.
3. Il affecte un enseignant, un groupe et une salle.
4. Le système vérifie les conflits et la capacité.
5. L'administrateur publie l'emploi du temps.
6. L'étudiant autorisé consulte son emploi du temps.
7. L'enseignant consulte ses propres séances.
8. L'administrateur déplace une séance.
9. Les conflits sont recalculés.
10. Les notifications pertinentes sont produites.
11. Les portails reflètent le nouvel état.
12. Un export PDF est généré avec les bonnes données.
13. Un import est prévisualisé, confirmé et rapporté.
14. Un utilisateur non autorisé tente les mêmes opérations et se voit refuser l'accès.

## Scénarios négatifs

- Séance en conflit.
- Salle trop petite.
- Ressource inactive.
- Faculté incorrecte.
- Session expirée.
- Double soumission.
- Import partiellement invalide.
- Échec d'une notification.
- Modification concurrente.
- Export d'une ressource interdite.

## Méthode

- Utiliser les outils de test déjà installés.
- Isoler les données et nettoyer uniquement les données créées par les tests.
- Éviter les tests dépendants d'un ordre d'exécution.
- Préférer des assertions sur les résultats métier, la base et les permissions.
- Utiliser un vrai navigateur uniquement si l'infrastructure E2E du projet le permet.
- Distinguer tests unitaires, tests HTTP, tests d'intégration et tests navigateur.

## Validation

Exécuter les tests ciblés, les suites liées et les tests globaux appropriés. Fournir le nombre de tests passés, échoués, ignorés et non exécutés.

Boucle obligatoire : LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

# Prompt 15 — Auto-passage global et rapport final

# LOOP SCHEDULE-15 — Orchestrateur de finalisation automatique

## Mission

Utiliser les rapports et registres des boucles SCHEDULE précédentes pour continuer le travail jusqu'à ce que tous les cas applicables soient validés ou explicitement bloqués.

## Ordre de traitement

1. P0 — Sécurité, contrôle d'accès, intégrité et perte de données.
2. P1 — Conflits, concurrence, imports, transactions et fiabilité.
3. P2 — Fonctionnalités, API, portails, exports et notifications.
4. P3 — Performance, ergonomie, accessibilité et optimisations secondaires.

## Boucle pour chaque cas

LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION → AUTO-PASSAGE.

### Conditions de passage

- `PASS` : preuve vérifiée et tests réussis.
- `PASS_WITH_WARNINGS` : fonctionnalité validée avec réserves documentées.
- `BLOCKED` : dépendance, environnement ou décision humaine manquante.
- `FAIL` : défaut encore présent.
- `NOT_EXECUTED` : aucune preuve d'exécution.
- `NOT_APPLICABLE` : fonctionnalité réellement absente ou hors périmètre, avec justification.

## Auto-passage

- Traiter les cas indépendants dans un ordre déterministe.
- Après correction, exécuter les tests ciblés.
- Après un lot cohérent, exécuter les tests de régression associés.
- Si un test échoue, analyser la cause avant toute nouvelle modification.
- Après trois tentatives infructueuses sur le même défaut, arrêter les modifications automatiques de ce défaut, documenter le blocage et poursuivre les cas indépendants.
- Ne jamais marquer automatiquement un cas comme réussi sans preuve.
- Ne jamais supprimer ou affaiblir un test pour faire passer la suite.
- Ne jamais effectuer de commit, déploiement, suppression massive ou migration destructive sans autorisation.
- Demander une décision humaine lorsqu'un changement modifie une règle métier, une permission, un contrat API ou une structure de données sensible.

## Contrôle final

- Aucun échec P0 connu.
- Aucun contournement de permission.
- Aucun test désactivé pour masquer un défaut.
- Aucune migration destructive non autorisée.
- Routes et permissions vérifiées.
- Tests ciblés et suites de régression exécutés.
- Pint exécuté sur les fichiers modifiés.
- Mesures de performance accompagnées de leur contexte.
- Les cas non exécutés et les blocages restent visibles.

## Rapport final

Mettre à jour :

- `docs/engineering/schedule-audit-report.md`
- `docs/engineering/schedule-audit-cases.md`
- `docs/engineering/schedule-audit-progress.md`

Le rapport doit contenir :

1. Résumé exécutif.
2. État initial et état final.
3. Nombre de cas PASS, PASS_WITH_WARNINGS, FAIL, BLOCKED, NOT_EXECUTED et NOT_APPLICABLE.
4. Vulnérabilités découvertes et corrigées.
5. Corrections fonctionnelles.
6. Résultats des tests.
7. Performances avant/après avec environnement et volumes.
8. Fichiers modifiés.
9. Migrations et risques résiduels.
10. Liste des décisions humaines encore nécessaires.

## Verdict

- `VALIDÉ POUR PRÉPRODUCTION` si les exigences critiques sont vérifiées et les tests pertinents réussis.
- `VALIDÉ AVEC RÉSERVES` si des limites non critiques restent documentées.
- `NON VALIDÉ` si un défaut critique, une régression importante ou une validation essentielle reste en échec.

Ne pas confondre validation des tests locaux et validation réelle en production. Fournir les preuves et limites du verdict final.

## Comment les exécuter efficacement

Je recommande de les lancer dans cet ordre :

Phase 1 — Comprendre avant de modifier

Prompts 1 à 4 : cartographie, sécurité, cycle de vie et horaires.

Phase 2 — Fiabiliser les règles métier

Prompts 5 à 8 : conflits, déplacements, explorateurs et imports.

Phase 3 — Durcir et optimiser

Prompts 9 à 13 : portails, audit, performance, SQL et API.

Phase 4 — Valider l'ensemble

Prompts 14 et 15 : parcours E2E, non-régression et rapport final.

Conseil important : commence par le prompt 1 et demande à ton agent de conserver un registre de progression persistant. Ensuite, fais-lui traiter les autres prompts en reprenant ce registre. Cela évite de réauditer inutilement les mêmes fichiers et permet de distinguer clairement les cas corrigés, validés, bloqués et non testés.