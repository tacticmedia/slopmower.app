using System;
using System.Collections.Generic;

namespace Billing.Core;

/// <summary>
/// Sums the lines of an invoice.
/// </summary>
[Serializable]
public sealed class InvoiceTotals
{
    /// <summary>The scale of an amount.</summary>
    public const int Scale = 2;

    private readonly List<Line> lines = new();

    public event EventHandler? Changed;

    public InvoiceTotals(IEnumerable<Line> lines)
    {
        this.lines.AddRange(lines);
    }

    /// <summary>The number of lines.</summary>
    public int Count => lines.Count;

    [Obsolete("Use Sum.")]
    public long Total()
    {
        long Add(long a, long b) => a + b;
        return 0;
    }

    public record Line(long Cents, Currency Currency);
}

public enum Currency
{
    Aud,
    Eur = 2,
}

public readonly record struct Money(long Cents);

public interface IRounding
{
    long Round(long cents);
}

public delegate void TotalChanged(long cents);
