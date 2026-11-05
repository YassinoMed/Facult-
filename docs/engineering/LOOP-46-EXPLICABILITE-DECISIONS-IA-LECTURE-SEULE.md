# LOOP 46 — EXPLICABILITÉ DES DÉCISIONS ET IA STRICTEMENT EN LECTURE SEULE

**Date :** 31 octobre 2026
**Périmètre :** explication d'une décision académique publiée et outil Assistant IA associé
**Nature :** technique (livraison et tests) et métier (droit d'expliquer une décision réelle)

---

## 1. Objectif

Rendre une décision académique publiée **explicable** sans jamais la réinterpréter :

1. confronter l'acte officiel du jury à la lecture du moteur d'acquis sur des faits
   mesurables uniquement ;
2. citer uniquement des sources réelles (décision, délibération, inscription,
   références déclarées par le moteur) ;
3. produire une empreinte SHA-256 vérifiable des faits exploités ;
4. garantir `lecture_seule=true`, zéro recommandation, zéro sanction, zéro écriture ;
5. exposer le tout via un outil `AssistantIa` métier gouverné par permission,
   anonymisation et périmètre facultaire.

Aucune règle officielle, aucun seuil, aucun quota, aucun délai n'a été inventé.

---

## 2. Audit préalable de l'existant

| Élément audité | Constat |
|---|---|
| `app/Services/Acquis/` | Aucun service d'explication de décision n'existait (recherche `Explication`/`explicabil`) |
| `MoteurDecisionAcademiqueService` | Sortie structurée `unites`/`ecues`/`credits`/`anomalies`/`references_regles`, lecture seule |
| `DecisionDeliberation` | `scopePubliee`, statut de publication porté par `publie` + délibération |
| `AssistantIa` | Contrat `OutilIaInterface`, `ResultatOutil`, `RegistreOutils`, `FiltreSecuriteIa` stables |
| Outils métier existants | `ExpliquerAcquisRedoublementTool`, `ExpliquerExclusionsEpreuveTool` : modèles de permission/anonymisation/périmètre |
| `RegistreOutils` | Exclusion des outils sensibles ou `preparer_action` pour un fournisseur distant ; **aucune** protection générique des données académiques |
| Test de registre | `OutilsMetierEtDeveloppeurTest` était **obsolète** : il attendait 18 outils alors que 19 étaient enregistrés (l'outil `expliquer_acquis_redoublement` n'avait pas été déclaré) |

Aucune duplication : le nouveau service réutilise le moteur existant et n'ajoute aucune
seconde logique de calcul.

---

## 3. Livrables

| Livrable | Fichier |
|---|---|
| Service d'explication | `app/Services/Acquis/ExplicationDecisionAcademiqueService.php` |
| Outil Assistant IA | `app/Modules/AssistantIa/Tools/Metier/ExpliquerDecisionAcademiqueTool.php` |
| Marqueur de données académiques | `app/Modules/AssistantIa/Contracts/OutilIaDonneesAcademiquesInterface.php` |
| Garde fournisseur distant | `app/Modules/AssistantIa/Services/RegistreOutils.php` |
| Enregistrement de l'outil | `app/Providers/ModuleServiceProvider.php` |
| Tests | `tests/Feature/Acquis/ExplicationDecisionAcademiqueLoop46Test.php` |
| Correction du test de registre | `tests/Feature/AssistantIa/OutilsMetierEtDeveloppeurTest.php` |

Aucune migration, aucune route, aucun contrôleur, aucune vue, aucun seed,
aucune modification de données réelles.

---

## 4. Fonctionnement du service

### 4.1 Fail-closed avant toute lecture

L'explication est refusée, sans appeler le moteur, dans les cas suivants :

| Motif | Situation |
|---|---|
| `DECISION_NON_PUBLIEE` | `evaluation_decisions.publie = false` |
| `DELIBERATION_INTROUVABLE` | Délibération rattachée absente |
| `DELIBERATION_NON_PUBLIEE` | Statut hors `published` / `locked` / `archived` |
| `INSCRIPTION_NON_RATTACHEE` | Aucune inscription académique liée |
| `INSCRIPTION_INCOHERENTE` | Inscription rattachée à un autre étudiant |
| `ETUDIANT_INTROUVABLE` | Étudiant absent |
| `PROGRAMME_INDETERMINE` | Ni programme étudiant, ni programme d'inscription |
| `REGIME_INDETERMINE` | `DomainException` du moteur : régime réglementaire inconnu |

Un refus ne conclut jamais à une sanction : il explique seulement pourquoi
l'explication n'est pas produite.

### 4.2 Comparaison : uniquement des faits mesurables

La décision du jury est reportée **verbatim** et déclarée `acte_souverain_du_jury`
(`decision_officielle_fait_foi=true`). Elle n'est jamais requalifiée.

Deux faits sont comparés, chacun avec un indicateur de comparabilité :

| Fait | Comparable si | Sinon |
|---|---|---|
| Crédits acquis | Une seule inscription et aucune note hors inscription | `raison_non_comparable` explicite |
| UE acquises | Idem + nombre d'UE lues = `ues_total` officiel | `raison_non_comparable` explicite |

Statuts produits : `CONCORDANTE`, `ECART_MESURE`, `NON_COMPARABLE`, `NON_EXPLICABLE`.
Un écart est un `constat_technique` avec `consequence_automatique=false`.

La moyenne officielle et le total officiel des crédits ne sont **pas** comparés :
le moteur ne recalcule pas de moyenne et le total de l'année n'est pas le total
requis par le programme. Ils sont exposés dans `faits_non_compares` avec la raison.

### 4.3 Citations, empreinte, anonymisation

- **Citations réelles** : décision (`evaluation_decisions#id`), délibération
  (`evaluation_deliberations#id`), inscription (`inscriptions_academiques#id`) avec
  `verifiee=true` ; références réglementaires déclarées par le moteur et règles
  d'UE avec `origine=moteur_decision` et `verifiee=false` (le texte n'est pas rouvert).
