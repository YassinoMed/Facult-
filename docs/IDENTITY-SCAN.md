# Scan CIN / Passeport et extraction automatique — Module d'inscription

> Parcours public optionnel : l'utilisateur scanne sa CIN tunisienne ou son
> passeport, l'OCR extrait les informations visibles, le formulaire est
> pré-rempli — l'utilisateur vérifie et corrige TOUJOURS avant l'inscription
> définitive. L'OCR n'est **jamais** une preuve d'identité.
>
> Implémentation de référence : `app/Services/Identity/*`,
> `app/Http/Requests/ScanIdentityDocumentRequest.php`,
> `InscriptionController::scanIdentity()`, route `inscription.identity.scan`,
> composant Alpine dans `resources/js/app.js` + `resources/views/inscription/create.blade.php`,
> `config/identity.php`, tests `IdentityParsersTest` (unit) et `IdentityScanTest` (feature).

---

## 1. Parcours (§1, §20)

```text
Choix du document (CIN ○ / Passeport ○)
    ↓
Scan 📷 (caméra mobile) ou import 📁 (JPG/PNG/PDF)
    ↓
POST /inscription/identity/scan (CSRF + throttle identity.scan)
    ↓
Validation serveur profonde → stockage temporaire PRIVÉ (nom aléatoire)
    ↓
OCR (moteur interchangeable) → parseur dédié → normalisation
    ↓
Contrôles de cohérence (format, plausibilité, MRZ checksums)
    ↓
Recherche BD fail-closed (n° document + date de naissance extraite)
    ├── trouvé → pré-remplissage depuis le dossier (données BD font foi)
    │           + signalisation des divergences OCR ↔ BD
    └── introuvable → pré-remplissage depuis l'OCR (avec confiance par champ)
    ↓
SUPPRESSION SYSTÉMATIQUE du fichier (§13 option A)
    ↓
L'utilisateur vérifie / corrige chaque champ (§9)
    ↓
Mot de passe saisi manuellement (§12) → inscription (validation stricte,
transaction, unicité CIN — aucune création automatique par l'OCR)
```

Le scan est **facultatif** : le formulaire manuel et la recherche CIN
(`/inscription/verifier-cin`) restent inchangés.

## 2. Architecture — OCR interchangeable (§4, §17)

```text
app/Services/Identity/
├── OcrServiceInterface.php      contrat applicatif : extract($path, $type): OcrResult
├── OcrService.php                orchestrateur : moteur → détection → parseur → normalisation
├── OcrEngineInterface.php        contrat MOTEUR BRUT : rawText($path): string
├── Engines/NullOcrEngine.php     moteur par défaut (placeholder — aucun texte)
├── Engines/TesseractOcrEngine.php  driver « tesseract » (binaire local)
├── Engines/PpocrOcrEngine.php      driver « ppocr » (PaddleOCR server,
│                                    runner Python RapidOCR/ONNX — scripts/ocr/)
├── Engines/HfVlmOcrEngine.php      driver « hfvlm » (VLM HF —
│                                    Qwen3-VL-4B MLX 4-bit, scripts/ocr/)
├── MrzParser.php                MRZ TD3 ICAO 9303 + checksums 7-3-1
├── CinParser.php                CIN tunisienne (texte OCR à libellés)
├── PassportParser.php           passeport : MRZ prioritaire, repli libellés
├── IdentityDataNormalizer.php   nettoyage identifiants/noms/dates/sexes
├── OcrResult.php                DTO intermédiaire + confiance par champ
└── IdentityDocumentService.php  service central (upload, validation fichier,
                                  extraction, cohérence, matching BD,
                                  divergences, payload de réponse, purge)
```

**Remplacer le moteur OCR** = implémenter `OcrEngineInterface` (Tesseract,
service cloud…) + l'ajouter à `identity.ocr.drivers` + définir
`IDENTITY_OCR_DRIVER`. Aucune autre couche ne change : parsers, normalisation,
service métier, contrôleur, tests et interface restent identiques.

### Brancher Tesseract (moteur RÉEL, inclus dans ce projet)

`App\Services\Identity\Engines\TesseractOcrEngine` est fourni — OCR local
gratuit, arabe + français. Installation :

