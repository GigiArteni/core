<?php

namespace Apiato\Repository\Support;

use Illuminate\Support\Arr;

class HashIdHelper
{
    /**
     * Decode HashIds for given value(s) if config is enabled.
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
        // Use your HashId decoding logic here (replace with actual implementation)
        $decode = fn($v) => is_numeric($v) ? $v : app('hashids')->decode($v)[0] ?? $v;
        if (is_array($value)) {
            return array_map($decode, $value);
        }
        return $decode($value);
    }
}
