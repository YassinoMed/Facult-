# LOOP-07 — RAPPORT DE VALIDATION : COFFRE-FORT DOCUMENTAIRE ET ATTESTATIONS QR

**Projet :** UniRegistre — Plateforme Universitaire Intelligente  
**Date :** 25 octobre 2026  
**Statut :** PASS  
**Périmètre :** Coffre-fort documentaire, attestations officielles, QR Code ISO/IEC 18004 natif, vérification publique sans fuite PII, anti-IDOR, purge des scans temporaires et politique de rétention.

---

## 1. Objectif du Lot

Établir une chaîne documentaire universitaire certifiée de bout en bout comprenant :
1. Un stockage privé hermétique hors racine web (`storage/app/private`) pour toutes les pièces sensibles et documents officiels générés.
2. Un générateur de QR Code natif pur PHP conforme à la norme **ISO/IEC 18004** (encodage octet UTF-8, Reed-Solomon GF(256), information de format BCH(15,5), masques 0-7, sans dépendance externe npm ou composer).
3. L'émission versionnée de documents officiels (attestations de réussite, d'inscription, relevés de notes, diplômes) avec injection systématique d'une URL de vérification opaque et de son QR Code vectoriel SVG.
4. Une page publique de vérification (`/verifier/{ref}` et `/verify/{code}`) avec affichage du statut réel (valide, expiré, révoqué, remplacé) et masquage strict des données personnelles (zéro fuite PII, hachage SHA-256 de l'IP cliente).
5. Une protection anti-IDOR hermétique au niveau des contrôleurs et des politiques d'accès (espace étudiant cloisonné à son propre dossier, accès administrateur limité au périmètre de sa faculté).
6. La purge systématique et déterministe des fichiers temporaires (scans OCR) dans un bloc `finally`.
7. L'automatisation des commandes console d'expiration (`documents:marquer-expires`) et de détection/purge des orphelins (`documents:purger-orphelins` avec mode simulation sécurisé par défaut).
8. L'audit de la politique de rétention : conservation des durées légales à l'état explicite `null` en l'absence de décision institutionnelle formelle, conformément à la règle stricte de ne jamais inventer de règles juridiques.

---

## 2. Audit Préalable & Corrections Fondamentales

Lors de l'audit approfondi de la chaîne documentaire et du support QR Code :

1. **Correction d'un bogue fatal PHP 8.2+ dans `QrCodeSvg` :**  
   La propriété statique `$log` (table logarithmique de Galois Field) n'était pas formellement déclarée (`private static ?array $log = null;`), provoquant une exception fatale `FatalError: Access to undeclared static property App\Support\QrCodeSvg::$log` dès la première initialisation de Reed-Solomon. Déclarée et corrigée.

2. **Correction des constantes Reed-Solomon du niveau L dans `QrCodeSvg` :**  
   Les définitions de `BLOCS_L` pour les versions 4 à 6 contenaient le total de mots de contrôle d'erreur au lieu du nombre de mots de contrôle par bloc (ex: `[2, 40, 20]` au lieu de `[2, 40, 10]`), ce qui provoquait une incohérence de capacité sur les charges utiles étendues. Réaligné avec la spécification exacte de l'ISO/IEC 18004 Table 7 pour couvrir l'intégralité des versions 1 à 6 (jusqu'à 134 octets).

3. **Correction de la direction de balayage zigzag :**  
   L'expression d'évaluation de la montée en colonne (`($colonne + 1) & 2 === 0`) était faussée par la précédence des opérateurs PHP (`===` prioritaire sur `&`), neutralisant l'alternance. Remplacée par l'utilisation directe de l'état booléen `$versLeHaut`.

4. **Synchronisation de l'injection QR dans `OfficialDocumentService` et `DefaultDocumentGenerator` :**  
   `OfficialDocumentService` et `DefaultDocumentGenerator` réservent désormais le code opaque et injectent `urlVerification`, `codeVerification` et le SVG vectoriel `qrCodeSvg` dans le jeu de données avant le rendu du gabarit Blade, garantissant l'inclusion du QR scannable dans les PDF et dans le snapshot immuable persisté.

