"""Sparse Mixture-of-Experts feed-forward block (conceptual).

Each token is routed to the top-2 of 8 experts. A load-balancing auxiliary
loss keeps expert utilization uniform during training. At inference we drop
the aux loss and use noiseless routing for reproducibility.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class MoEConfig:
    hidden_size: int = 4096
    intermediate_size: int = 14336
    num_experts: int = 8
    experts_per_token: int = 2
    capacity_factor: float = 1.25
    aux_loss_coef: float = 0.01
    router_noise_std: float = 0.3  # training only


class Expert:
    """A single SwiGLU FFN expert."""

    def __init__(self, hidden: int, intermediate: int) -> None:
        self.hidden = hidden
        self.intermediate = intermediate
        # w_gate, w_up, w_down are the three projection matrices.

    def forward(self, x):
        # y = w_down(silu(w_gate(x)) * w_up(x))
        raise NotImplementedError("conceptual expert forward")


class Router:
    """Top-k noisy router.

    Training:  logits = x @ W_r + noise;  probs = softmax(topk(logits, k))
    Inference: logits = x @ W_r;          probs = softmax(topk(logits, k))
    """

    def __init__(self, cfg: MoEConfig) -> None:
        self.cfg = cfg

    def route(self, x, *, training: bool):
        # Returns (expert_indices [B*T, k], gate_weights [B*T, k], aux_loss).
        raise NotImplementedError("conceptual router")


class MoEBlock:
    def __init__(self, cfg: MoEConfig) -> None:
        self.cfg = cfg
        self.router = Router(cfg)
        self.experts = [Expert(cfg.hidden_size, cfg.intermediate_size)
                        for _ in range(cfg.num_experts)]

    def forward(self, x, *, training: bool = False):
        # Real impl uses a permutation-based dispatch to avoid a Python loop.
        # 1. flatten tokens
        # 2. router picks top-2 experts + gate weights
        # 3. group tokens by chosen expert
        # 4. run each expert on its shard
        # 5. scatter results back and weight-sum
        raise NotImplementedError("conceptual MoE forward")
