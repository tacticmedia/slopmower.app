<?php

declare(strict_types=1);

namespace App\Domain\Text;

final class Utf8
{
    /**
     * Replaces each invalid UTF-8 sequence and each NUL with U+FFFD, as a browser shows them,
     * so that the text fits JSON and jsonb.
     */
    public static function scrub(string $text): string
    {
        return str_replace("\0", "\u{FFFD}", \UConverter::transcode($text, 'UTF-8', 'UTF-8'));
    }
}
