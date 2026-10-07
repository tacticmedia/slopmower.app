"""Invoice totals."""

import functools
from dataclasses import dataclass


@dataclass(frozen=True)
class Line:
    """One line of an invoice."""

    amount: int
    label: str = ""

    class Meta:
        ordering = ["label"]

        def describe(self) -> str:
            return "line"


class InvoiceTotals:
    def __init__(self, lines: list[Line]) -> None:
        self.lines = lines

    @property
    @functools.cache
    def total(self) -> int:
        def add(left: int, right: int) -> int:
            return left + right

        class Accumulator:
            pass

        return functools.reduce(add, (line.amount for line in self.lines), 0)

    @staticmethod
    async def load(path: str) -> "InvoiceTotals":
        return InvoiceTotals([])


def format_total(totals: InvoiceTotals) -> str:
    return f"{totals.total:.2f}"
