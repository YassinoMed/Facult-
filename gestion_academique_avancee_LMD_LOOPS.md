# Gestion académique avancée LMD

## Catalogue complet des fonctions et LOOP MATURE détaillés

**Projet cible :** plateforme universitaire Laravel 13 / PHP 8.4+ /
MySQL / Blade / Tailwind CSS / Alpine.js / PHPUnit\
**Principe directeur :** VALIDITÉ ACADÉMIQUE \> CONTRAINTES MÉTIER \>
SÉCURITÉ \> FACULTY SCOPE \> INTÉGRITÉ DES DONNÉES \> PERFORMANCE \> UX

------------------------------------------------------------------------

# 1. Objectif général

Ce document définit les fonctions complémentaires d'une plateforme
universitaire LMD avancée, inspirée des besoins d'un système de gestion
académique de type SALIMA mais allant plus loin sur la simulation,
l'analyse, l'automatisation, la traçabilité, la sécurité et l'aide à la
décision.

Le système doit conserver une séparation stricte entre :

-   données officielles ;
-   simulation ;
-   proposition ;
-   correction administrative ;
-   décision officielle ;
-   délibération.

Une simulation ne doit jamais modifier directement une donnée académique
officielle.

Le projet doit réutiliser les services, modèles, politiques, événements,
notifications, audit, calculs, GED, DataExchange, recherche et
composants UI déjà présents.

------------------------------------------------------------------------

# 2. Les fonctions du module

## Fonction 1 --- Planification automatique des parcours étudiants

### Objectif

Analyser le parcours d'un étudiant et proposer automatiquement les
chemins académiques possibles.

### Données utilisées

-   identité académique ;
-   année universitaire ;
-   établissement ;
-   faculté ;
-   formation ;
-   programme ;
-   spécialité ;
-   parcours ;
-   niveau ;
-   semestre ;
-   inscriptions ;
-   résultats ;
-   crédits ;
-   UE ;
-   ECUE ;
-   dettes ;
-   décisions de délibération ;
-   équivalences ;
-   dispenses ;
-   réorientations ;
-   passerelles.

### LOOP MATURE

**1. Audit**

Identifier les modèles et services existants liés aux étudiants,
inscriptions, formations, résultats, crédits et progression.

**2. Modélisation**

Définir un modèle de parcours composé d'étapes académiques et de
conditions.

**3. Implémentation**

Créer un service central de planification académique.

**4. Intégration**

Connecter le service au référentiel LMD et au moteur de calcul
académique.

**5. Simulation**

Produire un ou plusieurs parcours possibles sans modifier le dossier
officiel.

**6. Tests**

Tester les parcours normaux, les dettes, les redoublements et les
réorientations.

**7. Sécurité**

Appliquer Authorization et Faculty Scope.

**8. Performance**

Éviter les requêtes N+1 et optimiser les parcours complexes.

**9. Audit**

Tracer chaque simulation importante.

**10. Documentation**

Documenter les règles et les scénarios.

**11. Validation**

Valider les résultats avec les règles académiques.

**12. Maturity**

Mesurer la fiabilité, la performance et la couverture des tests.

------------------------------------------------------------------------

# 3. Fonction 2 --- Simulation de progression L1 → L2 → L3 → M1 → M2

### Objectif

Déterminer les trajectoires possibles à partir des résultats officiels.

### Règles essentielles

Ne jamais faire simplement :

`niveau actuel + 1`

La progression doit dépendre des crédits, décisions, règles de
validation et décisions d'admission.

### LOOP MATURE

1.  Audit du moteur de progression existant.
2.  Identification des règles LMD déjà présentes.
3.  Centralisation des règles manquantes.
4.  Calcul des résultats actuels.
5.  Analyse des conditions de passage.
6.  Génération des scénarios possibles.
7.  Explication de chaque scénario.
8.  Test des cas L1, L2, L3, M1 et M2.
9.  Test des redoublements.
10. Test des admissions Master.
11. Test des réorientations.
12. Validation et documentation.

### Cas obligatoires

-   L1 validé → L2 possible.
-   L2 validé → L3 possible.
-   L3 → M1 uniquement avec conditions d'admission.
-   M1 → M2 selon règles.
-   Échec → redoublement ou autre décision selon règles.
-   Réorientation → conservation de l'historique.

------------------------------------------------------------------------

# 4. Fonction 3 --- Détection automatique des étudiants à risque

