"""Paged KV cache — vLLM-style (conceptual).

The cache is split into fixed-size blocks (16 tokens each). A block table
maps each sequence to its list of physical blocks, so we can share prefixes
across concurrent requests (system prompt, retrieved docs, etc.).
"""
from __future__ import annotations

from dataclasses import dataclass, field


BLOCK_SIZE = 16


@dataclass
class Block:
    index: int
    ref_count: int = 0


@dataclass
class SequenceCache:
    seq_id: str
    block_ids: list[int] = field(default_factory=list)
    length: int = 0


class PagedKVCache:
    def __init__(self, num_blocks: int, num_layers: int, num_kv_heads: int, head_dim: int):
        self.num_blocks = num_blocks
        self.num_layers = num_layers
        self.num_kv_heads = num_kv_heads
        self.head_dim = head_dim
        self.free: list[int] = list(range(num_blocks))
        self.blocks: dict[int, Block] = {i: Block(i) for i in range(num_blocks)}
        self.sequences: dict[str, SequenceCache] = {}

    def allocate(self, seq_id: str, new_tokens: int) -> None:
        seq = self.sequences.setdefault(seq_id, SequenceCache(seq_id))
        needed = ((seq.length + new_tokens + BLOCK_SIZE - 1) // BLOCK_SIZE) - len(seq.block_ids)
        for _ in range(max(needed, 0)):
            if not self.free:
                raise RuntimeError("KV cache OOM — invoke preemption")
            bid = self.free.pop()
            self.blocks[bid].ref_count += 1
            seq.block_ids.append(bid)
        seq.length += new_tokens

    def free_sequence(self, seq_id: str) -> None:
        seq = self.sequences.pop(seq_id, None)
        if not seq:
            return
        for bid in seq.block_ids:
            self.blocks[bid].ref_count -= 1
            if self.blocks[bid].ref_count == 0:
                self.free.append(bid)
