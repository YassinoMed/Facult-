# SIM-03 — 07 · Résultats détaillés des vérifications

**Scénario :** SIM-03 — Gel des admissions  
**Date :** 2026-11-02  
**État mathématique :** 100 % conforme aux cas de référence  

---

## E.1 Concordance des cas de calcul

### Cas 1 : Gel total sur 3 ans (D=3, H=3, delta=0.0, flux gelés)
- Référence théorique :
  - $p=1 : \text{admissions} = 0$, total = 60
  - $p=2 : \text{admissions} = 0$, total = 60
  - $p=3 : \text{admissions} = 0$, total = 60
- Résultat mesuré : `admissions = [0, 0, 0]`, `totaux = [60, 60, 60, 60]`
- Écart : **0**.

### Cas 2 : Gel de 2 ans puis reprise à +10 % sur horizon 4
- Référence théorique :
  - $p=1 : 0$, total = 60
  - $p=2 : 0$, total = 60
  - $p=3 : \text{round}(10 \times 1.1^1) = 11$, total = 71
  - $p=4 : \text{round}(10 \times 1.1^2) = 12$, total = 83
- Résultat mesuré : `admissions = [0, 0, 11, 12]`, `totaux = [60, 60, 60, 71, 83]`
- Écart : **0**.

### Cas 3 : Gel de 2 ans avec déperdition active des cohortes
- Référence théorique :
  - Initial : 60 (L1=10, L2=20, L3=30)
  - Pas 1 : admissions = 0.
    - L3 : 15 sortants diplômés, 9 redoublants
    - L2 : 10 vers L3, 6 redoublants
    - L1 : 5 vers L2, 3 redoublants
    - Bilan cohortes : L1 = 3, L2 = 11, L3 = 19. Total = 33.
    - Écart de conservation : $60 + 0 - 15 - 12 = 33$. Écart : **0**.
  - Pas 2 : admissions = 0.
    - L3 : 10 sortants diplômés, 6 redoublants
    - L2 : 6 vers L3, 3 redoublants
    - L1 : 2 vers L2, 1 redoublant
    - Bilan cohortes : L1 = 1, L2 = 5, L3 = 12. Total = 18.
    - Écart de conservation : $33 + 0 - 10 - 5 = 18$. Écart : **0**.
- Résultat mesuré : `totaux = [60, 33, 18]`, snapshots cohérents au niveau individuel.
- Écart : **0**.

---

## E.2 Synthèse de la validation

Tous les cas limites (interruption stricte à 0, reprise différée, assèchement naturel des cohortes, allègement de tension sur les groupes) ont été confirmés par des tests indépendants sans réutilisation du code du moteur.

