"""Bounded, public Chatwoot message history for the LLM prompt."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

MESSAGE_TYPE_INCOMING = 0
MESSAGE_TYPE_OUTGOING = 1


def _message_type(value: Any) -> int | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str):
        normalized = value.strip().lower()
        if normalized == "incoming":
            return MESSAGE_TYPE_INCOMING
        if normalized == "outgoing":
            return MESSAGE_TYPE_OUTGOING
        if normalized.isdigit():
            return int(normalized)
    return None


def _created_sort_key(item: Mapping[str, Any]) -> tuple[int, str, int]:
    created_at = item.get("created_at")
    if isinstance(created_at, (int, float)) and not isinstance(created_at, bool):
        # Chatwoot normally returns a unix timestamp. Padding keeps numeric
        # and string timestamps sortable without relying on mixed-type order.
        return 0, f"{int(created_at):020d}", int(item.get("id", 0) or 0)
    if isinstance(created_at, str):
        # ISO timestamps sort lexicographically when they use one consistent format.
        return 1, created_at, int(item.get("id", 0) or 0)
    return 2, "", int(item.get("id", 0) or 0)


def build_history(
    messages: list[dict[str, Any]],
    *,
    max_messages: int = 10,
    max_chars: int = 8000,
) -> list[dict[str, str]]:
    """Return recent public text messages as chronological OpenAI roles."""
    if max_messages <= 0 or max_chars <= 0:
        return []

    cleaned: list[dict[str, str]] = []
    for item in sorted(messages, key=_created_sort_key):
        if item.get("private") is True:
            continue
        if item.get("status") == "failed":
            continue

        message_type = _message_type(item.get("message_type"))
        if message_type not in (MESSAGE_TYPE_INCOMING, MESSAGE_TYPE_OUTGOING):
            continue

        content = item.get("content")
        if not isinstance(content, str):
            continue
        content = content.strip()
        if not content:
            continue

        cleaned.append(
            {
                "role": "user" if message_type == MESSAGE_TYPE_INCOMING else "assistant",
                "content": content[:2000],
            }
        )

    selected: list[dict[str, str]] = []
    total_chars = 0
    for entry in reversed(cleaned):
        if len(selected) >= max_messages:
            break
        entry_chars = len(entry["content"])
        if selected and total_chars + entry_chars > max_chars:
            break
        selected.append(entry)
        total_chars += entry_chars

    selected.reverse()
    return selected
