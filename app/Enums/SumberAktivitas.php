<?php

namespace App\Enums;

/**
 * activity_log.sumber: where an action came from (design/source/Aktivitas.dc.html).
 */
enum SumberAktivitas: string
{
    case Panel = 'PANEL';
    case WhatsApp = 'WHATSAPP';
    case Adiva = 'ADIVA';
    case Sistem = 'SISTEM';

    public function label(): string
    {
        return match ($this) {
            self::Panel => 'Panel',
            self::WhatsApp => 'WhatsApp',
            self::Adiva => 'Adiva',
            self::Sistem => 'Sistem',
        };
    }

    /**
     * Tone of the Status component.
     */
    public function nada(): string
    {
        return match ($this) {
            self::Panel => 'blue',
            self::WhatsApp => 'green',
            self::Adiva => 'violet',
            self::Sistem => 'gray',
        };
    }
}
