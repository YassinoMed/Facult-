#!/usr/bin/env python3
"""Runner OCR PaddleOCR (RapidOCR / ONNX Runtime, CPU).

Appelé par App\\Services\\Identity\\Engines\\PpocrOcrEngine via Process
(arguments en tableau — aucun shell) :

    ppocr_runner.py <image.png> [rec_lang] [modele]

rec_lang : en | latin | arabic | ch   (défaut : latin)
modele   : server | mobile             (défaut : server)

Mode « server » (défaut — meilleure précision) :
    - détection : PP-OCRv5_server_det (~101 Mo) ;
    - reconnaissance latin/en : PP-OCRv6_rec_medium (multilingue latin,
      PAS d'arabe — langue « fr »/« en » passée en texte à RapidOCR) ;
    - reconnaissance arabic : arabic_PP-OCRv5_rec_mobile (~16 Mo — seul
      modèle arabe disponible, la détection server reste appliquée) ;
    - reconnaissance ch : ch_PP-OCRv5_rec_server (~89 Mo).

Mode « mobile » (historique, le plus rapide ~2-4 s/appel) : PP-OCRv5
mobile partout (dét ~4,7 Mo + rec 7,5-16 Mo).

Contrat (identique au driver tesseract) :
    - texte reconnu sur stdout en UTF-8, reconstruit en LIGNES de
      texte (boîtes d'une même ligne regroupées, ordre de lecture) ;
    - RIEN d'autre sur stdout ; erreurs → stderr + code de sortie != 0 ;
    - image vide/illisible : stdout vide, code 0 (fail-soft côté PHP).

Les modèles ONNX sont téléchargés une seule fois dans le venv
(scripts/ocr/.venv/.../rapidocr/models) puis lus en cache local — le
premier appel en mode « server » télécharge ~220 Mo.
"""

from __future__ import annotations

import os
import sys

# onnxruntime est bavard sur stderr (jamais stdout : contrat préservé).
os.environ.setdefault("ORT_LOGGING_LEVEL", "3")


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: ppocr_runner.py <image> [en|latin|arabic|ch] [server|mobile]", file=sys.stderr)
        return 2

    image = sys.argv[1]
    rec_lang = (sys.argv[2] if len(sys.argv) > 2 else "latin").lower()
    modele = (sys.argv[3] if len(sys.argv) > 3 else "server").lower()

    if not os.path.isfile(image):
        print(f"image introuvable: {image}", file=sys.stderr)
        return 2

    try:
        from rapidocr import (
            EngineType,
            LangDet,
            LangRec,
            ModelType,
            OCRVersion,
            RapidOCR,
        )
    except ImportError as exc:
        print(f"rapidocr indisponible: {exc}", file=sys.stderr)
        return 3

    langues = {
        "en": LangRec.EN,
        "latin": LangRec.LATIN,
        "arabic": LangRec.ARABIC,
        "ch": LangRec.CH,
    }
    if rec_lang not in langues:
        print(f"langue inconnue: {rec_lang}", file=sys.stderr)
        return 2

    if modele not in ("server", "mobile"):
        print(f"modele inconnu: {modele}", file=sys.stderr)
        return 2

    params = construire_parametres(rec_lang, modele, langues)

    try:
        engine = RapidOCR(params=params)
        result = engine(image)
    except Exception as exc:  # modèle non téléchargeable, image illisible…
        print(f"ocr: {exc}", file=sys.stderr)
        return 4

    txts = getattr(result, "txts", None) if result is not None else None
    if not txts:
        return 0  # rien lu : stdout vide (fail-soft côté PHP)

    lignes = normaliser_en_lignes(txts, getattr(result, "boxes", None))

    sys.stdout.write("\n".join(lignes))
    sys.stdout.write("\n")
    return 0


