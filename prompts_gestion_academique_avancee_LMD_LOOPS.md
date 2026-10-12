# Prompts indépendants --- Gestion académique avancée LMD

Ce fichier contient des prompts indépendants destinés à être exécutés un
par un par un agent de développement.

## Règles communes

Avant chaque implémentation : - analyser l'existant ; - rechercher les
modèles, services, contrôleurs, routes, policies, événements,
migrations, jobs et tests déjà présents ; - réutiliser les composants
existants ; - ne pas créer de doublon ; - ne pas casser les
fonctionnalités existantes.

Ordre de priorité : VALIDITÉ ACADÉMIQUE \> CONTRAINTES MÉTIER \>
SÉCURITÉ \> FACULTY SCOPE \> INTÉGRITÉ DES DONNÉES \> PERFORMANCE \> UX

Chaque prompt doit être traité comme une mission indépendante avec
audit, implémentation, tests, sécurité, performance, audit,
documentation et validation.

------------------------------------------------------------------------

## PROMPT 01 --- Planification automatique des parcours étudiants

Développe et finalise la fonctionnalité de planification automatique des
parcours étudiants dans le projet.

OBJECTIF Analyser le parcours académique réel d'un étudiant et proposer
les trajectoires compatibles avec les règles LMD.

AVANT DE CODER - Audite les modèles, services, contrôleurs, routes,
politiques, événements, tests et règles déjà présents. - Réutilise les
services existants de calcul académique, progression, référentiel,
inscriptions, délibérations, équivalences, dispenses et historique. - Ne
crée pas un deuxième moteur lorsqu'un composant équivalent existe.

FONCTIONNEMENT Le moteur doit prendre en compte la formation,
spécialité, parcours, niveau, semestre, résultats, crédits, dettes,
décisions, équivalences, dispenses, réorientations et passerelles. Il
doit proposer des trajectoires possibles et expliquer chaque
proposition. Il ne doit jamais modifier directement le dossier officiel.

LOOP MATURE 1. Audit complet de l'existant. 2. Cartographie des sources
de vérité. 3. Modélisation du parcours. 4. Implémentation du moteur
central. 5. Intégration au référentiel LMD. 6. Simulation sans mutation
des données officielles. 7. Tests unitaires et fonctionnels. 8. Tests
IDOR, Authorization et Faculty Scope. 9. Tests de concurrence. 10.
Optimisation des requêtes et prévention des N+1. 11. Audit des
opérations sensibles. 12. Documentation. 13. Tests de non-régression.
14. Validation métier. 15. Rapport final de maturité.

RÈGLE ABSOLUE Ne déduis jamais le niveau suivant par simple incrément du
niveau courant. Utilise uniquement les règles académiques, résultats,
crédits et décisions officielles disponibles.

------------------------------------------------------------------------

## PROMPT 02 --- Simulation de progression LMD

Développe une fonctionnalité complète de simulation de progression LMD.

OBJECTIF Simuler les trajectoires L1 vers L2, L2 vers L3, L3 vers M1 et
M1 vers M2 sans modifier les décisions officielles.

RÈGLES - L1 vers L2 dépend de la validation applicable. - L2 vers L3
dépend des règles applicables. - L3 vers M1 nécessite les conditions
d'admission Master. - M1 vers M2 dépend des règles applicables. - Un
redoublement doit conserver le niveau approprié. - Une réorientation
doit conserver l'historique. - Ne jamais utiliser la règle simpliste
niveau actuel + 1.

LOOP MATURE 1. Audit du moteur de progression existant. 2.
Identification des règles officielles déjà implémentées. 3.
Centralisation des règles manquantes. 4. Calcul des résultats et
crédits. 5. Simulation des scénarios. 6. Explication des conditions
satisfaites et manquantes. 7. Séparation stricte entre simulation et
décision. 8. Tests de tous les niveaux et cas limites. 9. Tests IDOR,
Faculty Scope et autorisation. 10. Tests de concurrence. 11.
Optimisation. 12. Audit. 13. Documentation. 14. Non-régression. 15.
Validation métier et rapport final.

