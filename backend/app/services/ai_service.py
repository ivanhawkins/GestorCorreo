"""
AI classification service using Ollama.
"""
import httpx
import json
from typing import Dict, Optional, Literal, List
from datetime import datetime

OLLAMA_BASE_URL = "http://localhost:11434"
GPT_MODEL = "gpt-oss:120b-cloud"
QWEN_MODEL = "qwen3-coder:480b-cloud"

# Classification labels
ClassificationLabel = Literal["Interesantes", "SPAM", "EnCopia", "Servicios"]

CLASSIFICATION_PROMPT = """
Eres un asistente de clasificación de correos electrónicos para la empresa Hawkins (@hawkins.es).

**Contexto del correo:**
- De: {from_name} <{from_email}>
- Para: {to_addresses}
- CC: {cc_addresses}
- Asunto: {subject}
- Fecha: {date}
- Cuerpo (primeras 500 palabras): {body_preview}

**Categorías disponibles:**

1. **Interesantes**: Correos con intención real de contratar servicios de Hawkins (presupuestos, propuestas comerciales, reuniones de negocio).

2. **SPAM**: Spam clásico, phishing, newsletters no solicitadas, Y MUY IMPORTANTE: cualquier correo cuyo propósito sea vendernos algo u ofrecernos sus servicios (cold outreach).

3. **EnCopia**: Correos donde hay múltiples destinatarios internos @hawkins.es en To o CC (no dirigidos solo a mí).

4. **Servicios**: Notificaciones transaccionales de plataformas conocidas (booking, bancos, Amazon, etc.).

**IMPORTANTE:**
- Si el correo intenta vendernos algo → SPAM
- Si solicitan nuestros servicios → Interesantes
- Responde SOLO con JSON válido, sin texto adicional.

**Formato de respuesta (JSON estricto):**
{{
  "label": "Interesantes|SPAM|EnCopia|Servicios",
  "confidence": 0.85,
  "rationale": "Máximo 2 frases explicando la decisión"
}}
"""

REVIEW_PROMPT = """
Eres un asistente de clasificación de correos. Dos modelos han clasificado el mismo correo y han llegado a conclusiones diferentes. Debes tomar la decisión final.

**Correo original:**
- De: {from_name} <{from_email}>
- Para: {to_addresses}
- Asunto: {subject}
- Cuerpo: {body_preview}

**Tu clasificación previa:**
- Label: {gpt_label}
- Confianza: {gpt_confidence}
- Razón: {gpt_rationale}

**Clasificación del segundo modelo (Qwen):**
- Label: {qwen_label}
- Confianza: {qwen_confidence}
- Razón: {qwen_rationale}

**Instrucciones:**
Analiza ambas clasificaciones y el correo original. Emite una decisión final única.

**Formato de respuesta (JSON estricto):**
{{
  "final_label": "Interesantes|SPAM|EnCopia|Servicios",
  "final_reason": "Máximo 3 frases explicando tu decisión final",
  "why_not_other": "Máximo 2 frases explicando por qué descartaste la otra clasificación"
}}
"""


class OllamaClient:
    """Client for Ollama API."""
    
    def __init__(self, base_url: str = OLLAMA_BASE_URL):
        self.base_url = base_url
    
    async def generate(
        self,
        model: str,
        prompt: str,
        format: str = "json",
        timeout: float = 120.0
    ) -> Dict:
        """
        Generate completion from Ollama.
        
        Args:
            model: Model name
            prompt: Prompt text
            format: Output format (json or text)
            timeout: Request timeout in seconds
        
        Returns:
            Dict with response
        """
        async with httpx.AsyncClient(timeout=timeout) as client:
            try:
                response = await client.post(
                    f"{self.base_url}/api/generate",
                    json={
                        "model": model,
                        "prompt": prompt,
                        "format": format,
                        "stream": False
                    }
                )
                response.raise_for_status()
                return response.json()
            except httpx.HTTPError as e:
                print(f"Ollama API error: {e}")
                raise
    
    async def check_health(self) -> bool:
        """Check if Ollama is running."""
        try:
            async with httpx.AsyncClient(timeout=5.0) as client:
                response = await client.get(f"{self.base_url}/api/tags")
                return response.status_code == 200
        except:
            return False


