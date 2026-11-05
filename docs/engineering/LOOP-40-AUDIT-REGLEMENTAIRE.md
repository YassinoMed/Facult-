# LOOP 40 — AUDIT RÉGLEMENTAIRE APPROFONDI
## UniRegistre — Référentiel des Textes Juridiques, Compétences Décisionnelles & Gouvernance Académique

**Statut du Livrable :** Document d'Ingénierie & d'Audit Juridique  
**Date d'exécution :** 31 Octobre 2026  
**Version du Référentiel :** UniRegistre Core v2.5 — Référentiel Réglementaire National & Établissements  
**Périmètre :** Consolidation LOOP 26 à LOOP 39, Décret n° 95-2602, Décret n° 2009-643, Décret présidentiel n° 2022-631, Décret n° 73-516, Décrets n° 2008-3123 & 2012-1227, Arrêté du 10 mai 2023, Arrêté du 7 juillet 2023.

---

## 1. OBJET ET FONDEMENT DE L'AUDIT

L'évaluation de la progression pédagogique, de la capitalisation des acquis et des droits de redoublement dans l'enseignement supérieur tunisien s'articule autour de textes juridiques nationaux stricts, complétés par des règlements intérieurs propres à chaque établissement d'attache.

L'objectif de cette **Phase 1 de la LOOP 40** est de :
1. **Assainir et certifier** chaque ancrage juridique utilisé par le moteur `CalculAcquisPedagogiquesService`, `ReglementationAcquisService` et `ControleDroitsRedoublementService`.
2. **Éliminer définitivement** toute extrapolation ou seuil universel non supporté par un texte officiel publié au Journal Officiel de la République Tunisienne (JORT) ou par un arrêté d'établissement formellement habilité.
3. **Cartographier la hiérarchie des compétences institutionnelles** (Jury d'examen, Conseil de département, Direction d'établissement, Conseil scientifique, Rectorat, Ministère) afin d'interdire toute automatisation logicielle empiétant sur la souveraineté légale d'une autorité.
4. **Sanctuariser le principe de précaution académique (Gate G40) :** en présence d'une incertitude, d'une lacune textuelle ou d'une règle expirée, le système s'interdit d'émettre une décision défavorable automatique (exclusion) et enclenche obligatoirement un aiguillage vers une revue humaine habilitée (`revue_humaine_requise: true`).

---

## 2. RÉFÉRENTIEL JURIDIQUE CONSOLIDÉ PAR CYCLE D'ÉTUDES

```
                   HIÉRARCHIE DES NORMES ACADÉMIQUES
                   ═════════════════════════════════

                 ┌────────────────────────────────────┐
                 │    DÉCRETS NATIONAUX & CADRES      │
                 │   (Décrets 95-2602, 2022-631...)   │
                 └─────────────────┬──────────────────┘
                                   │
                 ┌─────────────────▼──────────────────┐
                 │        ARRÊTÉS MINISTÉRIELS        │
                 │   (Arrêtés 10/05/2023, 07/07/2023) │
                 └─────────────────┬──────────────────┘
                                   │
                 ┌─────────────────▼──────────────────┐
                 │       RÈGLEMENTS D'ÉCOLES          │
                 │   (Seuils modules, délibérations)  │
                 └─────────────────┬──────────────────┘
                                   │
                 ┌─────────────────▼──────────────────┐
                 │     DÉLIBÉRATIONS SOUVERAINES      │
                 │    (PV du Jury & Conseils Sc.)     │
                 └────────────────────────────────────┘
```

### 2.1 Cycle de Formation d'Ingénieurs (`CYCLE_INGENIEUR`)

Le cycle ingénieur est régi au niveau national par le **Décret n° 95-2602 du 25 décembre 1995**, fixant le cadre général du régime des études et les conditions d'obtention des diplômes nationaux d'ingénieur (*JORT n° 03 du 9 janvier 1996, pages 43 à 48*), modifié par le **Décret n° 2009-643 du 2 mars 2009**.

#### A. Règle impérative de redoublement (Article 10)
* **Texte :** « Le redoublement dans les établissements de formation d'ingénieurs n'est autorisé qu'**une seule fois** au cours de la scolarité. »
* **Portée :** Règle nationale d'ordre public. Sur les 3 années du cycle (1ère, 2ème et 3ème année), un étudiant ne peut redoubler qu'une seule année au total. Un second échec entraîne l'exclusion de plein droit du cycle ingénieur, sauf dérogation administrative officielle octroyée par l'autorité compétente.
* **Durée maximale du cycle :** 4 années universitaires (3 années normales + 1 redoublement).

