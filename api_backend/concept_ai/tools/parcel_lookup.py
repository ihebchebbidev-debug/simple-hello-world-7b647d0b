"""parcel_lookup tool — return live state of a parcel (conceptual)."""
from __future__ import annotations

from .registry import ToolSpec


PARAMETERS = {
    "type": "object",
    "properties": {
        "parcel_id": {"type": "string", "description": "UUID of the parcel"},
        "fields": {
            "type": "array",
            "items": {"type": "string"},
            "description": "Subset of: soil_moisture, ec, ndvi, last_irrigation, area_ha",
        },
    },
    "required": ["parcel_id"],
}


def _handler(args: dict) -> dict:
    # Real impl would query the Laravel API using a signed service token.
    raise NotImplementedError("conceptual parcel_lookup")


SPEC = ToolSpec(
    name="parcel_lookup",
    description="Fetch the current agronomic state of a parcel by ID.",
    parameters=PARAMETERS,
    handler=_handler,
)
