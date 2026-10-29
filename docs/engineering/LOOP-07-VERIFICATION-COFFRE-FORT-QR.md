# LOOP-07 — VÉRIFICATION INDÉPENDANTE DU COFFRE-FORT DOCUMENTAIRE ET DU QR

**Date d'exécution :** 26/10/2026
**Périmètre :** interrupteur QR, câblage des gabarits PDF, signature d'intégrité des documents officiels, vérification publique, compatibilité des documents antérieurs, générateurs `OfficialDocumentService` et `DefaultDocumentGenerator`.
**Mode :** audit statique + tests ciblés et négatifs sur SQLite `:memory:` ; aucune migration, aucun commit, aucun push, aucune donnée réelle modifiée.
**Verdict :** `PASS_WITH_RESERVATIONS`

> Ce rapport est **distinct** des deux rapports préexistants :
> `docs/engineering/LOOP-07-COFFRE-FORT-ATTESTATIONS.md` et
> `docs/engineering/LOOP-07-COFFRE-FORT-DOCUMENTAIRE-QR.md` (verdict `PASS`,
> réserves R-07/1…7). Ils ont été lus et **non modifiés**. La vérification
> ci-dessous repart du code réel et ne reprend pas leurs conclusions.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture des deux rapports et de l'audit LOOP-00 §F7 ; inventaire des générateurs, du catalogue `config/documents-officiels.php`, des 14 gabarits PDF, du service de vérification, du contrôleur public et de la vue ; écriture d'une suite indépendante ; exécution avant/après correctif ; non-régression sur tout le domaine documents/QR.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35, SQLite `:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `APP_KEY` de test présent.
- **Limites :** MySQL non exécuté ; aucun test de rendu PDF binaire réel (les tests vérifient le HTML des gabarits et le snapshot) ; aucun scan physique de QR imprimé ; la suite complète n'est pas revendiquée verte.
- **Non exécuté :** rotation de clé en production, migration de clé sur des documents déjà émis, audit de rétention. Ces points sont des réserves, jamais des `PASS`.

---

## 1. État initial et frontière de flux

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` (inchangé) |
| Commit / push pendant le lot | aucun |
| Migration | aucune |
| Suite indépendante | `tests/Feature/Loop07CoffreFortVerificationTest.php` (non suivie) |
| Rapports préexistants | `LOOP-07-COFFRE-FORT-ATTESTATIONS.md`, `LOOP-07-COFFRE-FORT-DOCUMENTAIRE-QR.md`, lus, non modifiés |

Le lot revendique :

- `app/Services/Documents/DocumentSignatureService.php` (nouveau) ;
- `app/Services/Documents/DocumentVerificationService.php` ;
- `app/Services/Documents/DefaultDocumentGenerator.php` ;
- `app/Modules/StudentDossiers/Services/Pdf/OfficialDocumentService.php` ;
- `app/Http/Controllers/VerifyDocumentController.php` ;
- `resources/views/documents/verifier.blade.php` ;
- `config/documents-officiels.php` ;
- les 6 gabarits PDF qui n'avaient pas encore de slot QR ;
- `docs/academic/documents.md` ;
- la suite `Loop07CoffreFortVerificationTest.php` ;
- le présent rapport.

---

## 2. Défauts prouvés et corrigés

### D7/1 — Interrupteur QR déclaré mais non branché (R-07/1)

**Constat.** `config/documents-officiels.php` déclarait `qr.actif` / `DOCUMENTS_QR_ACTIF`, mais les deux générateurs injectaient `qrCodeSvg` **inconditionnellement**. Positionner la variable à `false` ne retirait pas l'image du PDF : l'interrupteur était décoratif.

**Correctif.** Conditionnement dans `OfficialDocumentService` et `DefaultDocumentGenerator` :

```php
if ((bool) config('documents-officiels.qr.actif', true)) {
    $donnees['qrCodeSvg'] = QrCodeSvg::generer($donnees['urlVerification'], 80);
}
```

