# Plan LOOP mature — Amélioration intelligente de l’expérience d’inscription des étudiants
## Projet Laravel universitaire existant

**Objectif :** faire évoluer progressivement le parcours d’inscription des étudiants en auditant, améliorant et testant chaque fonctionnalité une par une. Chaque étape possède sa propre LOOP, avec audit initial, implémentation contrôlée, tests, audit final et boucle corrective si nécessaire.

---

# 0. Protocole global obligatoire

## Rôle
Tu es un ingénieur logiciel senior spécialisé en Laravel, sécurité applicative, systèmes universitaires et UX. Tu travailles sur le dépôt existant, pas sur une application neuve.

## Règle fondamentale
**Une seule LOOP par exécution.** Ne commence jamais l’étape suivante automatiquement. À la fin de chaque LOOP, présente un rapport et attends explicitement ma validation.

## Déroulement obligatoire de chaque LOOP
1. **Découverte** : inspecter routes, contrôleurs, Form Requests, Policies, middleware, modèles, migrations, services, jobs, events, notifications, vues Blade, composants JavaScript, configuration et tests concernés.
2. **Cartographie** : identifier les fonctionnalités existantes, leurs dépendances et les sources de vérité. Ne pas supposer qu’une fonction est absente avant d’avoir cherché dans tout le code pertinent.
3. **Audit initial** : décrire le comportement réel, les écarts, les risques, les tests existants et les lacunes. Avant toute modification, afficher un plan concis des changements nécessaires.
4. **Implémentation** : après cet audit, effectuer les modifications nécessaires à cette LOOP uniquement, sauf si je demande explicitement un audit sans modification. Préserver les conventions, l’architecture et l’interface existantes.
5. **Tests** : ajouter ou adapter des tests ciblés; exécuter les tests pertinents et les tests de régression raisonnables. Ne pas prétendre qu’un test a réussi sans résultat de commande.
6. **Audit final** : vérifier le diff, la sécurité, les effets de bord, les erreurs de logs, l’accessibilité, les performances et les règles métier.
7. **Boucle corrective** : si un critère échoue, identifier la cause, corriger, relancer les tests et réauditer. Répéter jusqu’à PASS ou signaler explicitement un blocage externe réel. Ne pas masquer un échec en supprimant ou en affaiblissant un test.
8. **Rapport** : fournir statut, fichiers modifiés, migrations, tests exécutés avec résultats, risques restants, étapes manuelles éventuelles et décision attendue.

## Statuts
- `PASS` : critères d’acceptation satisfaits et vérifiés.
- `PARTIAL` : valeur partielle; lacunes restantes clairement listées.
- `FAIL` : comportement incorrect ou test critique échoué.
- `BLOCKED` : dépendance ou environnement empêche une vérification.
- `NOT APPLICABLE` : fonctionnalité non pertinente; justifier et attendre ma validation pour la remplacer ou la passer.

## Garde-fous
- Ne jamais réécrire le module complet ni créer une seconde version d’un service déjà présent.
- Réutiliser les routes, modèles, services, composants, statuts et références déjà implémentés lorsqu’ils sont adaptés.
- Ne pas créer de migration, table, enum, service, permission ou package sans démontrer le besoin après l’audit.
- Ne pas modifier des données réelles, exécuter de migration destructive, supprimer de données ou déclencher d’envoi réel de courriels/SMS sans autorisation.
- Préserver les inscriptions existantes et leur historique.
- Respecter la compatibilité de la version Laravel/PHP et les dépendances installées.
- Utiliser les règles métier officielles configurées dans le projet comme source de vérité; ne pas inventer de critères d’éligibilité.
- Protéger les données personnelles : minimisation, autorisations serveur, validation, stockage privé, accès par politique, absence de CIN/tokens/données sensibles dans les URL et logs.
- Traiter les sorties OCR/IA comme des propositions, jamais comme une preuve définitive d’identité ou une décision administrative.
- Toutes les actions sensibles doivent être autorisées côté serveur et journalisées selon les conventions du projet.
- Utiliser transactions et contraintes d’intégrité lorsque plusieurs écritures doivent rester cohérentes.
- Toute fonctionnalité asynchrone doit prévoir états, reprise, idempotence, échecs et feedback utilisateur.
- Interface responsive, accessible au clavier, libellés explicites, erreurs compréhensibles en français, états de chargement/vides/erreurs.
- Ne jamais inventer de données de test dans la base réelle; utiliser factories, seeders de test ou base de test.
- Si une commande échoue, expliquer pourquoi; ne pas annoncer PASS tant que les tests essentiels ne passent pas.

