package billing

/** A line amount. */
trait Amount {
  def cents: Long
  val currency: String
}

/** Sums the lines of an invoice. */
@SerialVersionUID(1L)
case class InvoiceTotals(lines: Seq[Amount]) {
  val scale = 2

  /** Adds the lines. */
  def total(): Long = {
    def add(a: Long, b: Long): Long = a + b
    lines.map(_.cents).foldLeft(0L)(add)
  }
}

object InvoiceTotals {
  type Lines = Seq[Amount]

  def empty: InvoiceTotals = InvoiceTotals(Nil)
}

enum Currency {
  case Aud, Eur

  /** US dollar. */
  case Usd extends Currency
}

def formatTotal(cents: Long): String = f"${cents / 100.0}%.2f"
