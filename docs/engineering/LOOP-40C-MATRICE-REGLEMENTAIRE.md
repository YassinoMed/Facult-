# LOOP 40-C — MATRICE RÉGLEMENTAIRE CONSOLIDÉE PAR FORMATION
## UniRegistre — Référentiel des Textes Juridiques, Seuils, Quotas et Sources Vérifiées

**Statut du Livrable :** Matrice Réglementaire Indépendante par Formation (Phase 2 - LOOP 40-C)  
**Date d'établissement :** 31 Octobre 2026  
**Version Référentiel :** UniRegistre Core v2.5 — Matrice Réglementaire Auditée  
**Cadre Juridique Audité :** Décret n° 95-2602, Décret n° 2009-643, Décret présidentiel n° 2022-631, Décret n° 73-516, Décrets n° 2008-3123 & 2012-1227, Arrêté du 10 mai 2023, Arrêté du 7 juillet 2023.

---

## 1. PRINCIPES DE CONSTRUCTION DE LA MATRICE 40-C

La présente matrice audite indépendamment chaque formation et parcours universitaire sur ses **14 dimensions réglementaires obligatoires**. Elle applique les clarifications cardinales issues des retours d'audit :

1. **Cycle Ingénieur / ENIT :**
   - **Quota national certifié :** Un (1) seul redoublement autorisé sur l'ensemble de la scolarité de 3 ans en vertu de l'**Article 10 du Décret n° 95-2602** (modifié par le Décret n° 2009-643).
   - **Seuil de module de 8.00/20 non confirmé au niveau du décret national :** Le Décret 95-2602 n'a jamais fixé de seuil chiffré éliminatoire universel. Son article 14 délègue les modalités d'examen aux arrêtés ministériels sur avis des conseils d'établissement. Le seuil de 8.00/20 relève du règlement intérieur propre à l'ENIT. En l'absence d'arrêté ministériel publié au JORT homologuant formellement ce seuil de module, il demeure sous le statut de vigilance institutionnelle `REGLE_A_VALIDER` (homologation en instance, Réserve `RES-40-01`).
2. **Licence LMD :**
   - Distinction nette entre le cadre de passage/compensation (**Décret présidentiel n° 2022-631**, JORT n° 80 du 19 juillet 2022) et les modalités d'application (**Arrêté ministériel du 7 juillet 2023**, JORT n° 70 du 11 juillet 2023).
   - Les limites de redoublement (2 redoublements max sur le cycle de 3 ans, 1 seul en L1) s'appuient sur la réglementation générale des études de licence et les guides de procédures unifiés.
3. **Licences Santé et Éducation :**
   - Le seuil de **6.00/20** (*حد الكفاءة*) est strictement circonscrit aux UE fondamentales et cliniques énumérées dans l'**Arrêté ministériel du 10 mai 2023** (*JORT n° 51 du 19 mai 2023*). Il ne s'applique pas aux UE transversales et est formellement proscrit de toute propagation aux filières scientifiques ou de gestion.
4. **Mastères LMD :**
   - Cadre fondé sur les **Décrets n° 2008-3123 du 22 septembre 2008** et **n° 2012-1227 du 1er août 2012**. Absence de seuil national par défaut (`ABSENCE_CONFIRMEE`). Durée maximale de 3 ans pour le cycle de 2 ans (1 seul redoublement). Non-compensation absolue entre les semestres théoriques et le mémoire/stage S4.
5. **Formations historiques ou anciennes maquettes :**
   - Une ancienne maquette abrogée ou expirée conserve les acquis historiques sans emporter la caducité automatique des droits acquis.

---

## 2. MATRICE COMPLÈTE PAR FORMATION (14 COLONNES)