- **Empreinte** : SHA-256 des faits officiels et de la lecture moteur, sans donnée
  identifiante ; `verifierEmpreinte()` détecte toute altération.
- **Anonymisation** : seul `etudiant.id` et un matricule masqué (`IN**********26`)
  sont exposés ; aucun nom, prénom, CIN, email ou téléphone.
- **Garanties déclarées** : `aucune_modification`, `aucune_recommandation`,
  `aucune_sanction`, `aucune_consequence_automatique`,
  `aucune_donnee_identifiante_en_clair`.

---

## 5. Outil Assistant IA

| Propriété | Valeur |
|---|---|
| Nom technique | `expliquer_decision_academique` |
| Mode | `metier` |
| Permission | `assistant_ia.mode_metier` |
| Sensible | `false` (lecture seule, aucune approbation requise) |
| Paramètre | `decision_id` obligatoire |
| Périmètre | Faculté de l'étudiant, à défaut celle de la délibération |
| Fournisseur distant | **Interdit** via `OutilIaDonneesAcademiquesInterface` |

La garde distante est appliquée dans `RegistreOutils` :

- l'outil est retiré des définitions exposées au modèle distant ;
- son exécution distante lève `OutilNonAutoriseException`.

Aucun indicateur de fonctionnalité n'a été ajouté : l'outil n'est atteignable par
aucune route HTTP et reste gouverné par la permission, le périmètre facultaire et
le marqueur distant. Un indicateur serait requis avant toute exposition HTTP.

---

## 6. Preuve de lecture seule

Le test `test_aucune_ecriture_en_base_pendant_l_explication` active le journal de
requêtes pendant l'explication et vérifie :

- **0 requête** `INSERT` / `UPDATE` / `DELETE` / `REPLACE` / `ALTER` / `DROP` / `TRUNCATE` ;
- comptages identiques avant/après pour `evaluation_decisions`, `notes`,
  `inscriptions_academiques` et `etudiants`.

Le moteur d'acquis lui-même est documenté et testé comme lecture seule (LOOP 16).

---

## 7. Limites assumées

1. La lecture du moteur agrège toutes les notes de l'étudiant (capitalisation à vie) :
   la comparaison chiffrée est marquée non comparable dès qu'il existe plusieurs
   inscriptions ou des notes hors inscription.
2. Les références réglementaires citées proviennent du moteur et ne sont pas
   recoupées avec le registre `regles_academiques_versions` (LOOP 44, vide en réel).
3. Une délibération publiée mais non verrouillée produit une alerte d'intégrité,
   pas un refus : l'acte peut encore évoluer.
4. Aucune route, vue ou notification : l'outil est exerçable uniquement via le
   registre Assistant IA (ou en test).
5. Les autres outils IA exposant des données académiques n'ont pas été rétro-équipés
   du marqueur distant (hors périmètre de cette boucle).
6. `VerifierReglesEvaluationTool` contient des seuils codés en dur non traçables au
   registre officiel ; signalé, non modifié dans cette boucle.

---

## 8. Conclusion technique

Le dispositif est **complet, déterministe et non décisionnaire**. Il produit une
explication vérifiable, anonymisée et strictement en lecture seule, refuse toute
situation incertaine sans sanctionner, et interdit l'exfiltration de données
académiques vers un fournisseur d'IA externe.

Le droit d'expliquer une décision réelle dépend néanmoins de la cohérence des
données de publication : en base réelle, 9 décisions publiées sur 14 sont
expliquables ; 5 sont refusées fail-closed car rattachées à une délibération au
statut non publié (`prepared`, `validee`).
