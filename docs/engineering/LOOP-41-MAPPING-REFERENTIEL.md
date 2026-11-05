# LOOP 41 — CARTOGRAPHIE ET MAPPING VERS LE RÉFÉRENTIEL CIBLE
## UniRegistre — Matrice de correspondance, règles de transformation et statuts de mapping

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** AUDITÉ ET VALIDÉ EN SIMULATION  
**Mode :** Zéro Écriture — Détection Préventive des Ambiguïtés  

---

## 1. Principes Directeurs du Mapping Académique

La migration des acquis historiques d'anciens régimes ou maquettes vers le modèle de données unifié UniRegistre obéit aux principes institutionnels suivants :

1. **Aucune équivalence automatique sur ressemblance vague** : deux unités ou matières portant des intitulés proches ne peuvent pas être fusionnées sans validation formelle par une commission pédagogique.
2. **Normalisation canonique des identifiants** : tous les codes d'UE et de matières sont normalisés (majuscules, suppression d'espaces superflus).
3. **Statut univoque par entité** : chaque élément est qualifié par l'un des 5 statuts formels :
   - `MAPPING_CONFIRME` : correspondance certifiée (code exact identique, crédits concordants, programme homologué).
   - `MAPPING_A_VALIDER` : correspondance probable (même intitulé exact dans la même filière mais code historique absent).
   - `MAPPING_AMBIGU` : correspondance douteuse (titres proches avec distance textuelle non nulle, syllabus incertain). Blocage automatique.
   - `MAPPING_IMPOSSIBLE` : aucune contrepartie dans la nouvelle maquette (matière supprimée, rupture de référentiel).
   - `SOURCE_MANQUANTE` : données sources incomplètes interdisant tout calcul.

---

## 2. Matrice Générale de Correspondance des Données

| Entité source (Legacy) | Modèle cible UniRegistre | Règle de transformation | Fondement réglementaire | Statut du mapping |
|---|---|---|---|---|
| `notes.note >= 10.0` (UE/Matière) | `parcours_plans.credits_acquis` / ECTS | Capitalisation définitive de l'élément validé | Décret 2022-631 Art. 8 / Décret 95-2602 Art. 12 | `MAPPING_CONFIRME` |
| `notes.note < 10.0` (Matière compensée) | Non transférable vers nouveau parcours | Maintien en historique local, zéro transfert d'office | Décret 2022-631 Art. 9 | `MAPPING_CONFIRME` |
| `inscriptions_academiques.statut = 'validee'` | `inscriptions_academiques.statut` | Reprise du statut administratif d'inscription | Statuts universitaires nationaux | `MAPPING_CONFIRME` |
| `inscriptions_academiques.decision` (texte) | `evaluation_decisions.decision` | Normalisation du vocabulaire jury (`admis`, `ajourne`, `autorise_a_redoubler`) | PV jury d'origine | `MAPPING_A_VALIDER` |
| Matières orphelines (`matieres.ue_id IS NULL`) | `matieres.ue_id` cible | Nécessite rattachement manuel à une UE de la maquette | Cahier des charges maquettes LMD | `MAPPING_AMBIGU` |
| Formations en co-construction | Référentiel national | Régime spécifique en attente de texte officiel | Réserves RES-40-01 / RES-40-02 | `MAPPING_A_VALIDER` |
| Anciennes maquettes abrogées (ex. 2018) | Maquette LMD 2023 | Règle expirée : orientation commission d'équivalence | Arrêté 7 juillet 2023 | `MAPPING_IMPOSSIBLE` |
| Inscription sans programme identifié | `inscriptions_academiques.programme_id` | Donnée manquante bloquante | Intégrité référentielle | `SOURCE_MANQUANTE` |

---

## 3. Algorithme de Comparaison et de Détection des Ambiguïtés

L'algorithme implémenté dans `SimulationMigrationHistoriqueService::cartographierUe()` procède en 3 niveaux de sécurité :

### Étape 1 — Concordance exacte par code (`MAPPING_CONFIRME`)
- Si `code_source === code_cible` (après normalisation canonique), la correspondance est directe.
- Attributs certifiés : `certifie = true`, `statut_mapping = 'MAPPING_CONFIRME'`.

### Étape 2 — Concordance stricte par intitulé normalisé (`MAPPING_A_VALIDER`)
- Si les codes diffèrent mais que `nom_source === nom_cible` à 100% :
- Statut attribué : `MAPPING_A_VALIDER` (`certifie = false`).
- Motif : *« Concordance exacte d'intitulé mais codes distincts ou absents. Validation pédagogique préalable requise. »*

### Étape 3 — Détection des similarités ambiguës (`MAPPING_AMBIGU` — Bloqué)
- Si les intitulés présentent une similarité textuelle partielle (Levenshtein ou inclusion de sous-chaînes) :
- Exemple : *"Algorithmique et Programmation C"* face à *"Algorithmique et Programmation Java"*.
- Bien que le tronc commun soit *"Algorithmique et Programmation"*, les syllabus et technologies diffèrent.
- Le système refuse formellement l'assimilation automatique :
  - `statut_mapping = 'MAPPING_AMBIGU'`
  - `certifie = false`
  - Motif : *« Similarité textuelle détectée mais libellés non strictement identiques. Risque d'équivalence abusive. Blocage automatique. »*

---

## 4. Écran de Séparation des Données et Préservation du Dossier Source

- Les données sources (`donnees_avant`) demeurent intactes et intouchées dans la base de données.
- Le mapping produit une projection théorique sous forme de structures de données en mémoire.
- Chaque simulation génère un hash cryptographique SHA-256 de la structure de projection (`empreinte_resultat`), garantissant la reproductibilité mathématique sans modifier un seul octet du stockage relationnel.

