namespace Billing
{
    namespace Tax
    {
        /// <summary>A tax rate.</summary>
        public struct Rate
        {
            public decimal Value { get; init; }
        }
    }

    public static class Defaults
    {
        public static readonly Tax.Rate Gst = new() { Value = 0.1m };
    }
}
