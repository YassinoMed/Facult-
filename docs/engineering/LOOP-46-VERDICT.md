# LOOP 46 — VERDICT D'ÉTAPE

**Date :** 31 octobre 2026
**Périmètre :** explicabilité des décisions académiques publiées et IA strictement en lecture seule
**Nature du verdict :** technique (livraison et tests) **et** métier (explicabilité des décisions réelles)

---

## 1. Verdict

| Dimension | Verdict | Justification |
|---|---|---|
| **Livraison technique** | **`PASS`** | Service d'explication, outil Assistant IA, garde distante, 24 tests / 128 assertions, non-régression ciblée (551 exécutions cumulées, sous-suites recouvrantes incluses), Pint propre |
| **Explication réelle** | **`PASS_WITH_RESERVATIONS`** | 9 décisions publiées sur 14 expliquables ; 5 refusées fail-closed car leur délibération n'est pas publiée ; décalage de statut `validee`/`validated` |
| **Portée actuelle** | **`LECTURE_SEULE`** | Aucune écriture, aucune recommandation, aucune sanction, aucune conséquence automatique ; aucun effet sur les résultats |

`PASS` porte sur l'outillage et les garanties serveur, **pas** sur la conformité du
dispositif au règlement de l'établissement. Aucune règle officielle n'a été inventée :
la décision du jury est reportée verbatim et déclarée souveraine.

---

## 2. Livrables

| Livrable | Fichier |
|---|---|
| Service d'explication | `app/Services/Acquis/ExplicationDecisionAcademiqueService.php` |
| Outil Assistant IA | `app/Modules/AssistantIa/Tools/Metier/ExpliquerDecisionAcademiqueTool.php` |
| Marqueur de données académiques | `app/Modules/AssistantIa/Contracts/OutilIaDonneesAcademiquesInterface.php` |
| Garde fournisseur distant | `app/Modules/AssistantIa/Services/RegistreOutils.php` |
| Enregistrement | `app/Providers/ModuleServiceProvider.php` |
| Tests | `tests/Feature/Acquis/ExplicationDecisionAcademiqueLoop46Test.php` |
| Correction test registre | `tests/Feature/AssistantIa/OutilsMetierEtDeveloppeurTest.php` |
| Documentation | `LOOP-46-EXPLICABILITE-DECISIONS-IA-LECTURE-SEULE.md`, `LOOP-46-RESULTATS-TESTS.md` |

Aucune migration, aucune route, aucun contrôleur, aucune vue, aucun seed.

---

## 3. Garanties vérifiées

- **Fail-closed total** : décision non publiée, délibération absente ou non publiée,
  inscription absente ou incohérente, programme ou régime indéterminé ⇒ aucune
  explication produite et aucun moteur appelé quand la situation est déjà incertaine.
- **Décision du jury souveraine** : la décision est reportée verbatim
  (`portee=acte_souverain_du_jury`, `decision_officielle_fait_foi=true`) ; elle n'est
  jamais requalifiée ni remplacée.
- **Comparaison honnête** : seuls les crédits et les UE sont comparés, et uniquement
  lorsque le périmètre des notes coïncide avec l'inscription ; sinon
  `NON_COMPARABLE` avec raison explicite. Moyenne et total officiels sont exposés
  sans comparaison abusive.
- **Zéro conséquence automatique** : un écart est un `constat_technique`
  (`consequence_automatique=false`), jamais une sanction ou une exclusion.
- **Zéro écriture** : 0 requête SQL d'écriture pendant l'explication, comptages
  inchangés sur les décisions, notes, inscriptions et étudiants.
- **Zéro recommandation / sanction** : garanties déclarées et absence des clés
  interdites dans tout le payload.
- **Anonymisation** : matricule masqué, aucun nom, prénom, CIN, email ou téléphone.
- **Traçabilité** : citations réelles de la décision, de la délibération et de
  l'inscription (`verifiee=true`) ; références moteur explicitement non rouvertes
  (`verifiee=false`) ; empreinte SHA-256 vérifiable.
- **Périmètre facultaire** : un administrateur de faculté n'explique que les décisions
  de sa faculté.
- **IA strictement locale pour ces données** : l'outil est retiré des définitions
  exposées à un fournisseur distant et son exécution distante est refusée.