------------------------------------------------------------------------

## PROMPT 03 --- Détection automatique des étudiants à risque

Développe un moteur de détection automatique des étudiants à risque
académique.

OBJECTIF Identifier les situations nécessitant une attention pédagogique
sans prendre automatiquement une décision contre l'étudiant.

INDICATEURS Prendre en compte les moyennes, crédits insuffisants,
dettes, semestres non validés, échecs répétés, progression lente et
retards académiques.

Le système doit produire un niveau de risque et une explication claire.

LOOP MATURE 1. Auditer les résultats et historiques existants. 2.
Définir les indicateurs. 3. Définir les niveaux de risque. 4.
Implémenter le moteur. 5. Générer une explication pour chaque risque. 6.
Intégrer le tableau de bord. 7. Tester profils normaux, limites et faux
positifs. 8. Tester confidentialité, IDOR, Faculty Scope et
autorisation. 9. Optimiser les calculs sur grandes populations. 10.
Auditer les changements de règles. 11. Documenter. 12. Exécuter la
non-régression. 13. Valider le fonctionnement métier. 14. Produire le
rapport final.

------------------------------------------------------------------------

## PROMPT 04 --- Simulation de validation des semestres

Développe une simulation complète de validation d'un semestre.

OBJECTIF Calculer le résultat possible d'un semestre sans modifier les
notes ou décisions officielles.

Le résultat doit afficher les notes, UE, ECUE, crédits, moyenne,
compensation éventuelle et décision possible.

Réutilise obligatoirement le moteur de calcul académique existant
lorsqu'il existe.

LOOP MATURE 1. Audit du calcul académique existant. 2. Identification de
la source de vérité. 3. Chargement des résultats. 4. Application des
règles. 5. Calcul de la simulation. 6. Explication détaillée. 7.
Garantie d'absence de mutation officielle. 8. Tests unitaires et
fonctionnels. 9. Tests sécurité et IDOR. 10. Tests de concurrence. 11.
Performance. 12. Audit. 13. Documentation. 14. Non-régression. 15.
Validation métier.

------------------------------------------------------------------------

## PROMPT 05 --- Simulation de compensation

Développe une fonctionnalité de simulation de compensation académique.

OBJECTIF Déterminer si une compensation serait possible selon les règles
configurées.

Le système doit afficher la situation avant compensation, les règles
appliquées, les éléments concernés, le résultat simulé et l'impact sur
les crédits.

La simulation ne doit jamais modifier les notes officielles.

LOOP MATURE 1. Audit des règles de compensation. 2. Identification du
moteur existant. 3. Modélisation des règles manquantes. 4. Calcul avant
compensation. 5. Application simulée. 6. Calcul après compensation. 7.
Explication. 8. Tests des seuils et cas limites. 9. Tests de sécurité et
Faculty Scope. 10. Tests de concurrence. 11. Performance. 12. Audit. 13.
Documentation. 14. Non-régression. 15. Validation métier.

------------------------------------------------------------------------

## PROMPT 06 --- Gestion des crédits ECTS

Développe et centralise la gestion des crédits ECTS.

OBJECTIF Fournir une vision cohérente des crédits obtenus, nécessaires
et restants par ECUE, UE, semestre et année.

RÈGLE Il doit exister une source de vérité claire. Ne crée pas plusieurs
moteurs contradictoires de calcul des crédits.

LOOP MATURE 1. Audit de toutes les sources de crédits. 2. Identification
de la source de vérité. 3. Détection des doubles calculs. 4.
Centralisation de la lecture et du calcul. 5. Intégration avec
progression et dettes. 6. Tests de cohérence. 7. Tests de double
comptage. 8. Tests après réorientation. 9. Tests IDOR et Faculty Scope.
10. Performance. 11. Audit. 12. Documentation. 13. Non-régression. 14.
Validation. 15. Rapport de maturité.