# Helper to build prompt
def build_classification_prompt(categories: List[Dict]) -> str:
    """
    Build the classification prompt dynamically based on available categories.
    """
    context_part = """
Eres un asistente de clasificación de correos electrónicos para la empresa Hawkins (@hawkins.es).

**Contexto del correo:**
- De: {from_name} <{from_email}>
- Para: {to_addresses}
- CC: {cc_addresses}
- Asunto: {subject}
- Fecha: {date}
- Cuerpo (primeras 500 palabras): {body_preview}
"""

    categories_text = "**Categorías disponibles:**\n\n"
    labels_list = []
    
    for idx, cat in enumerate(categories, 1):
        labels_list.append(cat['key'])
        categories_text += f"{idx}. **{cat['key']}**: {cat['ai_instruction']}\n\n"

    labels_joined = "|".join(labels_list)
    
    instructions_part = f"""
**IMPORTANTE:**
- Clasifica el correo en ÚNICAMENTE una de las categorías anteriores.
- Responde SOLO con JSON válido, sin texto adicional.

**Formato de respuesta (JSON estricto):**
{{
  "label": "{labels_joined}",
  "confidence": 0.85,
  "rationale": "Máximo 2 frases explicando la decisión"
}}
"""
    return context_part + categories_text + instructions_part


async def classify_with_model(
    message_data: Dict,
    model: str,
    categories: List[Dict],
    ollama_client: Optional[OllamaClient] = None
) -> Dict:
    """
    Classify message with a specific model.
    """
    if ollama_client is None:
        ollama_client = OllamaClient()
    
    # Prepare prompt
    body_preview = (message_data.get("body_text") or message_data.get("snippet") or "")[:500]
    
    dynamic_prompt_template = build_classification_prompt(categories)
    
    prompt = dynamic_prompt_template.format(
        from_name=message_data.get("from_name", ""),
        from_email=message_data.get("from_email", ""),
        to_addresses=message_data.get("to_addresses", ""),
        cc_addresses=message_data.get("cc_addresses", ""),
        subject=message_data.get("subject", ""),
        date=message_data.get("date", ""),
        body_preview=body_preview
    )
    
    # Call Ollama
    try:
        response = await ollama_client.generate(model, prompt, format="json")
        
        # Parse JSON response
        result_text = response.get("response", "{}")
        classification = json.loads(result_text)
        
        return {
            "label": classification.get("label"),
            "confidence": classification.get("confidence", 0.0),
            "rationale": classification.get("rationale", "")
        }
    
    except Exception as e:
        print(f"Error classifying with {model}: {e}")
        # Return default classification on error
        return {
            "label": "SPAM",
            "confidence": 0.0,
            "rationale": f"Error: {str(e)}"
        }


async def review_with_gpt(
    message_data: Dict,
    gpt_result: Dict,
    qwen_result: Dict,
    ollama_client: Optional[OllamaClient] = None
) -> Dict:
    """
    GPT reviews both classifications and makes final decision.
    
    Args:
        message_data: Original message data
        gpt_result: GPT's initial classification
        qwen_result: Qwen's classification
        ollama_client: Optional OllamaClient instance
    
    Returns:
        Dict with final_label, final_reason, why_not_other
    """
    if ollama_client is None:
        ollama_client = OllamaClient()
    
    body_preview = (message_data.get("body_text") or message_data.get("snippet") or "")[:500]
    
    prompt = REVIEW_PROMPT.format(
        from_name=message_data.get("from_name", ""),
        from_email=message_data.get("from_email", ""),
        to_addresses=message_data.get("to_addresses", ""),
        subject=message_data.get("subject", ""),
        body_preview=body_preview,
        gpt_label=gpt_result["label"],
        gpt_confidence=gpt_result["confidence"],
        gpt_rationale=gpt_result["rationale"],
        qwen_label=qwen_result["label"],
        qwen_confidence=qwen_result["confidence"],
        qwen_rationale=qwen_result["rationale"]
    )
    
    try:
        response = await ollama_client.generate(GPT_MODEL, prompt, format="json")
        result_text = response.get("response", "{}")
        review = json.loads(result_text)
        
        return {
            "final_label": review.get("final_label"),
            "final_reason": review.get("final_reason", ""),
            "why_not_other": review.get("why_not_other", "")
        }
    
    except Exception as e:
        print(f"Error in GPT review: {e}")
        # Default to GPT's original classification
        return {
            "final_label": gpt_result["label"],
            "final_reason": f"Review error, defaulting to GPT: {gpt_result['rationale']}",
            "why_not_other": str(e)
        }