### Objectif

Identifier les étudiants susceptibles de rencontrer une difficulté
académique.

### Indicateurs

-   faibles moyennes ;
-   accumulation de dettes ;
-   crédits insuffisants ;
-   semestres non validés ;
-   échecs répétés ;
-   progression lente ;
-   retard académique ;
-   absence de validation d'UE importantes.

### LOOP MATURE

1.  Audit des résultats et historiques.
2.  Définition des indicateurs.
3.  Définition des niveaux de risque.
4.  Création du moteur de scoring.
5.  Calcul du risque.
6.  Génération d'une explication.
7.  Affichage dans le tableau de bord.
8.  Tests sur différents profils.
9.  Tests de faux positifs.
10. Protection des données.
11. Audit des changements de règles.
12. Validation académique.

### Principe

Le risque est une information d'aide à la décision et non une sanction
automatique.

------------------------------------------------------------------------

# 5. Fonction 4 --- Simulation de validation des semestres

### Objectif

Simuler le résultat d'un semestre avant décision officielle.

### Résultats

-   moyenne ;
-   UE validées ;
-   UE non validées ;
-   ECUE validées ;
-   crédits obtenus ;
-   compensation ;
-   résultat possible.

### LOOP MATURE

1.  Audit du moteur de calcul existant.
2.  Réutilisation du calcul académique central.
3.  Chargement des résultats.
4.  Application des règles.
5.  Calcul de la simulation.
6.  Génération du détail explicatif.
7.  Aucun changement des notes officielles.
8.  Tests unitaires.
9.  Tests fonctionnels.
10. Tests de sécurité.
11. Audit.
12. Validation.

------------------------------------------------------------------------

# 6. Fonction 5 --- Simulation de compensation

### Objectif

Déterminer si une compensation est théoriquement possible.

### LOOP MATURE

1.  Audit des règles de compensation.
2.  Identification des règles applicables.
3.  Chargement des résultats.
4.  Calcul avant compensation.
5.  Application simulée de la compensation.
6.  Calcul après compensation.
7.  Présentation de l'impact.
8.  Aucun changement officiel.
9.  Tests limites.
10. Tests de sécurité.
11. Audit.
12. Validation.

### Le résultat doit afficher

-   règle appliquée ;
-   moyenne initiale ;
-   éléments concernés ;
-   moyenne après simulation ;
-   crédits ;
-   décision possible.

------------------------------------------------------------------------

# 7. Fonction 6 --- Simulation et gestion des crédits ECTS

### Objectif

Centraliser la lecture des crédits acquis et restants.

### Informations

-   crédits par ECUE ;
-   crédits par UE ;
-   crédits par semestre ;
-   crédits par année ;
-   crédits obtenus ;
-   crédits manquants ;
-   crédits liés aux dettes.

### LOOP MATURE

1.  Audit des sources de crédits.
2.  Identification de la source de vérité.
3.  Suppression des doubles calculs.
4.  Centralisation de la lecture.
5.  Calcul des crédits.
6.  Tests de cohérence.
7.  Tests de double comptage.
8.  Tests de changement de parcours.
9.  Audit.
10. Performance.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 8. Fonction 7 --- Gestion des équivalences

### Objectif

Gérer les correspondances entre éléments académiques.

### Une équivalence doit conserver

-   élément d'origine ;
-   élément correspondant ;
-   formation ;
-   contexte ;
-   décision ;
-   justification ;
-   responsable ;
-   date ;
-   statut.

### LOOP MATURE

1.  Audit des mécanismes existants.
2.  Définition du modèle d'équivalence.
3.  Définition des règles d'autorisation.
4.  Création du workflow.
5.  Création de l'équivalence.
6.  Validation par responsable.
7.  Application académique.
8.  Audit.
9.  Tests IDOR.
10. Tests Faculty Scope.
11. Tests de cohérence.
12. Documentation.

------------------------------------------------------------------------

# 9. Fonction 8 --- Gestion des dispenses

### Objectif

Gérer les dispenses accordées à un étudiant.

### LOOP MATURE

1.  Audit du dossier étudiant.
2.  Définition du modèle de dispense.
3.  Définition des motifs.
4.  Définition des permissions.
5.  Création de la demande.
6.  Validation.
7.  Application sans destruction de l'historique original.
8.  Audit.
9.  Tests d'autorisation.
10. Tests de sécurité.
11. Tests de réversibilité.
12. Documentation.