------------------------------------------------------------------------

## PROMPT 07 --- Gestion des équivalences

Développe une gestion complète des équivalences académiques.

OBJECTIF Permettre d'associer un élément académique d'origine à un
élément reconnu comme équivalent.

Une équivalence doit conserver l'origine, la destination, le contexte,
la décision, la justification, le responsable, la date et le statut.

LOOP MATURE 1. Audit des mécanismes existants. 2. Modélisation. 3.
Définition des règles d'autorisation. 4. Création du workflow. 5.
Création de la demande. 6. Vérification. 7. Validation. 8. Application
académique sans destruction de l'historique. 9. Tests IDOR et Faculty
Scope. 10. Tests de cohérence. 11. Audit. 12. Documentation. 13.
Non-régression. 14. Validation métier. 15. Rapport final.

------------------------------------------------------------------------

## PROMPT 08 --- Gestion des dispenses

Développe la gestion des dispenses académiques.

OBJECTIF Permettre d'accorder une dispense sur un élément déterminé sans
supprimer l'historique académique original.

Une dispense doit conserver l'étudiant, élément, motif, responsable,
date, période et statut.

LOOP MATURE 1. Audit de l'existant. 2. Modélisation de la dispense. 3.
Définition des motifs et statuts. 4. Définition des permissions. 5.
Création. 6. Validation administrative. 7. Application aux calculs selon
les règles. 8. Conservation de l'historique. 9. Tests d'autorisation,
IDOR et Faculty Scope. 10. Tests de réversibilité. 11. Audit. 12.
Documentation. 13. Non-régression. 14. Validation métier. 15. Rapport
final.

------------------------------------------------------------------------

## PROMPT 09 --- Gestion des réorientations

Développe la gestion complète des réorientations universitaires.

OBJECTIF Permettre le changement de formation, spécialité ou parcours en
conservant l'intégralité du passé académique.

Le système doit conserver l'ancienne trajectoire et créer une nouvelle
trajectoire à partir de la décision officielle.

LOOP MATURE 1. Audit des formations et parcours. 2. Audit de
l'historique étudiant. 3. Définition des règles. 4. Simulation de la
nouvelle trajectoire. 5. Analyse des équivalences et crédits
transférables. 6. Validation administrative. 7. Enregistrement de la
décision. 8. Conservation de l'ancien parcours. 9. Mise à jour du
parcours courant. 10. Tests IDOR et Faculty Scope. 11. Tests de
concurrence. 12. Audit. 13. Documentation. 14. Non-régression. 15.
Validation métier.

------------------------------------------------------------------------

## PROMPT 10 --- Passerelles Licence vers Master

Développe un moteur de gestion et de simulation des passerelles Licence
vers Master.

OBJECTIF Déterminer si un étudiant est éligible selon les conditions
configurées et la décision d'admission.

Vérifier formation, spécialité, diplôme, crédits, résultats, prérequis
et admission.

RÈGLE ABSOLUE Un étudiant L3 ne doit jamais être automatiquement
considéré comme admis en M1.

LOOP MATURE 1. Audit des formations Licence et Master. 2. Identification
des passerelles existantes. 3. Modélisation des prérequis. 4. Analyse du
dossier. 5. Simulation d'éligibilité. 6. Explication des conditions
manquantes. 7. Validation uniquement après décision officielle. 8. Tests
positifs et négatifs. 9. Tests IDOR, Faculty Scope et autorisation. 10.
Audit. 11. Performance. 12. Documentation. 13. Non-régression. 14.
Validation métier. 15. Rapport final.

------------------------------------------------------------------------

## PROMPT 11 --- Gestion des dettes académiques

Développe une gestion complète des dettes académiques.