Désactivé, le snapshot ne contient plus `qrCodeSvg`, mais `urlVerification` et `codeVerification` restent imprimés et vérifiables. Le test `test_interrupteur_qr_desactive_retire_image_mais_conserve_url_et_code` vérifie les deux générateurs et le rendu HTML.

### D7/2 — Câblage QR incomplet dans les gabarits (R-07/2)

**Constat mesuré.** Le rapport préexistant annonçait 4 gabarits câblés et 10 en attente. L'audit réel a trouvé **8 gabarits déjà câblés** (releve, releve-cursus était absent, attestation-reussite, attestation-inscription, diplome, certificat-reussite, attestation-scolarite, attestation-presence, certificat-scolarite) et **6 manquants** :

- `attestation-abandon.blade.php` ;
- `attestation-admis.blade.php` ;
- `attestation-assiduite.blade.php` ;
- `attestation-transfert.blade.php` ;
- `quittance.blade.php` ;
- `releve-cursus.blade.php`.

**Correctif.** Ajout du slot `@if(! empty($qrCodeSvg)) … {!! $qrCodeSvg !!} … @endif` dans les 6 gabarits, avec le même rendu flottant que les gabarits déjà câblés.

**Preuve de couverture.** Le catalogue contient **15 types** pour **14 gabarits uniques** (`releve_notes` et `releve_semestriel` partagent `releve.blade.php`). Le test parcourt le catalogue et vérifie que chaque gabarit contient `qrCodeSvg` ; la vérification programmatique affiche 14/14 `OK`.

### D7/3 — Absence de signature cryptographique (R-07/6)

**Constat.** La vérification publique ne s'appuyait que sur le statut en base (`valide`, `remplace`, `revoque`, `expire`). Une ligne dont le `contenu_hash` ou la référence avait été altérée restait « valide » : aucun contrôle d'intégrité n'était opposable à une modification de la base ou du snapshot.

**Correctif.** Création de `App\Services\Documents\DocumentSignatureService` :

- HMAC-SHA256 sur une charge canonique JSON : `type`, `reference`, `contenu_hash`, `code_verification` ;
- clé dédiée `documents-officiels.signature.cle` (`DOCUMENTS_SIGNATURE_KEY`) si présente ;
- sinon clé dérivée de `APP_KEY` :
  `hash_hmac('sha256', 'uniregistre:documents-officiels:signature:v1', APP_KEY)` ;
- signature stockée dans `snapshot_donnees.signature_document = {algorithme, valeur}` ;
- `verifier()` retourne `null` (document antérieur sans signature), `true` (valide) ou `false` (altérée).

Les deux générateurs signent **après** le calcul de la référence définitive :

- `OfficialDocumentService` signe `PREFIXE-reference-version` ;
- `DefaultDocumentGenerator` signe le `numero` GED (y compris un duplicata `-DUPn`).

`DocumentVerificationService` expose `signature_valide` et force `valide = false` si la signature est `false`, même si le statut en base est `valide`. Le contrôleur et la vue publique affichent « Vérifiée / Invalide / Non disponible (document antérieur) ».

### D7/4 — Documentation académique obsolète

**Constat.** `docs/academic/documents.md` affirmait encore que l'interrupteur n'était pas branché, que 4 gabarits étaient câblés et que la signature crypto était absente.

**Correctif.** Mise à jour de la section « QR scannable » (interrupteur branché, 14 gabarits) et ajout d'une section « Signature d'intégrité » décrivant la clé, la charge signée, les trois états de vérification et les tests.

---

## 3. Comportement de la signature — preuves

| Cas | Résultat attendu | Test |
|---|---|---|
| Document généré par `OfficialDocumentService` | signature HMAC 64 hex, reproductible depuis le registre | `test_generation_officielle_produit_une_signature_hmac_verifiee` |
| Document généré par `DefaultDocumentGenerator` | signature présente et vérifiée | `test_generateur_ged_signe_aussi_ses_documents` |
| `contenu_hash` altéré | `signature_valide = false`, `valide = false`, statut base inchangé | `test_signature_invalide_si_le_contenu_hash_est_altere` |
| Valeur de signature altérée | `false` | `test_signature_invalide_si_la_valeur_du_snapshot_est_alteree` |
| Référence altérée | `false` | `test_signature_invalide_si_la_reference_est_alteree` |
| Document antérieur sans signature | `null`, `valide` conservé, mention publique | `test_document_anterieur_sans_signature_reste_valide_et_signale_non_disponible` |
| Clé dédiée puis rotation | `true` puis `false` | `test_cle_dediee_est_utilisee_et_un_changement_de_cle_invalide_la_signature` |
| Page publique | « Vérifiée » puis « Signature invalide » | `test_page_publique_affiche_signature_verifiee_puis_invalide` |

