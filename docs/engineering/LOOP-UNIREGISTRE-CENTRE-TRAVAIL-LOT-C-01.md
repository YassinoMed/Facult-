# LOOP-UNIREGISTRE-CENTRE-TRAVAIL-LOT-C-01
## Rapport d'Ingénierie du Sous-lot C : Contrôle des Inscriptions et Explicabilité Décisionnelle (Lecture Seule)

- **Date :** 23 octobre 2026
- **Projet :** UniRegistre — Plateforme Universitaire Intégrée
- **Programme :** Centre de travail administratif intelligent
- **Sous-lot :** C — Consultation détaillée des inscriptions et évaluation d'éligibilité explicable en lecture seule
- **Référence :** `LOOP-UNIREGISTRE-CENTRE-TRAVAIL-LOT-C-01`
- **Auteurs :** Équipe d'Ingénierie & Architecture Logicielle UniRegistre
- **Verdict :** **`PASS`** (100% des tests validés — sous-lots D à F en attente de revue explicite)

---

## 1. PHASE 0 — AUDIT PRÉALABLE DU CODE RÉEL ET DES SERVICES CANONIQUES

Conformément au protocole, une inspection approfondie du code réel a été réalisée avant tout développement.

### 1.1 Architecture réelle de `InscriptionEligibilityService`
- **Façade canonique :** `InscriptionEligibilityService::evaluer(InscriptionAcademique $inscription): array`
- **Comportement :** Exécution en mode *dry-run* pur sans aucune écriture en base de données (`DB::transaction` ou mutations absentes).
- **Structure réelle de retour :**
  ```php
  [
      'eligible' => bool,
      'verdict' => 'ELIGIBLE' | 'AVERTISSEMENT' | 'BLOCAGE',
      'controles' => [
          'periode' => ['nom' => 'Période d\'inscription', 'statut' => 'OK'|'AVERTISSEMENT'|'BLOQUE', 'message' => string],
          'pieces' => ['nom' => 'Pièces justificatives requises', 'statut' => 'OK'|'BLOQUE', 'message' => string, 'details' => array],
          'anomalies' => ['nom' => 'Moteur de règles d\'anomalies', 'statut' => 'OK'|'AVERTISSEMENT'|'BLOQUE', 'message' => string, 'anomalies' => array],
          'identite_mens' => ['nom' => 'Identité nationale MENS', 'statut' => 'OK'|'AVERTISSEMENT'|'BLOQUE', 'message' => string],
      ],
      'blocages' => string[],       // Motifs bloquants interdisant la validation
      'avertissements' => string[],  // Points d'attention n'interdisant pas formellement l'inscription
      'documents' => array,         // Matrice des pièces GED (obligatoires, soumises, rejetées, manquantes)
      'actions_proposees' => string[], // Suggestions déduites des anomalies sans mutation
  ]
  ```
- **Conformité aux spécifications :** Les trois verdicts canoniques (`ELIGIBLE`, `AVERTISSEMENT`, `BLOCAGE`) sont rigoureusement respectés et ne sont jamais réévalués ou altérés par l'interface.

### 1.2 Inspection des services connexes et de la chaîne de validation
- **`InscriptionService` :** Possède la méthode de mutation `validerInscription(InscriptionAcademique $inscription)`. Elle est strictement isolée et **n'est jamais invoquée** lors du chargement de la consultation ou du diagnostic dans le Sous-lot C.
- **`BlocageInscriptionService` :** Identifie les conditions de gel (financier, disciplinaire, pièces manquantes). Ses vérifications sont appelées de manière non destructive via `evaluer()`.
- **`VerificationEngine` :** Moteur d'anomalies sur les règles académiques (ex. `R12_MENS`, `R01_AGE`, etc.). Utilisé en lecture par `evaluer()`.
- **`CorrectionService` & `DossierCorrection` :**
  - Gère les propositions de modification de données académiques avec règle stricte du workflow à quatre yeux : un agent ne peut pas approuver sa propre proposition (`propose_par !== user_id`).
  - En consultation Sous-lot C, le statut `proposee` est lu pour afficher les propositions en attente et indiquer clairement à l'agent connecté s'il est habilité à statuer (second regard) ou s'il est l'auteur en attente d'un pair.