## Format de rapport final obligatoire
1. LOOP et fonctionnalité traitée.
2. Statut global et justification.
3. État initial et problèmes confirmés.
4. Décisions techniques et raisons.
5. Fichiers créés/modifiés.
6. Migrations et commandes exécutées (préciser si aucune).
7. Tests exécutés : commande, nombre de tests/assertions et résultat réel.
8. Sécurité, autorisations et confidentialité vérifiées.
9. Critères d’acceptation cochés/non cochés.
10. Risques résiduels et tâches manuelles.
11. Prochaine LOOP proposée (sans l’exécuter).
12. Question finale : « Valides-tu cette LOOP pour passer à la suivante ? »

---

# 1. LOOP — Cartographie et diagnostic global du parcours d’inscription

## Objectif
Construire une carte fiable du parcours existant avant de toucher aux fonctionnalités.

## Travail demandé
- Identifier les parcours candidat public, étudiant connecté, agent de scolarité et administrateur.
- Cartographier routes, contrôleurs, services, modèles, vues, statuts, permissions et tests.
- Décrire le parcours réel depuis l’ouverture de la période jusqu’au récépissé et à l’affectation éventuelle.
- Identifier les sources de vérité pour période, identité, référentiel MENS, pièces obligatoires, dossier, décision administrative et groupe.
- Vérifier les doubles implémentations et les fonctionnalités déjà disponibles.
- Créer un tableau `fonctionnalité / implémentation / fichiers / tests / risques / priorité`.
- Ne pas modifier le code dans cette LOOP.

## Critères d’acceptation
- Le flux principal est documenté de bout en bout.
- Les éléments inconnus sont explicitement indiqués comme non vérifiés.
- Aucune fonctionnalité n’est déclarée absente sur la seule base d’une recherche par nom.
- Aucun fichier modifié.

---

# 2. LOOP — Assistant d’inscription guidé et parcours adaptatif

## Objectif
Permettre à l’étudiant de suivre un parcours clair adapté à sa situation.

## Travail demandé
- Auditer le formulaire et les parcours déjà existants.
- Déterminer, à partir des catégories réellement prises en charge, le parcours adapté : première inscription, réinscription, transfert, licence/master ou autre catégorie existante.
- Afficher les étapes sous forme de progression : identification, informations, documents, vérification, soumission.
- Afficher une checklist et la prochaine action à effectuer.
- Masquer uniquement les sections non applicables selon des règles métier explicites; ne jamais masquer une exigence obligatoire.
- Permettre de revenir aux étapes précédentes sans perte des données.
- Fournir un résumé avant soumission.
- Les règles métier doivent être côté serveur; l’interface ne constitue pas une barrière de sécurité.
- Éviter les refontes visuelles globales non nécessaires.

## Critères et tests
- Chaque catégorie autorisée affiche le bon parcours.
- Étapes complètes/incomplètes et erreurs de validation correctement présentées.
- Parcours utilisable sur mobile et clavier.
- Accès direct à une étape protégée contrôlé côté serveur.
- Aucune régression sur le formulaire existant.

---

# 3. LOOP — Sauvegarde automatique et reprise du formulaire

## Objectif
Éviter la perte des informations lorsque l’étudiant quitte ou recharge la page.

