import type { ReactNode } from 'react';
import { PageHeader } from '@/components/ui/page-header';

export type TabKatalog = {
    produk: number;
    paket: number;
    gambar: number;
    kebijakan: number;
    karyawan: number;
};

type KatalogHeaderProps = {
    aktif: keyof TabKatalog;
    tab: TabKatalog;
    aksi?: ReactNode;
};

// Routes follow design/halaman.json; switch to Wayfinder as each page is built.
const bagian: { kode: keyof TabKatalog; label: string; href: string }[] = [
    { kode: 'produk', label: 'Produk', href: '/katalog' },
    { kode: 'paket', label: 'Paket reseller', href: '/katalog/paket' },
    { kode: 'gambar', label: 'Gambar produk', href: '/katalog/gambar' },
    { kode: 'kebijakan', label: 'Kebijakan', href: '/katalog/kebijakan' },
    { kode: 'karyawan', label: 'Program karyawan', href: '/katalog/karyawan' },
];

/** Title, subtitle and the five tabs shared by every "Katalog & pengetahuan" page. */
export function KatalogHeader({ aktif, tab, aksi }: KatalogHeaderProps) {
    return (
        <PageHeader
            judul="Katalog & pengetahuan"
            sub="Semua harga dan jawaban Adiva diambil dari sini. Perubahan langsung dipakai di pesan berikutnya."
            aksi={aksi}
            tab={bagian.map((b) => ({
                label: b.label,
                n: tab[b.kode],
                aktif: b.kode === aktif,
                href: b.href,
            }))}
        />
    );
}
