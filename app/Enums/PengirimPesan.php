<?php

namespace App\Enums;

/**
 * pesan.pengirim: who wrote a chat line (design/source/Inbox.dc.html).
 */
enum PengirimPesan: string
{
    case Customer = 'CUSTOMER';
    case Adiva = 'ADIVA';
    case Admin = 'ADMIN';
    case Sistem = 'SISTEM';
}