------------------------------------------------------------------------

# 10. Fonction 9 --- Gestion des réorientations

### Objectif

Permettre le changement de formation, spécialité ou parcours tout en
conservant l'historique.

### LOOP MATURE

1.  Audit des formations et programmes.
2.  Audit de l'historique étudiant.
3.  Définition des règles de réorientation.
4.  Simulation de la nouvelle trajectoire.
5.  Vérification des équivalences.
6.  Validation administrative.
7.  Enregistrement de la décision.
8.  Conservation de l'ancien parcours.
9.  Mise à jour du parcours courant.
10. Audit.
11. Tests IDOR et Faculty Scope.
12. Documentation.

------------------------------------------------------------------------

# 11. Fonction 10 --- Gestion des passerelles Licence → Master

### Objectif

Analyser l'éligibilité d'un étudiant à une admission Master.

### Conditions possibles

-   diplôme ;
-   formation ;
-   spécialité ;
-   crédits ;
-   résultats ;
-   prérequis ;
-   décision d'admission.

### LOOP MATURE

1.  Audit des formations Licence et Master.
2.  Identification des passerelles autorisées.
3.  Modélisation des prérequis.
4.  Analyse du dossier.
5.  Simulation de l'éligibilité.
6.  Affichage des conditions manquantes.
7.  Enregistrement de l'admission uniquement après décision officielle.
8.  Audit.
9.  Tests négatifs.
10. Tests de sécurité.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 12. Fonction 11 --- Gestion des dettes académiques

### Objectif

Identifier et suivre toutes les matières ou unités non validées.

### LOOP MATURE

1.  Audit des notes et résultats.
2.  Identification des éléments non validés.
3.  Création ou réutilisation du concept de dette existant.
4.  Association au semestre d'origine.
5.  Association aux crédits.
6.  Détermination des possibilités de validation ultérieure.
7.  Mise à jour après nouveau résultat.
8.  Conservation de l'historique.
9.  Tests de recalcul.
10. Tests de changement d'année.
11. Audit.
12. Validation.

### Une dette doit conserver son origine

Une dette ne doit jamais effacer le résultat initial.

------------------------------------------------------------------------

# 13. Fonction 12 --- Historique académique complet

### Objectif

Créer une chronologie complète du parcours universitaire.

### Éléments

-   inscriptions ;
-   réinscriptions ;
-   formations ;
-   spécialités ;
-   parcours ;
-   semestres ;
-   UE ;
-   ECUE ;
-   notes ;
-   crédits ;
-   dettes ;
-   équivalences ;
-   dispenses ;
-   réorientations ;
-   décisions ;
-   délibérations ;
-   documents académiques.

### LOOP MATURE

1.  Audit des sources historiques.
2.  Identification des événements existants.
3.  Construction de la chronologie.
4.  Normalisation des événements.
5.  Association à l'étudiant.
6.  Affichage.
7.  Recherche et filtrage.
8.  Protection des données.
9.  Tests de complétude.
10. Tests d'autorisation.
11. Audit.
12. Documentation.

------------------------------------------------------------------------

# 14. Fonction 13 --- Comparaison des parcours étudiants

### Objectif

Comparer des parcours ou scénarios académiques.

### Comparaisons

-   progression ;
-   crédits ;
-   moyennes ;
-   dettes ;
-   validations ;
-   retards ;
-   réorientations ;
-   décisions.

### LOOP MATURE

1.  Audit des données disponibles.
2.  Définition des critères.
3.  Vérification des permissions.
4.  Sélection des étudiants autorisés.
5.  Normalisation des données.
6.  Calcul des différences.
7.  Présentation graphique ou tabulaire.
8.  Protection des données sensibles.
9.  Tests de confidentialité.
10. Tests de performance.
11. Audit.
12. Documentation.

------------------------------------------------------------------------

# 15. Fonction 14 --- Simulation multi-scénarios

### Objectif

Permettre à un responsable pédagogique de tester plusieurs hypothèses
sans modifier les données officielles.

### Exemples

-   validation d'une matière ;
-   validation d'une UE ;
-   compensation ;
-   équivalence ;
-   dispense ;
-   réorientation ;
-   dette validée ;
-   admission Master.

### LOOP MATURE

