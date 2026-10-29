# LOOP GLOBAL 02 — Matrice de couverture fonctionnelle

**Date :** 2026-10-25 · **Mode :** lecture seule · **Source :** registre
`LOOP-GLOBAL-INVENTAIRE.md` (F-01→F-42), rapprochement des familles A→K du
mandat. Statuts de présence : `IMPLEMENTED` / `PARTIAL` /
`NOT_FOUND_AFTER_AUDIT` / `À IDENTIFIER`. Le verdict de conformité deep
arrive avec les LOOP 03-08 ; cette matrice cartographie l'existant.

## Matrice familles A→K

| Famille | Couverture observée | Fonctionnalités (F-xx) | Présence | Contrôles nécessaires (priorité) |
|---|---|---|---|---|
| **A. Socle technique et accès** | Auth+MFA+PIN, rôles/419 permissions, périmètres, sessions database, récupération compte portails | F-01, F-02, F-03 | IMPLEMENTED | IDOR/isolement (L04), sessions/throttling (L04), MFA re-vérif (L04) |
| **B. Référentiel académique LMD** | 9 entités CRUD+arbre+qualité, maquettes versionnées, imports exports, compétences+couverture | F-04, F-05, F-06, F-07 | IMPLEMENTED | Ré-exécution suites référentiel (L03), ambiguïtés d'import hors compétences (L03) |
| **C. Inscriptions et dossiers** | Nouvelle/validation/unicité annuelle/corrections/affectation auto+masse, pièces+coffre+antivirus | F-08, F-09, F-12 | IMPLEMENTED | Concurrency inscriptions (L03/L05), IDOR dossiers (L04), flux pièces (L03) |
| **D. Portail étudiant/parcours** | Profil, résultats publiés seuls, documents, RDV, simulation parcours, notifications/préférences | F-10, F-19, F-41 | IMPLEMENTED | Isolation inter-étudiants (L04), résultats non publiés protégés (L04), simulation sans écriture (L05) |
| **E. Groupes et enseignements** | Groupes, affectations étudiant/enseignant, capacité/conflits, masse, historique ; charges (module actif) | F-12, F-29 | IMPLEMENTED | Concurrency affectations (L05), périmètres (L04) ; **remplacements : À IDENTIFIER** |
| **F. Examens/évaluations/résultats** | Sessions, épreuves, surveillants, éligibilité, saisie/validation notes, publication, délibérations | F-14, F-15 | IMPLEMENTED | Publication/verrouillage (L04/L05), séparation de rôles jury (L04), concurrence notes MySQL (L05) |
| **G. Documents et archives** | Coffre privé, validation type/taille/contenu, attestations QR+signatures+versions+révocation, archivage | F-09, F-17, F-18 | IMPLEMENTED | Accès direct fichiers (L04), QR minimal (L04), rétention (L05) |
| **H. Paiements et finances** | Frais, dossiers paiement, rapprochement (commandes d'audit dédiées) | F-30 | PARTIAL | Webhooks/idempotence fournisseurs : À IDENTIFIER (L04) ; prestataire simulé à confirmer (L03) |
| **I. Notifications/communications** | Centre unifié, préférences, templates, campagnes+studio+SMTP UI, files, purges, reprise échecs | F-19, F-20, F-39 | IMPLEMENTED | Doublons/idempotence (L03), masquage secrets logs (L04), fake transport (L03) |
| **J. Data Mesh et échanges** | Produits/contrats, schémas, observabilité graphe/flux/qualité/outbox, KPI/BI prewarm | F-21, F-22, F-07 | IMPLEMENTED | Autorisations consommateurs (L04), fraîcheur/provenance (L05), compatibilité contrats (L03) |
| **K. Transverses et avancées** | Workflow/tâches, cockpit KPI, IA RAG back-office (baseline 111 ✓), simulations (L14 ✓), décrochage (L15 ✓), assurance qualité (L16 ✓), compétences (L20 ✓), versioning, sentinel, sauvegardes, aide | F-23, F-24, F-25, F-26, F-27, F-28, F-31, F-32, F-33, F-38 | IMPLEMENTED (voir verdicts par lot) | IA front (F-25) articulation (L03) ; sentinel désactivé (activation = décision serve) |
| **K-bis. Absentes après audit** | **Alumni** (F-37) : NOT_FOUND_AFTER_AUDIT — bloqué faute de source de diplomation confirmée. **Hub d'intégration formalisé** (F-35) : PARTIAL (socle HMAC/journal existant, contrats/replay non livrés — Lot 18 non démarré). **Centre de gouvernance** (F-36) : NOT_FOUND_AFTER_AUDIT (Lot 19 non démarré). **Émargement PWA hors-ligne** (F-42) : À IDENTIFIER (1 route `hors-ligne`) | F-35, F-36, F-37, F-42 | PARTIAL / NOT_FOUND_AFTER_AUDIT | Conformément au mandat : NE PAS implémenter automatiquement |

## Synthèse de présence (42 fonctionnalités)

| Statut | Nombre | Détail |
|---|---|---|
| IMPLEMENTED | 36 | dont 4 cloturés ce jour-mission par verdicts PASS_WITH_RESERVATIONS documentés (F-06, F-26, F-27, F-28) + IA back-office baseline |
| PARTIAL | 3 | F-25 (IA front), F-30 (finances/webhooks), F-35 (hub : socle seul) |
| NOT_FOUND_AFTER_AUDIT | 2 | F-36 (gouvernance), F-37 (alumni) |
| UNKNOWN / À IDENTIFIER | 1 | F-42 (PWA hors-ligne) |

## Plan de test systématique (préalable à toute correction)

Ordre d'exécution sur base SQLite `:memory:` uniquement — suites ciblées
d'abord, complète ensuite :

1. **Securite/Auth/Mfa/Permission/Role** (~23 fichiers) — familles A, D, G.
2. **Inscription*/Etudiant*/Enseignant*** (~71 fichiers) — familles C, D.
3. **Referentiel*/Maquette*** (~34) — famille B.
4. **Evaluation*/Notes*/Exam*** (~33) — famille F.
5. **Document*/Archive*** (~42) — famille G.
6. **Schedule*/Enseignement restant** — famille E.
7. **Notification*/Campagne*/DataMesh*/Import*/Export*** — familles I, J.
8. **AiAssistant* (baseline 111), Simulateur, PreventionDecrochage, AssuranceQualite, Competences** — famille K (déjà verts ce jour).
9. **Suite complète** (`php artisan test`) : 542 fichiers / 4 748 méthodes —
   si des suites échouent pour environnement (MySQL requis), les qualifier
   `BLOQUÉ (environnement)` sans les compter échecs de code.
10. **UX navigateur** : `NOT_EXECUTED` — aucun navigateur automatisé
    confirmé installé ; à signaler, pas d'installation sans autorisation.

Critères d'arrêt avant correction : tout P0 (fuite de données, accès non
autorisisé grave, exposition de secrets) → blocage immédiat + rapport.

## Verdict LOOP-02

**ÉTABLI** — matrice complète des familles A→K, 42 fonctionnalités
cartographiées avec preuves, 3 PARTIAL et 2 NOT_FOUND_AFTER_AUDIT
identifiés sans invention. L'exécution des tests (LOOP-03) peut débuter
selon le plan ci-dessus ; aucune correction entreprise à ce stade.
