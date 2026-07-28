"""Domain eval harness (conceptual).

Tracks four suites:

  1. AgroQA-FR         — 500 French agronomy multiple-choice
  2. AgroQA-EN         — 500 English agronomy multiple-choice
  3. ParcelReasoning   — 200 tasks requiring the parcel_lookup tool
  4. IrrigationPlan    — 100 tasks requiring the irrigation_plan tool

Each run reports accuracy, tool-call precision/recall, and refusal rate.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class EvalResult:
    suite: str
    accuracy: float
    tool_precision: float | None
    tool_recall: float | None
    refusal_rate: float
    n: int


def run_all(model) -> list[EvalResult]:
    raise NotImplementedError("conceptual evals — populate golden_set.jsonl first")