---

## 4. Critères d'acceptation

| Critère | Statut | Preuve |
|---|---|---|
| C1 — L'interrupteur QR coupe réellement l'image | PASS | test des deux générateurs + rendu |
| C2 — L'URL et le code restent vérifiables sans QR | PASS | test de désactivation |
| C3 — Les 14 gabarits du catalogue affichent le QR | PASS | parcours programmatique 14/14 |
| C4 — Tout document généré porte une signature HMAC | PASS | deux tests de génération |
| C5 — Une altération du contenu, de la référence ou de la signature est détectée | PASS | trois tests négatifs |
| C6 — Un document antérieur reste valide et est signalé | PASS | test de compatibilité |
| C7 — La rotation de clé invalide les signatures | PASS | test de rotation |
| C8 — La page publique affiche l'état réel | PASS | test de rendu |
| C9 — Le PDF stocké reste vérifié par son SHA-256 au téléchargement | PASS (existant) | `DocumentCenterController` `hash('sha256', ...) !== $document->sha256` |

---

## 5. Preuves d'exécution

### 5.1 Suite de vérification indépendante

```bash
php artisan test --compact tests/Feature/Loop07CoffreFortVerificationTest.php
```

**Résultat : 11/11 PASS, 69 assertions, 3 276 ms.**

### 5.2 Non-régression documents / QR

```bash
php artisan test --compact --filter="AppelQrTest|AttendanceQrSecurityTest|AttestationFlowTest|CoffreFortAttestationsQrTest|DiplomePhaseGTest|DocumentAcademiqueTest|DocumentsCatalogueTest|DocumentsPhase16Test|DocumentsPhase7Test|DocumentsRequisTest|DocumentsReutilisablesTest|DocumentWorkflowTest|QrCodeSvgConformiteTest|RelevesGenerationTest|SensitiveDocumentDownloadTest|StudentArchiveDocumentTest|StudentDocumentIsolationTest|StudentDocumentSecurityTest|StudentDocumentTest|Loop07CoffreFortVerificationTest"
```

**Résultat : 193/193 PASS, 936 assertions, 17 895 ms.**

