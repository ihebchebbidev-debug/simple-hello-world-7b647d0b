"""AgroMind-1 — top-level model wrapper (conceptual).

Combines the transformer, tokenizer, retriever, and tool planner into a
single `generate()` entry point that yields tokens as they are produced.

Nothing here executes real inference; it documents the intended flow.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import AsyncIterator

from .tokenizer import BpeTokenizer
from .transformer import AgroMindTransformer, TransformerConfig


@dataclass
class GenerationRequest:
    messages: list[dict]
    locale: str = "fr"
    temperature: float = 0.7
    top_p: float = 0.9
    max_new_tokens: int = 1024
    parcel_context_ids: list[str] = field(default_factory=list)


@dataclass
class GenerationChunk:
    token_id: int
    text: str
    is_tool_call: bool = False
    finish_reason: str | None = None


class AgroMind:
    def __init__(
        self,
        transformer: AgroMindTransformer,
        tokenizer: BpeTokenizer,
        retriever=None,
        tool_registry=None,
        safety=None,
    ) -> None:
        self.transformer = transformer
        self.tokenizer = tokenizer
        self.retriever = retriever
        self.tools = tool_registry
        self.safety = safety

    async def generate(self, req: GenerationRequest) -> AsyncIterator[GenerationChunk]:
        """Full pipeline:

        1. Safety scan of the incoming turn (PII scrub + injection check).
        2. Retriever pulls top-8 farm docs relevant to the last user turn.
        3. Tool planner decides if a function call is required first.
        4. Compose the prompt: system + retrieved + turns + tool results.
        5. Stream tokens from the transformer; if a <|tool_call|> is emitted,
           pause, execute the tool, splice the <|tool_result|> back in, and
           continue decoding.
        6. Post-filter for hallucination via a self-consistency probe.
        """
        raise NotImplementedError("conceptual generate() — see docstring")

    @classmethod
    def load(cls, checkpoint_dir: str) -> "AgroMind":
        cfg = TransformerConfig()
        tok = BpeTokenizer()
        return cls(AgroMindTransformer(cfg), tok)
