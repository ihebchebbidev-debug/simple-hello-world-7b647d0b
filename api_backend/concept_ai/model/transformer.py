"""AgroMind-1 decoder stack (conceptual).

Each of the 32 layers is:

    x = x + attn(rmsnorm(x))
    x = x + moe(rmsnorm(x))
"""
from __future__ import annotations

from dataclasses import dataclass

from .attention import AttentionConfig, GroupedQueryAttention
from .moe import MoEBlock, MoEConfig


@dataclass
class TransformerConfig:
    num_layers: int = 32
    hidden_size: int = 4096
    vocab_size: int = 64_000
    max_position_embeddings: int = 32_768
    norm_eps: float = 1e-6


class RMSNorm:
    def __init__(self, dim: int, eps: float) -> None:
        self.dim = dim
        self.eps = eps
        # weight vector of shape [dim], learned.

    def forward(self, x):
        # y = x * rsqrt(mean(x^2) + eps) * weight
        raise NotImplementedError


class DecoderLayer:
    def __init__(self, cfg: TransformerConfig) -> None:
        self.attn_norm = RMSNorm(cfg.hidden_size, cfg.norm_eps)
        self.attn = GroupedQueryAttention(AttentionConfig(hidden_size=cfg.hidden_size))
        self.moe_norm = RMSNorm(cfg.hidden_size, cfg.norm_eps)
        self.moe = MoEBlock(MoEConfig(hidden_size=cfg.hidden_size))

    def forward(self, x, kv_cache=None, positions=None, training=False):
        h = self.attn.forward(self.attn_norm.forward(x), kv_cache, positions)
        x = x + h
        h = self.moe.forward(self.moe_norm.forward(x), training=training)
        return x + h


class AgroMindTransformer:
    def __init__(self, cfg: TransformerConfig) -> None:
        self.cfg = cfg
        self.embed = None  # nn.Embedding(vocab_size, hidden_size)
        self.layers = [DecoderLayer(cfg) for _ in range(cfg.num_layers)]
        self.final_norm = RMSNorm(cfg.hidden_size, cfg.norm_eps)
        self.lm_head = None  # nn.Linear(hidden_size, vocab_size, bias=False)

    def forward(self, input_ids, kv_cache=None, positions=None, training=False):
        # x = self.embed(input_ids)
        # for layer in self.layers: x = layer.forward(x, kv_cache, positions, training)
        # x = self.final_norm.forward(x)
        # return self.lm_head(x)
        raise NotImplementedError("conceptual transformer forward")
