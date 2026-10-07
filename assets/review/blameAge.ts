const MS_PER_DAY = 86_400_000;

/** The days below which a commit gets the scale 9, 8, and so on down to 1. Older commits get 0. */
const SCALE_LIMITS_DAYS = [7, 14, 21, 45, 90, 120, 180, 270, 450];

/** As GitHub colours blame ages, from 0 for the oldest to 9 for the newest: `--display-orange-scale-<n>`. */
export function blameAgeScale(authoredAt: string, now: Date): number {
    const days = (now.getTime() - Date.parse(authoredAt)) / MS_PER_DAY;
    const index = SCALE_LIMITS_DAYS.findIndex((limit) => days < limit);

    return -1 === index ? 0 : SCALE_LIMITS_DAYS.length - index;
}
