<?php

namespace App\Models;

use DateTimeInterface;

/**
 * Dates go to the web app in the platform's timezone (Africa/Lagos) with the offset, e.g.
 * "2026-11-19T13:15:00+01:00", not Laravel's default UTC "…T12:15:00.000000Z". The web app
 * shows times as wall-clock values, so a UTC string would display an hour early.
 */
trait SerializesLocalDates
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }
}
