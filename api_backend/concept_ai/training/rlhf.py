"""Direct Preference Optimization on agronomist-labeled pairs (conceptual).

We prefer DPO over classic PPO-RLHF because it avoids fitting a reward model
and reduces the moving parts during our short fine-tuning window.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class PreferencePair:
    prompt: str
    chosen: str
    rejected: str
    labeler_id: str
    confidence: float  # 0..1


def dpo_loss(policy_logp_chosen, policy_logp_rejected,
             ref_logp_chosen, ref_logp_rejected, *, beta: float = 0.1):
    """L_DPO = -log sigmoid( beta * ( (pi_c - ref_c) - (pi_r - ref_r) ) )

    beta ~ 0.1 keeps the policy close to the SFT reference.
    """
    # Kept as signature; the arithmetic is standard.
    raise NotImplementedError("conceptual DPO loss")
