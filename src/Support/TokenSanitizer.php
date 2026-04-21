<?php

namespace Guarzo\Seat\WandererSync\Support;

final class TokenSanitizer
{
    /**
     * Mask an API key for safe logging or UI display.
     *
     * @param  string|null  $apiKey
     * @param  int  $visibleChars  Number of trailing characters to leave visible.
     * @return string  'None' for empty/null; all-star mask for short input; '***<suffix>' otherwise.
     */
    public static function maskApiKey(?string $apiKey, int $visibleChars = 4): string
    {
        if ($apiKey === null || $apiKey === '') {
            return 'None';
        }

        if (strlen($apiKey) <= $visibleChars) {
            return str_repeat('*', strlen($apiKey));
        }

        return '***' . substr($apiKey, -$visibleChars);
    }
}
