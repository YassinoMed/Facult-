# Documents officiels (Phase 7)

Catalogue `config/documents-officiels.php` : `releve_notes` (REL-),
`attestation_reussite` (ATT-, décision publiée admis* exigée).
Génération `OfficialDocumentService` via `PdfDocumentService`
(registre `GeneratedDocument` : référence UNIQUE, version, `sha256`,
`contenu_hash`, disque privé) : anti-régénération à contenu égal,
nouvelle version sinon (anciennes `remplace`, jamais supprimées),
retry concurrence (§64), audit `document.genere` + notif.

Cycle de vie `valide → remplace | revoque` (migration `statut`,
`revoquer` motivé + audit + notif, téléchargement 410 si révoqué).
Gates réutilisées `student_dossiers.pdf` (+ faculté), zéro permission
créée. Archive : 4e source `officiel` (fix `toBase()` d'un crash
latent dès qu'une source secondaire est non vide). Masse :
`GenerateOfficialDocumentsJob` (délibération, chunk 200, ImportReport,
notif). Non implémentés (documentés) : multilingue (FR), PV/dossier
(exports existants).

## QR scannable (LOOP 07)

`App\Support\QrCodeSvg` : encodeur ISO/IEC 18004 **réel** en pur PHP —
mode octet UTF-8, versions 1-10 (21×21 à 57×57), niveaux M puis L,
Reed-Solomon GF(256) (poly 0x11D), information de format BCH(15,5)
en double copie (XOR 0x5412), information de version BCH(18,6) dès
v7, masque optimal par règles de pénalité 1-4. Tables RS et
alignements transposées depuis l'implémentation de référence
`qrcode-generator` (zéro reconstitution de mémoire) ; plafond
271 octets → `DomainException` explicite, jamais de QR décoratif
silencieux. L'ancien générateur décoratif (hash étalé, non
scannable) a été remplacé sans changer l'API `generer()` /
`creerMatrice()` : l'émargement (LOT 10) hérite d'un QR réel sans
aucune modification de son côté.

Injection : `OfficialDocumentService::generer()` ajoute `qrCodeSvg`
(encodage de `urlVerification` = `/verify/{code}`) APRÈS le calcul
de `contenu_hash` (dédoublonnage anti-régénération intact) et le
conserve dans `snapshot_donnees` (preuve de ce qui a été imprimé).
Gabarits PDF : slot `{!! $qrCodeSvg !!}` — **14 gabarits uniques
câblés** (15 types : `releve_notes` et `releve_semestriel` partagent
`releve.blade.php`). Couverture vérifiée par
`tests/Feature/Loop07CoffreFortVerificationTest.php`.

Preuve de scannabilité : `tests/Feature/QrCodeSvgConformiteTest.php`
(fixtures bit-à-bit + hachages épinglés v1→v10 + cohérence
blocs ↔ capacités ↔ totaux spec) ; au banc externe LOOP 07, parité
totale avec `qrcode-generator` à masque forcé (14/14) et décodage
`jsQR` 14/14 — l'URL de vérification v4-M est bit-à-bit identique
en sortie auto. Le masque retenu peut différer de la lib JS (règles
ISO vs variante simplifiée Arase) : les deux restent scannables.

Interrupteur `documents-officiels.qr.actif` (`DOCUMENTS_QR_ACTIF`,
défaut `true`) : **branché** dans `OfficialDocumentService` et
`DefaultDocumentGenerator`. Désactivé, l'image QR disparaît du PDF
mais l'URL et le code restent imprimés et vérifiables — aucune
régression de la vérification publique.

## Signature d'intégrité (LOOP 07, R-07/6)

`App\Services\Documents\DocumentSignatureService` : HMAC-SHA256 sur
la charge canonique `type`, `reference`, `contenu_hash`,
`code_verification` (JSON à ordre figé). Clé dédiée
`documents-officiels.signature.cle` (`DOCUMENTS_SIGNATURE_KEY`)
prioritaire ; à défaut, clé dérivée de `APP_KEY` par
`hash_hmac('sha256', 'uniregistre:documents-officiels:signature:v1', APP_KEY)`
— jamais de secret partagé en dur.

Émission : les deux générateurs (`OfficialDocumentService`,
`DefaultDocumentGenerator`) calculent la signature **après** la
référence définitive et la stockent dans
`snapshot_donnees.signature_document = {algorithme, valeur}`.
Vérification : `DocumentVerificationService` expose
`signature_valide` avec trois états — `null` (document antérieur,
compatibilité conservée), `true` (signature correcte), `false`
(signature altérée). Une signature `false` force `valide = false`
même si le statut en base reste `valide`. La page publique affiche
« Vérifiée / Invalide / Non disponible (document antérieur) ».

Tests : `tests/Feature/Loop07CoffreFortVerificationTest.php`
(11 tests) couvre QR actif/désactivé, signature nominale, altération
du `contenu_hash`, de la référence et de la valeur stockée, document
antérieur sans signature, rotation de clé et rendu public.