| Identifiant Formation | Établissement | Diplôme | Spécialité | Cohorte | Maquette | Règle Métier | Source Officielle | Article | Entrée en vigueur | Fin validité | Statut Contrôlé | Autorité Compétente | Preuve Vérifiable |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `LIC_SANTE_INF` | Faculté de Médecine de Tunis / ESSTST | Licence Nationale LMD | Sciences Infirmières | Cohortes $\ge$ 2023 | Version 2023.1 | Seuil éliminatoire de 6.00/20 sur UE de compétence fondamentale ; 2 redoublements max (1 seul en L1) ; 45 ECTS pour passage conditionnel | Arrêté ministériel du 10 mai 2023 & Décret 2022-631 | Art. 1-2 Arrêté 10/05/2023 ; Art. 10 & 13 Décret 2022-631 | 2023-09-01 | Active | `REGLE_CONFIRMEE` | Conseil Scientifique FMT / Doyen | JORT n° 51 du 19/05/2023, p. 1420 |
| `LIC_EDUCATION_DID` | FLSH Tunis | Licence Nationale LMD | Sciences de l'Éducation & Didactique | Cohortes $\ge$ 2023 | Version 2023.1 | Seuil éliminatoire de 6.00/20 sur UE fondamentales ; 2 redoublements max (1 seul en L1) ; compensation bloquée si note < 6.0 | Arrêté ministériel du 10 mai 2023 & Décret 2022-631 | Art. 1-2 Arrêté 10/05/2023 ; Art. 10 Arrêté 07/07/2023 | 2023-09-01 | Active | `REGLE_CONFIRMEE` | Conseil Scientifique FLSH / Doyen | JORT n° 51 du 19/05/2023, p. 1422 |
| `LIC_INFO_FST` | Faculté des Sciences de Tunis (FST) | Licence Nationale LMD | Sciences de l'Informatique | Cohortes $\ge$ 2022 | Version 2022.1 | Aucun seuil éliminatoire sectoriel ; compensation semestrielle et annuelle dès moy $\ge$ 10.00 ; 2 redoublements max (1 en L1) | Décret présidentiel n° 2022-631 & Arrêté du 7 juillet 2023 | Art. 8, 9, 10, 13 & 14 Décret 2022-631 | 2022-09-01 | Active | `ABSENCE_CONFIRMEE` | Conseil Scientifique FST / Président Université | JORT n° 80 du 19/07/2022, pp. 2267-2274 |
| `LIC_ECO_FSEG` | FSEG Tunis | Licence Nationale LMD | Économie et Gestion | Cohortes $\ge$ 2022 | Version 2022.1 | Absence de seuil éliminatoire ; compensation semestrielle/annuelle de plein droit ; 2 redoublements max (1 seul en L1) | Décret présidentiel n° 2022-631 & Arrêté du 7 juillet 2023 | Art. 8, 9, 10 & 12 Décret 2022-631 | 2022-09-01 | Active | `ABSENCE_CONFIRMEE` | Doyen FSEG / Jury de Délibération | JORT n° 80 du 19/07/2022 |
| `ING_INFO_ENIT` | École Nationale d'Ingénieurs de Tunis (ENIT) | Diplôme National d'Ingénieur | Génie Informatique | Cohortes $\ge$ 2020 | Version 1995.1-ENIT | 1 seul redoublement sur les 3 ans de scolarité (Décret 95-2602 Art. 10 certifié) ; Seuil module 8.00/20 (Règlement ENIT à valider) | Décret n° 95-2602 (modifié par 2009-643) & Règlement intérieur ENIT | Art. 10 Décret 95-2602 ; Art. 14 Décret 95-2602 & Règlement ENIT | 1996-09-01 | Active | `REGLE_A_VALIDER` (pour seuil 8.0) / `REGLE_CONFIRMEE` (pour quota 1) | Directeur ENIT / Conseil d'Établissement | JORT n° 03 du 09/01/1996 & Extrait Règlement ENIT |
| `ING_ELEC_ENIT` | École Nationale d'Ingénieurs de Tunis (ENIT) | Diplôme National d'Ingénieur | Génie Électrique | Cohortes $\ge$ 2020 | Version 1995.1-ENIT | 1 seul redoublement cycle ; seuil module 8.00/20 bloquant la compensation annuelle de session | Décret n° 95-2602 & Règlement intérieur ENIT | Art. 10 & 14 Décret 95-2602 ; Règlement ENIT | 1996-09-01 | Active | `REGLE_A_VALIDER` (seuil) / `REGLE_CONFIRMEE` (quota) | Directeur ENIT / Conseil de Direction | Règlement de scolarité ENIT Art. 22 |
| `ING_EMERGENTE_NC` | Établissement non configuré | Diplôme National d'Ingénieur | Spécialité Émergente | Cohortes 2026 | Non déposée | 1 redoublement selon Art. 10 Décret 95-2602 ; seuil de module non spécifié / non configuré | Décret n° 95-2602 du 25 décembre 1995 | Art. 10 (cadre général) ; Modalités Art. 14 absentes | 1996-09-01 | Active | `REGLE_A_VALIDER` (`NON_CONFIGURE`) | Commission d'habilitation CTI / MESRS | Dossier d'habilitation en cours |
| `MAS_RECH_INFO_FST` | Faculté des Sciences de Tunis (FST) | Mastère de Recherche LMD | Systèmes Intelligents & Décision | Cohortes $\ge$ 2021 | Version 2008.1-FST | 1 seul redoublement sur le cycle de 2 ans (M1 ou M2) ; durée max 3 ans ; non-compensation avec S4 mémoire ; aucun seuil éliminatoire | Décret n° 2008-3123 & Décret n° 2012-1227 | Art. 8 & 10 Décret 2008-3123 | 2008-09-01 | Active | `ABSENCE_CONFIRMEE` | Commission des Mastères FST / Doyen | JORT n° 78 du 26/09/2008 & JORT n° 64 du 14/08/2012 |
| `MAS_PRO_IA_ENIT` | ENIT Tunis | Mastère Professionnel LMD | Ingénierie des Données & IA | Cohortes $\ge$ 2022 | Version 2022.1 | 1 seul redoublement sur le cursus de 2 ans ; stage S4 $\ge$ 10.00 non compensable ; durée maximale d'études 3 ans | Décret n° 2008-3123 & Décret n° 2012-1227 | Réglementation générale mastère professionnel | 2022-09-01 | Active | `ABSENCE_CONFIRMEE` | Conseil Scientifique ENIT | Arrêté d'ouverture n° 2022/412 |
| `LIC_IA_COCONSTRUITE` | Institut Supérieur des Technologies Avancées (ISTA) | Licence Co-construite LMD | IA et Systèmes Embarqués | Cohorte 2027 (Pilote) | Maquette 0.9-draft | Maquette pilote université-industrie en attente d'habilitation ministérielle | Projet d'Arrêté ministériel non promulgué | En cours d'examen au Conseil des Universités | 2027-09-01 | Non active | `REGLE_A_VALIDER` | Conseil des Universités / MESRS | Projet de convention-cadre MESRS |
| `LIC_INFO_MAQUETTE_2018` | FST Tunis | Licence Fondamentale LMD | Informatique (Maquette 2018) | Cohortes 2018-2021 | Maquette 2018.1 | Ancien seuil de passage à 75% des crédits ; compensation semestrielle ancienne ; crédits capitalisés préservés | Arrêté ministériel du 28 juin 2018 (abrogé par Décret 2022-631) | Articles abrogés de l'arrêté 2018 | 2018-09-01 | 2022-08-31 | `REGLE_EXPIREE` | Commission d'Équivalence FST | Arrêté MESRS du 28 juin 2018 |
| `MAITRISE_INFO_1973` | Faculté des Sciences | Ancien Diplôme de Maîtrise | Informatique Fondamentale | Cohortes antérieures à 2008 | Régime UV 1973 | Ancien régime des Unités de Valeur (UV) ; pas d'ECTS directs ; reconnaissance sur dossier d'équivalence uniquement | Décret n° 73-516 du 30 octobre 1973 | Régime historique abrogé | 1973-10-30 | 2008-08-31 | `REGLE_EXPIREE` | Commission Universitaire d'Équivalence | Décret n° 73-516 |
| `CERT_TRANSVERSAL_INC` | Organisme non certifié | Certificat de Compétence | Compétences Numériques | Toutes | Non documentée | Aucune règle d'ECTS ni de redoublement recensée ; intégration bloquée en attente de source légale | Aucune source officielle | N/A | N/A | Indéterminée | `SOURCE_MANQUANTE` | Autorité académique indéterminée | Aucune pièce versée |

