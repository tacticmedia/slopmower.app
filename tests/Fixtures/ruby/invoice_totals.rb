# frozen_string_literal: true

# Billing computes invoice totals.
module Billing
  # The scale of an amount.
  SCALE = 2

  module Tax
    # A tax rate.
    class Rate
      def initialize(value)
        @value = value
      end
    end
  end

  # Sums the lines of an invoice.
  class InvoiceTotals < Base
    # Builds totals from lines.
    def self.from(lines)
      new(lines)
    end

    def total
      add = ->(a, b) { a + b }
      @lines.reduce(0, &add)
    end

    # Rounds half up.
    private def round(cents)
      cents
    end

    class << self
      def empty
        new([])
      end
    end
  end

  class Tax::Exempt
  end
end

def format_total(cents)
  format('%.2f', cents / 100.0)
end
