<?php

declare(strict_types=1);

namespace App\Support;

final class TitleNormalizer
{
    public static function normalize(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $collapsedWhitespace = preg_replace('/\s+/u', ' ', $title ?? '');
        $normalized = trim($collapsedWhitespace ?? '');

        if ($normalized === '') {
            return null;
        }

        return mb_strtolower($normalized, 'UTF-8');
    }
}
