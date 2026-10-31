<?php

namespace App\Accurate;

/**
 * Stands in for Accurate (demo mode, the tests): a short shelf of Toko Sawojajar, with a
 * few goods the shop does not sell (no barcode, suspended) so the filter has work to do.
 */
class TiruanAccurate implements BacaAccurate
{
    /** [id, kode, nama, barcode, kategori, satuan, harga, nonaktif] */
    private const BARANG = [
        [5001, 'BMB-001', 'Ketumbar Bubuk 100 gr', '8992001000011', 'BUMBU', 'PCS', 6500, false],
        [5002, 'BMB-002', 'Merica Bubuk 50 gr', '8992001000028', 'BUMBU', 'PCS', 9000, false],
        [5003, 'BMB-003', 'Bawang Putih Bubuk 100 gr', '8992001000035', 'BUMBU', 'PCS', 12000, false],
        [5004, 'BMB-004', 'Bumbu Balado Bubuk 250 gr', '8992001000042', 'BUMBU', 'PCS', 18500, false],
        [5005, 'BMB-005', 'Bumbu Jagung Manis 250 gr', '8992001000059', 'BUMBU', 'PCS', 17500, false],
        [5006, 'BMB-006', 'Daun Jeruk Kering 25 gr', '8992001000066', 'BUMBU', 'PCS', 5000, false],
        [5101, 'KLN-001', 'Tepung Tapioka Kiloan', '2000000510105', 'KILOAN', 'KG', 14000, false],
        [5102, 'KLN-002', 'Gula Pasir Kiloan', '2000000510202', 'KILOAN', 'KG', 18000, false],
        [5103, 'KLN-003', 'Kacang Tanah Kupas Kiloan', '2000000510309', 'KILOAN', 'KG', 32000, false],
        [5104, 'KLN-004', 'Makaroni Mentah Kiloan', '2000000510406', 'KILOAN', 'KG', 21000, false],
        [5201, 'BB-001', 'Basreng Pedas Daun Jeruk 200 gr', '8997002000012', 'BANG BANA', 'PCS', 16000, false],
        [5202, 'BB-002', 'Keripik Seblak Kencur 200 gr', '8997002000029', 'BANG BANA', 'PCS', 15000, false],
        [5203, 'BB-003', 'Makaroni Bantat Pedas 250 gr', '8997002000036', 'BANG BANA', 'PCS', 14000, false],
        [5204, 'BB-004', 'Usus Crispy Original 100 gr', '8997002000043', 'BANG BANA', 'PCS', 17000, false],
        [5301, 'MNM-001', 'Teh Botol 350 ml', '8996001600016', 'MINUMAN', 'BTL', 5000, false],
        // not on the shop's shelf
        [5901, 'BHN-001', 'Minyak Goreng Curah (bahan produksi)', null, 'BAHAN BAKU', 'LITER', 17000, false],
        [5902, 'PKG-001', 'Plastik Standing Pouch 20x30', null, 'KEMASAN', 'PAK', 45000, false],
        [5903, 'BMB-099', 'Bumbu Keju Lama', '8992001000998', 'BUMBU', 'PCS', 15000, true],
    ];

    public function barang(): array
    {
        return array_map(fn (array $b) => [
            'accurate_id' => $b[0],
            'kode' => $b[1],
            'nama' => $b[2],
            'barcode' => $b[3],
            'kategori' => $b[4],
            'satuan' => $b[5],
            'harga' => $b[6],
            'nonaktif' => $b[7],
        ], self::BARANG);
    }
}
