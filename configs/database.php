<?php

declare(strict_types=1);

if (! function_exists('databaseConfigs')) {
    function databaseConfigs()
    {
        return [
            'connections' => [
                // Usage statistics (App\Analytics\Usage), shown by /stats, each server's settings (App\Settings\GuildSettings),
                // each person's privacy settings (App\Settings\UserSettings), and who opted out of being recorded (App\Privacy\OptOuts).
                'stats' => [
                    'driver' => 'sqlite',
                    'database' => env('STATS_DATABASE', 'databases/stats.sqlite'),
                    'foreign_key_constraints' => true,
                ],
            ],
        ];
    }
}
