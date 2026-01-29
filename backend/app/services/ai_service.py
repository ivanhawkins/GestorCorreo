"""
AI classification service using Remote AI API.
"""
import httpx
import json
from typing import Dict, Optional, Literal, List
from datetime import datetime

# Constants removed - now loaded from database

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


class RemoteAIClient:
    """Client for Remote AI API."""
    
    def __init__(self, api_url: str, api_key: str):
        self.api_url = api_url
        self.api_key = api_key
    
    async def generate(
        self,
        model: str,
        prompt: str,
        format: str = "json",
        timeout: float = 120.0
    ) -> Dict:
        """
        Generate completion from Remote AI API.
        
        Args:
            model: Model name
            prompt: Prompt text
            format: Output format (json or text) - kept for compatibility
            timeout: Request timeout in seconds
        
        Returns:
            Dict with response in Ollama-compatible format
        """
        async with httpx.AsyncClient(timeout=timeout, verify=False) as client:
            try:
                # Construct generation URL
                # If api_url ends with /models, strip it to get base
                base_url = self.api_url.replace('/chat/models', '').replace('/models', '').rstrip('/')
                
                # Use /chat/chat endpoint as requested
                generate_url = f"{base_url}/chat/chat"
                
                response = await client.post(
                    generate_url,
                    headers={"x-api-key": self.api_key},
                    json={
                        "modelo": model,
                        "prompt": prompt
                    }
                )
                response.raise_for_status()
                
                # Read raw text first for debugging/fallback
                raw_text = response.text
                
                try:
                    data = response.json()
                    success = data.get("success", True)
                    
                    # Adapt response to Ollama format
                    # /api/chat returns "message": {"content": "..."}
                    message_content = ""
                    if "message" in data and "content" in data["message"]:
                        message_content = data["message"]["content"]
                    elif "choices" in data and isinstance(data["choices"], list) and len(data["choices"]) > 0:
                        # OpenAI format
                        first_choice = data["choices"][0]
                        if "message" in first_choice and "content" in first_choice["message"]:
                            message_content = first_choice["message"]["content"]
                        elif "text" in first_choice:
                            message_content = first_choice["text"]
                    elif "response" in data:
                         # Fallback for /api/generate
                        message_content = data["response"]
                    elif "respuesta" in data:
                        # Fallback for custom API
                        message_content = data["respuesta"]
                    else:
                        # Fallback: keep raw text if we can't extract specific content
                        message_content = raw_text
                
                except json.JSONDecodeError:
                    # Fallback for plain text response (or empty)
                    message_content = raw_text
                    success = True # Assume success if status code was 200
                
                return {
                    "response": message_content,
                    "success": success
                }
            except httpx.HTTPError as e:
                print(f"Remote AI API error: {e}")
                raise
    
    async def check_health(self) -> bool:
        """Check if Remote AI API is accessible."""
        try:
            async with httpx.AsyncClient(timeout=5.0, verify=False) as client:
                response = await client.get(
                    self.api_url,
                    headers={"x-api-key": self.api_key}
                )
                return response.status_code == 200
        except:
            return False
    
    async def list_models(self) -> List[str]:
        """
        Get available models from API.
        Since the API doesn't have a dedicated list endpoint,
        we return common Ollama models.
        """
        # Common Ollama models - user can type custom names too
        common_models = [
            "gpt-oss:120b-cloud",
            "qwen3-coder:480b-cloud",
            "qwen3:latest",
            "llama3:latest",
            "mistral:latest",
            "codellama:latest",
            "gemma:latest",
            "phi3:latest"
        ]
        return common_models


# Legacy alias for compatibility
OllamaClient = RemoteAIClient


# Helper to build prompt
def build_classification_prompt(categories: List[Dict], custom_prompt: Optional[str] = None) -> str:
    """
    Build the classification prompt dynamically based on available categories.
    """
    if custom_prompt:
        # If custom prompt is provided, use it as the base
        base_prompt = custom_prompt
    else:
        # Default prompt structure
        base_prompt = """
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
    
    if custom_prompt:
         # For custom prompt, we just append the categories and JSON instructions if not present?
         # Or we assume the user wrote a full prompt?
         # Best approach: Replace variables in custom prompt if they exist, or append categories.
         # For simplicity: We will expect the user to write {from_name} etc if they want dynamic values.
         # And we will ALWAYS append the JSON instructions at the end to ensure JSON format.
         pass
    else:
        pass # Handle default logic below

    # If using default, build it up
    if not custom_prompt:
        # ... logic as before ...
        pass
    
    # Actually, simplifying:
    # If custom prompt, we just return it formatted with message details + categories + json instructions?
    # No, user prompt should replace the "Contexto" part mainly.
    
    # Revised strategy:
    # If custom_prompt is None, use default.
    # If provided, use it but append categories and JSON constraints to ensure it works.
    
    prompt_text = base_prompt if custom_prompt else base_prompt + categories_text

    instructions_part = f"""
**IMPORTANTE:**
- Clasifica el correo en ÚNICAMENTE una de las categorías anteriores.
- Responde SOLO con JSON válido, sin texto adicional.

