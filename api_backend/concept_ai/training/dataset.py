"""Streaming multi-source dataset (conceptual)."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Iterator


@dataclass
class Shard:
    source: str
    weight: float
    path: str


class WeightedStreamingDataset:
    """Yields packed 32k-token sequences from many shards according to weights."""

    def __init__(self, shards: list[Shard], seq_len: int = 32_768, seed: int = 0):
        self.shards = shards
        self.seq_len = seq_len
        self.seed = seed

    def __iter__(self) -> Iterator[list[int]]:
        # Real impl: interleave shard iterators with a weighted RNG, pack into
        # seq_len windows separated by <|eos|>, and yield token id lists.
        raise NotImplementedError("conceptual streaming loader")
