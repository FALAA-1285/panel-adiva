import type { Auth } from '@/types/auth';
import type { Panel } from '@/types/panel';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            panel: Panel | null;
            [key: string]: unknown;
        };
    }
}
