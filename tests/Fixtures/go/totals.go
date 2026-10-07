// Package billing computes invoice totals.
package billing

import "errors"

// ErrEmpty is returned for an invoice without lines.
var ErrEmpty = errors.New("empty invoice")

// Currency is an ISO 4217 code.
type Currency string

const (
	// Scale is the number of decimal places.
	Scale    = 2
	Rounding = "half-up"
)

// Totals sums the lines of an invoice.
// It keeps the lines in order.
type Totals[T Amount] struct {
	// Lines are the invoice lines.
	Lines    []T
	Currency Currency
}

// Amount is a line amount.
type Amount interface {
	Cents() int64
}

// Sum adds the lines.
func (t *Totals[T]) Sum() (int64, error) {
	if len(t.Lines) == 0 {
		return 0, ErrEmpty
	}
	var sum int64
	for _, line := range t.Lines {
		sum += line.Cents()
	}
	return sum, nil
}

func (t Totals[T]) Count() int { return len(t.Lines) }

// Methods of Line, which line.go declares.

func (l *Line) Cents() int64 { return l.cents }

func Format(cents int64) string {
	type pair struct{ whole, part int64 }
	return ""
}