## Travail demandé
- Auditer le mécanisme de brouillon existant et la politique de conservation.
- Sauvegarder progressivement les champs autorisés avec un indicateur « Enregistrement… / Enregistré / Échec ».
- Prévoir une reprise après reconnexion lorsque le parcours nécessite une authentification.
- Si le parcours public n’a pas de compte, concevoir la reprise avec un mécanisme sécurisé déjà compatible avec l’application; ne pas mettre de données personnelles dans un identifiant facilement devinable ou dans l’URL.
- Éviter les écrasements concurrents avec version/timestamp ou mécanisme équivalent si justifié.
- Ne pas enregistrer inutilement les pièces sensibles en stockage navigateur. Définir le comportement pour les fichiers séparément.
- Mettre en place expiration, suppression contrôlée et reprise après erreur réseau.
- La sauvegarde ne doit pas créer de dossier officiel soumis avant confirmation explicite.

## Critères et tests
- Reprise des données après rechargement et reconnexion selon le parcours.
- Gestion des erreurs réseau et des sauvegardes concurrentes.
- Autorisation et isolation entre utilisateurs/dossiers.
- Aucun secret ni donnée sensible exposé dans URL ou logs.
- Soumission explicite nécessaire pour déposer officiellement le dossier.

---

# 4. LOOP — Checklist documentaire dynamique

## Objectif
Montrer uniquement les documents requis pour la situation réelle de l’étudiant, selon les règles officielles du projet.

## Travail demandé
- Localiser la source de vérité des pièces requises et les éventuelles règles par type d’inscription.
- Afficher pour chaque pièce : obligatoire/facultative, format accepté, taille maximale, état et motif de refus éventuel.
- Calculer les pièces manquantes côté serveur.
- Distinguer non fourni, fourni, à vérifier, accepté, refusé et à remplacer si ces états sont adaptés au domaine existant.
- Afficher le motif précis d’une pièce rejetée et permettre un nouveau dépôt sans perdre l’historique nécessaire.
- Ne pas faire confiance à l’état envoyé par le navigateur.
- Ne pas rendre visibles les documents d’un autre étudiant.

## Critères et tests
- Chaque type d’inscription utilise la liste correcte.
- Une pièce manquante empêche la soumission seulement si elle est officiellement obligatoire.
- Les documents facultatifs ne bloquent pas le dossier.
- Les refus permettent une correction contrôlée.
- Tests d’accès croisé aux fichiers et aux métadonnées.

---

# 5. LOOP — Dépôt de documents sécurisé et validation technique

## Objectif
Rendre le dépôt fiable et compréhensible, sans compromettre la sécurité.

## Travail demandé
- Auditer les règles actuelles de téléversement et le stockage.
- Vérifier extension, MIME réel, taille, limites, nom généré côté serveur et erreurs de téléversement.
- Stocker les documents dans un espace privé, jamais sous un chemin public directement accessible.
- Prévoir un téléchargement/aperçu via une route autorisée, avec contrôle de propriété et permission.
- Empêcher l’exécution de fichiers déposés; utiliser les protections de stockage adaptées.
- Gérer doublons de dépôt, remplacement, échec partiel et suppression selon la politique d’archivage.
- Ajouter une vérification antivirus uniquement si un service réellement disponible est configuré; sinon documenter la limite plutôt que simuler une protection.
- Fournir progression et messages d’erreur accessibles.

## Critères et tests
- Fichiers non autorisés et MIME incohérent rejetés.
- Accès anonyme et accès inter-étudiants refusés.
- Documents conservés privés.
- Remplacement sans corruption de l’historique.
- Limites de taille et erreurs réseau testées.

---

# 6. LOOP — Préremplissage et identification intelligente avec référentiel MENS

## Objectif
Réduire la saisie répétitive en réutilisant les données autorisées, sans créer de fausses identités.

