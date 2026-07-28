# concept_ai — AgroMind Neural Core (Conceptual)

> **Status:** Conceptual / research prototype. This folder is a *thought experiment*: what our stack would look like if we trained and served our own domain-specific LLM instead of calling an external gateway.
>
> Nothing in here is wired into the live application. The production assistant still goes through the AI gateway. Treat this as an architectural sketch — code shape, module boundaries, and training/serving flow — not a runnable model.

---

## Vision

**AgroMind-1** is an imaginary 7B-parameter mixture-of-experts transformer fine-tuned on:

- 1.2M French + English agronomy Q/A pairs
- Parcel telemetry (soil moisture, EC, NDVI time series)
- Irrigation schedules and yield outcomes from partner farms
- Internal product docs, changelogs, and support transcripts

The goal: an assistant that *reasons over a farm's live state* rather than answering generic gardening trivia.

## Architecture at a glance

```text
                ┌────────────────────────────┐
   user turn ──▶│  Router  (intent + locale) │
                └──────────────┬─────────────┘
                               │
        ┌──────────────────────┼──────────────────────┐
        ▼                      ▼                      ▼
   Retriever              Tool Planner            Safety Filter
  (hybrid BM25 +         (function calling        (PII, jailbreak,
   dense e5-mistral)      over farm APIs)          hallucination probe)
        │                      │                      │
        └──────────────┬───────┴──────────────────────┘
                       ▼
              ┌─────────────────────┐
              │  AgroMind-1 decoder │  ← 32 layers, 8 experts, top-2 routing
              │  (MoE transformer)  │
              └──────────┬──────────┘
                         ▼
                    streamed tokens
```

## Layout

```
concept_ai/
├── README.md                    ← you are here
├── config/
│   ├── model.yaml               ← hyperparameters
│   └── training.yaml            ← data mix + schedule
├── model/
│   ├── tokenizer.py             ← BPE, 64k vocab, FR+EN merges
│   ├── attention.py             ← grouped-query + rotary embeddings
│   ├── moe.py                   ← sparse mixture-of-experts block
│   ├── transformer.py           ← full decoder stack
│   └── agromind.py              ← top-level model + generate()
├── training/
│   ├── dataset.py               ← streaming corpus loader
│   ├── curriculum.py            ← domain-progressive schedule
│   ├── trainer.py               ← ZeRO-3 style trainer skeleton
│   └── rlhf.py                  ← DPO fine-tune with agronomist labels
├── inference/
│   ├── kv_cache.py              ← paged attention cache
│   ├── sampler.py               ← nucleus + repetition penalty
│   └── server.py                ← FastAPI-style streaming endpoint
├── retrieval/
│   ├── embedder.py              ← e5-mistral wrapper
│   ├── index.py                 ← HNSW + BM25 hybrid
│   └── reranker.py              ← cross-encoder rerank
├── tools/
│   ├── parcel_lookup.py         ← function-calling: farm state
│   ├── irrigation_plan.py       ← function-calling: schedule solver
│   └── registry.py              ← tool schema + dispatcher
├── safety/
│   ├── pii.py                   ← FR/EN PII scrubber
│   ├── jailbreak.py             ← prompt-injection classifier
│   └── hallucination.py         ← self-consistency probe
└── evals/
    ├── benchmarks.py            ← domain eval harness
    └── golden_set.jsonl         ← 200 curated Q/A (placeholder)
```

## Not to be confused with

- `app/Http/Controllers/AiChatController.php` — the **real** production endpoint, which proxies to the external AI gateway.
- `src/features/ai-chat/` — the frontend chat UI.

If you delete this folder, nothing breaks.
