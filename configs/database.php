<?php

declare(strict_types=1);

if (! function_exists('databaseConfigs')) {
    function databaseConfigs()
    {
        return [
            'connections' => [
                // Usage statistics (App\Analytics\Usage), shown by /stats, and each server's settings (App\Settings\GuildSettings).
                'stats' => [
                    'driver' => 'sqlite',
                    'database' => env('STATS_DATABASE', 'databases/stats.sqlite'),
                    'foreign_key_constraints' => true,
                ],
            ],
        ];
    }
}