OBJECTIF Identifier et suivre les éléments non validés tout en
conservant leur origine historique.

Une dette doit conserver la matière ou unité, semestre d'origine, année,
note, crédits et statut.

LOOP MATURE 1. Audit des résultats. 2. Identification des éléments non
validés. 3. Réutilisation ou amélioration du modèle existant. 4.
Association au semestre d'origine. 5. Calcul des crédits concernés. 6.
Détermination des possibilités de validation ultérieure. 7. Mise à jour
après nouveau résultat. 8. Conservation de l'historique. 9. Tests de
recalcul. 10. Tests de changement d'année. 11. Tests de sécurité. 12.
Audit. 13. Documentation. 14. Non-régression. 15. Validation.

------------------------------------------------------------------------

## PROMPT 12 --- Historique académique complet

Développe une vue complète et traçable de l'historique académique.

OBJECTIF Construire une chronologie fiable du parcours étudiant.

Inclure inscriptions, réinscriptions, formations, spécialités, parcours,
semestres, UE, ECUE, notes, crédits, dettes, équivalences, dispenses,
réorientations, décisions, délibérations et documents.

LOOP MATURE 1. Audit de toutes les sources historiques. 2.
Identification des événements existants. 3. Normalisation. 4.
Construction de la chronologie. 5. Association à l'étudiant. 6.
Recherche et filtrage. 7. Protection des données sensibles. 8. Tests de
complétude. 9. Tests IDOR et Faculty Scope. 10. Performance. 11. Audit.
12. Documentation. 13. Non-régression. 14. Validation. 15. Rapport
final.

------------------------------------------------------------------------

## PROMPT 13 --- Comparaison des parcours étudiants

Développe une fonctionnalité de comparaison de parcours étudiants.

OBJECTIF Comparer plusieurs parcours autorisés ou plusieurs scénarios
académiques.

Comparer progression, crédits, moyennes, dettes, validations, retards,
réorientations et décisions.

LOOP MATURE 1. Audit des données. 2. Définition des critères. 3.
Vérification des permissions. 4. Vérification du Faculty Scope. 5.
Normalisation des données. 6. Calcul des différences. 7. Interface
comparative. 8. Protection des données personnelles. 9. Tests de
confidentialité et IDOR. 10. Tests de performance. 11. Audit. 12.
Documentation. 13. Non-régression. 14. Validation métier. 15. Rapport
final.

------------------------------------------------------------------------

## PROMPT 14 --- Simulation multi-scénarios

Développe un moteur de simulation multi-scénarios.

OBJECTIF Permettre de tester plusieurs hypothèses académiques
indépendantes sans modifier les données officielles.

Scénarios possibles : matière validée, UE validée, compensation,
équivalence, dispense, dette validée, réorientation, admission Master.

LOOP MATURE 1. Audit des services de calcul. 2. Création d'un contexte
isolé de simulation. 3. Chargement des données nécessaires. 4.
Application des hypothèses. 5. Recalcul. 6. Comparaison avec la
situation réelle. 7. Explication des conséquences. 8. Garantie
d'isolation. 9. Tests d'isolation et concurrence. 10. Tests de sécurité.
11. Performance. 12. Audit. 13. Documentation. 14. Non-régression. 15.
Validation.

------------------------------------------------------------------------

## PROMPT 15 --- Moteur de règles académiques configurable

Développe ou consolide un moteur central de règles académiques.

OBJECTIF Éviter de placer les règles LMD dans les contrôleurs et
permettre leur évolution.

Le moteur doit pouvoir gérer validation, compensation, crédits,
progression, dettes, passerelles, équivalences, dispenses et
réorientations.

LOOP MATURE 1. Audit des règles codées dans le projet. 2. Identification
des duplications. 3. Définition d'une source de vérité. 4.
Centralisation des règles. 5. Versionnement. 6. Application aux
services. 7. Explication des résultats. 8. Tests par règle et par
version. 9. Tests de sécurité. 10. Performance. 11. Audit. 12.
Documentation. 13. Non-régression. 14. Validation métier. 15. Rapport
final.