1.  Création d'un contexte de simulation.
2.  Copie logique des données nécessaires.
3.  Application des hypothèses.
4.  Recalcul.
5.  Comparaison avec la situation réelle.
6.  Génération des conséquences.
7.  Affichage des différences.
8.  Suppression ou expiration de la simulation.
9.  Tests d'isolation.
10. Tests de concurrence.
11. Audit.
12. Validation.

------------------------------------------------------------------------

# 16. Fonction 15 --- Moteur de règles académiques configurable

### Objectif

Éviter de placer les règles LMD directement dans les contrôleurs.

### Le moteur doit gérer

-   validation ;
-   compensation ;
-   crédits ;
-   progression ;
-   dettes ;
-   passerelles ;
-   équivalences ;
-   dispenses ;
-   réorientations.

### LOOP MATURE

1.  Audit des règles existantes.
2.  Identification des règles codées en dur.
3.  Centralisation.
4.  Modélisation des règles.
5.  Versionnement.
6.  Application.
7.  Explication du résultat.
8.  Tests par version.
9.  Audit.
10. Performance.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 17. Fonction 16 --- Explicabilité académique

### Objectif

Chaque résultat important doit être compréhensible.

### Exemple

Le système doit pouvoir expliquer :

-   pourquoi l'étudiant peut progresser ;
-   pourquoi il ne peut pas progresser ;
-   pourquoi une compensation est possible ;
-   pourquoi une passerelle est refusée ;
-   pourquoi une dette existe ;
-   pourquoi un étudiant est classé à risque.

### LOOP MATURE

1.  Identifier les décisions calculées.
2.  Capturer les règles utilisées.
3.  Capturer les données déterminantes.
4.  Construire une explication.
5.  Afficher les conditions satisfaites.
6.  Afficher les conditions manquantes.
7.  Tester la cohérence entre calcul et explication.
8.  Auditer.
9.  Documenter.
10. Valider.

------------------------------------------------------------------------

# 18. Fonction 17 --- Tableau de bord académique avancé

### Objectif

Fournir une vision globale aux responsables pédagogiques.

### Indicateurs

-   nombre d'étudiants ;
-   progression ;
-   taux de validation ;
-   crédits moyens ;
-   dettes ;
-   étudiants à risque ;
-   redoublements ;
-   réorientations ;
-   passerelles ;
-   validations par semestre.

### LOOP MATURE

1.  Audit des données disponibles.
2.  Définition des KPI.
3.  Vérification des sources de vérité.
4.  Création des agrégations.
5.  Création du tableau de bord.
6.  Ajout des filtres.
7.  Ajout du Faculty Scope.
8.  Optimisation des requêtes.
9.  Tests.
10. Audit.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 19. Fonction 18 --- Planification académique prospective

### Objectif

Permettre de simuler l'évolution d'une population étudiante.

### Exemples

-   nombre d'étudiants susceptibles de passer ;
-   nombre de redoublants ;
-   nombre de dettes ;
-   besoins futurs en groupes ;
-   besoins en enseignants ;
-   besoins en salles ;
-   impact d'une nouvelle règle académique.

### LOOP MATURE

1.  Collecte des données officielles.
2.  Construction des scénarios.
3.  Simulation.
4.  Calcul des populations.
5.  Comparaison des scénarios.
6.  Analyse des besoins.
7.  Export des résultats.
8.  Tests de cohérence.
9.  Audit.
10. Performance.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 20. Fonction 19 --- Assistant académique intelligent

### Objectif

Ajouter une couche d'assistance capable d'expliquer les résultats
académiques.

### Principes

L'assistant ne doit pas devenir la source de vérité.

La source de vérité reste :

-   référentiel académique ;
-   résultats ;
-   décisions ;
-   délibérations ;
-   règles officielles.

L'assistant peut :

-   expliquer ;
-   rechercher ;
-   résumer ;
-   proposer ;
-   comparer ;
-   détecter des incohérences.

Il ne doit pas prendre seul une décision officielle.

### LOOP MATURE

1.  Audit des données accessibles.
2.  Définition des cas d'utilisation.
3.  Définition des permissions.
4.  Connexion aux services métier existants.
5.  Génération des réponses.
6.  Vérification des sources.
7.  Protection des données sensibles.
8.  Tests de sécurité.
9.  Tests contre les hallucinations.
10. Audit.
11. Documentation.
12. Validation humaine.

------------------------------------------------------------------------

# 21. Fonction 20 --- Détection des anomalies académiques

### Objectif

Détecter les incohérences dans les données.

