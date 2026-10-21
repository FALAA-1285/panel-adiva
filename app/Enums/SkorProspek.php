<?php

namespace App\Enums;

/**
 * crm_customer.skor_prospek. Shown as-is (HOT / WARM / COLD) in the design.
 */
enum SkorProspek: string
{
    case Hot = 'HOT';
    case Warm = 'WARM';
    case Cold = 'COLD';
}
