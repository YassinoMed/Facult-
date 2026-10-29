# UniRegistre — Examens Advanced — Audit de sécurité et contrôles d'intégrité

- **Date d'audit :** 2026-10-27
- **Statut :** Phase 0 — Inventaire et audit (lecture seule)
- **Périmètre :** Routes, contrôleurs, services, politiques d'accès, permissions, intégrité des données
- **Référentiel :** `docs/engineering/LOOP-UNIREGISTRE-EXAMENS-ADVANCED.md`

---

## 1. Matrice des autorisations et contrôle d'accès (RBAC)

### 1.1 Middlewares globaux du module
Toutes les routes d'administration du module Évaluation sont protégées par le groupe :
```php
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:evaluation'])
```
- `auth` : utilisateur authentifié obligatoire.
- `admin` : rôle administrateur/gestionnaire requis.
- `secondaire.verrou` : protection contre les actions secondaires si session verrouillée.
- `module:evaluation` : 404 automatique si le module est désactivé dans `config/modules.php` (défaut : désactivé hors testing).

### 1.2 Permissions requises vs permissions constatées

| Action sensible | Permission définie | Permission utilisée dans le code | Risque / Écart identifié | Niveau de risque |
|---|---|---|---|---|
| Création d'examen | `evaluations.create` | `can:evaluations.create` | Conforme | Faible |
| Modification d'examen | `evaluations.update` | `can:evaluations.update` | Conforme | Faible |
| Publication d'examen | `evaluations.publish` | `can:evaluations.update` | **Écart** : un utilisateur ayant le droit de modification peut publier sans la permission dédiée `evaluations.publish`. | **Moyen** |
| Publication d'épreuve | `evaluations.publish` | `can:epreuves.update` | **Écart** : la transition vers `published` utilise la permission de mise à jour simple. | **Moyen** |
| Verrouillage de session | `sessions_examen.lock` | `can:sessions_examen.lock` | Conforme | Faible |
| Affectation surveillants | `surveillances.assign` | `can:surveillances.assign` | Conforme | Faible |
| Gestion des contraintes | `surveillances.manage` | `can:surveillances.manage` | Conforme | Faible |
| Génération de documents | `document_batch.run` | `can:evaluations.create` | **Écart** : permission dédiée inutilisée. | Faible |
| Consultation étudiant | Espace étudiant | Session `etudiant_auth_id` | Conforme | Faible |

---

## 2. Analyse Anti-IDOR et étanchéité des périmètres

### 2.1 Périmètre faculté (multi-tenant logique)
- Chaque administrateur peut être restreint à une faculté via `$request->user()->faculteRestreinteId()`.
- Les contrôleurs et services appliquent le scope `pourFaculteId($faculteId)` sur les requêtes d'épreuves, examens et sessions.
- **Vérification salle :** `ExamScheduleEngineService::appliquerScenario` vérifie explicitement que la salle appartient à la même faculté que l'examen :
  ```php
  if ($salle->faculte_id !== null && $faculteId !== null && (int) $salle->faculte_id !== $faculteId) {
      throw new DomainException("Placement refusé : la salle '{$salle->nom}' appartient à une autre faculté.");
  }
  ```
- **Anomalie constatée :** `DocumentBatchController::index()` contient une clause :
  ```php
  SessionExamen::where('faculte_id', $faculteId)
  ```
  Or, la table `evaluation_sessions` ne comporte pas de colonne `faculte_id` (les sessions sont transversales au niveau établissement). Cette requête échoue si `$faculteId` est renseigné.

### 2.2 Espace étudiant : Anti-BOLA / IDOR
- Dans `Etudiant/ExamenController::convocation` :
  ```php
  abort_unless((int) $candidat->etudiant_id === (int) $etudiant->id, 404);
  ```
  Le candidat est impérativement rattaché à l'étudiant connecté. Un étudiant ne peut pas télécharger la convocation d'un autre étudiant.
- **Faille de cycle de vie constatée :**
  ```php
  abort_if(in_array($candidat->epreuve?->statut, ['draft', 'cancelled'], true), 404);
  ```
  Si une épreuve est au statut `planned` ou `scheduled` (non encore `published`), la convocation n'est pas bloquée si l'étudiant soumet directement l'URL. Cela viole la décision D-06 interdisant la convocation avant publication officielle.