### Exemples

-   crédits impossibles ;
-   notes incohérentes ;
-   étudiant affecté à une formation incompatible ;
-   progression impossible ;
-   doublon de résultat ;
-   dette incohérente ;
-   décision incompatible avec les résultats ;
-   modification suspecte.

### LOOP MATURE

1.  Audit des règles de cohérence.
2.  Définition des anomalies.
3.  Création du moteur de détection.
4.  Analyse des données.
5.  Classification des anomalies.
6.  Explication.
7.  Création d'une file de résolution.
8.  Correction administrative contrôlée.
9.  Audit.
10. Tests.
11. Performance.
12. Validation.

------------------------------------------------------------------------

# 22. Fonction 21 --- Gestion documentaire académique automatique

### Objectif

Produire automatiquement les documents académiques à partir des données
officielles.

### Documents

-   relevé de notes ;
-   attestation ;
-   certificat ;
-   décision ;
-   historique académique ;
-   documents de scolarité.

### LOOP MATURE

1.  Audit du moteur PDF/GED existant.
2.  Réutilisation de la GED.
3.  Définition des modèles.
4.  Récupération des données officielles.
5.  Création d'un snapshot des résultats.
6.  Génération.
7.  Hash du document.
8.  Versionnement.
9.  Publication.
10. Sécurisation du téléchargement.
11. Audit.
12. Tests.

------------------------------------------------------------------------

# 23. Fonction 22 --- Génération automatique des relevés de notes

### Objectif

Générer automatiquement les relevés de notes après validation
officielle.

### Types

-   relevé semestriel ;
-   relevé annuel ;
-   relevé complet ;
-   relevé provisoire ;
-   relevé officiel.

### Contenu

-   établissement ;
-   formation ;
-   étudiant ;
-   semestre ;
-   UE ;
-   ECUE ;
-   coefficients ;
-   notes ;
-   crédits ;
-   résultats ;
-   décision ;
-   session.

### LOOP MATURE

1.  Audit du système documentaire.
2.  Réutilisation de la GED.
3.  Connexion aux résultats officiels.
4.  Attente de la délibération officielle.
5.  Création du snapshot.
6.  Génération du document.
7.  Attribution d'une version.
8.  Calcul d'un hash.
9.  Publication sécurisée.
10. Notification.
11. Tests.
12. Audit et validation.

### Correction officielle

Lorsqu'une décision officielle est corrigée :

-   ancien relevé devient obsolète ;
-   nouvelle version est générée ;
-   historique conservé ;
-   audit obligatoire.

------------------------------------------------------------------------

# 24. Fonction 23 --- Import multi-format des notes

### Objectif

Importer les notes depuis plusieurs formats.

### Formats

-   CSV ;
-   XLSX ;
-   XML ;
-   JSON.

### Pipeline

Fichier → détection → lecture → normalisation → mapping → validation →
identification étudiant → association académique → prévisualisation →
validation → import transactionnel → rapport.

### LOOP MATURE

1.  Audit du DataExchange existant.
2.  Réutilisation des mécanismes d'import.
3.  Détection du format.
4.  Lecture.
5.  Mapping des colonnes.
6.  Normalisation.
7.  Validation.
8.  Identification de l'étudiant.
9.  Association avec formation, semestre, UE et ECUE.
10. Prévisualisation.
11. Confirmation transactionnelle.
12. Rapport et audit.

------------------------------------------------------------------------

# 25. Fonction 24 --- Association automatique des notes aux étudiants

### Objectif

Associer automatiquement une ligne de notes au bon étudiant.

### Identifiants possibles

-   CIN ;
-   matricule ;
-   identifiant universitaire ;
-   autre identifiant académique configuré.

### Règle

L'identification doit être déterministe autant que possible.

Une correspondance ambiguë doit être placée en vérification manuelle.

### LOOP MATURE

1.  Normalisation de l'identifiant.
2.  Recherche de l'étudiant.
3.  Vérification de l'année.
4.  Vérification de la formation.
5.  Vérification du semestre.
6.  Vérification de l'élément pédagogique.
7.  Association.
8.  Détection des ambiguïtés.
9.  Prévisualisation.
10. Validation.
11. Audit.
12. Rapport d'erreurs.

------------------------------------------------------------------------

# 26. Fonction 25 --- Import sécurisé des notes

### Objectif

Garantir qu'un fichier incorrect ne puisse pas corrompre les données
officielles.

