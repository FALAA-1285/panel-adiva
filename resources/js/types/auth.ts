export type User = {
    id: number;
    name: string;
    email: string;
    nomor_wa: string | null;
    peran: 'SUPERVISOR' | 'STAF_CS' | 'PJ_KARYAWAN';
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
};

export type Auth = {
    user: User;
};
