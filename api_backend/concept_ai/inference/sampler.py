"""Token samplers (conceptual)."""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class SamplingParams:
    temperature: float = 0.7
    top_p: float = 0.9
    top_k: int = 0
    repetition_penalty: float = 1.05
    presence_penalty: float = 0.0
    frequency_penalty: float = 0.0
    stop_tokens: tuple[int, ...] = ()


def sample(logits, params: SamplingParams, past_ids: list[int]) -> int:
    """Nucleus + repetition-penalty sampler.

    Steps:
      1. Apply repetition/frequency/presence penalties to logits over past_ids.
      2. Divide by temperature (skip if <= 0 → argmax).
      3. Sort, take smallest prefix whose cumulative softmax >= top_p.
      4. If top_k > 0, additionally restrict to that many highest logits.
      5. Sample from the renormalized distribution.
    """
    raise NotImplementedError("conceptual sampler")
