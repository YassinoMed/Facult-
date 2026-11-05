# LOOP 45 — VERDICT D'ÉTAPE

**Date :** 31 octobre 2026
**Périmètre :** circuit des recours académiques
**Nature du verdict :** technique (livraison et tests) **et** métier (ouverture réelle du circuit)

---

## 1. Verdict

| Dimension | Verdict | Justification |
|---|---|---|
| **Livraison technique** | **`PASS`** | Workflow `recours` v1, service complet, permissions séparées, quatre yeux, garde anti-contournement, 38 tests / 202 assertions, non-régression ciblée 211 tests, Pint propre |
| **Ouverture réelle du circuit** | **`BLOCKED_BUSINESS`** | Aucune référence réglementaire, aucun type de décision contestable, aucun délai, aucun type de demande support, aucune autorité institutionnelle confirmée |
| **Portée actuelle** | **`AUCUNE`** | Le circuit est inactif, non exposé, sans donnée réelle ; aucun résultat académique n'est modifié |

`PASS` porte sur l'outillage et les garanties serveur, **pas** sur la conformité du circuit au
règlement de l'établissement. Aucune règle officielle n'a été inventée.

---

## 2. Livrables

| Livrable | Fichier |
|---|---|
| Configuration fail-closed | `config/recours_academiques.php` |
| Définition de workflow | `app/Services/Workflow/Definitions/WorkflowRecoursAcademiqueDefinition.php` |
| Enregistrement | `app/Services/Workflow/RegistreWorkflow.php` |
| Service métier | `app/Modules/OnlineRequests/Services/RecoursAcademiqueService.php` |
| Garde du transitionneur générique | `app/Modules/OnlineRequests/Services/OnlineRequestService.php` |
| Permissions | `app/Support/Permissions.php` (`recours.*`) |
| Correction moteur | `app/Services/Workflow/MoteurWorkflowService.php` (`forceFill` du champ d'état) |
| Tests | `tests/Feature/OnlineRequests/RecoursAcademiqueLoop45Test.php` |
| Documentation | `LOOP-45-RECOURS-ACADEMIQUES.md`, `LOOP-45-RESULTATS-TESTS.md` |

Aucune migration, aucun contrôleur, aucune route, aucune vue, aucun seed.

---

## 3. Garanties vérifiées

- **Fail-closed total** : circuit inactif, référence absente, type support absent/inactif ou liste de
  décisions contestables vide ⇒ aucun dépôt.
- **Décision publiée uniquement** : la décision et sa délibération doivent être publiées ; la décision
  doit appartenir au demandeur.
- **Aucun rejet automatique** : un dépôt hors délai reste `A_VERIFIER` et exige une décision humaine.
- **Aucun délai inventé** : `delai_depot_jours=null` et `delaiMaxHeures()` toujours `null`.
- **Recevabilité humaine** : référence obligatoire, agent identifié, date tracée.
- **Instruction nominative** : un seul instructeur assigné ; un autre agent ne le supplante pas.
- **Quatre yeux effectif** : l'instructeur assigné ne peut jamais décider, même `super_admin`.
- **Séparation RBAC** : `admin_scolarite` instruit/applique sans décider ; `direction_etudes` décide
  sans instruire/recevoir.
- **Zéro écriture académique** : aucune note, moyenne ou décision de délibération modifiée sur le
  cycle complet.
- **Application manuelle obligatoire** : référence d'application requise avant clôture ; double
  application refusée.
- **Anti-contournement** : le transitionneur générique des demandes refuse tout recours, même circuit
  désactivé.
- **Traçabilité** : audit générique du moteur + événements `online_request.*`, sans motif ni pièce
  recopiés.

---

## 4. Pourquoi l'ouverture réelle reste bloquée

1. **Aucune référence réglementaire** : `reference_reglementaire=null`. Le circuit refuse d'agir sans
   texte officiel.
2. **Aucun type de décision contestable** : `types_decisions_contestables=[]`. Aucune décision réelle
   ne peut être contestée.
3. **Aucun délai de dépôt** : `delai_depot_jours=null`. La recevabilité reste entièrement manuelle.
4. **Aucun type de demande support** : `RECOURS_ACADEMIQUE` n'existe pas en base réelle
   (`type_existe=0`).
5. **Aucune donnée réelle** : `online_requests=0`, donc `recours=0`.
6. **Aucune autorité confirmée** : la répartition `admin_scolarite` / `direction_etudes` est un
   paramétrage technique, pas une désignation institutionnelle.
7. **Aucune exposition** : pas de route, pas de contrôleur, pas de vue ; le circuit n'est exerçable que
   par le service, en test.
8. **Aucune notification** : les événements du module ne sont pas branchés sur le circuit.
9. **Application externe** : l'effet d'un recours approuvé reste un acte manuel hors logiciel, avec
   une référence saisie.

---

## 5. Réserves

| Réserve | Description | Blocage |
|---|---|---|
| **RES-45-01** | Référence du texte officiel non fournie | `BLOCKED_BUSINESS` |
| **RES-45-02** | Liste des décisions contestables non déclarée | `BLOCKED_BUSINESS` |
| **RES-45-03** | Délai de dépôt non fourni ; recevabilité manuelle | `BLOCKED_BUSINESS` |
| **RES-45-04** | Type `RECOURS_ACADEMIQUE` absent de la base réelle | `BLOCKED_BUSINESS` |
| **RES-45-05** | Rôles instruction/décision à confirmer par l'institution | `BLOCKED_BUSINESS` |
| **RES-45-06** | Aucune route, vue ou notification exposée | `BLOCKED_ENVIRONMENT` (périmètre volontairement limité) |
| **RES-45-07** | Application de la décision non intégrée aux notes/délibérations | `BLOCKED_BUSINESS` |
| **RES-45-08** | Absence de test de concurrence sous charge | Réserve technique |
| **RES-45-09** | Modification du moteur partagé (`forceFill`) couverte par les suites ciblées, pas par la suite complète | Réserve technique |

---

## 6. Autorisations et interdictions

### Autorisé

- Exercer le cycle complet sur des données de test explicitement identifiées.
- Auditer le circuit en lecture seule.
- Préparer les routes, vues et notifications dans une étape ultérieure, sans activer le circuit.
- Faire évoluer la configuration dès que les éléments institutionnels sont fournis.

### Interdit

- Activer `recours_academiques.actif` sans référence officielle et sans type support.
- Déclarer un type de décision contestable sans texte.
- Fixer un délai de dépôt sans base réglementaire.
- Décider d'un recours sans instruction préalable ou par l'instructeur assigné.
- Appliquer automatiquement une décision de recours.
- Modifier une note ou une délibération depuis le circuit.
- Exposer le circuit à des étudiants tant que les réserves RES-45-01 à RES-45-05 ne sont pas levées.

---

## 7. Conclusion

Le circuit est **techniquement complet et sûr**, mais **volontairement fermé**. Il ne peut produire
aucun effet réel tant que l'institution n'a pas fourni :

1. le texte officiel et sa référence ;
2. la liste des décisions contestables ;
3. le délai de dépôt, s'il existe ;
4. la désignation des autorités de recevabilité, d'instruction, de décision et d'application ;
5. l'autorisation de créer le type de demande support.

Ces éléments ne peuvent pas être déduits du code ni inventés. Le verdict réel reste donc
`BLOCKED_BUSINESS`, avec une portée `AUCUNE`.

**Prochaine étape :** LOOP 46 — explicabilité des décisions et IA en lecture seule.