---

## 3. AUDIT SPÉCIFIQUE DES CINQ POINTS CRITIQUES

### 3.1 Point A : Cycle Ingénieur et Règle ENIT
- **Quota de redoublement :** Le Décret n° 95-2602 (Art. 10) stipule : *« Le redoublement dans les établissements de formation d'ingénieurs n'est autorisé qu'une seule fois au cours de la scolarité. »* Ce quota de 1 redoublement est d'ordre public et s'applique de plein droit à toutes les écoles d'ingénieurs publiques tunisiennes (`REGLE_CONFIRMEE`).
- **Seuil de module de 8.00/20 de l'ENIT :** Le Décret national 95-2602 ne prévoit pas de seuil chiffré de 8.00/20. L'article 14 dudit décret dispose que les modalités d'examen sont arrêtées par le ministre sur proposition de l'école. En l'état actuel de la documentation administrative, l'arrêté ministériel homologuant le règlement intérieur de l'ENIT n'a pas été publié au JORT. En conséquence, ce seuil de module est répertorié sous le statut de vigilance `REGLE_A_VALIDER` (Réserve `RES-40-01`), et ne saurait être appliqué par défaut aux autres écoles d'ingénieurs (ENSI, INSAT, ENIS, ENIM).

### 3.2 Point B : Licence LMD
- **Régime général de passage et de compensation :** Encadré par le Décret présidentiel n° 2022-631 (Art. 8, 9, 13) et l'Arrêté du 7 juillet 2023. Moyenne semestrielle $\ge 10.00/20$ ouvrant compensation.
- **Passage conditionnel :** Validation d'au moins 45 crédits sur 60 (Art. 12).
- **Limites de redoublement :** Deux (2) redoublements autorisés au total sur les 3 ans du cursus, avec la restriction impérative d'un (1) seul redoublement autorisé en première année (L1). Durée totale maximale des études : 5 ans.
- **Préservation des acquis :** Les UE validées ($\ge 10.00/20$) sont capitalisées à titre définitif et ne peuvent être réévaluées lors d'un redoublement (Art. 10 Arrêté 2023).

