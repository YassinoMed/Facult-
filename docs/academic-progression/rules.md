# Règles métier — progression LMD

Source unique : décision jury **publiée** liée à l'inscription source
(`decision_absente → bloque`). Référence niveau = inscription source
(`niveauRef`), jamais `etudiants.niveau` legacy.

| Décision | Action | Cible |
|---|---|---|
| `admis`, `admis_compensation` | `progresser` | niveau `ordre` supérieur même programme |
| `admis_conditionnel` | `progresser` | idem (conditions = jury, pas de règle ajoutée) |
| `ajourne`, `rattrapage`, `redouble` | `redoubler` | même niveau, année suivante |
| `exclu`, `a_examiner` | `bloque` | rien |
| admis + dernier niveau programme | `fin_cycle` | RIEN (admission externe requise) |
| admis* + cibles explicites | `progresser` | programme/niveau fournis (cohérence vérifiée) |
| non-admis + cibles explicites | `bloque` | refusé |

- Année cible : `suivante()` (dates), repli `active()` ; aucune → blocage.
- Idempotence : inscription existante (étudiant, année) retournée telle quelle.
- Inter-programme (L3→M1, M1→M2 autre mention) : JAMAIS deviné ; `transfert`/
  `rattacher` existants pour le dossier, cibles explicites pour l'inscription.
- Suspension : `reinscrire()` refuse (règle existante conservée).
- Redoublement ≠ échec : même niveau, nouvel historique, traçabilité complète.