async def classify_message(message_data: Dict, categories: List[Dict]) -> Dict:
    """
    Full classification pipeline with consensus/tiebreaker.
    
    Args:
        message_data: Dict with message fields
        categories: List of category dicts (key, ai_instruction)
    
    Returns:
        Dict with complete classification result
    """
    ollama_client = OllamaClient()
    
    # Check if Ollama is running
    if not await ollama_client.check_health():
        return {
            "status": "error",
            "error": "Ollama is not running or not accessible"
        }
    
    # Step 1: Classify with both models
    gpt_result = await classify_with_model(message_data, GPT_MODEL, categories, ollama_client)
    qwen_result = await classify_with_model(message_data, QWEN_MODEL, categories, ollama_client)
    
    # Step 2: Check for consensus
    if gpt_result["label"] == qwen_result["label"]:
        # Consensus reached
        return {
            "status": "success",
            "gpt_label": gpt_result["label"],
            "gpt_confidence": gpt_result["confidence"],
            "gpt_rationale": gpt_result["rationale"],
            "qwen_label": qwen_result["label"],
            "qwen_confidence": qwen_result["confidence"],
            "qwen_rationale": qwen_result["rationale"],
            "final_label": gpt_result["label"],
            "final_reason": f"Consensus: {gpt_result['rationale']}",
            "decided_by": "consensus"
        }
    
    else:
        # Disagreement - GPT reviews
        review_result = await review_with_gpt(message_data, gpt_result, qwen_result, ollama_client)
        
        return {
            "status": "success",
            "gpt_label": gpt_result["label"],
            "gpt_confidence": gpt_result["confidence"],
            "gpt_rationale": gpt_result["rationale"],
            "qwen_label": qwen_result["label"],
            "qwen_confidence": qwen_result["confidence"],
            "qwen_rationale": qwen_result["rationale"],
            "final_label": review_result["final_label"],
            "final_reason": review_result["final_reason"],
            "decided_by": "gpt_review"
        }

REPLY_PROMPT = """
Eres un asistente de redacción de correos electrónicos.
Tu trabajo es redactar una respuesta profesional y cortés a un correo recibido.

**Perfil del Propietario (QUIEN ERES TU):**
{owner_profile}

**Correo Original recibido:**
- De: {from_name} <{from_email}>
- Asunto: {subject}
- Cuerpo: {body_text}

**Instrucción del usuario (si la hay):**
{user_instruction}

**Tu Tarea:**
Redacta ÚNICAMENTE el cuerpo del correo de respuesta. No incluyas "Asunto:" ni saludos/despedidas genéricos si ya están en la firma (aunque un "Hola [Nombre]," al inicio está bien).
Sigue estrictamente el tono y estilo definidos en el perfil del propietario.
"""

async def generate_reply(
    message_data: Dict,
    user_instruction: str = "",
    owner_profile: str = "Eres profesional y eficiente.",
    ollama_client: Optional[OllamaClient] = None
) -> Dict:
    """
    Generate a reply for a message.
    """
    if ollama_client is None:
        ollama_client = OllamaClient()

    prompt = REPLY_PROMPT.format(
        from_name=message_data.get("from_name", ""),
        from_email=message_data.get("from_email", ""),
        subject=message_data.get("subject", ""),
        body_text=(message_data.get("body_text") or "")[:1000],
        user_instruction=user_instruction or "Responde adecuadamente al contexto.",
        owner_profile=owner_profile
    )

    try:
        # Use GPT model for better writing
        response = await ollama_client.generate(GPT_MODEL, prompt, format="json") # Should be text, but let's check format
        # Actually for generation we usually want plain text, or we can ask for specific JSON structure.
        # Let's ask for plain text for the body to be simple.
        
        # Re-call with text format
        response = await ollama_client.generate(GPT_MODEL, prompt, format="json") 
        # Wait, the prompt implies "Redacta ÚNICAMENTE el cuerpo". 
        # But my client 'generate' method defaults to JSON if format not specified? 
        # Let's check the client generate method.
        # It takes format arg.
        
        # Let's use format="json" and ask for {"reply_body": "..."} to be safe/structured? 
        # Or just text. Text is easier for "body only". 
        # But current client implementation might force "json" param if I didn't change it.
        # Line 91: format: str = "json".
        
        # Let's update the PROMPT to ask for JSON to safely extract the body.
        pass
    except Exception as e:
        return {"error": str(e)}
        
    # Re-doing the function properly with JSON for safety
    
    json_prompt = prompt + """
    
    **Formato de respuesta (JSON):**
    {
        "reply_body": "El texto del correo aquí..."
    }
    """
    
    try:
        response = await ollama_client.generate(GPT_MODEL, json_prompt, format="json")
        result_text = response.get("response", "{}")
        result_json = json.loads(result_text)
        return {
            "reply_body": result_json.get("reply_body", "")
        }
    except Exception as e:
         return {
            "reply_body": f"Error generando respuesta: {str(e)}"
        }