5. **Détection d'expiration duale dans `DocumentVerificationService` :**  
   Prise en compte conjointe de `valide_jusquau` et de `expires_at` pour basculer le statut public en `expire` dès l'atteinte de l'échéance.

---

## 3. Fichiers Concernés

### Composants Métier & Support
- [app/Support/QrCodeSvg.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Support/QrCodeSvg.php) : Déclaration de `$log`, correction des blocs Reed-Solomon `BLOCS_L`, balayage zigzag conforme.
- [app/Modules/StudentDossiers/Services/Pdf/OfficialDocumentService.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/StudentDossiers/Services/Pdf/OfficialDocumentService.php) : Injection de `qrCodeSvg` vectoriel via `QrCodeSvg::generer($url, 80)`.
- [app/Services/Documents/DefaultDocumentGenerator.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Documents/DefaultDocumentGenerator.php) : Réservation anticipée du code opaque et injection de `qrCodeSvg` avant compilation PDF.
- [app/Services/Documents/DocumentVerificationService.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Documents/DocumentVerificationService.php) : Détection d'expiration enrichie (`valide_jusquau` et `expires_at`).
- [app/Http/Controllers/VerifyDocumentController.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/VerifyDocumentController.php) : Contrôleur public sans authentification avec limitation de débit (throttle).
- [app/Modules/StudentDossiers/Services/DocumentStorageService.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/StudentDossiers/Services/DocumentStorageService.php) : Validation MIME réelle `finfo`, signatures magiques, UUID et nettoyage anti-traversal des noms de téléchargement.
- [app/Modules/StudentDossiers/Controllers/MonDossierController.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/StudentDossiers/Controllers/MonDossierController.php) : Téléchargement étudiant sécurisé anti-IDOR avec vérification d'appartenance de session.
- [app/Modules/StudentDossiers/Controllers/OfficialDocumentController.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Modules/StudentDossiers/Controllers/OfficialDocumentController.php) : Téléchargement scolarité avec filtrage strict par périmètre de faculté (`peutAccederFaculte`).

### Gabarits PDF
- [resources/views/modules/student-dossiers/pdf/attestation-reussite.blade.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/modules/student-dossiers/pdf/attestation-reussite.blade.php) : Intégration du cartouche QR SVG flottant.
- [resources/views/modules/student-dossiers/pdf/releve.blade.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/modules/student-dossiers/pdf/releve.blade.php) : Cartouche QR SVG.
- [resources/views/modules/student-dossiers/pdf/attestation-inscription.blade.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/modules/student-dossiers/pdf/attestation-inscription.blade.php) : Cartouche QR SVG.
- [resources/views/modules/student-dossiers/pdf/diplome.blade.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/modules/student-dossiers/pdf/diplome.blade.php) : Cartouche QR SVG.

### Suite de Tests Dédiée
- [tests/Feature/CoffreFortAttestationsQrTest.php](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/CoffreFortAttestationsQrTest.php) : 16 tests automatisés couvrant l'intégralité du cycle documentaire, de la cryptographie QR, des statuts publics et des invariants de sécurité.

---

## 4. Tests Réellement Exécutés & Résultats

### A. Suite de tests du Lot 07 (`CoffreFortAttestationsQrTest`)
```bash
php artisan test --compact --filter="CoffreFortAttestationsQrTest"
```
**Résultat :**
- Tests : **16 passed**
- Assertions : **85**
- Durée : 3.78s
- Code retour : 0

