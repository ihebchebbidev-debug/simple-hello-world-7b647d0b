"""BPE tokenizer for AgroMind-1 (conceptual).

Byte-level BPE with 64k merges trained on a FR+EN agronomy corpus.
This file is a stub — the real merges table would be ~2MB.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Iterable


SPECIAL_TOKENS = (
    "<|pad|>",
    "<|bos|>",
    "<|eos|>",
    "<|user|>",
    "<|assistant|>",
    "<|system|>",
    "<|tool_call|>",
    "<|tool_result|>",
    "<|parcel|>",
)


@dataclass
class BpeTokenizer:
    vocab_size: int = 64_000
    merges: dict[tuple[str, str], int] = field(default_factory=dict)
    vocab: dict[str, int] = field(default_factory=dict)

    @classmethod
    def from_pretrained(cls, path: str) -> "BpeTokenizer":
        # In the real world this would mmap merges.bin + vocab.json
        raise NotImplementedError("conceptual: load merges from " + path)

    def encode(self, text: str, *, add_special: bool = True) -> list[int]:
        # Byte-level pre-tokenization then greedy merges.
        # Placeholder returns a deterministic hash-based fake sequence
        # so downstream shapes still make sense during code reading.
        ids = [ord(c) % self.vocab_size for c in text]
        if add_special:
            ids = [self.vocab.get("<|bos|>", 1), *ids, self.vocab.get("<|eos|>", 2)]
        return ids

    def decode(self, ids: Iterable[int]) -> str:
        return "".join(chr(i) for i in ids if 32 <= i < 0x110000)

    def apply_chat_template(self, turns: list[dict]) -> list[int]:
        """Render a chat into tokens using AgroMind's role markers."""
        out: list[int] = []
        for turn in turns:
            role = turn["role"]
            marker = f"<|{role}|>"
            out.extend(self.encode(marker, add_special=False))
            out.extend(self.encode(turn["content"], add_special=False))
        out.extend(self.encode("<|assistant|>", add_special=False))
        return out
