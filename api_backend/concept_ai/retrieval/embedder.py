"""Dense embedder for retrieval (conceptual).

Wraps a small e5-mistral distillation trained on FR+EN agronomy pairs.
Outputs 768-dim L2-normalized vectors.
"""
from __future__ import annotations


class Embedder:
    dim: int = 768

    def embed(self, texts: list[str]) -> list[list[float]]:
        # Real impl: batch-tokenize, forward, mean-pool over attention mask,
        # L2-normalize. Placeholder returns deterministic pseudo-vectors.
        raise NotImplementedError("conceptual embedder")
