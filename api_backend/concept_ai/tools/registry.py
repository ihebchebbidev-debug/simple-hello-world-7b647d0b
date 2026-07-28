"""Tool schemas + dispatcher for function calling (conceptual).

The model emits a <|tool_call|> token followed by a JSON object matching
one of these schemas. The runtime validates against the schema, invokes
the handler, and splices a <|tool_result|> back into the stream.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Callable


@dataclass
class ToolSpec:
    name: str
    description: str
    parameters: dict         # JSON schema
    handler: Callable[[dict], Any]


class ToolRegistry:
    def __init__(self) -> None:
        self._tools: dict[str, ToolSpec] = {}

    def register(self, spec: ToolSpec) -> None:
        self._tools[spec.name] = spec

    def schema(self) -> list[dict]:
        return [
            {"name": s.name, "description": s.description, "parameters": s.parameters}
            for s in self._tools.values()
        ]

    def dispatch(self, name: str, args: dict) -> Any:
        spec = self._tools.get(name)
        if not spec:
            raise KeyError(f"unknown tool: {name}")
        # A real impl validates `args` against spec.parameters before calling.
        return spec.handler(args)