```bash
# 1. Binaire (macOS — Homebrew)
brew install tesseract

# 2. Données de langue (CIN tunisienne : arabe + français)
cd /usr/local/share/tessdata/
curl -sSL -O https://github.com/tesseract-ocr/tessdata_fast/raw/main/ara.traineddata
curl -sSL -O https://github.com/tesseract-ocr/tessdata_fast/raw/main/fra.traineddata
tesseract --list-langs   # doit lister ara et fra

# 3. Activer le moteur (.env)
IDENTITY_OCR_DRIVER=tesseract
```

Comportement du driver :
- indice de langue `ara+lat` → modèle combiné Tesseract `ara+fra` (§2) ;
- PDF : conversion de la première page via `sips` (macOS) — Tesseract
  ne lit pas les PDF nativement ;
- **fail-soft total** : binaire absent, langues manquantes, conversion
  impossible, timeout → texte vide + alertes « vérification manuelle »,
  JAMAIS d'exception vers l'utilisateur ;
- arguments passés en TABLEAU au Process (aucun shell, aucune injection) ;
- timeout configurable (`IDENTITY_OCR_TIMEOUT`, 30 s par défaut).

### Brancher PaddleOCR (driver `ppocr` — recommandé, inclus)

`App\Services\Identity\Engines\PpocrOcrEngine` appelle le runner Python
`scripts/ocr/ppocr_runner.py` (RapidOCR + ONNX Runtime, CPU, aucune API
externe). Gabarit de modèles choisi par `IDENTITY_PPOCR_MODELE` :

| Gabarit « server » (défaut — précision max) | Modèle | Taille |
| --- | --- | --- |
| Détection des zones de texte | `PP-OCRv5_server_det` | ~101 Mo |
| Reconnaissance français/latin | `PP-OCRv6_rec_medium` | ~117 Mo |
| Reconnaissance anglais/MRZ | `PP-OCRv6_rec_medium` (langue `en`) | ~117 Mo |
| Reconnaissance arabe (CIN — seul modèle arabe) | `arabic_PP-OCRv5_rec_mobile` | ~16 Mo |

| Gabarit « mobile » (`IDENTITY_PPOCR_MODELE=mobile` — le plus rapide) | Modèle | Taille |
| --- | --- | --- |
| Détection des zones de texte | `PP-OCRv5_mobile_det` | ~4,7 Mo |
| Reconnaissance anglaise (passeport/MRZ) | `en_PP-OCRv5_mobile_rec` | ~7,5 Mo |
| Reconnaissance multi-langues | `PP-OCRv5_mobile_rec` | ~16 Mo |

La langue de reconnaissance (« en », « latin » = français accentué…,
« arabic » = CIN arabe) est choisie par appel selon la table
`identity.ocr.ppocr.langues` (codes applicatifs de `config/ocr.php`
→ langue RapidOCR). Les modèles ONNX sont téléchargés UNE fois dans le
venv puis lus en cache local (premier appel « server » ≈ 220 Mo).

```bash
# 1. Environnement Python isolé (aucune dépendance système)
python3 -m venv scripts/ocr/.venv
scripts/ocr/.venv/bin/pip install -r scripts/ocr/requirements.txt

# 2. Activer le moteur (.env)
IDENTITY_OCR_DRIVER=ppocr
```

Comportement du driver :
- **fail-soft identique** aux autres drivers : venv/script absent,
  modèle indisponible, erreur runner, timeout → texte vide + alertes,
  jamais d'exception ;
- arguments passés en TABLEAU au Process (aucun shell) ;
- PDF : conversion de la première page via `pdftoppm` (repli `sips`) ;
- délai par appel `IDENTITY_PPOCR_TIMEOUT` (180 s — le premier appel
  télécharge puis charge les modèles ONNX « server » ; chaque appel
  relance Python, privilégier un scan = une action ponctuelle, déjà
  limitée à 5/min par IP, §19).

> **macOS / Apple Silicon — Rosetta** : si PHP est une build x86_64
> (Homebrew `/usr/local` sous Rosetta), les wheels arm64 du venv ne se
> chargent pas (`dlopen … incompatible architecture`). Le driver force
> alors la tranche native via `/usr/bin/arch -arm64` — réglable par
> `IDENTITY_PPOCR_ARCH` (défaut : `arm64` sur macOS, vide ailleurs).

### Brancher un VLM Hugging Face (driver `hfvlm`)