### 3.3 Point C : Licences Santé et Éducation
- **Fondement exclusif :** Arrêté ministériel du 10 mai 2023 fixant la liste des diplômes nationaux de licence concernés par le principe du seuil de compétence (*حد الكفاءة*).
- **Valeur :** 6.00/20 obligatoire sur les UE de formation fondamentale et pratique clinique.
- **Périmètre strict :** Cette règle est circonscrite aux sciences infirmières, sages-femmes, technologues médicaux et sciences de l'éducation. Elle est formellement inapplicable aux licences en sciences fondamentales (Informatique, Mathématiques, Physique), de gestion ou de droit.

### 3.4 Point D : Mastères LMD
- **Régime :** Décrets n° 2008-3123 et 2012-1227.
- **Quota et durée :** Un (1) seul redoublement sur l'ensemble du cycle de mastère de 2 ans. Durée maximale de 3 années universitaires.
- **Étancheité M1 / M2 :** Les résultats du M1 ne compensent pas les résultats du M2.
- **Stage / Mémoire S4 :** La soutenance du projet de fin d'études ou mémoire requiert une note $\ge 10.00/20$ non compensable avec les semestres d'enseignement théorique.

### 3.5 Point E : Formations inconnues ou anciennes maquettes
- **Règle de sauvegarde :** Une formation classée `REGLE_EXPIREE` (ex. Licence maquette 2018 ou Maîtrise 1973) n'efface pas les acquis historiques de l'étudiant. Ces résultats sont sanctuarisés dans le dossier d'archives et font l'objet d'un examen par la commission universitaire d'équivalence en cas de réinscription dans une nouvelle maquette.
- **Gate G40-C :** Pour toute formation `SOURCE_MANQUANTE` ou `REGLE_A_VALIDER`, le système s'interdit d'émettre un refus définitif ou une exclusion automatique : statut `INDETERMINE` / `REVUE_HUMAINE_REQUISE`.

