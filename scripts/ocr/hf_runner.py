#!/usr/bin/env python3
"""Runner OCR « modèle HF » : Qwen3-VL-4B-Instruct (MLX 4-bit).

Appelé par App\\Services\\Identity\\Engines\\HfVlmOcrEngine via Process
(arguments en tableau — aucun shell) :

    hf_runner.py <image> [langue] [model_id]

    - langue   : mot libre passé au prompt ('français', 'arabe'…)
                 résolu par identity.ocr.hfvlm.langues — défaut 'français'
    - model_id : dépôt HF/MLX (défaut identity.ocr.hfvlm.modelle)

Contrat (identique aux autres runners du dossier) :
    - texte transcrit sur stdout en UTF-8, une ligne par ligne ;
    - RIEN d'autre sur stdout ; erreurs → stderr + code de sortie != 0 ;
    - image vide/illisible : stdout vide, code 0 (fail-soft côté PHP).

Le modèle (~2,6 Go, 4-bit — ~4-5 Go RAM en pointe) est téléchargé UNE
fois dans le cache HF (~/.cache/huggingface — surchargeable via HF_HOME)
puis lu en cache local. Conçu pour Apple Silicon ≥ 8 Go.
"""

from __future__ import annotations

import os
import sys

# Journalisation discrète sur stderr uniquement (stdout = texte OCR).
os.environ.setdefault("TRANSFORMERS_VERBOSITY", "error")
os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: hf_runner.py <image> [langue] [model_id]", file=sys.stderr)
        return 2

    image = sys.argv[1]
    langue = (sys.argv[2] if len(sys.argv) > 2 else "français").strip() or "français"
    model_id = (sys.argv[3] if len(sys.argv) > 3 else "").strip() or "mlx-community/Qwen3-VL-4B-Instruct-4bit"

    if not os.path.isfile(image):
        print(f"image introuvable: {image}", file=sys.stderr)
        return 2

    try:
        from mlx_vlm import generate, load
        from mlx_vlm.prompt_utils import apply_chat_template
    except ImportError as exc:
        print(f"mlx-vlm indisponible: {exc}", file=sys.stderr)
        return 3

    try:
        model, processor = load(model_id)

        prompt = (
            "You are an OCR engine. Transcribe exactly every text visible "
            f"in this image ({langue}), ONE LINE PER LINE, in reading "
            "order — never merge several document lines into one. Copy "
            "every character exactly as printed, including digits, "
            "punctuation and diacritics. Answer ONLY with the raw "
            "transcribed text: no title, no comment, no markdown, no "
            "added punctuation, no translation."
        )
        formatted = apply_chat_template(processor, model.config, prompt, num_images=1)

        sortie = generate(
            model,
            processor,
            prompt=formatted,
            image=image,
            max_tokens=1024,
            temp=0.0,
            verbose=False,
        )
    except Exception as exc:  # modèle non téléchargeable, image illisible…
        print(f"ocr: {exc}", file=sys.stderr)
        return 4

    texte = sortie.text if hasattr(sortie, "text") else str(sortie)
    texte = texte.strip().strip("`").strip()

    if not texte:
        return 0  # rien lu : stdout vide (fail-soft côté PHP)

    # Nettoyage minimal : vire les lignes vides et les bavardages courants
    lignes = []
    for l in texte.splitlines():
        l = l.strip().lstrip("-*•> ")
        if l and not l.startswith("```"):
            lignes.append(l)

    sys.stdout.write("\n".join(lignes))
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
