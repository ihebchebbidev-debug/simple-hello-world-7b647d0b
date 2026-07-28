"""Hybrid HNSW + BM25 index (conceptual).

Dense recall for semantic similarity, sparse recall for exact-match terms
(SKUs, parcel IDs, chemical names). Results are fused with reciprocal rank
fusion before reranking.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class Hit:
    doc_id: str
    score: float
    text: str
    metadata: dict


class HybridIndex:
    def __init__(self, embedder, *, hnsw_m: int = 32, hnsw_ef: int = 128):
        self.embedder = embedder
        self.hnsw_m = hnsw_m
        self.hnsw_ef = hnsw_ef
        # self.hnsw = hnswlib.Index(space='cosine', dim=embedder.dim)
        # self.bm25 = rank_bm25.BM25Okapi(...)

    def add(self, docs: list[dict]) -> None:
        raise NotImplementedError

    def search(self, query: str, *, k: int = 20) -> list[Hit]:
        # 1. dense = hnsw.knn_query(embedder.embed([query]), k=k)
        # 2. sparse = bm25.get_top_n(query.split(), self.corpus, n=k)
        # 3. fused = reciprocal_rank_fusion(dense, sparse, k_rrf=60)
        # 4. return fused[:k]
        raise NotImplementedError("conceptual hybrid search")