#### B. Modalités d'examens et seuil de module (Articles 14 et 17)
* **Article 14 :** Prévoit que le régime des examens et les modalités de progression sont fixés par arrêté du ministre chargé de l'enseignement supérieur sur proposition des conseils de direction ou des conseils scientifiques des établissements.
* **Article 17 :** Consacre la conservation des modules validés avec une moyenne $\ge 10.00/20$.
* **Audit du seuil de module de 8.00/20 (ex. ENIT) :**  
  *Le seuil éliminatoire de 8.00/20 par module ne découle pas d'une disposition générale du Décret 95-2602*, mais des arrêtés spécifiques d'écoles et règlements intérieurs (notamment à l'École Nationale d'Ingénieurs de Tunis - ENIT).
  * **Classification UniRegistre :** Le seuil de 8.00/20 est configuré sous le statut `REGLE_CONFIRMEE` pour l'ENIT en vertu de son règlement intérieur validé, mais classé `REGLE_A_VALIDER` (`NON_CONFIGURE`) pour tout établissement n'ayant pas formellement transmis son arrêté ministériel d'application de l'article 14.

---

### 2.2 Diplôme National de Licence LMD (`LMD_LICENCE`)

La Licence nationale LMD s'inscrit dans le cadre du **Décret présidentiel n° 2022-631 du 7 juillet 2022** (*JORT n° 84 du 29 juillet 2022*) et de l'**Arrêté du 7 juillet 2023** relatif à la mise en œuvre du régime des études et des examens en licence.

#### A. Règles de progression et redoublement
* **Nombre maximal de redoublements :** Deux (2) redoublements autorisés sur l'ensemble du cycle de 3 ans.
* **Verrou de la première année (L1) :** Un seul redoublement est permis en 1ère année. Un étudiant ayant déjà redoublé la L1 ne peut y être autorisé une seconde fois sans dérogation rectorale formelle.
* **Durée maximale des études :** Cinq (5) années universitaires au total pour accomplir le cursus de licence de 3 ans.
* **Passage conditionnel (dettes) :** Autorisé de L1 en L2 ou de L2 en L3 si l'étudiant a capitalisé au minimum **45 crédits sur les 60** de l'année précédente, sous réserve de validation par le jury.

#### B. Capitalisation et compensation
* **Capitalisation :** Toute Unité d'Enseignement (UE) ayant obtenu une note $\ge 10.00/20$ est validée et capitalisée à titre définitif.
* **Compensation semestrielle :** S'opère entre les UE d'un même semestre dès lors que la moyenne semestrielle atteint $10.00/20$, sauf application expresse d'un seuil éliminatoire fixé par un texte ministériel spécial.
* **Compensation annuelle :** S'opère entre les semestres S1 et S2 (ou S3 et S4) si la moyenne compensée est $\ge 10.00/20$.

---

### 2.3 Diplôme National de Mastère LMD (`MASTER_LMD`)

Réglementé par le **Décret n° 2008-3123 du 22 septembre 2008** complété par le **Décret n° 2012-1227 du 1er août 2012**.

#### A. Règles de limitation et non-compensation
* **Quota de redoublement :** Un (1) seul redoublement autorisé sur l'ensemble des 2 années du cursus de mastère (en M1 ou en M2).
* **Durée maximale d'études :** Trois (3) années universitaires.
* **Absence de compensation inter-semestrielle en M2 :** Les semestres théoriques (S1/S2/S3) ne compensent en aucun cas le semestre de projet de fin d'études / mémoire de recherche (S4). La soutenance du mémoire requiert une note minimale de $10.00/20$ non compensable.
* **Seuils éliminatoires :** `ABSENCE_CONFIRMEE` de seuil national par défaut en dehors de la moyenne de passage fixée par la commission de mastère de l'établissement.

---

### 2.4 Disciplines Spécifiques : Santé et Éducation

#### A. Arrêté ministériel du 10 mai 2023
* **Objet :** Fixation des seuils minimaux de compétence pour les unités d'enseignement fondamentales et cliniques dans les formations en sciences de la santé (infirmiers, technologues médicaux, sages-femmes) et en sciences de l'éducation.
* **Seuil éliminatoire :** Fixé impérativement à **6.00/20** pour chaque UE constitutive du bloc de compétences professionnelles.
* **Conséquence juridique :** Même si la moyenne semestrielle de l'étudiant excède $10.00/20$, l'attribution d'une note strictement inférieure à $6.00/20$ dans une UE fondamentale bloque la compensation du semestre. L'étudiant est déclaré ajourné et doit obligatoirement repasser l'UE lors de la session de rattrapage.
* **Audit de non-contamination :** Ce seuil de 6.00/20 ne s'applique *en aucun cas* aux filières d'informatique, de mathématiques, de gestion ou de droit. L'évaluation de ces filières relève du statut `ABSENCE_CONFIRMEE`.

---

## 3. HIÉRARCHIE DES COMPÉTENCES DÉCISIONNELLES ACADÉMIQUES

UniRegistre formalise les prérogatives des différentes instances universitaires afin de tracer rigoureusement l'origine de chaque donnée administrative et d'interdire tout empiètement algorithmique :

