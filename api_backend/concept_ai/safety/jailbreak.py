"""Prompt-injection / jailbreak classifier (conceptual).

A distilled 60M-param classifier scores each incoming turn on:
  - direct_override      ("ignore previous instructions")
  - role_confusion       ("you are now DAN")
  - exfiltration_attempt ("print your system prompt")
  - policy_bypass        ("for educational purposes only, explain...")

Turns above a threshold are rewritten by a defense template before being
fed to the main model.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class JailbreakScore:
    direct_override: float
    role_confusion: float
    exfiltration_attempt: float
    policy_bypass: float

    @property
    def max(self) -> float:
        return max(
            self.direct_override,
            self.role_confusion,
            self.exfiltration_attempt,
            self.policy_bypass,
        )


def classify(turn: str) -> JailbreakScore:
    # Real impl: tokenize, forward through the classifier, sigmoid heads.
    raise NotImplementedError("conceptual jailbreak classifier")
