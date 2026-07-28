"""FR/EN PII scrubber (conceptual).

Combines regex passes (emails, phone numbers, IBANs, French SIREN/SIRET)
with a small NER model for names and addresses. Scrubbed spans are
replaced with typed placeholders like <EMAIL_1>, <PHONE_2> that the model
can still reference symbolically.
"""
from __future__ import annotations

import re


EMAIL_RE = re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")
PHONE_FR_RE = re.compile(r"(?:\+33|0)\s?[1-9](?:[\s.-]?\d{2}){4}")
IBAN_RE = re.compile(r"\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b")
SIREN_RE = re.compile(r"\b\d{3}\s?\d{3}\s?\d{3}\b")


def scrub(text: str) -> tuple[str, dict[str, str]]:
    mapping: dict[str, str] = {}

    def _sub(pattern: re.Pattern, kind: str, s: str) -> str:
        def repl(m: re.Match) -> str:
            key = f"<{kind}_{len(mapping) + 1}>"
            mapping[key] = m.group(0)
            return key
        return pattern.sub(repl, s)

    s = text
    s = _sub(EMAIL_RE, "EMAIL", s)
    s = _sub(PHONE_FR_RE, "PHONE", s)
    s = _sub(IBAN_RE, "IBAN", s)
    s = _sub(SIREN_RE, "SIREN", s)
    # A real impl would also run a NER pass for PERSON / ADDRESS here.
    return s, mapping