### LOOP MATURE

1.  Vérification du fichier.
2.  Vérification du format.
3.  Vérification des colonnes.
4.  Validation des types.
5.  Validation des valeurs.
6.  Vérification des étudiants.
7.  Vérification des UE et ECUE.
8.  Détection des doublons.
9.  Prévisualisation obligatoire.
10. Transaction.
11. Rollback complet en cas d'erreur critique.
12. Audit.

------------------------------------------------------------------------

# 27. Fonction 26 --- Centre de résolution des anomalies académiques

### Objectif

Fournir aux administrateurs un centre unique pour traiter les anomalies.

### Workflow

BLOCAGE → EXPLICATION → IDENTIFICATION DE LA CAUSE → CORRECTION → AUDIT
→ RE-VÉRIFICATION → CONFORME ou NOUVEAU BLOCAGE

### LOOP MATURE

1.  Audit des systèmes de vérification existants.
2.  Agrégation des anomalies.
3.  Classification.
4.  Affichage de la cause.
5.  Proposition d'action.
6.  Correction contrôlée.
7.  Motif obligatoire.
8.  Audit avant/après.
9.  Re-vérification automatique.
10. Résolution ou nouveau blocage.
11. Tests IDOR et Faculty Scope.
12. Documentation.

------------------------------------------------------------------------

# 28. Fonction 27 --- Notifications académiques

### Objectif

Informer les étudiants et administrateurs des événements importants.

### Événements

-   résultat publié ;
-   semestre validé ;
-   dette détectée ;
-   document généré ;
-   décision publiée ;
-   inscription ouverte ;
-   inscription fermée ;
-   correction académique ;
-   relevé disponible.

### LOOP MATURE

1.  Audit du système de notifications.
2.  Réutilisation des notifications centrales.
3.  Définition des événements.
4.  Création des templates.
5.  Dispatch.
6.  Prévention des doublons.
7.  Tests.
8.  Audit.
9.  Performance.
10. Documentation.
11. Validation.
12. Suivi des erreurs.

------------------------------------------------------------------------

# 29. Fonction 28 --- API académique

### Objectif

Exposer les fonctionnalités académiques à d'autres applications
autorisées.

### API

-   étudiants ;
-   parcours ;
-   inscriptions ;
-   résultats ;
-   crédits ;
-   progression ;
-   documents ;
-   décisions ;
-   simulations.

### LOOP MATURE

1.  Audit de l'API v1 existante.
2.  Réutilisation des ressources existantes.
3.  Définition des endpoints nécessaires.
4.  Authorization.
5.  Faculty Scope.
6.  Validation.
7.  Rate limiting.
8.  Journalisation.
9.  Tests API.
10. Tests BOLA/IDOR.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 30. Fonction 29 --- Recherche académique globale

### Objectif

Permettre de rechercher rapidement dans les données autorisées.

### Recherche

-   étudiant ;
-   matricule ;
-   CIN ;
-   formation ;
-   groupe ;
-   semestre ;
-   UE ;
-   ECUE ;
-   document ;
-   décision.

### LOOP MATURE

1.  Audit du moteur Search existant.
2.  Définition des index.
3.  Définition des permissions.
4.  Indexation.
5.  Recherche.
6.  Filtrage Faculty Scope.
7.  Protection des données sensibles.
8.  Tests.
9.  Performance.
10. Audit.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 31. Fonction 30 --- Audit académique avancé

### Objectif

Conserver une traçabilité complète des opérations sensibles.

### Événements

-   modification de note ;
-   import ;
-   correction ;
-   décision ;
-   équivalence ;
-   dispense ;
-   réorientation ;
-   délibération ;
-   génération documentaire ;
-   téléchargement sensible.

### LOOP MATURE

1.  Audit du système AuditService.
2.  Identification des événements manquants.
3.  Normalisation.
4.  Ajout des événements.
5.  Capture du contexte.
6.  Protection des logs.
7.  Recherche.
8.  Tests.
9.  Contrôle d'intégrité.
10. Performance.
11. Documentation.
12. Validation.

------------------------------------------------------------------------

# 32. Fonction 31 --- Sécurité académique avancée

### Objectif

Empêcher les accès et modifications non autorisés.

### Chaîne obligatoire

Authentication → Authorization → Faculty Scope → Validation → Business
Rules → Transaction → Audit → Notification

### LOOP MATURE