- **Policies (`InscriptionAcademiquePolicy`, `EtudiantPolicy`) :**
  - Garantissent que la consultation d'un dossier requiert la permission `archives.view` ou `tasks.view`.
  - Assurent le scoping facultaire : un agent rattaché à une faculté ne peut visualiser que les dossiers de sa faculté.

### 1.3 Suivi des réserves du Sous-lot B
- **Alimentation de `terminee_a` :** Vérifié dans `TaskService::completer()`.
- **Couverture des blocages dans les indicateurs :** Les KPIs comptabilisent les délibérations gelées et les inscriptions avec indicateur de blocage (`data->bloque = true`).
- **Isolation facultaire :** Recontrôlée côté serveur sur chaque route (`peutAccederFaculte`).
- **Absence de mutation au chargement :** Démontrée expérimentalement par des captures d'état avant/après requête HTTP.

---

## 2. INVENTAIRE DES FICHIERS CONSULTÉS, MODIFIÉS ET CRÉÉS

### Fichiers consultés
- `app/Services/InscriptionEligibilityService.php`
- `app/Services/InscriptionService.php`
- `app/Services/BlocageInscriptionService.php`
- `app/Services/VerificationEngine.php`
- `app/Services/CorrectionService.php`
- `app/Models/DossierCorrection.php`
- `app/Models/HistoriqueAcademique.php`
- `app/Policies/InscriptionAcademiquePolicy.php`
- `app/Policies/EtudiantPolicy.php`

### Fichiers modifiés
1. [app/Http/Controllers/Admin/CentreTravailController.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/CentreTravailController.php) :
   - Ajout de l'action `show(Request $request, InscriptionAcademique $inscription): View`.
   - Vérification des permissions (`tasks.view` ou `archives.view`).
   - Vérification de l'isolation facultaire stricte (`$utilisateur->peutAccederFaculte()`, retour 404 anti-BOLA si non autorisé).
   - Chargement eager-loading des relations (`etudiant.faculte`, `anneeUniversitaire`, `programmeRef`, `niveauRef`, `documentsInscription`).
   - Appel canonique en lecture seule à `InscriptionEligibilityService::evaluer($inscription)`.
   - Récupération des corrections 4-eyes en attente (`DossierCorrection`), de la tâche associée (`Task`) et du journal d'audit récent (`HistoriqueAcademique`).
2. [routes/web.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/routes/web.php) :
   - Enregistrement de la route `admin.centre-travail.show` :
     `Route::get('centre-travail/{inscription}', [CentreTravailController::class, 'show'])->whereNumber('inscription')->name('centre-travail.show');`
   - Protégée par le groupe d'administration avec `auth`, `admin`, `mfa.obligatoire`, `secondaire.verrou`.
3. [resources/views/admin/centre-travail/index.blade.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/admin/centre-travail/index.blade.php) :
   - Mise à jour des boutons d'action de la file de travail unifiée pour pointer vers `admin.centre-travail.show` lorsqu'une inscription est associée à la tâche.

