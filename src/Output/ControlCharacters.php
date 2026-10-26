<?php

namespace GodfreysDragon\MigrationReview\Output;

/**
 * Text in reports can come from migration source, exceptions or the AI. Control characters in it
 * (terminal escape sequences, for example) must not reach the terminal or a PR comment.
 */
final class ControlCharacters
{
    /**
     * Replaces invalid UTF-8 with "?", then removes C0 and C1 control characters except tab and newline.
     */
    public static function strip(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u', '', mb_scrub($text, 'UTF-8'));
    }
}
