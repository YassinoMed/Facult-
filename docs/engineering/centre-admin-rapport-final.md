# Centre d'administration — Rapport final (12-13/10/2026)

## 1. Verdict : `PASS_WITH_RESERVATIONS`

Périmètre livré et prouvé ; réserves explicites §6 ; production `NOT_VERIFIED`.

## 2. Preuves (tests réellement exécutés)

| Suite | Résultat |
|---|---|
| 14 suites Settings/centre (`Settings*`, `Smtp`, `Messagerie`, `Comptes`, `Roles`, `SecuriteF`, `AuditG`) | **42 (41+1 skip MySQL)** |
| Voisins (`ServicesTransverses`, `Backup*`, `BackOffice`, `SeparationProfils`, `Navigation`) | verts |
| Pint | vert sur tous les fichiers du chantier ; 9 échecs globaux hors périmètre (mains parallèles/pré-existant, non touchés) |

## 3. Écarts vs cahier des charges

- SMTP : ORM + `mail:tester` + diagnostic livrés ; envoi réel impossible sans fournisseur (pas de SMTP configuré) — `mail.test.send` non créé.
- MFA, invitations, 17 permissions candidates : **non créés** (décisions requises) ; matrice lecture + verrous en place.
- Aperçu avant publication : remplacé par aperçu inline + versions (aucun paramètre public consommé en front).
- Historique versions : backend + UI liste (comparaison visuelle avant/après inline, pas de diff côte-à-côte).

## 4. Décisions ouvertes

DEC-04 (CSP), DEC-05 (hors-ligne/IA), DEC-06 (prod), D-C-04 (sentinel soldée par seeds), permissions candidates, MFA, invitations, fournisseur SMTP, première clé B, pilotage DB des flags.

## 5. Fichiers (créés/modifiés par ce chantier)

Modèles `SmtpConfiguration`, `ParametreVersion` ; services `SettingDefinition`, `ParametreService` ; contrôleurs `Parametre`, `Role`, `Administrateur` (recherche), `PurgerNotifications` ; commande `TesterMessagerie` ; routes `parametres.restaurer`, `roles.index` ; vues `parametres/index`, `roles/index`, `administrateurs/index`, sidebar (2 entrées) ; 2 migrations additives appliquées dev ; 14 suites de tests ; 9 docs `docs/engineering/` (inventaire, registres, matrices, rapports B→H, suivi, matrice rôles).
