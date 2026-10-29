# LOOP-07 — COFFRE-FORT DOCUMENTAIRE ET ATTESTATIONS QR

> **Verdict : PASS** (conditions d'application et réserves en fin de rapport)
> Date : 25/10/2026 — protocole audit-first, réutilisation maximale, 0 commit/push/déploiement.
> HEAD `38ef149` **inchangé** pendant tout le lot. Exécution dans le cadre du
> programme LOOP MASTER (auto-pass enchaîné L05 → L02 → L03 → L04 → **L07** → L01…).

---

## 1. Périmètre (entrée audit §F7, L99-107)

**Statut d'entrée : stockage/OCR/vérification EXISTANT_VALIDÉ ; QR scannable
ABSENT_CONFIRMÉ (à clarifier).** Manques et questions nommés par l'audit :

1. **QR scannable ABSENT** — aucune lib composer ; `App\Support\QrCodeSvg.php`
   = QR **décoratif non ISO non scannable** (géométrie de mireurs correcte mais
   contenu = hash sha256 étalé sur la matrice, indécodable — docblock
   prétendant « Implémentation QR Code standard (ISO/IEC 18004) » **faux**) ;
   seule la lib JS `qrcode-generator.min.js` (MFA) encode réellement, côté navigateur.
2. **Rétentions toutes `null`** (`config/retention.php`) → décisions métier obligatoires ;
3. Candidat lib `chillerlan/php-qrcode` « à valider » (dépendance nouvelle à justifier).

## 2. État des lieux (lecture amont)

- **Storage privé validé** (audit) : disque `private`, UUID + sha256 + racines
  séparées (`DocumentStorageService:43-87,174-223`), validation finfo/MIME réel/
  anti-polyglote, hook antivirus ClamAV/Null — **zone active du co-flux** (ClamavAntivirusScanner,
  ReutilisationDocumentsService…), non touchée.
- **OCR identité validé** (audit) : suppression immédiate en `finally`, drivers
  fail-soft désactivés par défaut, 23 tests `IdentityScanTest`.
- **Vérification publique validée** (audit) : `/verifier/{reference}` et
  `/verify/{code}` (`documents.verify`, throttle 30/min), statuts
  `valide|expire|revoque|remplace|inexistant`, nom masqué, IP hachée, 404 sans
  confirmation d'existence — réutilisée telle quelle (zéro modification).
- **Catalogue** : 15 types, tous `'qr' => true`, section
  `'qr' => ['actif' => env('DOCUMENTS_QR_ACTIF', true)]` déclarée
  « image QR en plus de l'URL + code imprimés. Interrupteur responsable » —
  **contrat prévu, jamais branché**.
- **Consommateurs de `QrCodeSvg`** : `AppelController:265` (jeton nominatif
  émargement, **169 octets**), `AttendanceTemplateRenderer` (2 sites), bloc Blade
  attendance — aucun ne teste l'intérieur, tous consomment `generer()`.
- **Aucun test** n'assertait le contenu des QR avant ce lot.

## 3. Co-édition majeure — traçage de provenance

Le co-flux exécutait **le même lot en parallèle** (constat en cours de lot,
après relectures successives d'un gabarit qui changeait sous les yeux) :

- `OfficialDocumentService::generer()` : injection
  `$donnees['qrCodeSvg'] = QrCodeSvg::generer($donnees['urlVerification'], 80)`
  **après le calcul de `contenu_hash`** (dédoublonnage intact — vérifié), conservée
  dans `snapshot_donnees` ;
- 4 gabarits PDF câblés (slot `{!! $qrCodeSvg !!}` float + clear, dompdf-safe) :
  releve, attestation-reussite, attestation-inscription, diplome ;
- `DefaultDocumentGenerator` (GED) injecte aussi le QR ;
- suite `CoffreFortAttestationsQrTest` (16 tests) couvrant QR, snapshot, cycle de
  vie, anti-IDOR, purge `finally`, commandes expire/orphelins, rétention figée.

**Frontière tenue** : je n'ai jamais édité leurs fichiers applicatifs ; leur
seul fichier touché est leur suite de test (renforcement tracé §5.4). Mon
encodeur réécrit, eux consomment l'API inchangée : les deux œuvres se
complètent en une feature complète — le câblage co-flux + l'encodeur réel.

## 4. Parti pris minimal (réutilisation maximale)

**Zéro dépendance composer** — le candidat `chillerlan/php-qrcode` est écarté :
l'encodeur ISO réel tient en une classe pur PHP (~450 lignes), l'API publique
`generer()`/`creerMatrice()` est conservée à l'identique → **zéro changement
d'appelant** (émission, templates, GED, LOT 10 futur). La règle du lot est
l'inverse de l'ancien comportement : **jamais de QR décoratif silencieux** —
plafond dépassé = `DomainException` explicite.

## 5. Réalisations

### 5.1 Encodeur ISO/IEC 18004 réel (`app/Support/QrCodeSvg.php`)

Réécriture interne complète, API inchangée : mode octet UTF-8, versions 1-10
(21×21 → 57×57), niveau M puis bascule L, Reed-Solomon GF(256) (poly 0x11D,
blocs égaux puis **groupes inégaux dès v8**), information de format BCH(15,5)
double copie (XOR 0x5412), **information de version BCH(18,6) dès v7** (double
copie), alignements multiples selon la table des positions (6 motifs v7+,
centres sur ligne/colonne 6 admis), masque optimal par règles de pénalité
1-4 ISO, compte de longueur 8 bits (v1-9) puis 16 bits (v10), bits de reste
par version. Plafond 271 octets (v10-L).

**Provenance des tables — zéro reconstitution de mémoire** : RS_BLOCK_TABLE,
PATTERN_POSITION_TABLE, G15/G15_MASK/G18 dumpées mécaniquement depuis le
package `qrcode-generator` (implémentation de référence) installé dans le
banc externe, puis validées par l'invariant spec
`Σ(données) + Σ(RS) = 26/44/70/100/134/172/196/242/292/346 codewords`.

**Incident tracé** : ma première « correction » des tables L depuis ma
mémoire violait l'invariant (v6-L 208 ≠ 172) — **attrapée par ma propre
garde `DomainException` d'incohérence de capacité** au premier run du banc,
puis tables définitives alignées sur la référence. Leçon consignée : les
constantes de spec ne se reconstituent jamais de mémoire, seules la
transposition mécanique et la preuve par banc font foi.

**Bugs corrigés en cours de banc** (tous détectés par la parité de référence,
aucun par relecture seule) :
- séparateurs de mireur peints sombres (clause `$c >= 0 && $c <= 6`
  manquante → 6 modules faux sur toute matrice) ;
- capacités v2/v3 conservatives d'un octet (25/41 → 26/42, formule
  `octets données − 2`) ;
- précédences PHP (`&` vs `===`), pénalités règles 1 et 4, comptage
  règle 3 sans chevauchement.

### 5.2 Extension v7-v10 motivée par le tronc (régression détectée)

`AppelQrTest::test_enseignant_affiche_qr_nominatif_et_idor` (**test trunk
existant, engagé**) poste un jeton émargement de **169 octets** : l'ancien
encodeur décoratif l'acceptait, mon plafond initial v6 (134) levait une
exception → HTTP 500 → **régression trunk**. Le domaine réel des payloads
(émission) exigeait l'extension aux versions 7-10 (169 octets → v9-M),
avec groupes inégaux et info de version — pas une envolée de périmètre,
la couverture du domaine existant.

### 5.3 Preuve de scannabilité (banc externe, répertoire temporaire approuvé, hors repo)

- **Parité bit-à-bit avec `qrcode-generator` à masque forcé : 14/14
  IDENTIQUE** — v1→v10, M et L, blocs égaux/inégaux, comptes 8/16 bits,
  6 alignements + info version. Le pipeline entier est conforme à la
  référence, seul le choix de masque peut diverger (mes règles = ISO ;
  Arase = règle 1 « voisins 3×3 » non-spec + règle 3 horizontale seule).
- **Décodage indépendant `jsQR` : 14/14 DÉCODÉ OK** en sortie auto —
  y compris jeton 169 octets, UTF-8 multi-octets, plafond 271.
- **Cas d'usage du lot : l'URL de vérification v4-M est IDENTIQUE
  bit-à-bit en sortie auto** (même masque retenu par les deux implémentations).

### 5.4 Suite de conformité épinglée dans le repo

`tests/Feature/QrCodeSvgConformiteTest.php` (17 tests, 89 assertions) —
la preuve devient exécutable en CI sans Node : fixtures bit-à-bit v1/v2
UTF-8/v4 URL ; 13 hachages sha256 épinglés couvrant v1→v10 (toute dérive
d'un seul module casse un hachage) ; cohérence blocs ↔ capacités ↔
totaux spec (anti-dérive) ; structure fonctionnelle (mireurs, module
sombre, copies de format identiques, BCH de version lisible) ;
déterminisme ; limites (vide, 272 octets).

**Renforcement tracé du test co-flux** : leur
`test_qr_code_svg_rejet_payload_trop_volumineux` attendait l'exception à
135 octets (plafond v6 qu'ils avaient lu en vol) ; le plafond est
désormais 271 — seuil déplacé à **272** (test renforcé, jamais affaibli,
commentaire daté), et deux commentaires de versions périmés corrigés, plus
le cas 169 octets ajouté à leur test de niveaux.

### 5.5 Documentation

`docs/academic/documents.md` : « QR (dépendance à approuver) » retiré des
non-implémentés ; section « QR scannable (LOOP 07) » ajoutée (encodeur,
injection après hash, preuves, interrupteur non branché, héritage LOT 10).

## 6. Preuves

| Vérification | Résultat |
|---|---|
| `vendor/bin/pint` (encodeur + suite conformité, x3) | PASSED (3 fixers de style au 1er passage, 0 ensuite) |
| `php -l` encodeur + rendus CLI | Aucune erreur |
| Suite conformité `QrCodeSvgConformiteTest` | **17/17 PASSED, 89 assertions, ~0,6 s** |
| Suite co-flux `CoffreFortAttestationsQrTest` | **16/16 PASSED, 85 assertions** |
| Régression trunk `AppelQrTest|AttendanceQrSecurityTest` | **14/14 PASSED** (169 octets → v9-M réel) |
| **RUN CANONIQUE LOT 07** (19 suites : CoffreFortAttestationsQrTest, QrCodeSvgConformiteTest, AppelQrTest, AttendanceQrSecurityTest, DocumentsPhase7Test, DocumentsPhase16Test, AttestationFlowTest, DiplomePhaseGTest, RelevesGenerationTest, DocumentWorkflowTest, StudentDocumentTest, StudentDocumentSecurityTest, StudentDocumentIsolationTest, SensitiveDocumentDownloadTest, IdentityScanTest, MfaLoopJTest, InscriptionRecepissePdfTest, DocumentAcademiqueTest, StudentArchiveDocumentTest) | **180/180 PASSED, 809 assertions, ~16,5 s** |
| Banc externe (hors repo) | Parité forcée 14/14 IDENTIQUE ; jsQR 14/14 ; URL v4-M identique en auto |
| HEAD / commits | `38ef149` inchangé, 0 commit, 0 push |
| Migrations | **0** (aucune table ni colonne touchée) |
| Tests ignorés | 0 |

## 7. Verdict

**PASS.** Le manque nommé par l'audit — QR scannable absent, encodeur
décoratif indécodable — est traité à la racine : encodeur ISO/IEC 18004
réel v1-v10, prouvé bit-à-bit contre l'implémentation de référence et
par décodage indépendant, conformité épinglée en CI, câblage attestation
réalisé par le co-flux sur mon API stable, régression trunk résolue par
extension motivée. Réutilisation maximale : zéro dépendance, zéro route,
zéro migration, zéro changement d'appelant.

## 8. Conditions d'application

Aucune pour mon périmètre : le code est actif dès déploiement, les
consommateurs existants (émargement, templates, GED) produisent
automatiquement des QR réels. Le complément de câblage UI (10 gabarits
restants) est en cours côté co-flux.

## 9. Réserves

- **R-07/1** — Interrupteur `documents-officiels.qr.actif`
  (`DOCUMENTS_QR_ACTIF`) **déclaré mais non branché** : l'injection du QR
  dans `OfficialDocumentService` est inconditionnelle. Câblage = 1 ligne +
  test de désactivation ; zone active du co-flux, non touchée ce lot.
- **R-07/2** — 10 gabarits PDF sur 14 n'affichent pas encore le QR
  (4 câblés par le co-flux, en vol) ; le snapshot contient déjà le QR pour
  tous les types — l'affichage suivra sans regeneration.
- **R-07/3** — Rétentions toutes `null` : décision métier toujours ouverte.
  Position co-flux « aucune invention arbitraire » figée par test —
  validée ; arbitrage métier explicite requis avant toute purge légale.
- **R-07/4** — `snapshot_donnees` embarque le SVG (~50-80 Ko/document) :
  choix assumé du co-flux (preuve de ce qui a été imprimé) ; surveiller la
  volumétrie de la colonne sur la masse (GenerateOfficialDocumentsJob).
- **R-07/5** — Divergence de choix de masque vs lib JS `qrcode-generator`
  (MFA) : règles ISO (PHP) contre variante simplifiée Arase (JS) — les
  deux sorties sont scannables, l'écart est documenté et sans impact.
- **R-07/6** — Signature crypto des attestations toujours absente (le
  §F7 le notait) : hors périmètre minimal, décision d'architecture future.
- **R-07/7** — Le docblock de l'ancien encodeur affirmait à tort la
  conformité ISO : corrigé par implémentation réelle + preuve. Vigilance
  générale du programme sur les docblocks non prouvés par test.
- Héritage toujours ouvert : R-03/1…5, R-04/1…6, R1-R4 de LOOP-00.

## 10. Note pour le LOT 10 (émargement hors ligne)

L'émargement consomme la même API : `AppelController` (jeton 169 octets →
v9-M) et `AttendanceTemplateRenderer` produisent désormais des QR réels
sans aucune modification de leur côté. Au LOT 10 : vérifier la lisibilité
du rendu template à 60 px (denses, v9 = 53×53) et l'expérience scan
étudiant ; le rendu contrôleur (220 px) est confortable.
