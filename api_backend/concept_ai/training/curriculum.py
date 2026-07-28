"""Domain-progressive curriculum.

Phase 1 (0 - 60% of steps):   general FR+EN web + code + math
Phase 2 (60% - 85%):          upweight agronomy textbooks + FAO datasets
Phase 3 (85% - 100%):         partner telemetry + internal docs

Sharpens domain competence without catastrophic forgetting.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class Phase:
    name: str
    start_frac: float
    end_frac: float
    mix: dict[str, float]


CURRICULUM = [
    Phase("general", 0.00, 0.60, {
        "common-crawl-fr-en-agri-filtered": 0.55,
        "code-python-sql": 0.20,
        "math-and-reasoning": 0.15,
        "agronomy-textbooks": 0.10,
    }),
    Phase("domain", 0.60, 0.85, {
        "agronomy-textbooks": 0.35,
        "fao-open-datasets": 0.25,
        "common-crawl-fr-en-agri-filtered": 0.25,
        "partner-farm-telemetry-serialized": 0.15,
    }),
    Phase("product", 0.85, 1.00, {
        "partner-farm-telemetry-serialized": 0.45,
        "internal-docs-and-tickets": 0.30,
        "agronomy-textbooks": 0.25,
    }),
]


def mix_for(step: int, total: int) -> dict[str, float]:
    frac = step / max(total, 1)
    for phase in CURRICULUM:
        if phase.start_frac <= frac < phase.end_frac:
            return phase.mix
    return CURRICULUM[-1].mix
