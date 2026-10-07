//! Invoice totals.

use std::fmt;

/// The scale of an amount.
pub const SCALE: u32 = 2;

/// An invoice total in cents.
#[derive(Debug, Clone, Copy)]
#[must_use]
pub struct Total {
    /// The amount in cents.
    pub cents: i64,
    currency: Currency,
}

#[derive(Debug, Clone, Copy)]
pub enum Currency {
    Aud,
    Eur,
}

impl Total {
    pub const ZERO: Total = Total { cents: 0, currency: Currency::Aud };

    pub fn new(cents: i64) -> Self {
        fn clamp(cents: i64) -> i64 {
            cents.max(0)
        }
        Self { cents: clamp(cents), currency: Currency::Aud }
    }
}

impl From<i64> for Total {
    fn from(cents: i64) -> Self {
        Self::new(cents)
    }
}

impl From<u32> for Total {
    fn from(cents: u32) -> Self {
        Self::new(i64::from(cents))
    }
}

impl std::ops::Add<i64> for Total {
    type Output = Total;

    fn add(self, cents: i64) -> Total {
        Total::new(self.cents + cents)
    }
}

impl fmt::Display for Total {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.cents)
    }
}

pub trait Rounding {
    fn round(&self) -> Total;
}

macro_rules! total {
    ($cents:expr) => {
        Total::new($cents)
    };
}

pub mod billing {
    pub mod tax {
        pub struct Rate<T>(pub T);

        impl<T: Copy> Rate<T> {
            pub fn value(&self) -> T {
                self.0
            }
        }
    }
}
