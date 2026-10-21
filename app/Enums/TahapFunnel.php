<?php

namespace App\Enums;

/**
 * crm_customer.tahap_funnel, in pipeline order (design/source/Pipeline.dc.html).
 */
enum TahapFunnel: string
{
    case Baru = 'BARU';
    case TanyaProduk = 'TANYA_PRODUK';
    case TanyaHarga = 'TANYA_HARGA';
    case Nego = 'NEGO';
    case SiapOrder = 'SIAP_ORDER';
    case OrderDibuat = 'ORDER_DIBUAT';
    case CartDitinggal = 'CART_DITINGGAL';
    case TidakMinat = 'TIDAK_MINAT';

    public function label(): string
    {
        return match ($this) {
            self::Baru => 'Baru menyapa',
            self::TanyaProduk => 'Tanya produk',
            self::TanyaHarga => 'Tanya harga',
            self::Nego => 'Nego',
            self::SiapOrder => 'Siap order',
            self::OrderDibuat => 'Order dibuat',
            self::CartDitinggal => 'Keranjang ditinggal',
            self::TidakMinat => 'Tidak minat',
        };
    }

    /**
     * How lists and the stage bars write it (Customer.dc.html: "Baru", not "Baru menyapa").
     */
    public function labelSingkat(): string
    {
        return $this === self::Baru ? 'Baru' : $this->label();
    }

    /**
     * The stages a customer moves through, in order (Customer.dc.html: the seven bars).
     *
     * @return list<self>
     */
    public static function alur(): array
    {
        return [self::Baru, self::TanyaProduk, self::TanyaHarga, self::Nego, self::SiapOrder, self::OrderDibuat];
    }
}
