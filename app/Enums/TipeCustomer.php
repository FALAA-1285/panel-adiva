<?php

namespace App\Enums;

/**
 * crm_customer.tipe_customer (design/source/Pipeline.dc.html).
 */
enum TipeCustomer: string
{
    case Reseller = 'RESELLER';
    case Retail = 'RETAIL';
    case Karyawan = 'KARYAWAN';
    case BelumJelas = 'BELUM_JELAS';

    public function label(): string
    {
        return match ($this) {
            self::Reseller => 'Reseller',
            self::Retail => 'Retail',
            self::Karyawan => 'Karyawan',
            self::BelumJelas => 'Belum jelas',
        };
    }
}
