const std = @import("std");

/// The scale of an amount.
pub const scale = 2;

/// Sums the lines of an invoice.
/// It keeps the lines in order.
pub const Totals = struct {
    /// The line amounts in cents.
    lines: []const i64,
    currency: Currency = .aud,

    pub const zero: Totals = .{ .lines = &.{} };

    /// Adds the lines.
    pub fn sum(self: Totals) i64 {
        const Acc = struct { value: i64 };
        var acc = Acc{ .value = 0 };
        for (self.lines) |line| acc.value += line;
        return acc.value;
    }
};

pub const Currency = enum {
    aud,
    eur,

    pub fn code(self: Currency) []const u8 {
        return @tagName(self);
    }
};

pub fn format(cents: i64) i64 {
    return @divTrunc(cents, 100);
}

test "sum" {
    try std.testing.expectEqual(0, Totals.zero.sum());
}