def normaliser_en_lignes(txts: tuple, boxes) -> list:
    """Rétablit le niveau LIGNE attendu par les parseurs PHP : le det
    « server » isole parfois les mots d'une même ligne (« BEN » + « ALI »)
    — on regroupe les boîtes dont les centres se recouvrent verticalement,
    dans l'ordre de lecture. Repli sur txts brut si les boîtes manquent."""
    try:
        if boxes is None or len(boxes) != len(txts):
            raise ValueError
        metriques = []
        for txt, box in zip(txts, boxes):
            points = [(float(p[0]), float(p[1])) for p in box]
            ys = [p[1] for p in points]
            xs = [p[0] for p in points]
            metriques.append((min(ys), max(ys), min(xs), txt.strip()))
        metriques.sort(key=lambda m: ((m[0] + m[1]) / 2, m[2]))
    except (TypeError, ValueError, IndexError):
        return [t.strip() for t in txts if t and t.strip()]

    lignes = []
    for y_min, y_max, x, texte in metriques:
        if not texte or not any(c.isalnum() for c in texte):
            continue  # poussière OCR : token sans lettre ni chiffre (« + »…)
        centre = (y_min + y_max) / 2
        hauteur = max(y_max - y_min, 1.0)
        for ligne in lignes:
            centre_l = (ligne["y_min"] + ligne["y_max"]) / 2
            hauteur_l = max(ligne["y_max"] - ligne["y_min"], 1.0)
            # chevauchement vertical suffisant = même ligne de texte
            if abs(centre - centre_l) <= 0.6 * max(hauteur, hauteur_l):
                ligne["y_min"] = min(ligne["y_min"], y_min)
                ligne["y_max"] = max(ligne["y_max"], y_max)
                ligne["mots"].append((x, texte))
                break
        else:
            lignes.append({"y_min": y_min, "y_max": y_max, "mots": [(x, texte)]})

    lignes.sort(key=lambda l: (l["y_min"] + l["y_max"]) / 2)
    sorties = []
    for ligne in lignes:
        texte = " ".join(t for _, t in sorted(ligne["mots"]))
        if texte.strip():
            sorties.append(texte.strip())
    return sorties


def construire_parametres(rec_lang: str, modele: str, langues: dict) -> dict:
    """Paramètres RapidOCR : détection + reconnaissance adaptées à la
    langue demandée et au gabarit (« server » = précision maximale,
    « mobile » = historique rapide). Appelée APRÈS l'import réussi de
    rapidocr dans main() (import mis en cache — aucun surcoût)."""
    from rapidocr import (
        EngineType,
        LangDet,
        LangRec,
        ModelType,
        OCRVersion,
    )

    det_base = {
        "Det.engine_type": EngineType.ONNXRUNTIME,
        "Det.lang_type": LangDet.CH,
    }
    det_mobile = {
        **det_base,
        "Det.model_type": ModelType.MOBILE,
        "Det.ocr_version": OCRVersion.PPOCRV5,
    }
    det_server = {
        **det_base,
        "Det.model_type": ModelType.SERVER,
        "Det.ocr_version": OCRVersion.PPOCRV5,
    }
    rec_v5_mobile = {
        "Rec.engine_type": EngineType.ONNXRUNTIME,
        "Rec.lang_type": langues[rec_lang],
        "Rec.model_type": ModelType.MOBILE,
        "Rec.ocr_version": OCRVersion.PPOCRV5,
    }

    if modele == "mobile":
        return {**det_mobile, **rec_v5_mobile}

    if rec_lang == "arabic":
        # L'arabe n'existe qu'en PP-OCRv5 mobile — seule la détection
        # passe en server (elle porte l'essentiel du gain sur photos).
        return {**det_server, **rec_v5_mobile}

    if rec_lang in ("latin", "en"):
        # PP-OCRv6 (multilingue latin) : rec le plus récent/précis pour
        # le français & le MRZ. RapidOCR accepte une langue en texte
        # (« fr »/« en ») pour la v6 (aucune valeur d'enum correspond).
        return {
            **det_server,
            "Rec.engine_type": EngineType.ONNXRUNTIME,
            "Rec.lang_type": "fr" if rec_lang == "latin" else "en",
            "Rec.model_type": ModelType.MEDIUM,
            "Rec.ocr_version": OCRVersion.PPOCRV6,
        }

    # « ch » : le rec server v5 existe
    return {
        **det_server,
        "Rec.engine_type": EngineType.ONNXRUNTIME,
        "Rec.lang_type": LangRec.CH,
        "Rec.model_type": ModelType.SERVER,
        "Rec.ocr_version": OCRVersion.PPOCRV5,
    }


if __name__ == "__main__":
    raise SystemExit(main())