## Travail demandé
- Auditer le mécanisme d’identification existant, le référentiel et les règles d’appariement.
- Utiliser les champs de correspondance déjà approuvés par le projet.
- Distinguer correspondance certaine, correspondance probable, ambiguïté et absence de résultat.
- Préremplir seulement les champs autorisés; afficher la source et demander confirmation à l’étudiant lorsque nécessaire.
- Ne jamais fusionner automatiquement deux dossiers sur une correspondance approximative.
- Pour les homonymes, utiliser les identifiants fiables disponibles et appliquer les règles existantes; ne pas supposer que le nom est unique.
- Prévoir erreurs de connexion au référentiel, indisponibilité et reprise.
- Respecter throttle, permissions, minimisation des données et journaux sans données sensibles.
- L’IA éventuelle ne doit pas être la source de vérité pour l’identité.

## Critères et tests
- Correspondance certaine, probable, ambiguë, aucune correspondance et référentiel indisponible.
- Pas de fusion ni création silencieuse d’identité en cas d’ambiguïté.
- Les données non confirmées sont clairement identifiées.
- Tests de limitation de débit et de fuite d’information.

---

# 7. LOOP — Détection des doublons et des incohérences

## Objectif
Repérer les dossiers potentiellement dupliqués ou contradictoires tout en évitant les faux positifs.

## Travail demandé
- Auditer les contraintes de base de données et contrôles applicatifs existants.
- Distinguer doublon exact confirmé et correspondance potentielle.
- Appliquer les règles officielles de l’établissement sur les identifiants uniques. Ne pas imposer l’unicité du nom/prénom.
- Comparer uniquement les données nécessaires et autorisées.
- Pour les cas ambigus, créer une alerte à vérifier plutôt qu’un rejet automatique.
- Contrôler les divergences entre formulaire et documents, en gardant une trace des valeurs vérifiées selon la politique de confidentialité.
- Prévoir la résolution par agent habilité et journaliser la décision sans exposer les données sensibles dans les logs techniques.

## Critères et tests
- Homonymes légitimes acceptés.
- Doublon certain traité selon les règles du projet.
- Correspondance ambiguë orientée vers une vérification humaine.
- Soumissions concurrentes ne créant pas de doublon lorsque l’unicité est exigée.
- Aucun rejet irréversible fondé uniquement sur une similarité de nom.

---

# 8. LOOP — Contrôles d’éligibilité et orientation vers le bon parcours

## Objectif
Aider l’étudiant à sélectionner le parcours auquel il peut prétendre selon les règles officielles configurées.

## Travail demandé
- Identifier les règles académiques existantes et leur source.
- Vérifier faculté, programme, spécialité, niveau, année universitaire, type d’inscription et prérequis uniquement lorsque ces règles sont disponibles.
- Afficher les options permises et expliquer les options indisponibles sans divulguer des informations confidentielles.
- Distinguer erreur bloquante, avertissement et cas nécessitant une décision humaine.
- Revalider les règles côté serveur lors de la soumission; ne pas se fier à la sélection du navigateur.
- Ne pas inventer de critères d’admission ni remplacer une décision administrative officielle par un score IA.

## Critères et tests
- Choix valide accepté.
- Choix incompatible bloqué côté serveur.
- Référentiel incomplet ou règle indéterminée traité explicitement.
- Changement de configuration entre affichage et soumission correctement géré.
- Exceptions administratives traçables.

---

# 9. LOOP — Validation avant soumission et résumé final

## Objectif
Permettre à l’étudiant de détecter les erreurs avant de déposer son dossier officiellement.

## Travail demandé
- Afficher un résumé des données et documents, avec possibilité de revenir modifier chaque section.
- Afficher les champs manquants, les pièces requises non fournies et les avertissements.
- Distinguer les erreurs bloquantes des avertissements non bloquants.
- Exiger une action explicite pour soumettre définitivement.
- Empêcher la double soumission par double clic ou requêtes concurrentes.
- Après succès, afficher une confirmation et une référence non sensible.
- Ne pas déclarer le dossier accepté : distinguer « soumis » de « validé ».

## Critères et tests
- Soumission bloquée lorsque des exigences obligatoires manquent.
- Double soumission idempotente.
- Référence générée selon les conventions existantes.
- État affiché cohérent avec l’état en base.
- Navigation arrière ne provoque pas de double dépôt.