### Nouveaux fichiers créés
1. [resources/views/admin/centre-travail/show.blade.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/admin/centre-travail/show.blade.php) :
   - Vue de consultation détaillée et explicable, 100% accessible (WCAG 2.1 AA) et responsive :
     - Fil d'Ariane et avertissement institutionnel de consultation en lecture seule (photographie instantanée sans réservation de dossier).
     - Carte récapitulative du dossier étudiant et rattachement académique (sans exposition de secrets ni données personnelles sensibles superflues).
     - Bannière de verdict canonique dynamique (`ELIGIBLE` en émeraude, `AVERTISSEMENT` en ambre, `BLOCAGE` en rouge).
     - Grille des 4 piliers de contrôle (Période, Pièces GED, Moteur d'anomalies, Identité MENS).
     - Matrice détaillée des pièces justificatives GED obligatoires, soumises, rejetées et manquantes avec badges d'état.
     - Section analytique des motifs précis de blocage, avertissements et actions suggérées issues du moteur de règles.
     - Tableau du workflow à quatre yeux recensant les propositions de correction en attente et le rôle de l'agent courant.
     - Fiche d'état de la tâche administrative associée et journal chronologique des 10 derniers événements d'audit académique.
2. [tests/Feature/CentreTravailDetailTest.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/CentreTravailDetailTest.php) :
   - Suite complète de 12 tests automatisés couvrant tous les critères de la spécification du Sous-lot C.
3. [docs/engineering/LOOP-UNIREGISTRE-CENTRE-TRAVAIL-LOT-C-01.md](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/docs/engineering/LOOP-UNIREGISTRE-CENTRE-TRAVAIL-LOT-C-01.md) :
   - Rapport formel d'ingénierie et de certification du Sous-lot C.

---

## 3. PHASE 1 — DÉTAIL D'UNE INSCRIPTION ET MINIMISATION DES DONNÉES

La page de consultation détaillée `admin.centre-travail.show` a été conçue selon le principe de minimisation des données personnelles (Privacy by Design / RGPD) :

| Donnée affichée | Utilité pour la tâche de contrôle administratif | Exposition de données sensibles évitée |
| :--- | :--- | :--- |
| **Identité & CIN** | Identification univoque du dossier de l'étudiant | Mots de passe, tokens de session, identifiants internes masqués |
| **Année & Programme** | Vérification de la cohérence de l'affectation pédagogique | Pas d'exposition de coordonnées bancaires personnelles |
| **Statut d'inscription** | Visualisation de l'état réel actuel (`en_attente`, `en_cours`, etc.) | Aucune modification possible depuis cette interface |
| **Pièces GED** | Contrôle de conformité documentaire (type, statut, date de dépôt) | Pas de lien direct non protégé vers les fichiers binaires |
| **Corrections 4 yeux** | Surveillance des modifications de données sensibles en cours | Respect du principe du second regard (non auto-approbation) |
| **Audit académique** | Traçabilité des actions antérieures et des opérateurs | Horodatage et identité de l'agent sans divulgation d'informations privées |

---

## 4. PHASE 2 — ÉVALUATION EXPLICABLE ET LES 4 PILIERS CANONIQUES

L'évaluation s'appuie exclusivement sur la méthode `InscriptionEligibilityService::evaluer()`.

```
                                  [ Inscription Académique ]
                                               │
                                 ┌─────────────┴─────────────┐
                                 ▼                           ▼
                     [ Données Étudiant / GED ]    [ Règles Académiques ]
                                 └─────────────┬─────────────┘
                                               │
                                  InscriptionEligibilityService
                                          ::evaluer()
                                               │
        ┌───────────────────────┬──────────────┴───────────────┬───────────────────────┐
        ▼                       ▼                              ▼                       ▼
   [ 1. PÉRIODE ]          [ 2. PIÈCES ]               [ 3. ANOMALIES ]           [ 4. MENS ]
   RegistrationPeriod      DocumentsRequisService      VerificationEngine         IdentiteNationale
   (Ouverte / Fermée)      (Matrice GED reçue/rejetée) (Règles R01..R12)          (Conformité Registre)
        │                       │                              │                       │
        └───────────────────────┼──────────────────────────────┴───────────────────────┘
                                ▼
                       [ VERDICT GLOBAL ]
             ┌──────────────────┼──────────────────┐
             ▼                  ▼                  ▼
        🟢 ELIGIBLE       🟡 AVERTISSEMENT     🔴 BLOCAGE
     (Validation permise) (Vigilance requise)  (Validation interdite)
             │                  │                  │
             └──────────────────┼──────────────────┘
                                ▼
            [ Explications détaillées, Motifs & Actions ]
```

### Détail des 4 piliers de contrôle :
1. **Pilier 1 : Période d'inscription (`periode`) :**
   - Contrôle si l'année académique et le programme disposent d'une session d'inscription active.
2. **Pilier 2 : Pièces justificatives GED (`pieces`) :**
   - Compare la liste des documents obligatoires configurés avec les pièces effectivement déposées et validées.
   - Isole les pièces manquantes et les pièces rejetées par les gestionnaires GED.
3. **Pilier 3 : Moteur de règles d'anomalies (`anomalies`) :**
   - Exécute les règles métier canoniques (`VerificationEngine`) et extrait les anomalies catégorisées (bloquantes ou informatives).
4. **Pilier 4 : Identité nationale MENS (`identite_mens`) :**
   - Vérifie la cohérence d'état civil avec le registre central MENS.

---

## 5. PHASE 3 — SÉCURITÉ, FRESHNESS ET ISOLATION ÉTANCHE (ANTI-BOLA / IDOR)

### 5.1 Périmètre facultaire strict côté serveur
- Le contrôleur vérifie systématiquement :
  ```php
  abort_unless(
      $utilisateur->peutAccederFaculte((int) $etudiant->faculte_id),
      404,
      'Dossier hors du périmètre de votre faculté.'
  );
  ```
- **Politique Anti-Énumération :** Si un agent d'une Faculté A tente d'accéder à l'identifiant d'une inscription de la Faculté B en modifiant l'URL (`/admin/centre-travail/123`), le serveur retourne immédiatement un code **HTTP 404** (et non un 403), interdisant à l'attaquant de déterminer si l'identifiant existe dans une autre faculté. Ce point est validé par le test `test_isolation_facultaire_acces_detail_anti_bola`.

### 5.2 Fraîcheur des données et absence de cache partagé
- L'évaluation est calculée en temps réel au moment précis de la requête.
- Aucun cache multi-utilisateurs ou inter-facultés n'est employé, éliminant tout risque de *cross-tenant data leakage*.
- L'écran indique explicitement qu'il s'agit d'une photographie instantanée qui ne constitue pas une réservation exclusive du dossier.

---

## 6. PHASE 4 — PREUVES FORMELLES DE LECTURE SEULE STRICTE

Conformément aux exigences du Sous-lot C, le chargement du détail ne doit provoquer aucune mutation.

| Composant surveillé | État avant consultation | État après consultation | Preuve de non-mutation |
| :--- | :--- | :--- | :--- |
| **Inscription académique** | `statut = en_cours`, timestamps identiques | Identique à 100% | `test_absence_de_mutation_lors_de_la_consultation_du_detail` |
| **Tâche administrative** | `statut = pending`, `assigne_a = null` | Identique à 100% | `test_absence_de_mutation_lors_de_la_consultation_du_detail` |
| **Dossier de correction** | `statut = proposee`, `valide_par = null` | Identique à 100% | `test_absence_de_mutation_lors_de_la_consultation_du_detail` |
| **Courriels sortants** | 0 courriel envoyé | 0 courriel envoyé | `Mail::assertNothingSent()` (`test_absence_envoi_courriel_et_transition_metier`) |
| **Événements de transition** | Aucun événement de validation | Aucun événement déclenché | `Event::assertNotDispatched()` |

---

## 7. PHASE 5 — MATRICE DES TESTS RÉELLEMENT EXÉCUTÉS

### 7.1 Suite dédiée au détail du Centre de travail (`CentreTravailDetailTest`)
Commande exécutée : `php artisan test --filter="CentreTravailDetailTest"`

| N° | Test | Scénario vérifié | Résultat |
| :---: | :--- | :--- | :---: |
| 1 | `test_acces_autorise_au_detail_inscription` | Agent scolarité de la même faculté accède au détail (200 OK, identité et programme affichés). | **PASS** |
| 2 | `test_refus_utilisateur_non_authentifie` | Requête anonyme redirigée vers la connexion d'administration (302). | **PASS** |
| 3 | `test_refus_role_sans_permission` | Utilisateur sans permission `tasks.view` ni `archives.view` rejeté (403 Forbidden). | **PASS** |
| 4 | `test_isolation_facultaire_acces_detail_anti_bola` | Agent Faculté 1 tentant d'accéder à un dossier de Faculté 2 reçoit un 404 strict (anti-BOLA). | **PASS** |
| 5 | `test_affichage_verdict_canonique_eligible_ou_bloque` | Rendu fidèle des verdicts canoniques (`ELIGIBLE`, `AVERTISSEMENT`, `BLOCAGE`) sans altération. | **PASS** |
| 6 | `test_presentation_motifs_blocage_et_avertissements` | Affichage structuré des 4 piliers, des motifs bloquants et des points d'attention. | **PASS** |
| 7 | `test_presentation_matrice_pieces_requises` | Affichage exhaustif des pièces obligatoires, de leur état GED et des pièces manquantes. | **PASS** |
| 8 | `test_affichage_propositions_correction_quatre_yeux` | Détection des corrections 4-eyes en attente et indication d'habilitation de l'agent connecté. | **PASS** |
| 9 | `test_absence_de_mutation_lors_de_la_consultation_du_detail` | Vérification stricte d'immutabilité avant/après sur Inscription, Tâche et Correction. | **PASS** |
| 10 | `test_absence_envoi_courriel_et_transition_metier` | Preuve absolue d'aucun envoi d'e-mail (`Mail::fake`) et préservation du statut métier. | **PASS** |
| 11 | `test_absence_fuite_donnees_sensibles` | Vérification qu'aucun hash de mot de passe ni token n'est exposé dans le HTML. | **PASS** |
| 12 | `test_affichage_tache_et_historique_associes` | Rendu de la tâche d'instruction associée et des 10 derniers événements d'audit académique. | **PASS** |
| **Total** | **12 tests, 42 assertions** | **Tous les tests passent à 100% (durée : 1,41 s)** | **PASS** |

### 7.2 Tests de non-régression transversaux
Commande exécutée : `php artisan test --filter="CentreTravailAdminTest|CentreTravailDetailTest|TaskGenerationInscriptionTest|TasksCockpitTest|InscriptionEligibilityTest"`

- `CentreTravailAdminTest` : 10 tests, 61 assertions (**100% PASS**)
- `CentreTravailDetailTest` : 12 tests, 42 assertions (**100% PASS**)
- `TaskGenerationInscriptionTest` : 11 tests, 56 assertions (**100% PASS**)
- `TasksCockpitTest` : 8 tests, 28 assertions (**100% PASS**)
- **Cumul : 41 tests, 187 assertions, 0 échec (durée totale : 2,18 s).**

### 7.3 Conformité du style de code
- Commande exécutée : `vendor/bin/pint --dirty --test`
- Résultat : `{"tool":"pint","result":"passed"}` (100% conforme aux règles Laravel Pint / PSR-12 du dépôt).

---

## 8. CONFIRMATION DU RESPECT DES CONTRAINTES STRICTES

- **Aucun commit ni push :** L'arbre git conserve toutes les modifications locales sans commit.
- **Aucune migration destructive ni suppression de données :** Aucune modification de schéma effectuée.
- **Aucun contournement d'autorisation :** Le périmètre serveur est appliqué systématiquement.
- **Aucune validation d'inscription :** Le bouton de validation définitive reste absent du Sous-lot C.
- **Aucune mutation du workflow de correction à quatre yeux :** Les propositions sont uniquement lues avec affichage explicatif.
- **Aucun démarrage des sous-lots D, E ou F :** Arrêt strict après le Sous-lot C.

---

## 9. RÉSERVES ET SCÉNARIOS NON COUVERTS

1. **Intégration d'actions de mutation (Sous-lots D & E) :**
   - La présente version est strictement en lecture seule. Les actions d'approbation directe, de rejet de pièces justificatives ou de demande de correction interactive devront être implémentées dans les sous-lots ultérieurs avec leurs contrôles d'autorisation respectifs.
2. **Visionneuse binaire directe des pièces GED :**
   - La matrice liste l'état des documents (présent, rejeté, manquant), mais la prévisualisation directe du PDF/JPEG reste déléguée aux contrôleurs GED existants déjà sécurisés contre l'exfiltration.

---

## 10. VERDICT FINAL

**VERDICT : `PASS`**

Le Sous-lot C est intégralement réalisé, testé et certifié conforme aux exigences fonctionnelles, de sécurité, d'explicabilité décisionnelle et de lecture seule stricte.
Le système est prêt pour revue avant tout engagement sur les sous-lots suivants (Sous-lot D : actions de traitement et workflow à quatre yeux).
