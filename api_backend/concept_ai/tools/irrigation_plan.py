"""irrigation_plan tool — propose an irrigation schedule (conceptual).

Under the hood this would call a small mixed-integer solver that optimizes
water usage subject to soil moisture targets, weather forecast, and pump
capacity constraints. Here we only expose the schema.
"""
from __future__ import annotations

from .registry import ToolSpec


PARAMETERS = {
    "type": "object",
    "properties": {
        "parcel_id": {"type": "string"},
        "horizon_days": {"type": "integer", "minimum": 1, "maximum": 14},
        "target_moisture_pct": {"type": "number", "minimum": 0, "maximum": 100},
        "max_daily_liters": {"type": "number", "minimum": 0},
    },
    "required": ["parcel_id", "horizon_days"],
}


def _handler(args: dict) -> dict:
    raise NotImplementedError("conceptual irrigation_plan")


SPEC = ToolSpec(
    name="irrigation_plan",
    description="Compute an optimized irrigation schedule for a parcel.",
    parameters=PARAMETERS,
    handler=_handler,
)