------------------------------------------------------------------------

## PROMPT 16 --- Explicabilité académique

Développe une couche d'explicabilité pour les résultats académiques.

OBJECTIF Pour chaque résultat important, expliquer les données et règles
ayant conduit au résultat.

Expliquer notamment progression, compensation, validation, dette,
passerelle et risque.

LOOP MATURE 1. Identifier les calculs nécessitant une explication. 2.
Capturer les règles utilisées. 3. Capturer les données déterminantes. 4.
Construire une explication lisible. 5. Afficher conditions satisfaites
et manquantes. 6. Vérifier que l'explication correspond réellement au
calcul. 7. Tester les cas limites. 8. Tester les permissions. 9.
Auditer. 10. Documenter. 11. Tester la non-régression. 12. Valider avec
les règles métier.

------------------------------------------------------------------------

## PROMPT 17 --- Tableau de bord académique avancé

Développe un tableau de bord académique avancé.

OBJECTIF Donner aux responsables une vision synthétique et fiable de la
population universitaire.

KPI : nombre d'étudiants, progression, validation, crédits, dettes,
étudiants à risque, redoublements, réorientations et passerelles.

LOOP MATURE 1. Audit des sources. 2. Définition des KPI. 3.
Identification des sources de vérité. 4. Création des agrégations. 5.
Développement du dashboard. 6. Filtres par année, formation, niveau et
faculté. 7. Faculty Scope. 8. Optimisation. 9. Tests. 10. Audit. 11.
Documentation. 12. Non-régression. 13. Validation.

------------------------------------------------------------------------

## PROMPT 18 --- Planification académique prospective

Développe une fonction de planification académique prospective.

OBJECTIF Simuler l'évolution future d'une population étudiante et
estimer les besoins.

Analyser notamment les futurs passages, redoublements, dettes, groupes,
enseignants et salles.

LOOP MATURE 1. Audit des données. 2. Définition des scénarios. 3.
Simulation. 4. Calcul des populations futures. 5. Comparaison des
scénarios. 6. Estimation des besoins. 7. Export des résultats. 8. Tests
de cohérence. 9. Tests de performance. 10. Sécurité et Faculty Scope.
11. Audit. 12. Documentation. 13. Non-régression. 14. Validation.

------------------------------------------------------------------------

## PROMPT 19 --- Assistant académique intelligent

Développe une couche d'assistance académique intelligente.

OBJECTIF Aider les responsables et étudiants à comprendre les données et
règles académiques.

L'assistant peut expliquer, rechercher, résumer, comparer et proposer.

PRINCIPE ABSOLU L'assistant n'est jamais la source de vérité et ne prend
aucune décision officielle seul.

Les sources de vérité restent les données officielles, règles, résultats
et décisions.

LOOP MATURE 1. Audit des données accessibles. 2. Définition des cas
d'usage. 3. Définition des permissions. 4. Connexion aux services
métier. 5. Génération des réponses. 6. Vérification des sources. 7.
Protection des données sensibles. 8. Tests contre les hallucinations. 9.
Tests de sécurité. 10. Audit. 11. Documentation. 12. Validation humaine.
13. Non-régression. 14. Rapport final.

------------------------------------------------------------------------

## PROMPT 20 --- Détection des anomalies académiques

Développe un moteur de détection des anomalies académiques.

OBJECTIF Détecter les incohérences dans les données et les résultats.

Exemples : crédits impossibles, notes incohérentes, formation
incompatible, progression impossible, doublons, dette incohérente ou
décision incompatible.