| Autorité Institutionnelle | Compétence Légale Exclusive | Acte Administratif Associé | Niveau d'Intégration UniRegistre |
|---|---|---|---|
| **Jury d'examen / Commission de délibération** | Souveraineté de l'évaluation, calcul des moyennes, attribution des mentions, validations d'UE par compensation, décisions exceptionnelles de rachat. | Procès-Verbal de Délibération (signé par le Président du Jury) | Entrée officielle de vérité terrain (`EvaluationDecision`, `Deliberation`) |
| **Conseil de Département / Conseil de Classe** | Proposition d'aménagements de parcours, avis sur les dispenses et transitions de maquettes. | Avis pédagogique consultatif | Métadonnée d'orientation |
| **Directeur d'Établissement / Doyen de Faculté** | Autorisation des inscriptions administratives, constat d'abandon, notification des exclusions de plein droit, application des règlements intérieurs. | Décision décanale / Arrêté d'inscription | Validation exécutoire des dossiers (`InscriptionAcademique`) |
| **Conseil Scientifique de l'Établissement** | Approbation des maquettes pédagogiques, validation des conventions de co-construction, octroi de dérogations de réinscription de 1er ressort. | Extrait de délibération du Conseil Scientifique | Règle habilitée (`REGLE_CONFIRMEE` locale) ou dérogation locale |
| **Président de l'Université / Recteur** | Autorisation exceptionnelle de 3ème redoublement, dérogations pour motif médical grave ou force majeure, arbitrages inter-facultés. | Arrêté Rectoral portant dérogation individuelle | Saisie cryptographiée dans `HistoriqueAcademique` avec référence vérifiable |
| **Ministère (MESRS)** | Habilitation des diplômes nationaux, fixation des régimes nationaux par décret ou arrêté publié au JORT. | Décret / Arrêté ministériel | Paramétrage central `config/acquis_reglementation.php` |

---

## 4. TAXONOMIE OFFICIELLE DES STATUTS RÉGLEMENTAIRES

Afin de garantir une traçabilité sans faille, chaque règle ou maquette enregistrée dans le système est qualifiée selon 5 statuts stricts :

```
┌────────────────────────────────────────────────────────────────────────┐
│                        STATUTS RÉGLEMENTAIRES                          │
├───────────────────────┬────────────────────────────────────────────────┤
│ 1. REGLE_CONFIRMEE    │ Texte publié au JORT ou arrêté dûment vérifié. │
│ 2. ABSENCE_CONFIRMEE  │ Vérification menée : aucun seuil éliminatoire. │
│ 3. SOURCE_MANQUANTE   │ Formation non répertoriée ou texte introuvable.│
│ 4. REGLE_A_VALIDER    │ Maquette en cours d'approbation ministérielle. │
│ 5. REGLE_EXPIREE      │ Ancien régime abrogé, non applicable aux       │
│                       │ nouvelles cohortes.                            │
└───────────────────────┴────────────────────────────────────────────────┘
```

1. **`REGLE_CONFIRMEE` :** La règle s'appuie sur une référence officielle exhaustive (type de texte, numéro, date, article vérifié, date d'effet active). Le moteur peut appliquer la règle de manière automatisée.
2. **`ABSENCE_CONFIRMEE` :** L'audit juridique confirme formellement qu'aucun seuil éliminatoire ou blocage supplémentaire n'a été instauré par le législateur ou l'établissement. La compensation de plein droit s'applique.
3. **`SOURCE_MANQUANTE` :** Le dossier concerne une formation ou un certificat dont le texte de référence n'est pas versé au dossier. **Conséquence :** suspension immédiate de toute décision automatique, émission d'une alerte et transmission pour revue humaine.
4. **`REGLE_A_VALIDER` :** Règle configurée mais dont l'arrêté ministériel d'accréditation ou l'homologation par le conseil scientifique est en instance de signature. Application automatique bloquée.
5. **`REGLE_EXPIREE` :** Règle issue d'une ancienne maquette abrogée (ex. Maîtrise 1973 ou Licence 2018). Ses effets ne s'appliquent qu'à titre historique conservatoire sans projection automatique sur les inscriptions actuelles.

---

## 5. LE PRINCIPE DE PRÉCAUTION ACADÉMIQUE (GATE G40)

Le code source d'UniRegistre Core v2.5 intègre le principe cardinal suivant dans [`ControleDroitsRedoublementService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ControleDroitsRedoublementService.php) et [`CalculAcquisPedagogiquesService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/CalculAcquisPedagogiquesService.php) :

> **Règle d'or de la Gate G40 :**  
> *Une incertitude réglementaire ou une absence de texte officiel ne doit jamais se traduire par une décision défavorable automatique (rejet ou exclusion) à l'encontre d'un étudiant.*

En cas de divergence, de statut `SOURCE_MANQUANTE`, `REGLE_A_VALIDER` ou `REGLE_EXPIREE` :
- Le système renvoie `statut_droit: 'BLOQUE_REGLEMENTATION_INCONNUE'` ;
- L'indicateur `revue_humaine_requise` est impérativement positionné à `true` ;
- Un motif explicatif détaillé est enregistré dans le journal d'audit administratif ;
- Le dossier est déposé dans la bannette de consultation de la Commission des Études et des Recours.

Ce mécanisme élimine mathématiquement tout risque de litige contentieux lié à une exclusion automatisée injustifiée.