---

## 3. Confidentialité des données étudiantes (PII)

### 3.1 Numéros anonymes
- L'architecture attribue un `numero_anonyme` aléatoire par candidat et par épreuve (`Candidat::numero_anonyme`).
- Contrainte d'unicité en base : `unique(epreuve_id, numero_anonyme)`.
- Les listes d'émargement et les procès-verbaux de notes utilisent ce numéro anonyme. La CIN complète n'est jamais exposée dans les logs ou les documents d'examen publics.

### 3.2 Journalisation sans PII
- `IntegrationLog::tracer()` n'enregistre aucun identifiant personnel complet, aucune note d'étudiant, ni aucune donnée biométrique ou OCR.
- Les logs consignent uniquement les références d'épreuve (`EPR-xxx`), d'examen, de document (`EMG-xxx`, `CNV-xxx`), l'acteur et le statut HTTP.

---

## 4. Intégrité des écritures et concurrence

### 4.1 Revalidation stricte côté serveur
Le client ne peut jamais imposer un statut ou une affectation arbitraire :
1. **Transitions d'état :** Matrice d'états immuable `Examen::TRANSITIONS` et `Epreuve::TRANSITIONS`. Toute valeur non autorisée lève une `DomainException`.
2. **Scénarios de planification :** `ExamScheduleEngineService::appliquerScenario` ne fait pas confiance au JSON transmis par le navigateur :
   - Re-vérification que chaque épreuve appartient à l'examen sous verrou `lockForUpdate()`.
   - Contrôle d'unicité de chaque épreuve dans le scénario (refus des doublons).
   - Contrôle d'exhaustivité : refus si une épreuve de l'examen est omise.
   - Contrôle de validité du format de date et d'horaires.
   - Contrôle d'activité et de capacité de la salle.
   - Détection des collisions avec les cours réguliers et autres épreuves.
   - Détection des indisponibilités de salles et surveillants.

### 4.2 Verrouillage transactionnel et rollback
- Toutes les opérations sensibles (planification, émargement, affectation de surveillants, validation de population) sont encapsulées dans `DB::transaction(...)`.
- En cas de collision ou de contrainte dure violée, une exception est levée et aucun changement partiel n'est persisté en base de données.

---

## 5. Vulnérabilités et écarts découverts à corriger

| Réf. | Composant | Vulnérabilité / Écart | Conséquence | Correctif requis |
|---|---|---|---|---|
| **SEC-01** | `Etudiant/ExamenController::convocation` | Vérifie seulement `!in_array(statut, ['draft', 'cancelled'])` | Téléchargement prématuré de convocation pour épreuve `scheduled` | N'autoriser le téléchargement que si `statut === 'published'` (ou ultérieur) |
| **SEC-02** | `ExamenController::transiter` / `EpreuveController::transiter` | Utilise `can:evaluations.update` pour la transition `published` | Publication possible sans la permission dédiée `evaluations.publish` | Exiger `evaluations.publish` pour la transition vers `published` |
| **SEC-03** | `EpreuveService::genererCandidats` | Si `groupe_id` est renseigné, convoque tous les étudiants du groupe sans valider l'inscription pédagogique | Risque de convocation d'un étudiant non inscrit au module | Centraliser le calcul d'éligibilité via `ServiceEligibiliteExamen` (Lot 1) |
| **SEC-04** | `DocumentBatchController::index` | Requête `SessionExamen::where('faculte_id', ...)` | Crash potentiel car la colonne n'existe pas | Adapter le filtrage au modèle réel des sessions |
| **SEC-05** | `ExamenService::transiter` | `estCritique()` bloque la transition vers `ongoing` pour un examen `published` | Blocage du cycle de vie opérationnel | Aligner la méthode `estCritique()` avec `TRANSITIONS` |

---

## 6. Verdict de l'audit de sécurité

- **Contrôles existants :** Protection CSRF active, injection SQL prévenue via Eloquent/PDO paramétré, XSS prévenu via échappement Blade, Anti-IDOR présent sur les vues principales.
- **Écarts identifiés :** 5 points de vigilance documentés ci-dessus (SEC-01 à SEC-05), aucun ne constituant une compromission active, mais tous nécessitant une remédiation dès les lots concernés.
- **Verdict :** `PASS_WITH_RESERVATIONS`