Détail des 16 tests certifiés :
1. `test_qr_code_svg_generateur_produit_svg_conforme` : validation de la structure XML/SVG vectorielle, de la matrice et du style `crispEdges`.
2. `test_qr_code_svg_gestion_erreurs_et_limites` : rejet explicite des chaînes vides via `DomainException`.
3. `test_qr_code_svg_rejet_payload_trop_volumineux` : rejet déterministe des charges utiles supérieures à la capacité maximale supportée (134 octets).
4. `test_qr_code_supporte_niveaux_m_et_l_dans_les_limites` : couverture complète des versions 1 à 6 en niveaux M et L.
5. `test_official_document_service_inclut_qr_code_et_url_verification` : émission d'attestation de réussite avec code opaque de 32 caractères, URL `/verify/{code}` et QR vectoriel dans le snapshot.
6. `test_default_document_generator_injecte_qr_code` : conformité du centre documentaire via `DefaultDocumentGenerator`.
7. `test_verification_publique_document_valide_sans_fuite_pii` : validation du masquage de nom (`B••••••`), absence de CIN, absence de note, présence du statut `Document valide.` et anonymisation par hachage de l'adresse IP.
8. `test_verification_statut_expire_revoque_remplace_et_inconnu` : vérification publique des 4 statuts d'invalidation (expiré, révoqué, remplacé par supersession, inexistant avec code HTTP 404).
9. `test_anti_idor_espace_etudiant_mon_dossier` : un étudiant ne peut en aucun cas télécharger le document d'un tiers (HTTP 404).
10. `test_anti_idor_administration_faculte_etrangere` : un administrateur scolarité ne peut pas accéder aux documents d'une faculté hors de son périmètre (HTTP 403 Forbidden).
11. `test_telechargement_document_revoque_renvoie_410` : un document révoqué renvoie immédiatement le code HTTP 410 Gone à l'étudiant comme à l'administrateur.
12. `test_nettoyage_nom_fichier_anti_header_injection_et_traversal` : neutralisation des séquences de traversal (`../`) et d'injection d'en-têtes HTTP (`\r\n`, guillemets).
13. `test_document_temporaire_ocr_purge_systematiquement_dans_finally` : vérification que le fichier temporaire de scan d'identité est supprimé du disque privé à l'issue de la requête.
14. `test_commande_marquer_documents_expires_traite_les_echeances` : bascule programmée des documents arrivés à échéance.
15. `test_commande_purger_orphelins_mode_simulation_et_execution` : exécution de la purge en mode simulation par défaut et suppression physique uniquement sous `--execute`.
16. `test_politique_de_retention_documentee_sans_invention_arbitraire` : confirmation que les durées de conservation restent `null` dans `config/retention.php` en attente de décision institutionnelle.

### B. Validation croisée avec les suites de régression documentaire
```bash
php artisan test --compact --filter="CoffreFortAttestationsQrTest|DocumentsPhase7Test|DocumentsPhase16Test|IdentityScanTest|DocumentsCatalogueTest"
```
**Résultat :**
- Tests : **75 passed**
- Assertions : **384**
- Durée : 20.78s
- Code retour : 0 (zéro régression)

### C. Contrôle de style et formatage PSR-12
```bash
vendor/bin/pint --dirty --test
```
**Résultat :** PASS (code retour : 0).

---

## 5. Résumé des Engagements et Invariants

| Invariant | Statut | Preuve |
|---|---|---|
| Stockage privé hermétique | RESPECTÉ | `Storage::disk('private')`, aucun fichier dans `public/` |
| QR Code sans dépendance externe | RESPECTÉ | `App\Support\QrCodeSvg` en pur PHP ISO/IEC 18004 |
| Zéro PII sur la page de vérification | RESPECTÉ | Nom masqué `B••••••`, pas de CIN, pas de notes, IP hachée en base |
| Cycle de vie complet | RESPECTÉ | Statuts `valide`, `expire`, `revoque`, `remplace` audités |
| Anti-IDOR rigoureux | RESPECTÉ | 404 sur tentative inter-étudiants, 403 sur tentative inter-facultés |
| Purge des scans temporaires | RESPECTÉ | Bloc `finally` dans `InscriptionController::scanIdentity` |
| Rétention sans invention | RESPECTÉ | `duree_jours` maintenu à `null` dans `config/retention.php` |

---

## 6. Verdict

**Verdict du Lot 07 : PASS**

La chaîne documentaire universitaire, le coffre-fort sécurisé, la génération vectorielle QR ISO/IEC 18004 et le portail de vérification publique sont entièrement opérationnels, certifiés par 75 tests d'intégration et prêts pour la production.

**Conditions de passage au Lot 08 :**
- Autorisation de passer au **Lot 08 — Assistant IA RAG avec citations**.
