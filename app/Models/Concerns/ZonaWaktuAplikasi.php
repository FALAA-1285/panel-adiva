<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;

/**
 * Postgres returns timestamptz values with a bare offset (+07), which Carbon keeps
 * as a "+07:00" zone. Move them back to app.timezone so formats like "13:42 WIB" work.
 */
trait ZonaWaktuAplikasi
{
    /**
     * Return a timestamp as DateTime object, in the application timezone.
     *
     * @param  mixed  $value
     */
    protected function asDateTime($value): CarbonInterface
    {
        return parent::asDateTime($value)->setTimezone(config('app.timezone'));
    }
}
