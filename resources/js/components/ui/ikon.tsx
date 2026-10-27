import type { ReactNode } from 'react';

/**
 * Icons copied path-for-path from design/source (they differ slightly from Lucide).
 * Add new ones here from the source of the page that uses them.
 */
const bentuk = {
    // Sidebar.dc.html
    ringkasan: (
        <>
            <rect x="3" y="3" width="7" height="9" rx="1.5" />
            <rect x="14" y="3" width="7" height="5" rx="1.5" />
            <rect x="14" y="12" width="7" height="9" rx="1.5" />
            <rect x="3" y="16" width="7" height="5" rx="1.5" />
        </>
    ),
    percakapan: (
        <>
            <path d="M22 12h-6l-2 3h-4l-2-3H2" />
            <path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z" />
        </>
    ),
    pesanan: (
        <>
            <path d="M3 7l9-4 9 4v10l-9 4-9-4z" />
            <path d="M3 7l9 4 9-4" />
            <path d="M12 11v10" />
        </>
    ),
    jeda: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M10 9v6M14 9v6" />
        </>
    ),
    pipeline: (
        <>
            <rect x="3" y="4" width="5" height="16" rx="1.5" />
            <rect x="10" y="4" width="5" height="11" rx="1.5" />
            <rect x="17" y="4" width="4" height="7" rx="1.5" />
        </>
    ),
    pelanggan: (
        <>
            <circle cx="9" cy="8" r="3.5" />
            <path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
            <path d="M16 4.5a3.5 3.5 0 0 1 0 7" />
            <path d="M18 14.5a6.5 6.5 0 0 1 3.5 5.5" />
        </>
    ),
    broadcast: (
        <>
            <path d="M4.9 19.1a10 10 0 0 1 0-14.2" />
            <path d="M7.8 16.2a6 6 0 0 1 0-8.4" />
            <circle cx="12" cy="12" r="2" />
            <path d="M16.2 7.8a6 6 0 0 1 0 8.4" />
            <path d="M19.1 4.9a10 10 0 0 1 0 14.2" />
        </>
    ),
    katalog: (
        <>
            <path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z" />
            <path d="M4 19V5" />
            <path d="M8 7h7" />
        </>
    ),
    prompt: (
        <>
            <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" />
            <path d="M14 3v6h6" />
            <path d="m9 13-2 2 2 2" />
            <path d="m15 13 2 2-2 2" />
        </>
    ),
    otomasi: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    pengaturan: (
        <>
            <circle cx="12" cy="12" r="3" />
            <path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1" />
        </>
    ),
    monitoring: <path d="M3 12h4l3-8 4 16 3-8h4" />,
    tim: (
        <>
            <path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z" />
            <path d="m9 12 2 2 4-4" />
        </>
    ),
    aktivitas: (
        <>
            <path d="M8 6h13M8 12h13M8 18h13" />
            <path d="M3 6h.01M3 12h.01M3 18h.01" />
        </>
    ),
    keluar: (
        <>
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <path d="m16 17 5-5-5-5" />
            <path d="M21 12H9" />
        </>
    ),
    // Topbar.dc.html
    sidebar: (
        <>
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="M9 4v16" />
        </>
    ),
    cari: (
        <>
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </>
    ),
    lonceng: (
        <>
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
            <path d="M10.3 21a1.9 1.9 0 0 0 3.4 0" />
        </>
    ),
    // Login.dc.html
    email: (
        <>
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3 7 9 6 9-6" />
        </>
    ),
    gembok: (
        <>
            <rect x="4" y="10" width="16" height="11" rx="2" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
        </>
    ),
    mata: (
        <>
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
            <circle cx="12" cy="12" r="3" />
        </>
    ),
    mataTutup: (
        <>
            <path d="M3 3l18 18" />
            <path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6" />
            <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
        </>
    ),
    whatsapp: (
        <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z" />
    ),
    // Daftar.dc.html
    pengguna: (
        <>
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21a8 8 0 0 1 16 0" />
        </>
    ),
    kembali: <path d="M15 18l-6-6 6-6" />,
    // Katalog.dc.html
    unggah: (
        <>
            <path d="M12 21V9" />
            <path d="m7 14 5-5 5 5" />
            <path d="M5 3h14" />
        </>
    ),
    tambah: <path d="M12 5v14M5 12h14" />,
    // Paket.dc.html
    tutup: <path d="M18 6 6 18M6 6l12 12" />,
    // Gaya audit (Lucide-shaped)
    jam: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    tugas: (
        <>
            <path d="m9 11 3 3L22 4" />
            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
        </>
    ),
    laporan: <path d="M18 20V10M12 20V4M6 20v-6" />,
    produk: (
        <>
            <path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z" />
            <circle cx="7" cy="7" r="1" />
        </>
    ),
    promosi: (
        <>
            <path d="m3 11 18-5v12L3 14v-3z" />
            <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6" />
        </>
    ),
    // Kebijakan.dc.html
    putar: <path d="m7 4 13 8-13 8z" />,
    // Gambar.dc.html
    foto: (
        <>
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21" />
        </>
    ),
    // Tim.dc.html
    ubah: (
        <>
            <path d="M12 20h9" />
            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
        </>
    ),
    lainnya: (
        <>
            <circle cx="5" cy="12" r="1" />
            <circle cx="12" cy="12" r="1" />
            <circle cx="19" cy="12" r="1" />
        </>
    ),
    // Customer.dc.html: "Aksi lain", filled dots
    lainnyaPenuh: (
        <>
            <circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none" />
            <circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none" />
            <circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none" />
        </>
    ),
    centang: <path d="M20 6 9 17l-5-5" />,
    laptop: (
        <>
            <rect x="4" y="5" width="16" height="11" rx="1.5" />
            <path d="M2 19h20" />
        </>
    ),
    ponsel: (
        <>
            <rect x="7" y="2" width="10" height="20" rx="2" />
            <path d="M11 18h2" />
        </>
    ),
    // Otomasi.dc.html
    riwayat: (
        <>
            <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
            <path d="M3 3v5h5" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    kanan: <path d="m9 18 6-6-6-6" />,
    // Settings.dc.html
    simpan: (
        <>
            <path d="M5 3h11l3 3v15H5z" />
            <path d="M8 3v5h8" />
            <path d="M8 21v-7h8v7" />
        </>
    ),
    // Karyawan.dc.html
    // OrderNew.dc.html: "Hapus item"
    hapus: (
        <>
            <path d="M3 6h18" />
            <path d="M8 6V4h8v2" />
            <path d="M19 6l-1 14H6L5 6" />
        </>
    ),
    // OrderDetail.dc.html
    cetak: (
        <>
            <path d="M6 9V3h12v6" />
            <rect x="3" y="9" width="18" height="8" rx="2" />
            <path d="M6 14h12v7H6z" />
        </>
    ),
    // Orders.dc.html: bukti transfer
    gambar: (
        <>
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-5-5L5 21" />
        </>
    ),
    unduh: (
        <>
            <path d="M12 3v12" />
            <path d="m7 10 5 5 5-5" />
            <path d="M5 21h14" />
        </>
    ),
    // Inbox.dc.html: "Reset memori chat"
    segarkan: (
        <>
            <path d="M3 12a9 9 0 0 1 15.5-6.2L21 8" />
            <path d="M21 3v5h-5" />
            <path d="M21 12a9 9 0 0 1-15.5 6.2L3 16" />
            <path d="M3 21v-5h5" />
        </>
    ),
    // Inbox.dc.html: the composer's hint
    info: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v4M12 16h.01" />
        </>
    ),
    // BroadcastNew.dc.html: "Kirim uji ke supervisor"
    kirim: (
        <>
            <path d="M22 2 11 13" />
            <path d="M22 2 15 22l-4-9-9-4z" />
        </>
    ),
    // small screens and shortcuts (not in the design; Lucide's shapes)
    menu: <path d="M4 6h16M4 12h16M4 18h16" />,
    bawah: <path d="M12 5v14M5 12l7 7 7-7" />,
    keyboard: (
        <>
            <rect x="2" y="5" width="20" height="14" rx="2" />
            <path d="M6 9h.01M10 9h.01M14 9h.01M18 9h.01M7 15h10" />
        </>
    ),
    // the panel run from one place (not in the design; Lucide's shapes)
    turun: <path d="m6 9 6 6 6-6" />,
    sosmed: (
        <>
            <circle cx="12" cy="12" r="4" />
            <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8" />
        </>
    ),
} satisfies Record<string, ReactNode>;

export type NamaIkon = keyof typeof bentuk;

type IkonProps = {
    nama: NamaIkon;
    ukuran?: number;
    tebal?: number;
    className?: string;
};

export function Ikon({ nama, ukuran = 16, tebal = 1.8, className }: IkonProps) {
    return (
        <svg
            width={ukuran}
            height={ukuran}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={tebal}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={className}
        >
            {bentuk[nama]}
        </svg>
    );
}