`App\Services\Identity\Engines\HfVlmOcrEngine` appelle le runner Python
`scripts/ocr/hf_runner.py` : **Qwen3-VL-4B-Instruct** (vision-language,
~2,6 Go en **MLX 4-bit**, local, aucune API externe) transcrit exactement
le texte du document ligne par ligne — bien plus robuste sur photos
bruitées/inclinées qu'un OCR à segments, y compris en arabe.

```bash
# 1. Même venv que le driver ppocr (ajout de mlx-vlm + torchvision)
/usr/bin/arch -arm64 scripts/ocr/.venv/bin/pip install \
    -r scripts/ocr/requirements.txt

# 2. Pré-télécharger le modèle (~2,6 Go — sinon le 1er appel le fait,
#    au risque de toucher le timeout)
/usr/bin/arch -arm64 scripts/ocr/.venv/bin/python -c \
    "from huggingface_hub import snapshot_download; \
snapshot_download('mlx-community/Qwen3-VL-4B-Instruct-4bit')"

# 3. Activer le moteur (.env) — IA DÉSACTIVÉE par défaut : le flag
#    explicite est requis, sinon rawText() rend '' sans toucher au modèle
IDENTITY_OCR_DRIVER=hfvlm
IDENTITY_HFVLM_ENABLED=true
```

Comportement du driver :
- langue annoncée au prompt selon `identity.ocr.hfvlm.langues`
  (codes applicatifs → « français », « arabe », « English »…) ;
- modèle surchargeable : `IDENTITY_HFVLM_MODEL`
  (défaut `mlx-community/Qwen3-VL-4B-Instruct-4bit` ; cache HF standard
  `~/.cache/huggingface`, surchargeable via `HF_HOME`) ;
- **fail-soft identique** aux autres drivers (venv/script/modèle absent,
  erreur, timeout → texte vide + alertes) ;
- délai par appel `IDENTITY_HFVLM_TIMEOUT` (300 s).

> **Performance** : chaque appel recharge le modèle (~20-60 s à froid
> sur Apple Silicon 8 Go ; ~4-5 Go RAM en pointe). Le pipeline CIN
> multi-variantes × zones (§5) appelle le moteur de nombreuses fois →
> scan CIN en plusieurs minutes. Recommandation :
> **passeport/pleine page → `hfvlm`**, **CIN → `ppocr`** (4-8 s/appel) ;
> bascule par `IDENTITY_OCR_DRIVER` selon le parcours ou la machine.
> MLX requiert arm64 : sous PHP x86_64 (Rosetta), l'exécution est forcée
> via `arch -arm64` (`IDENTITY_HFVLM_ARCH`, défaut arm64 sur macOS).
>
> > **IA désactivée par défaut** : `identity.ocr.hfvlm.active` vaut `false`
> > (`IDENTITY_HFVLM_ENABLED`) — le moteur rend `''` sans exécuter le
> > modèle, et le test d'intégration réelle est esquivé. Sans moteur
> > activé (`null`), voir le repli manuel ci-dessous.

Sans moteur activé (`null`), le panneau de scan affiche
« non détecté » partout avec les alertes de vérification manuelle —
c'est le comportement du placeholder, pas un bug : le formulaire
manuel et la recherche CIN restent pleinement fonctionnels.

Le contrôleur reste **léger** (aucune logique OCR dans Controller/Blade/JS) :
validation FormRequest → service → JSON whitelist.

## 3. Mapping des données (schéma existant, rien inventé)

| OcrResult | Champ formulaire/BD | Persisté ? |
|---|---|---|
| `document_number` (CIN **ou** n° passeport normalisé) | `cin` (identifiant métier unique, varchar 30) | ✅ |
| `last_name` / `first_name` | `nom` / `prenom` | ✅ |
| `date_of_birth` | `date_naissance` | ✅ |
| `sex` | `sexe` (M/F) | ✅ |
| `place_of_birth`, `nationality` | — | ❌ affichés à l'écran (information), aucune colonne n'existe |

Pour un passeport, le numéro normalisé (majuscules, `<`/espaces retirés)
devient l'identifiant unique dans la colonne `cin` — un seul identifiant
métier par personne, pas de duplication.

## 4. Confiance et seuils (§8)

Champ extrait → score `[0..1]` (config `identity.confiance`) :

