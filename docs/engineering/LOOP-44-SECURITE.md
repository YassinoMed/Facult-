# LOOP 44 — SÉCURITÉ, CONTRÔLES D'INTÉGRITÉ ET AUDIT DES ACTES
## UniRegistre — Défense en profondeur, imperméabilité aux fraudes et journalisation append-only

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Governance Team  
**Statut :** AUDITÉ ET CONFORME (PHASE 8 - LOOP 44)  
**Référence contractuelle :** LOOP 44 — Phase 8 & 10  

---

## 1. Vecteurs d'Attaque et Contre-Mesures Certifiées

La gouvernance des règles académiques constitue une cible critique : toute falsification de seuil permettrait l'obtention indue de diplômes ou de validations d'UE. Le système applique une défense en profondeur multi-niveaux :

| Vecteur d'Attaque Testé | Risque Associé | Contre-Mesure Implémentée | Preuve par Test |
|---|---|---|:---:|
| **1. Altération SQL Directe d'une Règle Publiée** | Modification malveillante d'un seuil de 10 à 8 en base de données. | Empreinte canonique SHA-256 scellée à la création. Recalcul par `verifierCoherence()` qui détecte `CHECKSUM_INVALIDE`. | `test_verifier_coherence_detecte_un_checksum_invalide` |
| **2. Modification via Modèle Eloquent d'une Règle Active** | Script ou code interne essayant de modifier `date_effet` ou `contenu`. | Garde `static::updating()` levant une `DomainException` dès qu'un champ figé est altéré. | `test_06_detection_d_une_modification_non_autorisee` |
| **3. Auto-Approbation par l'Auteur (Violation 4 Yeux)** | Un rédacteur tente d'approuver sa propre proposition de règle. | Vérification stricte `(int)$regle->cree_par_id === (int)$approbateur->id` qui lève une exception immédiate. | `test_approbation_quatre_yeux_interdit_a_l_auteur` |
| **4. Auto-Publication par l'Auteur** | L'auteur tente de publier sa propre règle même après approbation tierce. | Contrôle `(int)$regle->cree_par_id === (int)$acteur->id` interdisant la publication directe. | `test_publication_interdite_a_l_auteur_meme_apres_approbation` |
| **5. Publication sans Approbation Préalable** | Tentative de forcer l'état `PUBLIEE` depuis un statut `BROUILLON`. | Contrôle `statut !== STATUT_APPROUVEE` rejetant la requête en `DomainException`. | `test_04_refus_de_publication_sans_validation` |
| **6. Chevauchement Temporel de Versions Publiées** | Deux règles publiées actives simultanément créant une incertitude de calcul. | Contrôle d'intersection temporelle `intervallesSeChevauchent()` rejetant la publication. | `test_publication_refuse_un_chevauchement_avec_une_version_publiee` |
| **7. Suppression d'une Règle Historique** | Effacement de traces pour masquer une décision passée litigieuse. | Garde `static::deleting()` interdisant la suppression de tout enregistrement non brouillon. | `test_version_publiee_est_immuable_et_non_supprimable` |
| **8. Rejet des Conflits Documentaires** | Application arbitraire d'un texte local contredisant un décret. | Détection de sources contradictoires renvoyant `REVUE_JURIDIQUE_REQUISE`. | `test_08_detection_des_conflits` |
| **9. Cloisonnement Multi-Facultés** | Gestionnaire FSEG tentant de modifier une règle FST. | Contexte `faculte_id` vérifié dans le modèle et isolation RBAC. | `test_14_isolation_facultaire` |
| **10. Absence de Rétroaction Non Autorisée** | Une nouvelle version modifie les acquis passés. | Sélection temporelle stricte par date d'événement ; l'audit passé consomme toujours la version d'origine. | `test_12_absence_de_recalcul_retroactif_automatique` |

---

## 2. Séparation RBAC des Permissions Académiques

Les profils de sécurité définis dans [`App\Support\Permissions`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Support/Permissions.php) garantissent une stricte ségrégation des privilèges :

```
Rédacteur Scolarité     : [regles.rediger, regles.soumettre, regles.consulter]
Direction des Études   : [regles.approuver, regles.consulter]
Autorité de Publication : [regles.publier, regles.retirer, regles.consulter]
Auditeur / Contrôle     : [regles.consulter]
```

- Aucun rôle unique ne cumule les privilèges de rédaction (`regles.rediger`) et de validation/publication (`regles.approuver`, `regles.publier`).
- Tout acteur intervenant sur un statut laisse son identifiant immuable (`cree_par_id`, `approuve_par_id`, `publie_par_id`, `retire_par_id`) et son horodatage précis.

---

## 3. Journalisation Append-Only et Protection de la Vie Privée (Zero PII)

Toute transition d'état et tout contrôle d'intégrité sont tracés de manière append-only :
- **Absence de PII** : Les logs ne consignent aucun matricule étudiant, aucun nom de personne physique, ni adresse IP nominative.
- **Identifiants Techniques Découplés** : Seuls les identifiants techniques opaques (`user_id`, `regle_code`, `version`, `checksum`, `statut`) et les motifs institutionnels sont conservés.
- **Inaltérabilité** : Les traces d'audit de gouvernance ne peuvent être ni purgées ni écrasées via les interfaces web standard.

