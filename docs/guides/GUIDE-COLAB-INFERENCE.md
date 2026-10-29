# GUIDE OPÉRATIONNEL : MOTEUR D'INFÉRENCE GOOGLE COLAB
## UniRegistre Assistant IA — Mode Prototypage Souverain

Ce guide détaille la mise en œuvre du moteur d'inférence open-source distant sur **Google Colab** pour le prototypage de l'assistant IA UniRegistre.

---

## 1. Principes et Invariants de Sécurité

1. **Aucun accès à l'infrastructure interne :** Le notebook Google Colab n'a AUCUN accès à la base de données MySQL locale du Mac, aucun accès au système de fichiers de l'application, aucun accès au terminal.
2. **Flux réseau strictement unidirectionnel :** C'est le serveur Laravel sur le Mac qui émet des requêtes HTTP POST sortantes chiffrées vers le tunnel HTTPS Colab.
3. **Authentification forte côté serveur :** Chaque requête transmet un jeton Bearer secret (`Authorization: Bearer <TOKEN>`), défini dans `.env` et validé par le notebook.
4. **Minimisation stricte des données :** Le payload envoyé à Colab ne contient aucun secret, aucune clé `.env`, aucun mot de passe, aucun dump MySQL, et aucune PII (CIN masqué `CIN_***`).
5. **Fail-Soft souverain :** En cas d'arrêt, déconnexion ou expiration de session Colab, le serveur Laravel renvoie une erreur contrôlée (HTTP 504) et une bannière informative. **Aucun basculement automatique silencieux vers un service tiers payant n'est opéré**.

---

## 2. Configuration dans UniRegistre (`.env`)

Dans votre fichier `.env` local (sur votre Mac) :

```ini
# Activation du fournisseur Colab
ASSISTANT_IA_COLAB_ACTIF=true
ASSISTANT_IA_COLAB_FORCER_HTTPS=true

# URL publique HTTPS générée par Cloudflare Tunnel ou Ngrok
ASSISTANT_IA_COLAB_URL=https://votre-tunnel-colab.trycloudflare.com/v1

# Jeton secret partagé entre Laravel et le serveur Colab
ASSISTANT_IA_COLAB_TOKEN=uniregistre_secret_colab_token_2026

# Modèle open-source chargé dans Colab
ASSISTANT_IA_COLAB_MODELE=qwen2.5-coder-7b-instruct
```

---

## 3. Script Python du Serveur Colab (FastAPI + HuggingFace / vLLM)

Dans votre notebook Colab (sur GPU T4 gratuit) :

```python
# Cellule 1 : Installation des dépendances
!pip install -q fastapi uvicorn pyngrok transformers accelerate bitsandbytes pydantic

# Cellule 2 : Serveur d'inférence avec authentification par jeton
import os
import uvicorn
from fastapi import FastAPI, Header, HTTPException, Depends
from pydantic import BaseModel
from typing import List, Optional, Dict, Any
import torch
from transformers import AutoModelForCausalLM, AutoTokenizer, pipeline

SECRET_TOKEN = "uniregistre_secret_colab_token_2026"
MODEL_ID = "Qwen/Qwen2.5-Coder-7B-Instruct"

print(f"Chargement du modèle {MODEL_ID} sur GPU T4...")
tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
model = AutoModelForCausalLM.from_pretrained(
    MODEL_ID,
    device_map="auto",
    torch_dtype=torch.float16,
    load_in_4bit=True
)
pipe = pipeline("text-generation", model=model, tokenizer=tokenizer)
print("Modèle prêt !")

app = FastAPI(title="UniRegistre Colab Inference Server")

def verify_token(authorization: Optional[str] = Header(None)):
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Jeton Bearer requis")
    token = authorization.split(" ")[1]
    if token != SECRET_TOKEN:
        raise HTTPException(status_code=401, detail="Jeton Bearer invalide")
    return token

class ChatMessage(BaseModel):
    role: str
    content: Optional[str] = ""

class ChatCompletionRequest(BaseModel):
    model: Optional[str] = "qwen2.5-coder-7b-instruct"
    messages: List[ChatMessage]
    tools: Optional[List[Dict[str, Any]]] = None
    temperature: Optional[float] = 0.2
    max_tokens: Optional[int] = 2048

@app.get("/v1/models")
def get_models(token: str = Depends(verify_token)):
    return {"object": "list", "data": [{"id": MODEL_ID, "object": "model"}]}

@app.post("/v1/chat/completions")
def chat_completions(req: ChatCompletionRequest, token: str = Depends(verify_token)):
    prompt_messages = [{"role": m.role, "content": m.content or ""} for m in req.messages]
    formatted_prompt = tokenizer.apply_chat_template(prompt_messages, tokenize=False, add_generation_prompt=True)
    
    outputs = pipe(
        formatted_prompt,
        max_new_tokens=req.max_tokens,
        temperature=req.temperature,
        do_sample=req.temperature > 0,
        pad_token_id=tokenizer.eos_token_id
    )
    
    generated_text = outputs[0]["generated_text"][len(formatted_prompt):].strip()
    
    return {
        "id": "chatcmpl-colab-7b",
        "object": "chat.completion",
        "model": req.model,
        "choices": [
            {
                "index": 0,
                "message": {"role": "assistant", "content": generated_text},
                "finish_reason": "stop"
            }
        ],
        "usage": {
            "prompt_tokens": len(formatted_prompt.split()),
            "completion_tokens": len(generated_text.split()),
            "total_tokens": len(formatted_prompt.split()) + len(generated_text.split())
        }
    }

# Cellule 3 : Exposition via Cloudflare Tunnel sécurisé
!wget -q https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -O cloudflared
!chmod +x cloudflared
import subprocess, threading

def run_tunnel():
    subprocess.run(["./cloudflared", "tunnel", "--url", "http://127.0.0.1:8000"])

threading.Thread(target=run_tunnel, daemon=True).start()

# Lancement de l'API
uvicorn.run(app, host="127.0.0.1", port=8000)
```

---

## 4. Test et Vérification de la Liaison

Depuis votre terminal sur le Mac :

```bash
# Vérification de santé du point de terminaison
curl -X GET "https://votre-tunnel-colab.trycloudflare.com/v1/models" \
     -H "Authorization: Bearer uniregistre_secret_colab_token_2026"
```

Le serveur répond avec HTTP 200 et la liste des modèles. L'assistant UniRegistre peut désormais solliciter le modèle open-source sans aucun échange de données personnelles.