---

# 10. LOOP — Suivi du dossier étudiant

## Objectif
Permettre à l’étudiant de connaître l’état réel de son dossier et sa prochaine action.

## Travail demandé
- Réutiliser les statuts existants avant d’en ajouter.
- Présenter une chronologie : création, dépôt, vérification, demande de complément, nouvelle soumission, décision.
- Afficher la date de dernière mise à jour, les actions à effectuer et les pièces à corriger.
- N’afficher que les données accessibles à l’étudiant authentifié ou au détenteur du mécanisme public sécurisé.
- Définir un comportement neutre pour les références inexistantes afin d’éviter l’énumération de dossiers.
- Garder une cohérence entre état affiché, modèle et décision enregistrée.

## Critères et tests
- Chaque statut a un libellé compréhensible.
- Un étudiant ne peut consulter le dossier d’un autre.
- La demande de complément explique précisément la prochaine action.
- Historique et état courant restent cohérents.
- Référence inconnue ne révèle aucune donnée personnelle.

---

# 11. LOOP — Demande de complément et correction du dossier

## Objectif
Éviter les rejets définitifs lorsque le dossier peut être complété.

## Travail demandé
- Auditer les statuts et workflows de correction existants.
- Permettre à un agent autorisé d’indiquer les champs ou documents à corriger et un motif clair.
- Informer l’étudiant et fournir un lien sécurisé vers les éléments concernés.
- Autoriser la modification des seules sections permises par l’état du dossier.
- Conserver les versions ou traces nécessaires des documents remplacés et des décisions.
- Revalider le dossier lors de la nouvelle soumission.
- Empêcher une modification silencieuse après décision définitive, sauf procédure officielle.

## Critères et tests
- Demande de complément autorisée uniquement pour les rôles habilités.
- Étudiant limité à son propre dossier.
- Les éléments corrigés sont revalidés.
- Historique des demandes et réponses conservé.
- Notifications idempotentes et erreurs gérées.

---

# 12. LOOP — Notifications et rappels d’échéance

## Objectif
Informer l’étudiant au bon moment sans envoyer de messages inutiles ou confidentiels.

## Travail demandé
- Auditer les notifications et canaux existants.
- Déclencher les événements pertinents : période ouverte, dépôt reçu, dossier à compléter, échéance proche, décision prise.
- Préférer les notifications en base et réutiliser le système de queue si disponible.
- Ne pas inclure de CIN, documents ou détails sensibles dans les courriels/SMS.
- Prévoir déduplication, tentatives, échec, statut d’envoi et reprise.
- Respecter les préférences et les règles institutionnelles.
- Éviter tout envoi réel pendant les tests.

## Critères et tests
- Un événement ne produit pas de notifications dupliquées.
- Échec du canal externe n’annule pas la transaction métier.
- Contenu minimal et non sensible.
- Queue et échecs observables.
- Tests avec notifications simulées.

---

# 13. LOOP — Récépissé PDF et preuve de dépôt

## Objectif
Fournir une preuve claire de soumission.

## Travail demandé
- Réutiliser le générateur PDF et les conventions de référence existants.
- Inclure uniquement les informations nécessaires : référence, date, statut, résumé et documents reçus si approprié.
- Distinguer clairement le récépissé de dépôt de la décision d’acceptation.
- Contrôler les autorisations avant téléchargement.
- Protéger contre les accès directs et les références prévisibles.
- Gérer les erreurs de génération et permettre une régénération contrôlée.
- Ne pas stocker inutilement des copies multiples de documents sensibles.

## Critères et tests
- PDF généré avec les bonnes données et le bon statut.
- Accès inter-étudiants refusé.
- Le PDF ne contient pas de données d’un autre dossier.
- Échec de génération communiqué sans perdre le dépôt.
- Référence et date cohérentes avec la base.

---

# 14. LOOP — Affectation académique après validation

## Objectif
Automatiser l’affectation à un groupe uniquement lorsque les conditions métier sont satisfaites.

