# LOOP 41 — DRY-RUN REPRODUCTIBLE DE MIGRATION HISTORIQUE

**Date d'exécution :** 31 octobre 2026
**Environnement :** `local` — MySQL `inscription_etudiants` (`127.0.0.1:3306`)
**Mode :** `LECTURE_SEULE_STRICTE`
**Commande :** `php artisan acquis:dry-run-migration --json`
**Code de sortie :** `0`
**Durée mesurée :** 11,09 s (temps réel, 2 152 dossiers)
**Artefact brut :** `storage/app/loop41/dry-run-full.json`

> Ce document remplace l'absence de rapport de dry-run constatée à l'ouverture de la LOOP 42.
> Il ne contient aucun résultat inventé : toutes les valeurs proviennent de l'exécution ci-dessus.

---

## 1. Périmètre exécuté

| Élément | Valeur observée |
|---|---|
| Source de données | base locale `inscription_etudiants` |
| Filtre faculté | aucun (toutes facultés) |
| Filtre programme | aucun |
| Limite | 0 (tous les dossiers) |
| Dossiers simulés | **2 152** |
| Erreurs techniques | **0** |

Le service exécuté est `App\Services\Acquis\SimulationMigrationHistoriqueService`, version de moteur
déclarée dans le rapport : `SimulationMigrationHistoriqueService@loop41`.

---

## 2. Répartition des statuts de migration

| Statut | Dossiers | Part |
|---|---:|---:|
| `MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME` | 2 018 | 93,77 % |
| `MIGRABLE_APRES_VALIDATION` | 0 | 0,00 % |
| `REVUE_HUMAINE_REQUISE` | 134 | 6,23 % |
| `SOURCE_INSUFFISANTE` | 0 | 0,00 % |
| `BLOQUE_DONNEES` | 0 | 0,00 % |
| `HORS_PERIMETRE` | 0 | 0,00 % |
| **Total** | **2 152** | **100 %** |

### Lecture critique de ce tableau

Le statut `MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME` **ne signifie pas** que 2 018 dossiers
possèdent des crédits à transférer. Le rapport mesure séparément :

| Indicateur | Valeur |
|---|---:|
| Dossiers avec au moins un crédit reconnu | **42** |
| Crédits totaux reconnus (simulation) | **1 230** |
| Dossiers sans aucun crédit reconnu | **2 110** |

Autrement dit, 2 018 dossiers sont « migrables » parce qu'ils n'ont **rien à transférer** (aucune note
validante). Cette classification est sans effet matériel, mais elle ne doit jamais être présentée
comme une validation de masse.

---

## 3. Anomalies et avertissements

| Type | Occurrences |
|---|---:|
| Notes rattachées à des matières orphelines (`ue_id IS NULL`) | **34 dossiers** |
| Aucune inscription académique répertoriée | **100 dossiers** |
| **Total anomalies** | **134** |
| Avertissements `ANOM-41-02` | **0** |

### Pourquoi zéro avertissement ANOM-41-02 ?

Le contrôle ajouté en LOOP 41 (voir `LOOP-41-RECONCILIATION.md`) déclenche un avertissement lorsqu'un
dossier possède des crédits à transférer **et** aucun acte officiel de décision. Or :

| Contrôle | Valeur observée |
|---|---:|
| Étudiants avec au moins une note ≥ 10 | 42 |
| Étudiants avec une décision textuelle d'origine | 45 |
| Étudiants avec une décision normalisée publiée | 14 |
| **Étudiants avec crédits mais sans aucun acte** | **0** |

Les 42 étudiants porteurs de crédits disposent tous d'au moins une trace officielle. Le contrôle est
donc actif mais non déclenché sur le jeu réel.

---

## 4. Couverture réglementaire observée

| Statut de règle retourné par le moteur | Dossiers |
|---|---:|
| `REGLE_CONFIRMEE` | **2 152** |
| `REGLE_A_VALIDER` | 0 |
| `SOURCE_MANQUANTE` | 0 |
| `REGLE_EXPIREE` | 0 |

**Point critique :** ce résultat est obtenu au niveau du **régime national** (LMD Licence, Ingénieur,
Mastère), pas au niveau de la **formation**. Aucun des 12 programmes réels de la base ne porte un code
présent dans `config/acquis_reglementation.php > matrice_formations` (qui contient des fiches
`LIC_SANTE`, `LIC_EDUCATION`, `LIC_INFO_STANDARD`, `ING_INFO_ENIT`, etc.).

Conséquence : la mention `REGLE_CONFIRMEE` ne prouve **pas** qu'une règle de formation (seuil, quota,
compensation locale) est confirmée. Elle prouve seulement que le régime national est identifiable.
Cette distinction est portée par `ReglementationAcquisService::evaluerCouvertureReglementaire()` et
bloque le pilote (voir `LOOP-42-*`).

---

## 5. Contrôle d'intégrité (zéro écriture)

Le rapport embarque un comptage des 13 tables académiques avant et après exécution :

```
etudiants, inscriptions_academiques, notes, matieres, unites_enseignement,
maquettes, evaluation_deliberations, evaluation_decisions, historique_academique,
parcours_dispenses, parcours_equivalences, parcours_plans, archives_etudiants
```

| Contrôle | Résultat |
|---|---|
| Empreinte avant = empreinte après | **OUI** |
| Écritures effectuées | **0** |
| Code de sortie | **0** (échec si modification détectée) |

---

## 6. Reproductibilité

| Exécution | Date | Dossiers | `MIGRABLE_AUTOMATIQUEMENT` | `REVUE_HUMAINE` | Anomalies | Intégrité |
|---|---|---:|---:|---:|---:|---|
| 1 (avant correctif ANOM-41-02) | 31/10/2026 | 2 152 | 2 018 | 134 | 134 | inchangée |
| 2 (après correctif) | 31/10/2026 | 2 152 | 2 018 | 134 | 134 | inchangée |
| 3 (vérification) | 31/10/2026 | 2 152 | 2 018 | 134 | 134 | inchangée |

Les empreintes `empreinte_source` / `empreinte_resultat` sont calculées par SHA-256 canonical JSON et
sont déterministes pour un même état de la base (test `test_exigence8_idempotence_et_reprise_dry_run`).
Le champ `execute_le` varie à chaque exécution : le fichier JSON n'est donc pas identique octet pour
octet, mais la distribution et les empreintes par dossier le sont.

---

## 7. Absence de données personnelles

Le rapport ne contient ni CIN, ni nom, ni prénom. Chaque dossier est identifié par
`DRYRUN-ETU-<16 hex>` = `sha256(etudiant_id|cin)` tronqué. Aucune note nominative n'est exportée :
seuls les agrégats, statuts, justifications et empreintes sont conservés.

---

## 8. Limites explicites

1. Le dry-run **ne prouve pas** la conformité réglementaire : il prouve la cohérence interne de la
   simulation.
2. Le dry-run **ne vaut pas** autorisation de migration.
3. Les maquettes réelles ne sont pas rattachées aux fiches de la matrice réglementaire : la couverture
   est au niveau régime seulement.
4. 100 dossiers sans inscription et 34 dossiers à matières orphelines restent non traitables
   automatiquement.
