"""Self-consistency hallucination probe (conceptual).

For factual questions we sample N=4 completions at temperature=0.7, embed
each, and cluster them. If the largest cluster covers < 60% of samples we
flag the answer as low-confidence and either:

  a) route to the retriever again with a rewritten query, or
  b) hedge the final response with an explicit uncertainty phrase.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class ProbeResult:
    consistent: bool
    top_cluster_fraction: float
    representative: str


def probe(completions: list[str], embedder) -> ProbeResult:
    # Real impl: embed all, agglomerative-cluster with cosine threshold 0.85,
    # pick largest cluster, return its share and the medoid string.
    raise NotImplementedError("conceptual hallucination probe")
