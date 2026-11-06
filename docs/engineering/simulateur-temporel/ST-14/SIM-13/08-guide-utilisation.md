# SIM-13 — 08 · Guide d'utilisation

**Scénario :** SIM-13 — Crise académique combinée
**Destinataire :** administrateur du module Simulation temporelle

---

## A. Accès

Via le formulaire existant — aucune route, aucun menu, aucune permission
nouvelle.

```
Module Simulation temporelle → Nouvelle simulation → Scénario "Crise académique combinée"
```

---

## B. Paramètres

| paramètre | type | obligatoire | domaine |
|-----------|------|-------------|---------|
| `delta_admissions` | nombre | oui | dans `[bornes.min, bornes.max]` |
| `bornes` | objet | oui | `min ≤ delta ≤ max` |
| `horizon_annees` | entier | oui | `[1, 6]` |
| `taux` (référence) | objet | oui | 3 taux `[0,1]`, somme ≤ 1.0001 |
| `taux_modifie` | objet | oui | 3 taux `[0,1]`, somme ≤ 1.0001 |
| `axe_variation` | chaîne | oui | `passage` \| `redoublement` \| `abandon` |
| `sens_variation` | chaîne | oui | `hausse` \| `baisse` (apparié) |
| `niveau_id` / `groupes` | — | non | périmètre restreint |

**Règles clés** :

- la paire focale doit être **dégradante** : passage→baisse,
  redoublement→hausse, abandon→hausse ;
- **aucune amélioration** : le passage ne peut pas monter, le redoublement
  et l'abandon ne peuvent pas baisser (vs référence) ;
- **au moins deux** axes doivent se dégrader **strictement**.

---

## C. Procédure

1. Ouvrir le module **Simulation temporelle**.
2. Choisir **« Crise académique combinée »** (SIM-13).
3. Saisir les deux jeux de taux :

```
taux (référence)     = {passage: 0.6, redoublement: 0.2, abandon: 0.2}
taux_modifie (crise) = {passage: 0.5, redoublement: 0.3, abandon: 0.2}   ← passage↓ + redoublement↑
axe_variation        = redoublement     (ou passage, ou abandon)
sens_variation       = hausse           (apparié à l'axe)
horizon_annees       = 2
```

4. Lancer la simulation.

### Interprétation

- `totaux` vs `totaux_reference_reussite` : effet joint de la crise.
- `ecarts_reussite_par_periode` / `ecart_reussite_final` : signed.
- `sortants_par_periode` vs `sortants_reference` : diplômés par année.
- `effet_sortants_cumules` : impact cumulé sur les sortants (peut être
  négatif fortement même si les totaux s'équilibrent).

### Cas de validation rejetée (messages)

- *« la paire focale abandon/baisse n'est pas une dégradation »* → paire
  focale non conforme.
- *« passage_modifie supérieur au passage de référence — aucune
  amélioration admise »* → un axe s'améliore.
- *« au moins deux axes doivent se dégrader strictement — une dégradation
  isolée relève de SIM-08, SIM-09 ou SIM-11 »* → crise non combinée.
- *« passage + redoublement + abandon (modifié) > 1 »* → somme incohérente.
- *« … inconnu … »* → clé interdite (gel, choc, cible, creux, alias).

---

## D. Exemple vérifié

```
Base : L1=10, L2=20, L3=30 (60) ; delta = 0 ; H = 2
Référence {0.6, 0.2, 0.2}  → totaux [60, 40, 31] ; sortants [18, 11]
Crise     {0.5, 0.3, 0.2}  → totaux [60, 43, 36] ; sortants [15, 10]
```

Lecture : la crise combinée augmente les totaux à court terme (+5) mais
réduit les sortants de −4 — les redoublants restent dans le système tandis
que le passage vers le diplôme recule.

## E. Exemple de non-additivité (à connaître)

```
Crise {0.4, 0.3, 0.3} (3 dégradations) → totaux [60, 40, 31] (écarts 0 !)
mais sortants [12, 7] vs [18, 11] → effet cumulé −10.
```

Ne pas conclure « aucun effet » à partir des totaux seuls.

---

## F. Questions fréquentes

**Q : Peut-on combiner seulement redoublement↑ + abandon↑ ?**
R : Oui — 2 strictes, passage inchangé ; focal = redoublement ou abandon.

**Q : Peut-on améliorer un axe dans la même simulation ?**
R : Non — une crise combinée n'admet aucune amélioration (règle de domaine).

**Q : SIM-13 modifie-t-il le moteur ?**
R : Non — `VERSION_MOTEUR` reste 1.8 ; SIM-13 est une configuration du
descripteur générique livré par SIM-09.

**Q : Et si la crise inclut un gel ou un choc ?**
R : Interdit — ces profils relèvent de SIM-03/SIM-04 (clés refusées).

---

*Document de référence : `ST-14/SIM-13/08-guide-utilisation.md`.*
