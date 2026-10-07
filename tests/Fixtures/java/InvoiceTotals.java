package com.example.billing;

import java.util.List;

/**
 * Sums the lines of an invoice.
 */
public final class InvoiceTotals {
    /** The scale of an amount. */
    public static final int SCALE = 2;

    private final List<Line> lines;

    public InvoiceTotals(List<Line> lines) {
        this.lines = List.copyOf(lines);
    }

    /** Adds the lines. */
    @Deprecated
    public long total() {
        class Sum {
            long value;
        }
        return lines.size();
    }

    @Override
    public String toString() {
        Runnable log = new Runnable() {
            public void run() {}
        };
        return "InvoiceTotals";
    }

    public record Line(long cents, Currency currency) {
        public Line {
        }
    }

    public enum Currency {
        /** Australian dollar. */
        AUD("A$"),
        EUR("E") {
            @Override
            String sign() {
                return "EUR";
            }
        };

        private final String sign;

        Currency(String sign) {
            this.sign = sign;
        }

        String sign() {
            return sign;
        }
    }

    static class Rounding {
    }
}

@interface Audited {
    String value() default "";
}
