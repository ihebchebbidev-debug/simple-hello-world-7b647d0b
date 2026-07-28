"""Streaming inference server (conceptual).

If we ever ran AgroMind ourselves, this would be the FastAPI process behind
a load balancer. It speaks SSE, uses continuous batching, and shares the
KV cache across concurrent sessions.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class ServerConfig:
    host: str = "0.0.0.0"
    port: int = 8088
    max_concurrent_sequences: int = 128
    max_batch_tokens: int = 8192
    kv_cache_gb: int = 40


async def stream_chat(request):
    """Handler pseudo-code:

        payload = await request.json()
        req = GenerationRequest(**payload)
        async for chunk in model.generate(req):
            yield sse({"delta": chunk.text, "done": False})
        yield sse({"delta": "", "done": True})
    """
    raise NotImplementedError("conceptual SSE handler")


def build_app(model, cfg: ServerConfig):
    # In the real thing:
    #   app = FastAPI()
    #   app.post("/v1/chat/stream")(stream_chat)
    #   return app
    return {"model": model, "config": cfg}