**Formato de respuesta (JSON estricto):**
{{{{
  "label": "{labels_joined}",
  "confidence": 0.85,
  "rationale": "Máximo 2 frases explicando la decisión"
}}}}
"""
    return prompt_text + instructions_part


async def classify_with_model(
    message_data: Dict,
    model: str,
    categories: List[Dict],
    ai_client: Optional[RemoteAIClient] = None,
    custom_prompt: Optional[str] = None
) -> Dict:
    """
    Classify message with a specific model.
    """
    if ai_client is None:
        # Load config from DB
        config = await _get_ai_config()
        ai_client = RemoteAIClient(config["api_url"], config["api_key"])
    
    # Prepare prompt
    body_preview = (message_data.get("body_text") or message_data.get("snippet") or "")[:500]
    
    if custom_prompt:
         # Use custom prompt template
         dynamic_prompt_template = build_classification_prompt(categories, custom_prompt)
    else:
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
    
    # Call Remote AI
    try:
        response = await ai_client.generate(model, prompt, format="json")
        
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
    ai_client: Optional[RemoteAIClient] = None,
    gpt_model: str = None
) -> Dict:
    """
    GPT reviews both classifications and makes final decision.
    
    Args:
        message_data: Original message data
        gpt_result: GPT's initial classification
        qwen_result: Qwen's classification
        ai_client: Optional RemoteAIClient instance
        gpt_model: Primary model name
    
    Returns:
        Dict with final_label, final_reason, why_not_other
    """
    if ai_client is None:
        config = await _get_ai_config()
        ai_client = RemoteAIClient(config["api_url"], config["api_key"])
        gpt_model = config["primary_model"]
    
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
        response = await ai_client.generate(gpt_model, prompt, format="json")
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


async def _get_ai_config() -> Dict:
    """Load AI configuration from database."""
    try:
        from app.database import AsyncSessionLocal
        from app.models import AIConfig
        from sqlalchemy import select
        
        async with AsyncSessionLocal() as db:
            result = await db.execute(select(AIConfig).limit(1))
            config = result.scalar_one_or_none()
            
            if not config:
                # Return defaults if not found
                print("⚠️ Warning: No AI config found in database, using defaults")
                return {
                    "api_url": "https://192.168.1.45/chat/models",
                    "api_key": "OllamaAPI_2024_K8mN9pQ2rS5tU7vW3xY6zA1bC4eF8hJ0lM",
                    "primary_model": "gpt-oss:120b-cloud",
                    "secondary_model": "qwen3-coder:480b-cloud"
                }
            
            return {
                "api_url": config.api_url,
                "api_key": config.api_key,
                "primary_model": config.primary_model,
                "secondary_model": config.secondary_model
            }
    except Exception as e:
        print(f"❌ Error loading AI config: {e}, using defaults")
        return {
            "api_url": "https://192.168.1.45/chat/models",
            "api_key": "OllamaAPI_2024_K8mN9pQ2rS5tU7vW3xY6zA1bC4eF8hJ0lM",
            "primary_model": "gpt-oss:120b-cloud",
            "secondary_model": "qwen3-coder:480b-cloud"
        }


async def classify_message(message_data: Dict, categories: List[Dict], custom_prompt: Optional[str] = None) -> Dict:
    """
    Full classification pipeline with consensus/tiebreaker.
    
    Args:
        message_data: Dict with message fields
        categories: List of category dicts (key, ai_instruction)
        custom_prompt: Optional custom classification prompt
    
    Returns:
        Dict with complete classification result
    """
    # Load AI config
    config = await _get_ai_config()
    ai_client = RemoteAIClient(config["api_url"], config["api_key"])
    
    # Check if Remote AI is accessible
    if not await ai_client.check_health():
        return {
            "status": "error",
            "error": "Remote AI API is not running or not accessible"
        }
    
    # Step 1: Classify with both models
    gpt_result = await classify_with_model(message_data, config["primary_model"], categories, ai_client, custom_prompt)
    qwen_result = await classify_with_model(message_data, config["secondary_model"], categories, ai_client, custom_prompt)
    
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
        review_result = await review_with_gpt(message_data, gpt_result, qwen_result, ai_client, config["primary_model"])
        
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
    ai_client: Optional[RemoteAIClient] = None
) -> Dict:
    """
    Generate a reply for a message.
    """
    if ai_client is None:
        config = await _get_ai_config()
        ai_client = RemoteAIClient(config["api_url"], config["api_key"])
        gpt_model = config["primary_model"]
    else:
        config = await _get_ai_config()
        gpt_model = config["primary_model"]

    prompt = REPLY_PROMPT.format(
        from_name=message_data.get("from_name", ""),
        from_email=message_data.get("from_email", ""),
        subject=message_data.get("subject", ""),
        body_text=(message_data.get("body_text") or "")[:1000],
        user_instruction=user_instruction or "Responde adecuadamente al contexto.",
        owner_profile=owner_profile
    )

    # Continue with secure JSON prompt construction defined below
        
    # Re-doing the function properly with JSON for safety
    
    json_prompt = prompt + """
    
    **Formato de respuesta (JSON):**
    {
        "reply_body": "El texto del correo aquí..."
    }
    """
    
    try:
        response = await ai_client.generate(gpt_model, json_prompt, format="json")
        result_text = response.get("response", "{}")
        
        try:
            result_json = json.loads(result_text)
            reply_body = result_json.get("reply_body", "")
            if not reply_body and isinstance(result_json, dict):
                 # Maybe it's directly in another key or just empty?
                 # If keys look like "body", "content", try them
                 reply_body = result_json.get("body") or result_json.get("content") or ""
            
            if not reply_body:
                 # If JSON parsed but empty/irrelevant, fallback to text if it looks like prose
                 # But if result_text was literally "{}", nothing to do.
                 if len(result_text) > 5 and "{" not in result_text[:5]:
                      reply_body = result_text 
        except json.JSONDecodeError:
            # Not JSON, assume it's the text body directly
            reply_body = result_text

        return {
            "reply_body": reply_body
        }
    except Exception as e:
         return {
            "reply_body": f"Error generando respuesta: {str(e)}"
        }
