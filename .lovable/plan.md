# Upgrade the Flehty AI assistant

Turn the JSON-lookup bot into a small tool-calling analyst. Keep OpenRouter free models only.

## 1. Switch the model chain to tool-capable free models

Update `config/openrouter.php` model chain (all `:free`, all support function-calling):

```
1. deepseek/deepseek-chat-v3.1:free      (primary, strong tool use)
2. meta-llama/llama-3.3-70b-instruct:free
3. qwen/qwen-2.5-72b-instruct:free
4. google/gemini-2.0-flash-exp:free      (fallback, tool-calling)
```

Add `tools` + `tool_choice: "auto"` support in `OpenRouterClient::chat()` (non-stream path only — tool loops don't stream partial function calls reliably on free models). Keep `chatStream()` unchanged for the final answer pass.

## 2. New service: `AiToolRegistry` — the retrieval layer

Replaces the pre-baked context blob for anything beyond the tiny baseline. Each tool is a pure PHP method with a JSON schema; the model picks which to call.

Tools (all read-only, all scoped by role):

- `get_overview()` — small KPI snapshot (counts + active campaign). Cheap, always allowed.
- `list_plots(filter?, campaign_id?, crop?)` — id, name, area, crop, active campaign.
- `list_campaigns(status?)` — active/past campaigns with dates + crop.
- `get_operations(type, plot_id?, campaign_id?, from?, to?, limit?=50)` — irrigation/fertilization/phyto/harvest rows with dates, quantities, costs.
- `aggregate_operations(type, group_by, metric, from?, to?, plot_id?, campaign_id?, crop?)`
  - `group_by`: `day|week|month|quarter|year|campaign|plot|crop|product`
  - `metric`: `sum_quantity|sum_cost|sum_volume_m3|sum_yield_kg|count|avg_cost_per_kg`
  - Returns a small time series or grouped table (max 60 buckets).
- `compare_periods(type, metric, period_a, period_b, plot_id?, crop?)` — YoY / MoM / arbitrary window compare with delta + %.
- `search_catalog(kind, query)` — fertilizers / pesticides / pests by name or scientific name (RAG-lite over the existing tables).
- `recent_operations(limit?=10)` — cross-type latest activity feed.
- `get_prices(kind, item_id?, from?, to?)` — price history series.

Every tool result is capped (≤ 60 rows / ≤ 2 KB serialized) and stamps `currency`, `units`, `generated_at`.

## 3. New service: `AiAgentLoop` — plan-then-answer

Replaces the current single-shot call in `AiChatService::reply` / `replyStream`.

Loop (max 4 iterations, hard budget cap):

```text
turn 0: system + tools schema + user
        → model returns either tool_calls OR final answer
turn 1..N: append tool results → model reasons again
final: streamed answer to the client
```

Reasoning trace: the model is instructed to first emit a short internal `plan` field via a lightweight `plan(steps)` pseudo-tool (max 4 steps, 200 chars) before invoking data tools. The plan is streamed to the UI as a collapsed "Thinking…" section (new `type: 'plan'` NDJSON event). Chain-of-thought is never exposed beyond that short plan.

Streaming behavior:

- Intermediate tool-call rounds: non-streamed (JSON call).
- Final answer round: streamed as today via `chatStream()`.
- New NDJSON event types added by `AiChatController::streamResponse`:
  - `plan` — steps array
  - `tool` — `{name, args, ok, ms}` (no result body, just activity)
  - existing `delta` / `revise` / `done` / `error` unchanged

## 4. Retire the keyword `PromptRouter`

Removed from the call path. The tool loop makes it redundant: the model asks for exactly the data it needs. `AiContextBuilder` shrinks to a tiny `baseline_context()` (counts, currency, units, active campaign, plot list ids+names) always sent with the system prompt so the model has enough to *choose tools*.

`PromptRouter.php` + `AiContextBuilder.php` heavy sections kept but only invoked *by tools* (e.g. `aggregate_operations` reuses the existing SQL builders). Delete `PromptRouterTest.php`, add `AiToolRegistryTest.php`.

## 5. Flexible aggregations

`aggregate_operations` and `compare_periods` implement the missing windows via SQL:

- Week: `date_trunc('week', occurred_at)`
- Quarter: `date_trunc('quarter', occurred_at)`
- Campaign: join `campaigns` on date range
- Crop: join `plots.crop`
- YoY: two windowed queries + delta

Sensible caps: max 24 months of daily data, 60 buckets returned, one metric per call.

## 6. Frontend changes (small)

`src/lib/aiChat.ts`:
- Extend `StreamEvent` union with `plan` and `tool`.
- Pass them to two new optional callbacks `onPlan(steps)` and `onTool(activity)`.

`src/features/ai-chat/` chat window:
- Render plan steps in a collapsible "Reasoning" panel above the streamed answer.
- Render tool activity as small chips ("queried irrigation · 120 ms").
- No changes to markdown rendering of the final reply.

## 7. Tests

- `AiToolRegistryTest` — one test per tool (schema, caps, role scoping).
- `AiAgentLoopTest` — mocked OpenRouter returns `tool_calls` → verify loop dispatches, feeds results back, stops on final answer, respects iteration cap.
- Update `AiChatTest` to fake the two-round exchange (tool call → final answer).

## Files touched

Backend:
- `config/openrouter.php` — model chain, add `tools_enabled` flag
- `app/Services/AiChat/OpenRouterClient.php` — add tools/tool_choice + tool_calls parsing
- `app/Services/AiChat/AiToolRegistry.php` — new
- `app/Services/AiChat/AiAgentLoop.php` — new
- `app/Services/AiChat/AiChatService.php` — delegate to agent loop, drop router call
- `app/Services/AiChat/AiContextBuilder.php` — add `baseline()`; keep aggregation helpers, called by tools
- `app/Http/Controllers/Api/AiChatController.php` — forward `plan`/`tool` events
- `tests/Feature/AiChat/AiChatTest.php` — updated
- `tests/Unit/AiChat/AiToolRegistryTest.php` — new
- `tests/Unit/AiChat/AiAgentLoopTest.php` — new
- `tests/Unit/AiChat/PromptRouterTest.php` — removed

Frontend:
- `src/lib/aiChat.ts` — new event types
- `src/features/ai-chat/*` — plan panel + tool chips (single component change)

## Non-goals

- No paid models, no vision, no voice.
- No RAG vector store — catalog "search" is SQL `ILIKE` (Pest/Fertilizer/Pesticide tables are small).
- No changes to feedback endpoint, cache layer, circuit breaker, token budget, or auth.