LOOP MATURE 1. Audit des règles de cohérence. 2. Définition des
anomalies. 3. Implémentation du moteur. 4. Analyse des données. 5.
Classification. 6. Explication. 7. Création d'une file de résolution. 8.
Correction administrative contrôlée. 9. Audit. 10. Tests. 11.
Performance. 12. Sécurité. 13. Documentation. 14. Non-régression. 15.
Validation.

------------------------------------------------------------------------

## PROMPT 21 --- Gestion documentaire académique

Développe la gestion documentaire académique automatique en réutilisant
la GED et le moteur PDF déjà présents.

OBJECTIF Produire des documents académiques fiables à partir des données
officielles.

Documents possibles : attestations, certificats, décisions, historiques
et relevés.

LOOP MATURE 1. Audit du moteur PDF et de la GED. 2. Réutilisation des
composants existants. 3. Définition des modèles. 4. Récupération des
données officielles. 5. Création d'un snapshot. 6. Génération. 7. Hash
et versionnement. 8. Publication sécurisée. 9. Téléchargement contrôlé.
10. Tests. 11. Audit. 12. Documentation. 13. Non-régression. 14.
Validation.

------------------------------------------------------------------------

## PROMPT 22 --- Génération automatique des relevés de notes

Développe la génération automatique des relevés de notes.

OBJECTIF Générer un relevé semestriel, annuel ou complet à partir des
résultats officiels.

CONTENU Institution, étudiant, formation, semestre, UE, ECUE,
coefficients, notes, crédits, résultats, décision et session.

RÈGLE Le relevé officiel ne doit être généré qu'à partir de résultats et
décisions officiellement disponibles.

LOOP MATURE 1. Audit du système documentaire. 2. Réutilisation de la
GED. 3. Connexion aux résultats officiels. 4. Déclenchement après
délibération. 5. Snapshot des données. 6. Génération PDF. 7.
Versionnement. 8. Hash. 9. Publication sécurisée. 10. Notification. 11.
Tests de contenu et sécurité. 12. Gestion des corrections par nouvelle
version. 13. Audit. 14. Documentation. 15. Non-régression. 16.
Validation.

------------------------------------------------------------------------

## PROMPT 23 --- Import multi-format des notes

Développe un système d'import des notes depuis CSV, XLSX, XML et JSON.

PIPELINE Fichier → détection → lecture → normalisation → mapping →
validation → identification étudiant → association académique →
prévisualisation → confirmation → import transactionnel → rapport.

LOOP MATURE 1. Audit de DataExchange. 2. Réutilisation des mécanismes
existants. 3. Détection du format. 4. Lecture. 5. Mapping. 6.
Normalisation. 7. Validation. 8. Identification. 9. Association UE et
ECUE. 10. Prévisualisation. 11. Transaction. 12. Rapport d'import. 13.
Audit. 14. Tests de sécurité. 15. Tests de gros fichiers. 16.
Documentation. 17. Non-régression. 18. Validation.

------------------------------------------------------------------------

## PROMPT 24 --- Association automatique des notes aux étudiants

Développe l'association automatique des lignes de notes aux étudiants.

IDENTIFIANTS Privilégier CIN, matricule ou identifiant universitaire
selon le référentiel existant.

RÈGLE Une correspondance ambiguë ne doit jamais être validée
automatiquement.

LOOP MATURE 1. Audit des identifiants disponibles. 2. Normalisation. 3.
Recherche. 4. Vérification année. 5. Vérification formation. 6.
Vérification semestre. 7. Vérification UE et ECUE. 8. Association. 9.
Détection des ambiguïtés. 10. Prévisualisation. 11. Validation. 12.
Audit. 13. Tests d'identification. 14. Tests IDOR et Faculty Scope. 15.
Rapport d'erreurs. 16. Non-régression. 17. Validation.

------------------------------------------------------------------------

## PROMPT 25 --- Import sécurisé des notes

Renforce l'import des notes pour empêcher toute corruption des données
officielles.

OBJECTIF Un fichier incorrect doit être rejeté avant toute modification
irréversible.

