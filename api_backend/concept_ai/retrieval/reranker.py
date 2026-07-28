"""Cross-encoder reranker (conceptual).

Small 120M-param encoder scores (query, doc) pairs jointly. Top-8 of the
retriever's 20 candidates are kept and passed to the LLM.
"""
from __future__ import annotations


class CrossEncoderReranker:
    def rerank(self, query: str, hits: list) -> list:
        # Real impl batches (query, hit.text) through the encoder,
        # sorts by [CLS] logit, keeps top-k.
        raise NotImplementedError("conceptual reranker")