1.  Audit des routes.
2.  Audit des policies.
3.  Audit du Faculty Scope.
4.  Recherche des IDOR.
5.  Vérification des contrôleurs.
6.  Vérification des services.
7.  Vérification des transactions.
8.  Tests d'accès interdits.
9.  Tests de concurrence.
10. Tests de données sensibles.
11. Correction.
12. Rapport de sécurité.

------------------------------------------------------------------------

# 33. Fonction 32 --- Gestion des versions des règles académiques

### Objectif

Conserver la règle utilisée au moment d'une décision.

### LOOP MATURE

1.  Identifier les règles susceptibles de changer.
2.  Versionner les règles.
3.  Associer une version aux calculs.
4.  Associer une version aux décisions.
5.  Conserver les anciennes versions.
6.  Tester plusieurs années universitaires.
7.  Vérifier la reproductibilité.
8.  Auditer.
9.  Documenter.
10. Valider.

------------------------------------------------------------------------

# 34. Fonction 33 --- Simulation institutionnelle

### Objectif

Permettre à l'administration de simuler l'impact d'une modification
académique.

### Exemples

-   modification d'une règle de validation ;
-   modification d'un seuil ;
-   modification d'un crédit ;
-   modification d'une passerelle ;
-   changement de capacité.

### LOOP MATURE

1.  Copier logiquement le contexte.
2.  Appliquer la nouvelle règle uniquement au scénario.
3.  Recalculer.
4.  Comparer avec la situation actuelle.
5.  Mesurer les impacts.
6.  Afficher les différences.
7.  Ne jamais modifier les données officielles.
8.  Exporter le rapport.
9.  Auditer.
10. Valider.

------------------------------------------------------------------------

# 35. Architecture transversale obligatoire

Toutes les fonctions doivent respecter les mêmes principes.

## Source de vérité

Avant de créer un nouveau modèle ou service :

1.  rechercher le modèle existant ;
2.  rechercher le service existant ;
3.  rechercher les policies ;
4.  rechercher les événements ;
5.  rechercher les migrations ;
6.  rechercher les routes ;
7.  rechercher les tests ;
8.  réutiliser ce qui existe.

## Sécurité

Toute opération sensible suit :

Authentication → Authorization → Faculty Scope → Validation → Business
Rules → Transaction → Audit → Notification

## Données sensibles

Les données sensibles ne doivent pas être exposées par :

-   recherche standard ;
-   timeline standard ;
-   export standard ;
-   API standard.

Elles nécessitent les permissions appropriées.

## IDOR

Tester systématiquement les accès par identifiant.

Un utilisateur ne doit pas pouvoir remplacer un identifiant par celui
d'un autre étudiant pour accéder à ses données.

## Faculty Scope

Un utilisateur d'une faculté ne doit pas pouvoir consulter ou modifier
les données d'une autre faculté.

Lorsque le projet applique la règle 404 avant 403 pour les ressources
hors périmètre, conserver cette convention.

------------------------------------------------------------------------

# 36. LOOP MATURE global du projet

Chaque fonctionnalité doit suivre ce cycle.

## LOOP 1 --- Audit

Comprendre l'existant avant toute modification.

## LOOP 2 --- Cartographie

Identifier modèles, services, contrôleurs, routes, vues, migrations,
jobs, événements, policies et tests.

## LOOP 3 --- Détection de duplication

Ne pas créer une deuxième implémentation d'une fonction existante.

## LOOP 4 --- Modélisation

Définir les données, relations, contraintes et règles.

## LOOP 5 --- Implémentation

Développer le minimum nécessaire.

## LOOP 6 --- Intégration

Connecter les nouveaux composants aux modules existants.

## LOOP 7 --- Tests fonctionnels

Tester les scénarios normaux et les cas limites.

## LOOP 8 --- Tests de sécurité

Tester Authorization, Faculty Scope, IDOR, données sensibles et accès
directs.

## LOOP 9 --- Tests de concurrence

Tester les doubles validations, doubles imports, modifications
simultanées et opérations concurrentes.

## LOOP 10 --- Performance

Analyser N+1, requêtes lourdes, index et traitements asynchrones.

## LOOP 11 --- Audit

Tracer toutes les opérations sensibles.

## LOOP 12 --- Documentation

Documenter architecture, règles, workflow et décisions.

## LOOP 13 --- Non-régression

Exécuter les tests existants et vérifier que les modules précédents
restent fonctionnels.