## Travail demandé
- Auditer l’`AffectationService`, l’engine, les groupes et les règles existantes.
- Déclencher l’affectation au moment métier approprié, seulement après validation et dossier complet si cette règle est confirmée.
- Vérifier compatibilité faculté/programme/spécialité/niveau/année et capacité réelle.
- Ne jamais affecter un étudiant de L1 à un groupe L2, ni contourner les règles de capacité.
- Utiliser une simulation sans écriture avant application massive lorsque pertinent.
- Garantir transaction, verrouillage, idempotence, historique et possibilité de reprise.
- Si aucun groupe compatible n’est disponible, placer le dossier dans un état explicite à traiter; ne pas inventer une affectation.
- Afficher le résultat et le groupe attribué uniquement après confirmation de l’écriture.

## Critères et tests
- Affectation compatible effectuée une seule fois.
- Groupe plein ou incompatible jamais choisi.
- Exécutions concurrentes sans double affectation.
- Simulation sans écriture.
- Absence de groupe gérée avec état et message explicites.

---

# 15. LOOP — Assistant FAQ fondé sur les sources officielles

## Objectif
Répondre aux questions fréquentes sur l’inscription sans inventer de règles.

## Travail demandé
- D’abord rechercher si une FAQ, une base de connaissances ou une aide existe déjà.
- Utiliser uniquement des contenus approuvés : règles, procédures, calendrier et pages officielles.
- Si une recherche sémantique/RAG existe déjà, la réutiliser; sinon proposer une conception minimale avant d’ajouter une nouvelle infrastructure.
- Montrer la source ou la page de référence lorsque disponible.
- Si la réponse est incertaine ou personnalisée, orienter vers la scolarité.
- Ne pas exposer l’état d’un dossier via un assistant public sans authentification et autorisation.
- Ne pas laisser le modèle modifier les dossiers, statuts ou décisions.
- Journaliser les métriques de qualité sans conserver inutilement les questions contenant des données personnelles.

## Critères et tests
- Questions couvertes répondues à partir des sources.
- Questions inconnues orientées vers une aide humaine.
- Réponses sans source officielle présentées comme incertaines ou refusées.
- Aucun accès aux dossiers privés depuis le FAQ public.
- Pas d’action métier déclenchée par le modèle.

---

# 16. LOOP — Rendez-vous ou demande d’aide à la scolarité

## Objectif
Permettre à l’étudiant de demander une aide humaine quand l’automatisation ne suffit pas.

## Travail demandé
- Vérifier si un module de rendez-vous ou de demandes existe déjà.
- Réutiliser les créneaux, horaires et disponibilités réellement configurés.
- Permettre une demande avec motif catégorisé et informations minimales.
- Ne pas confirmer un créneau qui n’a pas été réservé côté serveur.
- Gérer annulation, modification, capacité, conflits et notifications.
- Si aucun agenda n’existe, commencer par une demande d’assistance plutôt que créer un système complet sans validation.

## Critères et tests
- Pas de double réservation d’un même créneau si le modèle l’interdit.
- Seuls les créneaux disponibles sont proposés.
- Les demandes sont visibles aux agents autorisés.
- Données personnelles minimisées.
- Confirmation envoyée uniquement après enregistrement réussi.

---

# 17. LOOP — Accessibilité, mobile et connexion instable

## Objectif
Rendre le parcours utilisable sur téléphone, avec clavier et sur réseau lent.

## Travail demandé
- Auditer l’interface actuelle avec une liste de critères vérifiables.
- Vérifier responsive design, labels, ordre de tabulation, focus visible, erreurs associées aux champs, contraste et messages d’état.
- Réduire les soumissions répétées et conserver les données non sensibles en cas de problème réseau.
- Ajouter des états chargement, vide, réussite, erreur et reprise.
- N’ajouter un mode hors ligne que si son périmètre et ses risques sont justifiés; ne pas mettre des documents ou données sensibles dans un cache public.
- Respecter les conventions Blade, Tailwind, Alpine et Vite du projet.

