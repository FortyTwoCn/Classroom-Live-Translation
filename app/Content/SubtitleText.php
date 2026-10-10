<?php

declare(strict_types=1);

namespace App\Content;

use Illuminate\Database\Query\Builder;

/** One blank-text rule for provider writes, PostgreSQL pages and exports. */
final class SubtitleText
{
    public const NON_BLANK = '[^[:space:]'."\u{0085}\u{00A0}\u{1680}\u{180E}\u{2000}-\u{200F}\u{2028}-\u{202F}\u{205F}\u{2060}-\u{206F}\u{3000}\u{FEFF}".']';

    public static function hasContent(string $text): bool
    {
        return preg_match('/'.self::NON_BLANK.'/u', $text) === 1;
    }

    public static function visible(Builder $query, string $expression = 'COALESCE(current_text,original_text)'): Builder
    {
        return $query->whereRaw($expression.' ~ ?', [self::NON_BLANK]);
    }
}