| Source | Confiance |
|---|---|
| MRZ avec checksums valides | 0.98 par champ |
| CIN 8 chiffres valides | 0.95 |
| CIN ambiguë (« 1234567B ») | 0.45 → alerte de vérification manuelle |
| MRZ à checksum invalide | 0.45 par champ |
| Passeport expiré | 0.40 sur `expiry_date` |
| Repli libellés OCR (passeport sans MRZ) | 0.60 |

Seuils : `eleve ≥ 0.90` (pré-remplissage silencieux), `moyen ≥ 0.70`
(pré-remplissage + avertissement), sinon vérification manuelle. Configurables
via `IDENTITY_CONFIANCE_ELEVE` / `IDENTITY_CONFIANCE_MOYEN`.

**Jamais** un étudiant n'est créé sur la base d'un score OCR : la validation
humaine puis l'inscription finale (`InscriptionRequest`, transaction,
unicité) restent les seules voies d'écriture.

## 5. Recherche BD et divergences (§10-11)

- `matchExistingStudent` réutilise `StudentLookupService::trouverPourPrefill`
  (n° **+ date de naissance extraite**) — **fail-closed** : un numéro seul, ou
  avec une date divergente, ne révèle JAMAIS l'existence d'un dossier
  (anti-énumération, cohérent avec l'audit §P0-4 de `verifier-cin`).
- Dossier trouvé → les données BD validées **font foi** ; l'OCR ne les écrase
  jamais. Chaque écart (ex. « Mohamed » vs « Mohammed ») est signalé :
  champ, valeur extraite, valeur enregistrée — vérification humaine.
- La réponse ne contient jamais : `password`, hash, `remember_token`,
  identifiant interne, contenu OCR brut.

## 6. Décision de conservation — §13 OPTION A (temporaire)

**Décision explicite de minimisation** : `upload → OCR → suppression
SYSTÉMATIQUE` (dans un `finally`, même en cas de panne moteur). Raison :
aucune colonne/module de pièces d'identité n'existe dans le modèle de données ;
les documents métiers (relevés, diplômes…) relèvent du module Archives via
`DocumentAcademique` avec son propre cycle de vie. L'option B (archivage
privé + Policy + audit + rétention) reste une extension documentée sans
changer l'architecture.

Le stockage temporaire : disque `local` (`storage/app/private`), répertoire
`identity-scan/tmp`, nom `Str::uuid()` — **jamais** le nom client, jamais
dans `public/`.

## 7. Sécurité des fichiers (§14) et données (§15)

Contrôles serveur (extension seule jamais crue) :

1. extension dans la whitelist (jpg/jpeg/png/pdf) ;
2. MIME **réel** par contenu (finfo) dans la whitelist ;
3. cohérence extension ↔ MIME (anti-polyglot : un « .jpg » contenant un PDF
   est rejeté, et réciproquement) ;
4. PDF : signature `%PDF-` ; image : `getimagesize` (fichier corrompu rejeté) ;
5. taille ≤ `identity.upload.taille_max` (8 Mo par défaut) ;
6. nom client jamais utilisé comme chemin + garde `..` ;
7. formats actifs/exécutables exclus par construction (whitelist stricte).

Rate limiting dédié `identity.scan` (5/min/IP par défaut,
`IDENTITY_THROTTLE_SCAN`) : OCR coûteux, anti-uploads massifs. Route dans le
groupe `web` ⇒ CSRF obligatoire, publique (sans auth).

Journaux : uniquement `identity.ocr.completed` (type demandé, type utilisé,
durée) — **jamais** le contenu OCR, jamais les images, jamais les données
personnelles extraites.

## 8. Anti-fraude / cohérence (§16)

- format CIN (8 chiffres attendus) ;
- dates plausibles (`checkdate`, naissance non future) ;
- passeport : expiration contrôlée + checksums MRZ ICAO complets
  (n°, naissance, expiration, n° personnel, composite) ;
- CIN existant : cohérence OCR ↔ BD (divergences signalées) ;
- confiance faible sur l'identifiant → « vérification manuelle nécessaire » ;
- le système ne déclare JAMAIS une personne « authentifiée » sur la base
  d'un OCR.

## 9. Réponse API (§18)

