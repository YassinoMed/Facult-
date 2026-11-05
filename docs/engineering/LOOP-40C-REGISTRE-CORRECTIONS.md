# LOOP 40-C — REGISTRE DES CORRECTIONS DU MOTEUR DE DROITS
## UniRegistre — Assainissement Métier, Contrôle des Dérogations & Taxonomie Décisionnelle

**Statut du Livrable :** Registre Officiel des Corrections (Phases 3 & 4 - LOOP 40-C)  
**Date d'établissement :** 31 Octobre 2026  
**Composant Modifié :** [`ControleDroitsRedoublementService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ControleDroitsRedoublementService.php)  
**Version Système :** UniRegistre Core v2.5-C  

---

## 1. TRAITEMENT EXHAUSTIF DES 10 COMPORTEMENTS DU MOTEUR

Conformément au cahier des charges de la **Phase 3 de la LOOP 40-C**, les dix comportements cibles ont été audités et corrigés dans `ControleDroitsRedoublementService` :

```
             LES 10 COMPORTEMENTS CORRIGÉS DU MOTEUR DE DROITS
             ═════════════════════════════════════════════════

 1. Quota inconnu         ───► Retourne `null`, jamais converti en quota 0
 2. Seuil absent          ───► Pas de constante en dur, renvoie `null`
 3. Règle d'école         ───► Circonscrite à l'établissement (pas d'extension nationale)
 4. Règle expirée         ───► `REVUE_HUMAINE_REQUISE`, fail-closed
 5. Transfert de cycle    ───► Compteurs de redoublement isolés par type de diplôme
 6. Interruption d'études ───► Neutralisée uniquement si reconnaissance officielle établie
 7. Décision du jury      ───► Ne peut outrepasser une loi impérative sans délégation
 8. Dérogation            ───► Audit des 11 attributs cardinaux de sécurité
 9. Cas indéterminé       ───► Statut `INDETERMINE`, aucune exclusion automatique
10. Explicabilité         ───► Motifs détaillés, traçabilité et logs sans PII
```

### Détail des corrections implémentées :

1. **Comportement 1 — Quota inconnu non converti en quota zéro :**  
   Lorsqu'une formation relève d'un régime non configuré ou d'une source manquante (`SOURCE_MANQUANTE`), les clés `limite_redoublements_cycle` et `limite_redoublements_niveau` renvoient désormais strictement `null` (et non `0`). Le statut canonique associé est `INDETERMINE`.
2. **Comportement 2 — Seuil absent non remplacé par une valeur en dur :**  
   Dans `config/acquis_reglementation.php`, la clé `seuil_competence_defaut` pour le cycle ingénieur national a été assainie pour ne plus imposer un seuil chiffré de 8.00/20 par défaut au niveau du décret. Seule une maquette d'école disposant d'un texte d'homologation valide un tel seuil.
3. **Comportement 3 — Règle propre à une école non étendue à d'autres formations :**  
   Le seuil de 8.00/20 pour l'ENIT est strictement cantonné au code formation `ING_INFO_ENIT` (et sous statut `REGLE_A_VALIDER` en attendant publication JORT) et ne contamine aucune autre école (ex. ENSI, INSAT, EPT).
4. **Comportement 4 — Règle expirée non appliquée aux nouvelles cohortes :**  
   Toute règle marquée `REGLE_EXPIREE` renvoie `applicable: false`, basculant le statut vers `REVUE_HUMAINE_REQUISE` pour orienter vers une commission d'équivalence sans calcul automatique.
5. **Comportement 5 — Transfert n'amputant pas les droits du nouveau cycle :**  
   Le filtrage par `typeDiplomeIdCible` et catégorie de régime isole hermétiquement les inscriptions. Un échec en Licence n'impacte pas le quota de redoublement d'un étudiant intégrant ultérieurement un Cycle Ingénieur ou un Mastère.
6. **Comportement 6 — Interruption neutralisée sur reconnaissance officielle uniquement :**  
   Le moteur vérifie que l'observation ou le type d'inscription ne contient aucune mention de refus (`refus`, `injustifié`, `non autorisé`). Seuls les congés académiques et interruptions dument autorisés neutralisent le comptage.
7. **Comportement 7 — Décision de jury ne neutralisant pas une règle impérative :**  
   Si une délibération de jury invoque une décision dérogatoire sans que l'autorité n'ait compétence légale, le service assigne `DECISION_EXCEPTIONNELLE_A_VERIFIER` avec `revue_humaine_requise: true`.
8. **Comportement 8 — Dérogation vérifiée sur l'ensemble de ses composantes :**  
   Implémentation du contrôle multi-critères (périmètre étudiant, diplôme cible, établissement, validité temporelle, compétence et anti-rejeu).
9. **Comportement 9 — Résultat indéterminé non transformé en refus définitif :**  
   Un cas non résolu produit `autorise: false` avec `revue_humaine_requise: true` et `statut_canonique: 'INDETERMINE'`, garantissant l'absence de notification d'exclusion sans instruction préalable.
10. **Comportement 10 — Explicabilité et auditabilité :**  
    Chaque évaluation fournit le motif exact, les références juridiques et les métriques d'inscriptions distinctes.

---

## 2. NOUVELLE TAXONOMIE DES STATUTS CANONIQUES

Le moteur enrichit son retour de la clé standardisée `'statut_canonique'`, coexistant harmonieusement avec `'statut_droit'` pour garantir une rétrocompatibilité intégrale :

| Statut Canonique LOOP 40-C | Statut Droit Historique | Définition Métier & Conséquence | Revue Humaine |
|---|---|---|---|
| **`AUTORISE`** | `AUTORISE_PLEIN_DROIT` / `DEROGATION_ACCORDEE` | Réinscription ou redoublement légalement autorisé (de plein droit ou sur dérogation certifiée). | Non |
| **`NON_AUTORISE_REGLE_CONFIRME`** | `LIMITE_DEPASSEE` | Quota de redoublement épuisé en vertu d'un texte légal certifié (`REGLE_CONFIRMEE`). | Selon recours |
| **`INDETERMINE`** | `BLOQUE_REGLEMENTATION_INCONNUE` | Absence de règle documentée ou quota inconnu (`SOURCE_MANQUANTE`). Décision non définitive. | **Oui** |
| **`REVUE_HUMAINE_REQUISE`** | `BLOQUE_REGLEMENTATION_INCONNUE` / `LIMITE_DEPASSEE` | Règle en cours de validation (`REGLE_A_VALIDER`) ou expirée (`REGLE_EXPIREE`). | **Oui** |
| **`DEROGATION_A_VERIFIER`** | `LIMITE_DEPASSEE` | Dérogation soumise à contrôle (période, autorité, diplôme ou rejeu suspect). | **Oui** |
| **`DECISION_EXCEPTIONNELLE_A_VERIFIER`** | `LIMITE_DEPASSEE` | Décision dérogatoire de jury nécessitant confirmation de compétence institutionnelle. | **Oui** |

---

## 3. AUDIT DU WORKFLOW DE DÉROGATION (PHASE 4)

La méthode `verifierDerogationOfficielle()` contrôle désormais les **11 attributs cardinaux de sécurité** prescrits par le cahier des charges :

```
                  CONTRÔLE DES 11 ATTRIBUTS DE DÉROGATION
                  ═══════════════════════════════════════

  1. identifiant_unique    ───► Identifiant technique ou n° d'acte
  2. beneficiaire_id       ───► Concordance stricte avec $etudiant->id
  3. diplome_cible_code    ───► Concordance avec le programme cible
  4. faculte_cible_id      ───► Isolation inter-facultés (anti-BOLA)
  5. autorite_emettrice    ───► Chef d'établissement / Président d'Université
  6. fondement_competence  ───► Dispositions dérogatoires officielles
  7. reference_acte        ───► Réf. arrêt / PV de commission
  8. date_decision         ───► Date de signature de l'acte
  9. periode_validite      ───► Non expirée et déjà entrée en vigueur
 10. perimetre_autorise    ───► Objet précis (ex: REDOUBLEMENT_CYCLE)
 11. preuve_documentaire   ───► Archive numérique ou document physique
```

### Cas de rejet sécurisé testés :
* **Dérogation expirée :** Rejet avec motif `DEROGATION_EXPIREE`, statut `DEROGATION_A_VERIFIER`.
* **Dérogation hors période (anticipée) :** Rejet avec motif `DEROGATION_HORS_PERIODE`.
* **Dérogation pour un autre étudiant :** Rejet avec motif `PERIMETRE_INVALIDE`.
* **Dérogation pour un autre diplôme :** Rejet avec motif `DIPLOME_INVALIDE`.
* **Dérogation émise hors faculté (anti-BOLA) :** Rejet avec motif `ACCES_INTERFACULTE_INTERDIT`.
* **Tentative de rejeu :** Rejet si la dérogation a déjà été consommée (`REJEU_INTERDIT`).
* **Dérogation révoquée / annulée :** Rejet avec motif `DEROGATION_ANNULEE`.
* **Autorité non habilitée :** Rejet avec motif `AUTORITE_NON_HABILITEE`.