---

## 4. Pourquoi l'explication réelle reste partielle

1. **Cinq décisions publiées sur délibération non publiée** : en base réelle,
   14 décisions sont publiées, dont 5 rattachées à une délibération au statut
   `prepared` ou `validee`. Le service refuse de les expliquer
   (`DELIBERATION_NON_PUBLIEE`) plutôt que de communiquer un acte non officiel.
2. **Décalage de statut modèle/données** : la base contient `validee` alors que
   `Deliberation::STATUTS` déclare `validated`. Aucun mapping n'a été inventé ;
   l'alignement relève de l'institution et du modèle.
3. **Dix-huit étudiants ont plusieurs inscriptions** : la lecture du moteur agrège
   les notes de toutes les inscriptions (capitalisation à vie). Pour ces dossiers,
   la comparaison chiffrée est marquée non comparable au lieu d'être extrapolée.
4. **Références réglementaires non recoupées** : les règles citées sont celles
   déclarées par le moteur ; leur recoupement avec le registre
   `regles_academiques_versions` (LOOP 44, vide en réel) n'est pas encore possible.
5. **Deux délibérations publiées non verrouillées** : alerte d'intégrité signalée,
   refus non prononcé car l'acte reste publié.

---

## 5. Réserves

| Réserve | Description | Blocage |
|---|---|---|
| **RES-46-01** | 5 décisions publiées rattachées à une délibération non publiée : non explicables en l'état | `BLOCKED_BUSINESS` |
| **RES-46-02** | Statut réel `validee` absent du modèle (`validated`) : alignement institutionnel requis | `BLOCKED_BUSINESS` |
| **RES-46-03** | Lecture moteur cumulée sur plusieurs inscriptions : comparaison chiffrée impossible pour 18 étudiants | `BLOCKED_BUSINESS` (arbitrage d'un mode de lecture par inscription) |
| **RES-46-04** | Références du moteur non recoupées avec le registre officiel des règles (LOOP 44 vide) | `BLOCKED_BUSINESS` |
| **RES-46-05** | `VerifierReglesEvaluationTool` contient des seuils codés en dur non traçables au registre officiel ; signalé, non modifié | Réserve métier (pré-existante) |
| **RES-46-06** | Protection « fournisseur distant » appliquée au nouvel outil uniquement ; les autres outils académiques ne sont pas rétro-équipés | Réserve technique (pré-existante) |
| **RES-46-07** | Aucune route, vue ou notification : l'outil n'est atteignable que via le registre Assistant IA | `BLOCKED_ENVIRONMENT` (périmètre volontairement limité) |
| **RES-46-08** | Aucun test de charge ou de concurrence (dispositif en lecture seule) | Réserve technique |
| **RES-46-09** | Test de registre obsolète (18 attendus vs 19 réels) : corrigé et porté à 20 | Résolu dans cette boucle |

---

## 6. Autorisations et interdictions

### Autorisé

- Expliquer en lecture seule toute décision publiée dont la délibération est publiée.
- Auditer l'explication et son empreinte sans modifier les données.
- Proposer une explication à un utilisateur habilité dans son périmètre facultaire.
- Faire évoluer les données de publication dès que l'institution l'autorise.

### Interdit

- Expliquer une décision non publiée ou une délibération non publiée.
- Présenter l'écart mesuré comme une sanction, une exclusion ou une décision.
- Modifier une note, une décision, une délibération, une inscription ou un statut
  depuis le dispositif d'explication.
- Exposer une donnée identifiante en clair.
- Transmettre le résultat de l'outil à un fournisseur d'IA externe.
- Inventer un seuil, un délai, un quota ou une règle pour « compléter » une explication.

---

## 7. Conclusion

Le dispositif est **techniquement complet, sûr et vérifiable**, et il est
**réellement exerçable en lecture seule** sur 9 des 14 décisions publiées.

Il refuse les 5 situations incohérentes sans sanctionner, marque les comparaisons
non pertinentes sans extrapoler, et n'écrit jamais. Le verdict réel est
`PASS_WITH_RESERVATIONS`, porté par les réserves RES-46-01 à RES-46-04 qui
dépendent de l'institution et non du code.

**Prochaine étape :** LOOP 47 — simulation de planning (aucune publication).