```json
{
    "success": true,
    "document_type": "cin",
    "data": {"cin": "12345678", "nom": "BEN ALI", "prenom": "MOHAMED",
             "date_naissance": "2002-02-15", "sexe": "M",
             "lieu_naissance": "SFAX", "nationalite": null},
    "confidence": {"cin": 0.95, "nom": 0.75, "prenom": 0.75,
                   "date_naissance": 0.9, "sexe": 0.85, "lieu_naissance": 0.75},
    "seuils": {"eleve": 0.9, "moyen": 0.7},
    "existing_student": false,
    "alerts": []
}
```

Avec dossier existant : `existing_student: true`, `source: "dossier_existant"`,
`etudiant` (DTO whitelist de `StudentLookupService::donneesPrefill`),
`divergences` éventuelles. Passé 5 scans/minute : HTTP 429.

## 10. Interface (§2, §20)

Carte dédiée en tête de formulaire (facultative) : choix CIN/Passeport →
caméra mobile (`capture="environment"`) ou import → états visuels
(analyse en cours, erreurs, rate limiting) → panneau « Informations
détectées » avec badge de confiance par champ (vert/orange/rouge) →
divergences + alertes + message anti-doublon si dossier existant →
« Vérifier ces informations et les reporter dans le formulaire » →
scroll vers le bloc Identité où tous les champs restent modifiables
et visuellement marqués comme pré-remplis.

## 11. Tests (§21)

- **Unitaires** (`IdentityParsersTest`, 18) : checksums ICAO valides/invalides,
  dates MRZ, CIN extraite/bruitée/ambiguë (« 1234567B » → confiance
  dégradée), confusions OCR (O→0, I→1), repli libellés, passeport expiré,
  données manquantes jamais inventées, dates futures et sexes invalides rejetés.
- **Moteur réel** (`TesseractEngineTest`, 3) : fail-soft garanti (binaire
  absent/erreur → chaîne vide), PDF sans exception, et intégration RÉELLE
  OCR → parseur → pré-remplissage sur une image CIN générée à la volée
  (esquivée si le binaire `tesseract` est absent de la machine).
- **Fonctionnels** (`IdentityScanTest`, 23) : image/PDF valides, trop
  volumineux, mauvais MIME réel, extension dangereuse (`.php`), corrompu,
  polyglot PDF↔JPG, path traversal (nom client jamais utilisé comme chemin),
  type invalide, MRZ passeport, OCR muet (fail-soft), CIN ambiguë, dossier
  identique/divergent/date divergente (fail-closed), aucun doublon créé,
  inscription refusée sur CIN existante, réponse sans password/hash/ID,
  suppression du fichier (succès ET panne), rate limiting, endpoint public +
  CSRF, page embarquant le parcours.

## 12. OCR ARABE — CIN tunisienne (ar → extraction → normalisation → translittération)

### Parcours (§28)

```text
CIN tunisienne (العربية + mentions latines)
    ↓ OCR (indice de langue 'ara+lat' prioritaire, §1-2)
    ↓ Texte arabe brut (jamais traduit, jamais loggé)
    ↓ ArabicLabelDictionary → identification des champs (§4)
    ↓ ArabicNormalizer → normalisation arabe (§16)
    ↓ ArabicTransliterationService → translittération latin (§7-8)
    ↓ Validation des données (champs structurés = normalisation, §13)
    ↓ Recherche BD fail-closed (CIN + date extraite)
    ├── trouvé   → données BD font foi (§20) + comparaison intelligente
    └── introuvable → données OCR translittérées
    ↓ Pré-remplissage + originaux arabes conservés (§9/§12)
    ↓ Vérification humaine OBLIGATOIRE → mot de passe manuel → inscription
```

### Responsabilités séparées (§24)

| Service | Rôle |
|---|---|
| `ArabicNormalizer` | détection de langue (ar/latin/mixte §2), normalisation Unicode (diacritiques محمّد≡محمد, tatweel, variantes أ/إ/آ→ا, ى→ي), **chiffres arabo-indiques ٠-٩ → 0-9** (§5-6), comparaison |
| `ArabicLabelDictionary` | dictionnaire de libellés arabes EXTENSIBLE (اللقب, الاسم, تاريخ الولادة, مكان الولادة, الجنسية, الجنس, رقم بطاقة التعريف الوطنية…) avec variantes d'écriture |
| `ArabicTransliterationService` (+ `TransliterationServiceInterface`) | **translittération déterministe** des noms propres — JAMAIS une traduction sémantique (محمد → Mohamed, بن علي → Ben Ali) : dictionnaire de tokens courants + repli lettre-à-lettre francophone tunisien ; résolution des **lieux** via la table administrative (§14) |
| `ArabicOcrService` | assemblage du résultat riche : langue détectée, valeurs translittérées, **originaux arabes conservés** (§9/§10) |
| `IdentityMatcher` | comparaison INTELLIGENTE OCR↔BD (§15-16) : normalisation Unicode → normalisation arabe → translittération → casse/ponctuation ignorées → verdict `identique` / `similaire` (tolérance OCR : Mohamed vs Mohammed) / `different` |
| `IdentityPrefillService` | payload de réponse : langue, data (translittérée pour le formulaire), `originals` (raw arabe + translittération pour comparaison §12), jamais password/hash |