## Critères et tests
- Formulaire utilisable sans souris pour les parcours essentiels.
- Affichage exploitable sur petit écran.
- Erreurs compréhensibles et associées aux champs.
- Réseau lent/erreur gérés sans fausse confirmation.
- Pas de cache public de données sensibles.

---

# 18. LOOP — Tableau de bord administratif et file de traitement

## Objectif
Aider la scolarité à traiter les dossiers efficacement, sans réduire la qualité du contrôle.

## Travail demandé
- Auditer les écrans, filtres, recherches, permissions et services existants.
- Afficher les dossiers par statut, ancienneté et action attendue.
- Ajouter des filtres utiles sans exposer les données d’une autre faculté.
- Prévoir une fiche de vérification structurée, commentaires de traitement et motifs normalisés si cohérents avec l’existant.
- Mesurer les délais et volumes, sans classement opaque des étudiants.
- Les décisions définitives restent réservées aux rôles autorisés.
- Éviter les requêtes N+1 et tester la pagination sur de grands volumes.

## Critères et tests
- Scoping faculté appliqué à chaque requête.
- Permissions contrôlées côté serveur.
- Pagination et filtres corrects.
- Indicateurs cohérents avec la base.
- Actions sensibles tracées.

---

# 19. LOOP — Audit de sécurité et de confidentialité transversal

## Objectif
Vérifier le parcours complet contre les risques d’accès non autorisé, de fuite de données et d’abus.

## Travail demandé
- Auditer les contrôles d’accès, IDOR/BOLA, CSRF, rate limiting, validation, mass assignment, XSS, upload, stockage privé, journalisation et sessions.
- Vérifier les autorisations sur chaque lecture, téléchargement, modification et action.
- Contrôler la séparation candidat public/étudiant/agent/admin et le cloisonnement par faculté.
- Vérifier les transactions, contraintes d’unicité, idempotence et concurrence.
- Vérifier que les réponses publiques ne permettent pas d’énumérer les identités ou dossiers.
- Vérifier les secrets et données sensibles dans les logs, exceptions, URL et exports.
- Ajouter des tests de régression ciblés; ne pas affaiblir les tests existants.

## Critères d’acceptation
- Tests de permissions et d’accès croisé réussis.
- Routes sensibles protégées et limitées en débit si nécessaire.
- Fichiers privés et téléchargements autorisés.
- Aucune vulnérabilité critique connue non documentée.
- Les risques impossibles à vérifier sont clairement signalés.

---

# 20. LOOP — Performance, fiabilité et observabilité

## Objectif
Garantir une expérience stable lorsque beaucoup d’étudiants s’inscrivent simultanément.

## Travail demandé
- Mesurer avant d’optimiser.
- Vérifier les requêtes lentes, N+1, index, pagination, taille des fichiers et traitements OCR/PDF.
- Utiliser les queues pour les tâches longues seulement si l’architecture existante le permet.
- Prévoir statut de traitement, retries limités, idempotence, échecs permanents et reprise.
- Ajouter des logs techniques utiles sans données personnelles.
- Tester concurrence sur les opérations critiques dans l’environnement de test.
- Ne pas déclarer des objectifs de performance atteints sans mesures réelles.

## Critères d’acceptation
- Mesures avant/après documentées.
- Aucun traitement long inutilement exécuté dans une requête synchrone.
- Pas de doublons lors des retries.
- Échecs visibles et récupérables.
- Aucun secret ni PII inutile dans les logs.

---

# 21. LOOP — Tests de parcours complet et non-régression

## Objectif
Valider le parcours étudiant complet depuis le démarrage jusqu’à la décision et, si applicable, l’affectation.

## Travail demandé
- Construire des tests d’intégration à partir des règles réellement implémentées.
- Couvrir les cas nominaux, documents manquants, période fermée, ambiguïté d’identité, doublon, correction, notification, PDF, absence de groupe et permissions.
- Vérifier les transitions de statut et les effets de bord.
- Utiliser une base de test et des notifications/fichiers simulés.
- Exécuter les tests ciblés, puis la suite de régression appropriée.
- Corriger les défauts causés par les changements de ce plan sans supprimer ni affaiblir les assertions.