La suite inclut `QrCodeSvgConformiteTest` (conformité de l'encodeur ISO/IEC 18004) et les tests de sécurité des documents ; aucune régression n'est observée.

### 5.3 Couverture des gabarits

```text
types=15 gabarits=14
OK modules.student-dossiers.pdf.releve
OK modules.student-dossiers.pdf.releve-cursus
OK modules.student-dossiers.pdf.attestation-reussite
OK modules.student-dossiers.pdf.attestation-inscription
OK modules.student-dossiers.pdf.diplome
OK modules.student-dossiers.pdf.attestation-scolarite
OK modules.student-dossiers.pdf.certificat-scolarite
OK modules.student-dossiers.pdf.attestation-presence
OK modules.student-dossiers.pdf.attestation-assiduite
OK modules.student-dossiers.pdf.attestation-admis
OK modules.student-dossiers.pdf.attestation-abandon
OK modules.student-dossiers.pdf.attestation-transfert
OK modules.student-dossiers.pdf.quittance
OK modules.student-dossiers.pdf.certificat-reussite
```

### 5.4 Qualité de code

```bash
php -l app/Services/Documents/DocumentSignatureService.php
php -l app/Services/Documents/DocumentVerificationService.php
php -l app/Services/Documents/DefaultDocumentGenerator.php
php -l app/Modules/StudentDossiers/Services/Pdf/OfficialDocumentService.php
php -l app/Http/Controllers/VerifyDocumentController.php
php -l config/documents-officiels.php
vendor/bin/pint --test <fichiers PHP modifiés>
```

**Résultat : aucune erreur de syntaxe ; Pint PASSED.**

---

## 6. Non-régression et hygiène

- Aucun commit, push, tag ni déploiement.
- Aucune migration, aucun `migrate:fresh`, `db:wipe` ou suppression.
- Aucun secret, jeton, mot de passe ou PII réelle dans les tests ou ce rapport.
- Les deux rapports préexistants LOOP-07 n'ont pas été écrasés.
- Les documents antérieurs sans signature restent lisibles et valides : aucune rupture de compatibilité.

---

## 7. Risques résiduels et réserves

- **R-07/1 — CLOSE.** Interrupteur branché et prouvé.
- **R-07/2 — CLOSE.** 14/14 gabarits couverts.
- **R-07/3 — OUVERTE (décision métier).** Les rétentions restent `null` : aucune durée légale n'est inventée. Arbitrage institutionnel explicite requis avant toute purge. **Ne pas trancher techniquement.**
- **R-07/4 — OUVERTE (volumétrie).** `snapshot_donnees` embarque le SVG (~50-80 Ko/document) et désormais la signature (~100 octets). Surveiller la colonne sur la génération de masse (`GenerateOfficialDocumentsJob`). **Non bloquant.**
- **R-07/5 — OUVERTE (documentaire).** Divergence de choix de masque entre l'encodeur PHP (règles ISO) et la lib JS `qrcode-generator` (variante simplifiée Arase) : les deux sorties sont scannables. **Non bloquant.**
- **R-07/6 — CLOSE.** Signature HMAC-SHA256 implémentée, stockée et vérifiée.
- **R-07/7 — CLOSE (vigilance maintenue).** Le docblock de l'ancien encodeur affirmait à tort la conformité ISO ; l'implémentation réelle et sa suite de conformité corrigent le fond. Vigilance générale sur les docblocks non prouvés.
- **R-07/8 — NOUVELLE (périmètre de signature).** La signature couvre `type`, `reference`, `contenu_hash` et `code_verification`. Elle ne couvre **ni** le `sha256` du PDF, **ni** une recomposition du snapshot : une altération directe d'un champ du snapshot (par exemple le nom affiché) n'est pas détectée par la page publique. Le `sha256` du PDF est vérifié séparément au téléchargement (`DocumentCenterController`), mais cette vérification n'est pas signée. Défense en profondeur perfectible ; **non bloquant** pour le périmètre actuel, à traiter si le snapshot devient une surface d'affichage autoritative.
- **R-07/9 — NOUVELLE (gestion de clé).** Il n'existe pas de versionnement de clé : changer `DOCUMENTS_SIGNATURE_KEY` invalide toutes les signatures existantes (comportement prouvé par test). Une rotation doit être planifiée avec conservation de l'ancienne clé ou migration des snapshots. **Opérationnel, non bloquant.**
- **R-07/10 — NOUVELLE (documentation d'exploitation).** `DOCUMENTS_QR_ACTIF` et `DOCUMENTS_SIGNATURE_KEY` ne figurent pas dans `.env.example` ; la configuration est documentée dans `docs/academic/documents.md` et `config/documents-officiels.php`, mais l'exploitant doit ajouter les variables à son modèle d'environnement. **Non bloquant.**
- Héritage : R-03/1…5, R-04/1…3, R-06/V1…V6 toujours ouverts.

---

## 8. Verdict

**`PASS_WITH_RESERVATIONS`.**

Les trois manques nommés par le rapport préexistant — interrupteur non branché, gabarits incomplets, signature absente — sont corrigés et prouvés par 11 tests dédiés et 193 tests de non-régression. La compatibilité des documents antérieurs est conservée. Les réserves restantes relèvent de décisions métier (rétention), de la gestion opérationnelle des clés, de la volumétrie du snapshot et du périmètre exact de la signature ; aucune ne rouvre un défaut bloquant du lot. Aucun `NOT_EXECUTED` n'est compté comme `PASS`.
