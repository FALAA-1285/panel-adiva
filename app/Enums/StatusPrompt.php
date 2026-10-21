<?php

namespace App\Enums;

/**
 * prompt_versi.status (design/source/Prompt.dc.html): at most one draft being worked
 * on, one active version Adiva reads, and the archived ones that can be restored.
 */
enum StatusPrompt: string
{
    case Draf = 'DRAF';
    case Aktif = 'AKTIF';
    case Arsip = 'ARSIP';

    public function label(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Aktif => 'Aktif',
            self::Arsip => 'Arsip',
        };
    }

    /**
     * Tone of the Status component.
     */
    public function nada(): string
    {
        return match ($this) {
            self::Draf => 'amber',
            self::Aktif => 'green',
            self::Arsip => 'gray',
        };
    }
}
