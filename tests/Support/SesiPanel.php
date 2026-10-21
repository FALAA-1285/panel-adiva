<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows in Laravel's sessions table (no model), as the database session driver writes them.
 */
final class SesiPanel
{
    /**
     * @param  string  $sejak  last activity relative to now, e.g. "-5 hours"
     * @return string the session id
     */
    public static function buat(User $user, string $userAgent, string $sejak, ?string $id = null): string
    {
        $id ??= Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->modify($sejak)->getTimestamp(),
        ]);

        return $id;
    }
}