## Critères d’acceptation
- Scénarios critiques passés.
- Résultats de test rapportés exactement.
- Aucun échec masqué.
- Parcours bout en bout cohérent entre interface, services et base.
- Problèmes préexistants distingués des régressions nouvelles.

---

# 22. LOOP — Mesure de l’expérience et amélioration continue

## Objectif
Évaluer si les changements améliorent réellement le parcours.

## Travail demandé
- Définir des métriques avec une formule précise : complétude au premier dépôt, taux d’abandon si mesurable de façon appropriée, taux de correction, délai de traitement et taux d’erreur technique.
- Respecter la minimisation des données et ne pas mettre en place de suivi invasif.
- Produire des statistiques agrégées, avec filtre temporel et faculté selon les permissions.
- Ne pas présenter une corrélation comme une preuve causale.
- Proposer un cycle d’amélioration fondé sur les résultats réels.

## Critères d’acceptation
- Chaque métrique possède définition et source.
- Calculs testés sur un jeu de données contrôlé.
- Aucune donnée personnelle affichée dans les rapports agrégés.
- Aucun objectif chiffré inventé présenté comme résultat.

---

# 23. LOOP finale — Audit de livraison et décision GO / NO-GO

## Objectif
Établir si le parcours amélioré est prêt à être livré.

## Travail demandé
- Vérifier le diff complet depuis le début du projet.
- Vérifier routes, migrations, permissions, données historiques, tests, assets et configuration.
- Rejouer les tests critiques et la suite pertinente.
- Contrôler les procédures de déploiement et de retour arrière pour les changements de schéma.
- Vérifier les traductions, messages d’erreur, accessibilité, documentation et tâches planifiées.
- Lister les points non vérifiés, les risques résiduels et les étapes manuelles.
- Ne pas déployer ni lancer de migrations en production sans autorisation explicite.

## Décision
- `GO` uniquement si les critères critiques sont vérifiés.
- `GO WITH CONDITIONS` si des limites non critiques sont acceptées et clairement listées.
- `NO-GO` si une faille critique, perte de données possible ou défaut métier important demeure.

---

# Checklist de pilotage

- [x] LOOP 01 — Cartographie et diagnostic
- [x] LOOP 02 — Assistant d’inscription guidé
- [x] LOOP 03 — Sauvegarde et reprise
- [x] LOOP 04 — Checklist documentaire dynamique
- [x] LOOP 05 — Dépôt sécurisé
- [x] LOOP 06 — Préremplissage MENS
- [x] LOOP 07 — Doublons et incohérences
- [x] LOOP 08 — Éligibilité et orientation
- [x] LOOP 09 — Résumé avant soumission
- [x] LOOP 10 — Suivi du dossier
- [x] LOOP 11 — Demande de complément
- [x] LOOP 12 — Notifications et échéances
- [x] LOOP 13 — Récépissé PDF
- [x] LOOP 14 — Affectation académique
- [x] LOOP 15 — FAQ officielle
- [x] LOOP 16 — Demande d’aide / rendez-vous
- [x] LOOP 17 — Accessibilité et réseau instable
- [x] LOOP 18 — File administrative
- [x] LOOP 19 — Audit sécurité transversal
- [x] LOOP 20 — Performance et fiabilité
- [x] LOOP 21 — Tests de bout en bout
- [x] LOOP 22 — Mesure de l’expérience
- [x] LOOP 23 — Audit final GO / NO-GO

**Règle finale :** ne coche une LOOP qu’après vérification de ses critères d’acceptation. Une fonctionnalité `PARTIAL`, `FAIL` ou `BLOCKED` ne peut pas être présentée comme terminée. Chaque LOOP doit se terminer par un rapport et attendre ma validation explicite avant de continuer.
