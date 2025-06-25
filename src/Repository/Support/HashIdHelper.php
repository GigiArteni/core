<?php

namespace Apiato\Repository\Support;

use Illuminate\Support\Arr;

class HashIdHelper
{
    /**
     * Decode HashIds for given value(s) if config is enabled.
     * Recursively decodes arrays and falls back to raw value if decode fails.
     *
     * @param string|int|array $value
     * @return string|int|array
     */
    public static function decodeIfNeeded(string $field, $value): mixed
    {
        if (!config('repository.hashid_decode', true)) {
            return $value;
        }
        // Only decode for id or *_id fields
        if (!preg_match('/(^id$|_id$)/', $field)) {
            return $value;
        }
        $decode = function ($v) use (&$decode) {
            if (is_array($v)) {
                return array_map($decode, $v);
            }
            if (is_numeric($v)) {
                return $v;
            }
            $decoded = app('hashids')->decode($v);
            if (is_int($decoded)) {
                return $decoded;
            }
            if (is_array($decoded) && count($decoded) === 1 && is_int($decoded[0])) {
                return $decoded[0];
            }
            // Fallback: if decode fails, return original value
            return $v;
        };
        return $decode($value);
    }
}