### Découpage des traitements (§13)

- **Champs structurés** (CIN, date, sexe) → normalisation : espaces/parasites supprimés, chiffres arabo-indiques convertis, date → `Y-m-d` (stockage DATE), sexe ذكر/أنثى → M/F. **Le CIN n'est jamais translittéré** (§5).
- **Noms propres** (nom, prénom, lieu) → translittération. L'original arabe est conservé dans la réponse (`originals.<champ>.raw` + `.transliterated`) pour vérification humaine comparative à l'écran (§12) — affiché via `x-text` (textContent, jamais de HTML).

### Lieux de naissance (§14)

Table administrative `lieux_naissance` (utf8mb4) : `arabic_name` (affichage), `arabic_name_key` (normalisée, index unique), `latin_name`, `official_name`, `country`. Résolution : clé normalisée → toute variante d'écriture mène au même lieu (صفاقس → Sfax). Lieu inconnu → repli translittération lettre-à-lettre. Extensible : `LieuNaissance::enregistrer('المدينة', 'Ville')` — seeder `LieuNaissanceSeeder` (20 villes tunisiennes).

### Comparaison BD multilingue (§15-16, §23)

L'OCR arabe بن علي/محمد correspond au dossier latin BEN ALI/Mohamed **après translittération** → pré-remplissage depuis la BD sans fausse divergence ; les variantes d'écriture (محمّد, على) sont neutralisées avant comparaison ; une faute de lecture admissible (distance ≤ 1-2) est « similaire » → signalée pour vérification ; un écart réel (TRABELSI) → divergence, la donnée BD n'est jamais écrasée silencieusement (§20). Le registre garde sa recherche latine — la recherche multilingue s'obtient par la même translittération (testée au niveau scan).

### utf8mb4 (§22)

MySQL déjà en `utf8mb4`/`utf8mb4_unicode_ci` (config/database.php) : l'arabe persiste sans corruption (test de round-trip بن علي en BD). Les originaux arabes ne sont PAS persistés par choix de minimisation (§13 option A) : ils ne servent qu'à la vérification humaine dans la réponse de scan, jamais stockés, jamais journalisés.

### Sécurité OCR arabe (§25, §27)

Le texte OCR — arabe compris — est **non fiable** : réponse JSON strict, affichage via `x-text` (textContent) sans jamais `{!! !!}` ; l'original arabe ne conserve QUE les caractères arabes (toute charge latine `<script>`, SQL… en est retirée par construction) ; identifiants nettoyés et requêtes Eloquent paramétrées (injection SQL testée) ; jamais de contenu OCR dans les logs (seuls type, langue, durée).

## 13. Compatibilité (§22)

Le scan appartient exclusivement au parcours public d'inscription. Les
administrateurs (super admin, admin de faculté) n'utilisent JAMAIS ce
mécanisme : leur espace `/admin` conserve ses propres contrôles (RBAC,
policies, isolation par faculté — cf. `docs/PROFILS-ET-SCOPING.md`).
Le CIN d'un administrateur n'existe pas dans le registre étudiant
(testé). Toutes les protections existantes (CSRF, throttles, validation
d'inscription, pré-remplissage CIN + date) sont conservées.

Tests du pipeline arabe : `ArabicIdentityTest` (27 unitaires :
normalisation, chiffres arabo-indiques, translittération déterministe,
lieux, libellés arabes, matcher) et `ArabicCinScanTest` (11
fonctionnels : parcours HTTP complet, chiffres arabo-indiques,
correspondance OCR arabe ↔ BD latine sans divergence, écart réel
signalé, XSS/SQL via OCR neutralisés, round-trip utf8mb4).