## LOOP 14 --- Validation métier

Vérifier que le comportement correspond aux règles académiques.

## LOOP 15 --- Maturity

Mesurer :

-   couverture de tests ;
-   sécurité ;
-   performance ;
-   cohérence ;
-   explicabilité ;
-   maintenabilité ;
-   qualité documentaire.

------------------------------------------------------------------------

# 37. Ordre recommandé d'implémentation

Pour éviter une architecture incohérente, implémenter dans cet ordre :

1.  Référentiel académique.
2.  Moteur de règles.
3.  Calcul académique.
4.  Crédits ECTS.
5.  Dettes.
6.  Validation des semestres.
7.  Progression.
8.  Planification des parcours.
9.  Équivalences.
10. Dispenses.
11. Réorientations.
12. Passerelles Licence → Master.
13. Simulation multi-scénarios.
14. Détection des étudiants à risque.
15. Historique académique.
16. Comparaison des parcours.
17. Anomalies.
18. Import des notes.
19. Association automatique.
20. Centre de résolution.
21. Génération des relevés.
22. Notifications.
23. API.
24. Recherche.
25. Dashboard.
26. Audit avancé.
27. Sécurité avancée.
28. Simulation institutionnelle.
29. Assistant académique.

------------------------------------------------------------------------

# 38. Tests globaux obligatoires

## Tests académiques

-   progression correcte ;
-   progression refusée ;
-   redoublement ;
-   dette ;
-   compensation ;
-   équivalence ;
-   dispense ;
-   réorientation ;
-   passerelle ;
-   crédits ;
-   historique.

## Tests d'import

-   CSV valide ;
-   XLSX valide ;
-   XML valide ;
-   JSON valide ;
-   colonne manquante ;
-   étudiant inconnu ;
-   étudiant ambigu ;
-   note invalide ;
-   doublon ;
-   mauvaise UE ;
-   mauvais semestre ;
-   rollback.

## Tests de sécurité

-   IDOR ;
-   BOLA ;
-   Faculty Scope ;
-   accès non autorisé ;
-   modification interdite ;
-   données sensibles ;
-   téléchargement interdit ;
-   API non autorisée.

## Tests de concurrence

-   double validation ;
-   double import ;
-   double génération de relevé ;
-   double correction ;
-   deux administrateurs sur le même dossier.

## Tests de performance

-   population importante ;
-   import volumineux ;
-   génération massive de documents ;
-   dashboard ;
-   recherche ;
-   calcul de progression.

------------------------------------------------------------------------

# 39. Critères de maturité finale

Le module est considéré comme mature uniquement si :

-   les règles académiques sont centralisées ;
-   les simulations sont séparées des décisions officielles ;
-   les crédits sont cohérents ;
-   les dettes sont traçables ;
-   les parcours sont explicables ;
-   les équivalences sont auditées ;
-   les dispenses sont auditées ;
-   les réorientations conservent l'historique ;
-   les passerelles respectent les règles ;
-   les étudiants à risque sont identifiés sans décision automatique ;
-   les imports sont transactionnels ;
-   l'association automatique est fiable ;
-   les relevés sont versionnés ;
-   les anomalies disposent d'un centre de résolution ;
-   les API sont sécurisées ;
-   les données sensibles sont protégées ;
-   le Faculty Scope est respecté ;
-   les IDOR sont testés ;
-   les opérations sensibles sont auditées ;
-   les tests de non-régression sont verts ;
-   les performances sont acceptables ;
-   la documentation est à jour.

------------------------------------------------------------------------

# 40. Principe final

Le système doit suivre cette philosophie :

**Le système calcule.\
Le système explique.\
Le système simule.\
Le système détecte.\
Le système propose.\
L'administration décide.\
La délibération officialise.\
L'audit trace.**

Aucune fonction intelligente ne doit contourner les règles académiques
officielles.

Aucune simulation ne doit devenir automatiquement une décision
officielle.

Aucune correction ne doit supprimer l'historique.

Aucune donnée sensible ne doit être exposée sans autorisation.

Aucune fonctionnalité nouvelle ne doit dupliquer inutilement une
fonctionnalité existante.

Le résultat attendu est une plateforme universitaire LMD robuste,
explicable, sécurisée, traçable, évolutive et capable de dépasser une
gestion académique classique par des mécanismes de simulation, d'analyse
et d'aide à la décision.
