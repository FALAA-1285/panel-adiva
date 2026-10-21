/**
 * Shared props for the Sidebar and Topbar (HandleInertiaRequests::panel).
 */
export type Panel = {
    /** "Senin, 28 Sep · 13:42 WIB" */
    waktu: string;
    hitungan: {
        percakapan: number;
        pesanan: number;
        jeda: number;
        monitoring: number;
        notifikasi: number;
        /** Instagram & Facebook contacts waiting for an admin */
        sosmed: number;
    };
    status: {
        nomorWaha: number;
        workflowAktif: number;
        dijeda: number;
    };
    pengguna: {
        nama: string;
        inisial: string;
        peran: string;
    };
    /** Menu items the signed-in role may open. */
    akses: {
        katalog: boolean;
        tim: boolean;
        pengaturan: boolean;
        /** Reply, pause and resume the bot, and edit what Adiva knows of a customer. */
        balas: boolean;
        catat: boolean;
        /** Instagram & Facebook: hidden in demo mode (not in the design) */
        sosmed: boolean;
    };
};