LOOP MATURE 1. Validation du fichier. 2. Validation du format. 3.
Validation des colonnes. 4. Validation des types. 5. Validation des
valeurs. 6. Vérification des étudiants. 7. Vérification des UE et ECUE.
8. Détection des doublons. 9. Prévisualisation obligatoire. 10.
Confirmation explicite. 11. Transaction complète. 12. Rollback en cas
d'erreur critique. 13. Audit. 14. Tests de concurrence. 15. Tests de
sécurité. 16. Tests de fichiers volumineux. 17. Documentation. 18.
Validation.

------------------------------------------------------------------------

## PROMPT 26 --- Centre de résolution des anomalies académiques

Développe un centre administratif unique pour traiter les blocages et
anomalies académiques.

WORKFLOW BLOCAGE → EXPLICATION → CAUSE → CORRECTION → AUDIT →
RE-VÉRIFICATION → CONFORME ou NOUVEAU BLOCAGE

RÈGLE Ne jamais créer un simple bouton permettant de forcer une
validation sans contrôle.

LOOP MATURE 1. Audit des vérificateurs existants. 2. Agrégation des
anomalies. 3. Classification. 4. Affichage du constat. 5. Proposition
d'action. 6. Correction contrôlée. 7. Motif obligatoire. 8. Audit
avant/après. 9. Re-vérification automatique. 10. Résolution ou nouveau
blocage. 11. Tests IDOR et Faculty Scope. 12. Tests de concurrence. 13.
Audit. 14. Documentation. 15. Non-régression. 16. Validation métier.

------------------------------------------------------------------------

## PROMPT 27 --- Notifications académiques

Développe les notifications académiques en réutilisant le système
central de notifications.

ÉVÉNEMENTS Résultat publié, semestre validé, dette détectée, document
généré, décision publiée, inscription ouverte ou fermée et correction
académique.

LOOP MATURE 1. Audit du système de notifications. 2. Réutilisation des
mécanismes existants. 3. Définition des événements. 4. Création ou
réutilisation des templates. 5. Dispatch. 6. Prévention des doublons. 7.
Gestion des erreurs. 8. Tests. 9. Audit. 10. Performance. 11.
Documentation. 12. Non-régression. 13. Validation.

------------------------------------------------------------------------

## PROMPT 28 --- API académique

Développe ou complète une API académique sécurisée en réutilisant l'API
existante.

RESSOURCES Étudiants, parcours, inscriptions, résultats, crédits,
progression, documents, décisions et simulations.

LOOP MATURE 1. Audit de l'API existante. 2. Réutilisation des
ressources. 3. Définition des endpoints nécessaires. 4. Authorization.
5. Faculty Scope. 6. Validation. 7. Rate limiting. 8. Journalisation. 9.
Tests fonctionnels. 10. Tests BOLA et IDOR. 11. Tests de données
sensibles. 12. Documentation API. 13. Non-régression. 14. Validation.

------------------------------------------------------------------------

## PROMPT 29 --- Recherche académique globale

Développe une recherche académique globale sécurisée.

RECHERCHE Étudiant, matricule, CIN, formation, groupe, semestre, UE,
ECUE, document et décision.

LOOP MATURE 1. Audit du moteur Search existant. 2. Définition des
sources. 3. Définition des permissions. 4. Indexation. 5. Recherche. 6.
Filtrage Faculty Scope. 7. Protection des données sensibles. 8. Tests de
pertinence. 9. Tests IDOR. 10. Performance. 11. Audit. 12.
Documentation. 13. Non-régression. 14. Validation.

------------------------------------------------------------------------

## PROMPT 30 --- Audit académique avancé

Développe l'audit académique avancé en réutilisant AuditService et les
mécanismes existants.

ÉVÉNEMENTS Modification de note, import, correction, décision,
équivalence, dispense, réorientation, délibération, génération
documentaire et téléchargement sensible.

