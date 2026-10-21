@php
    $rp = fn (int $n) => ($n < 0 ? '-' : '').number_format(abs($n), 0, ',', '.');
    $mulai = 0;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice_number }}</title>
<style>
    @page { margin: 40pt 30pt 30pt 30pt; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #000; margin: 0; }
    h1 { font-size: 21pt; text-align: center; margin: 0 0 14pt 0; }
    table { border-collapse: collapse; }
    .kepala { width: 100%; margin-bottom: 14pt; }
    .kepala td { vertical-align: top; padding: 0 0 4pt 0; }
    .kepala .kiri { width: 55%; }
    .pasang td { padding: 0 0 2pt 0; vertical-align: top; }
    .pasang .label { width: 62pt; }
    .pasang .labelkanan { width: 98pt; }
    .item { width: 100%; border-top: 1.2pt solid #000; }
    .item th { border-bottom: 1.2pt solid #000; padding: 5pt 4pt; text-align: center; font-weight: bold; }
    .item td { padding: 3pt 4pt; vertical-align: top; }
    .item .no { width: 30pt; text-align: center; }
    .item .qty, .item .unit { text-align: center; }
    .item .angka { text-align: right; }
    .total td { border-top: 1.2pt solid #000; border-bottom: 1.2pt solid #000; padding: 4pt; font-weight: bold; }
    .ringkas { width: 100%; margin-top: 8pt; }
    .ringkas td { padding: 2pt 0; }
    .ringkas .isi { width: 215pt; }
    .ringkas .kanan { text-align: right; }
    .grand td { border: 1.2pt solid #000; padding: 4pt 6pt; font-weight: bold; }
    .miring { font-style: italic; font-weight: bold; }
    .bawah { margin-top: 22pt; }
    .tanda { width: 100%; margin-top: 26pt; }
    .tanda td { padding: 0; height: 56pt; vertical-align: top; }
    .kaki { margin-top: 26pt; font-size: 9.5pt; font-style: italic; }
    .bersambung { font-size: 9pt; font-style: italic; margin-top: 6pt; }
    .pisah { page-break-after: always; }
</style>
</head>
<body>
@foreach ($halaman as $nomorHalaman => $baris)
    @php $akhir = $nomorHalaman === count($halaman) - 1; @endphp
    <div @class(['pisah' => ! $akhir])>
        <h1>INVOICE</h1>

        <table class="kepala">
            <tr>
                <td class="kiri">
                    <div>Kepada Yth</div>
                    <table class="pasang">
                        <tr><td class="label">Customer</td><td>: {{ $customer }}</td></tr>
                        <tr><td class="label">Telp</td><td>: {{ $telp }}</td></tr>
                        <tr><td class="label">Address</td><td>: {{ $alamat }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="pasang">
                        <tr><td class="labelkanan">Invoice Number</td><td>: {{ $invoice_number }}</td></tr>
                        <tr><td class="labelkanan">Tgl Invoice</td><td>: {{ $tgl_invoice }}</td></tr>
                        <tr><td class="labelkanan">Estimated Shipping</td><td>: {{ $estimated_shipping }}</td></tr>
                        <tr><td class="labelkanan">Platform</td><td>: {{ $platform }}</td></tr>
                        <tr><td class="labelkanan">ADMIN</td><td>: {{ $admin }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="item">
            <tr>
                <th class="no">No</th>
                <th>Item</th>
                <th style="width: 50pt">Qty</th>
                <th style="width: 50pt">Unit</th>
                <th style="width: 83pt">Unit Price</th>
                <th style="width: 100pt">Amount</th>
            </tr>
            @foreach ($baris as $i => $b)
                <tr>
                    <td class="no">{{ $nomorHalaman * \App\Support\InvoiceAdiva::BARIS_PER_HALAMAN + $i + 1 }}</td>
                    <td>{{ $b['nama'] }}</td>
                    <td class="qty">{{ $b['qty'] }}</td>
                    <td class="unit">{{ $b['unit'] }}</td>
                    <td class="angka">{{ $rp($b['harga']) }}</td>
                    <td class="angka">{{ $rp($b['qty'] * $b['harga']) }}</td>
                </tr>
            @endforeach
            @if ($akhir)
                <tr class="total">
                    <td colspan="5" style="text-align: center">TOTAL</td>
                    <td class="angka">{{ $rp($subtotal) }}</td>
                </tr>
            @endif
        </table>

        @if (! $akhir)
            <div class="bersambung">Bersambung ke halaman berikutnya...</div>
        @else
            <table class="ringkas">
                <tr>
                    <td></td>
                    <td class="isi">
                        <table style="width: 100%">
                            <tr><td>Shipping Cost</td><td class="kanan">{{ $rp($shipping_cost) }}</td></tr>
                            <tr><td>Packing Cost</td><td class="kanan">{{ $rp($packing_cost) }}</td></tr>
                            <tr><td><b>Total Amount</b></td><td class="kanan"><b>{{ $rp($total_amount) }}</b></td></tr>
                            <tr><td>Diskon Produk</td><td class="kanan">{{ $rp($diskon_produk) }}</td></tr>
                            <tr><td>Diskon Ongkir</td><td class="kanan">{{ $rp($diskon_ongkir) }}</td></tr>
                        </table>
                        <table class="grand" style="width: 100%; margin-top: 6pt">
                            <tr><td>Grand Total</td><td class="kanan">{{ $rp($grand_total) }}</td></tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div class="bawah">
                <div class="miring">Terbilang&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;{{ $terbilang }}</div>
                <div class="miring" style="margin-top: 5pt">Notes: {{ $notes1 }}</div>
                <div class="miring" style="margin-top: 5pt">Notes: {{ $notes2 }}</div>
            </div>

            <table class="tanda">
                <tr>
                    <td style="padding-left: 110pt">Tanda Terima<br><br><br><br><br>(Customer)</td>
                    <td style="padding-left: 40pt">Hormat Kami,</td>
                </tr>
            </table>

            <div class="kaki">
                <div style="font-style: normal">Notes:</div>
                <div>1. {{ $catatan_bayar }}</div>
                <div>2. {{ $catatan_tukar }}</div>
            </div>
        @endif
    </div>
@endforeach
</body>
</html>
