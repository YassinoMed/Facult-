# Décisions humaines requises

> Établi le 12/10/2026 (LOOP 1). Aucune de ces décisions ne peut être tranchée par le
> code ni par cette mission documentaire. Chaque entrée cite son impact et le verrou
> qui l'empêche d'être devinée automatiquement.

## 1. Arbitrages pédagogiques (registre `docs/pedagogie/registre-arbitrages-lmd.md` — `A_DÉCIDER`)

| Décision | Réf. registre | Entrée | Impact | Verrou |
|---|---|---|---|---|
| Contenu UE S2-S6 de DEMO-LGL (fichiers officiels attendus) | ARB-001 | REF-06 | Bloque toute activation LMD de DEMO-LGL | Jamais de « ressemblance de noms » ; aperçu→confirmation explicite |
| Maquettes v1 PAR-GEST / PAR-ECO / PAR-INFO | ARB-002 | REF-05 | Couverture LMD des autres programmes | Contenu officiel requis |
| Approbation puis activation DEMO-LGL v1 | ARB-003 | REF-06 | Passage review→approved→active | ARB-001 préalable ; approbation formelle |
| 38 matières standalone (1 DOCUMENTEE / 5 AMBIGUE / 32 SANS_CORRESPONDANCE) | ARB-004 | REF-04 | Rattachement ou retrait du LMD | Aucune correspondance unique prouvée |
| 2 087 inscriptions sans programme (re-mesuré exact le 12/10) | ARB-005 | REF-07 | Rattachement/réorientation | Jamais automatique (règle mission §6) |
| Clarification « SEM-03 » | ARB-006 | REF-08 | Référentiel semestres | Source ambiguë |
| Seuils de charge pédagogique | ARB-007 | REF-09 | Valeurs d'administration | Décision d'administration |
| Versions applicables aux historiques | ARB-008 | REF-02/06 | Politique de versionnement maquettes | Cohérence historique |
| Notifications d'activation (ancien C2) | ARB-009 | REF-06 | Communication aux intéressés | Gabarits à définir |

## 2. Décisions d'ingénierie et de sécurité (autorisation de correction requise)

| Décision | Entrée | Impact | Priorité |
|---|---|---|---|
| DH-10 — Autoriser le correctif « révocation des sessions au reset/changement de mot de passe » (+ kill-switch super_admin en option) | AUTH-01 | Cookie volé cesse de survivre au reset ; fix triviale (driver database déjà en place) | P2 |
| DH-11 — Autoriser la re-passe `vendor/bin/pint` (7 fichiers en échec, contradiction C1) | INFRA-04 | Gate style redevient franchissable | P1 |
| DH-12 — Autoriser l'ajout d'une `APP_KEY` de test dans `phpunit.xml` (clé de test publique, jamais la clé prod) | INFRA-03 | Rend l'étape tests CI exécutable sans `.env` | P1 |
| DH-13 — Décider de l'initialisation du dépôt Git + push distant + protections de branche (required checks) | INFRA-02 | Condition d'existence de tout gate CI ; interdit de committer `.env` | P0 |
| DH-14 — Fournir le document source des identifiants B2-B14 / C1-C3 / D1-D5 / E1-E8, ou valider une reconstruction | Contradiction C7 | Débloque la priorisation fine du backlog | P1 (documentaire) |
| DH-15 — Trancher les 5 décisions métier schedule : PDF EDT étudiant · attribution dossiers→agents · canal demande changement de groupe · gel des changements · seuil « après début des cours » | SCH-05 | Clôture complète du chantier EDT | P2 |
| DH-16 — Arbitrer l'architecture des demandes/réclamations enseignant (tables parallèles `teacher_requests`/`teacher_reclamations` vs OnlineRequests canonique) | PORT-07 | Suppression de la dette de double système | P2 |
| DH-17 — Décider OTP à l'inscription (actuellement : porte CIN+naissance + throttles, écart assumé) | INSC-07 | Durcissement facultatif du parcours public | P3 |
| DH-18 — Décider 2FA back-office | `docs/roadmap.md` (dette) | Durcissement comptes admin | P3 |
| DH-19 — Décider le chantier CSP `@alpinejs/csp` (dépendance + refonte des 68 vues `x-data`) | AUTH-04 | Suppression `unsafe-inline/eval` | P3 |

## 3. Décisions d'exploitation (hors portée du code)

| Décision | Entrée | Contenu attendu |
|---|---|---|
| DH-20 — Exécuter la checklist de production avec l'exploitant | INFRA-08, AUTH-07 | TLS/HSTS effectif, `TRUSTED_PROXIES` réel, secrets/rotation, sauvegardes chiffrées + drill périodique, rotation des logs, `APP_DEBUG=false` |
| DH-21 — Alimenter les affectations d'enseignement avant activation des seuils de charge | EXM-09 | Limite opérationnelle documentée (aucune heure réalisée n'existe en base) |
| DH-22 — Déployer la migration additive `historique_academique.etudiant_id` en environnement cible | MTR-02 | Exécutée en dev, à planifier ailleurs |

## 4. Règles

1. Toute décision prise est enregistrée avec date, périmètre, responsable et expiration éventuelle (politique de dérogation LOOP 6/8).
2. Les décisions P4 (PORT-18 hors-ligne, PORT-19 assistant IA) sont des **NO-GO explicites** : ne pas réouvrir sans nouvelle approbation.
3. Aucune décision de ce registre ne peut être implémentée « par défaut » : silence ≠ accord.
