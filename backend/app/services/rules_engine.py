"""
Rules engine for email classification priority.
"""
from typing import Dict, Optional, List
import json
import re


def is_service_whitelist(from_email: str, whitelist_domains: List[str]) -> bool:
    """
    Check if email is from a whitelisted service domain.
    
    Args:
        from_email: Sender email address
        whitelist_domains: List of domain patterns
    
    Returns:
        True if matches whitelist
    """
    from_email_lower = from_email.lower()
    
    for pattern in whitelist_domains:
        pattern_lower = pattern.lower()
        
        # Remove @ prefix if present
        if pattern_lower.startswith('@'):
            pattern_lower = pattern_lower[1:]
        
        # Handle wildcards
        if '*' in pattern_lower:
            # Convert glob pattern to regex
            regex_pattern = pattern_lower.replace('.', r'\.').replace('*', '.*')
            if re.search(regex_pattern, from_email_lower):
                return True
        else:
            # Exact domain match
            if pattern_lower in from_email_lower:
                return True
    
    return False


def is_en_copia(to_addresses: str, cc_addresses: str, hawkins_domain: str = "@hawkins.es") -> bool:
    """
    Check if email has multiple Hawkins recipients (EnCopia).
    
    Args:
        to_addresses: JSON string of To addresses
        cc_addresses: JSON string of CC addresses
        hawkins_domain: Domain to check for
    
    Returns:
        True if multiple Hawkins addresses found
    """
    try:
        # Parse JSON arrays
        to_list = json.loads(to_addresses) if to_addresses else []
        cc_list = json.loads(cc_addresses) if cc_addresses else []
        
        # Combine all recipients
        all_recipients = to_list + cc_list
        
        # Count Hawkins addresses
        hawkins_count = sum(1 for addr in all_recipients if hawkins_domain.lower() in addr.lower())
        
        # EnCopia if more than 1 Hawkins recipient
        return hawkins_count > 1
    
    except Exception as e:
        print(f"Error checking EnCopia: {e}")
        return False


def apply_priority_rules(
    message_data: Dict,
    whitelist_domains: List[str]
) -> Optional[Dict]:
    """
    Apply priority rules before AI classification.
    
    Priority order:
    1. Servicios (whitelist)
    2. EnCopia (multiple @hawkins.es)
    
    Args:
        message_data: Message data dict
        whitelist_domains: List of whitelisted domains
    
    Returns:
        Classification dict if rule matches, None otherwise
    """
    from_email = message_data.get("from_email", "")
    to_addresses = message_data.get("to_addresses", "[]")
    cc_addresses = message_data.get("cc_addresses", "[]")
    
    # Rule 1: Servicios (highest priority)
    if is_service_whitelist(from_email, whitelist_domains):
        return {
            "status": "success",
            "final_label": "Servicios",
            "final_reason": f"Sender {from_email} is in whitelist",
            "decided_by": "rule_whitelist",
            "gpt_label": None,
            "qwen_label": None
        }
    
    # Rule 2: EnCopia
    if is_en_copia(to_addresses, cc_addresses):
        return {
            "status": "success",
            "final_label": "EnCopia",
            "final_reason": "Multiple @hawkins.es recipients detected",
            "decided_by": "rule_multiple_recipients",
            "gpt_label": None,
            "qwen_label": None
        }
    
    # No rule matched, proceed to AI classification
    return None


async def classify_with_rules_and_ai(
    message_data: Dict,
    whitelist_domains: List[str],
    categories: List[Dict] = []
) -> Dict:
    """
    Complete classification with rules + AI.
    
    Args:
        message_data: Message data
        whitelist_domains: Whitelist domains
        categories: List of available categories
    
    Returns:
        Classification result
    """
    from app.services.ai_service import classify_message
    
    # First, try priority rules
    rule_result = apply_priority_rules(message_data, whitelist_domains)
    
    if rule_result:
        return rule_result
    
    # No rule matched, use AI
    return await classify_message(message_data, categories)
