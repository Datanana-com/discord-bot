<?php

declare(strict_types=1);

if (! function_exists('snake')) {
    /**
     * Convert a string to snake case.
     *
     * @param  string  $value
     * @param  string  $delimiter
     * @return string
     */
    function snake($value, $delimiter = '_')
    {
        $key = $value;

        if (! ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));

            $value = mb_strtolower(
                preg_replace(
                    '/(.)(?=[A-Z])/u',
                    '$1' . $delimiter,
                    $value
                ),
                'UTF-8'
            );
        }

        return $value;
    }
}