LOOP MATURE 1. Audit du système d'audit existant. 2. Identification des
événements manquants. 3. Normalisation. 4. Ajout des événements
nécessaires. 5. Capture du contexte. 6. Protection des logs. 7.
Recherche et filtrage. 8. Tests d'intégrité. 9. Tests de sécurité. 10.
Performance. 11. Documentation. 12. Non-régression. 13. Validation.

------------------------------------------------------------------------

## PROMPT 31 --- Sécurité académique avancée

Réalise un audit et un renforcement complet de la sécurité des
fonctionnalités académiques.

CHAÎNE OBLIGATOIRE Authentication → Authorization → Faculty Scope →
Validation → Business Rules → Transaction → Audit → Notification

VÉRIFIER IDOR, BOLA, accès inter-facultés, accès direct aux routes,
modification non autorisée, données sensibles, téléchargements et
opérations concurrentes.

LOOP MATURE 1. Audit des routes. 2. Audit des policies. 3. Audit du
Faculty Scope. 4. Recherche des IDOR. 5. Vérification des services. 6.
Vérification des transactions. 7. Tests négatifs. 8. Tests de
concurrence. 9. Tests données sensibles. 10. Correction. 11. Audit
final. 12. Documentation. 13. Non-régression. 14. Rapport de sécurité.

------------------------------------------------------------------------

## PROMPT 32 --- Versionnement des règles académiques

Développe le versionnement des règles académiques.

OBJECTIF Permettre de savoir quelle version des règles était applicable
lors d'un calcul ou d'une décision.

LOOP MATURE 1. Identifier les règles variables. 2. Définir le modèle de
version. 3. Associer une version aux calculs. 4. Associer une version
aux décisions. 5. Conserver les anciennes versions. 6. Tester plusieurs
années universitaires. 7. Vérifier la reproductibilité. 8. Tester les
migrations de règles. 9. Audit. 10. Documentation. 11. Non-régression.
12. Validation.

------------------------------------------------------------------------

## PROMPT 33 --- Simulation institutionnelle

Développe un moteur de simulation institutionnelle.

OBJECTIF Permettre à l'administration de mesurer l'impact d'une
modification académique sans modifier les données officielles.

SCÉNARIOS Modification d'une règle de validation, seuil, crédit,
passerelle, capacité ou autre règle configurable.

LOOP MATURE 1. Audit du référentiel et moteur de règles. 2. Création
d'un contexte de simulation isolé. 3. Application de la nouvelle règle.
4. Recalcul. 5. Comparaison avec la situation réelle. 6. Mesure des
impacts. 7. Rapport. 8. Garantie d'absence de mutation officielle. 9.
Tests de sécurité. 10. Tests de performance. 11. Audit. 12.
Documentation. 13. Non-régression. 14. Validation.

------------------------------------------------------------------------

## PROMPT 34 --- LOOP MATURE global d'intégration

Applique ce prompt après l'implémentation des fonctionnalités
précédentes afin de réaliser une intégration globale.

OBJECTIF Vérifier que tous les modules académiques fonctionnent comme un
système cohérent.

LOOP MATURE 1. Audit global. 2. Vérification des sources de vérité. 3.
Détection des duplications. 4. Vérification des relations entre
référentiel, population, inscriptions, enseignement, notes et
délibérations. 5. Vérification du moteur de progression. 6. Vérification
des crédits et dettes. 7. Vérification des simulations. 8. Vérification
des équivalences, dispenses et réorientations. 9. Vérification des
imports. 10. Vérification des relevés. 11. Vérification de l'audit. 12.
Vérification des notifications. 13. Vérification API et recherche. 14.
Tests IDOR et Faculty Scope. 15. Tests de concurrence. 16. Tests de
performance. 17. Tests de non-régression. 18. Documentation globale. 19.
Validation métier. 20. Rapport de maturité.

------------------------------------------------------------------------
