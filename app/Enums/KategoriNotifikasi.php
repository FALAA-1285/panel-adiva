<?php

namespace App\Enums;

/**
 * notifikasi.kategori: the group a notification is filed under (Notifikasi.dc.html tabs).
 */
enum KategoriNotifikasi: string
{
    case Order = 'ORDER';
    case ButuhAdmin = 'BUTUH_ADMIN';
    case Sistem = 'SISTEM';
}
