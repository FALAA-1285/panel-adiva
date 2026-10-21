import { useSyncExternalStore } from 'react';

/** Tailwind's default breakpoints, used by the max-xl: / max-lg: / max-md: classes. */
export const LAYAR = {
    /** the design's own layout (1440) still fits */
    lebar: '(min-width: 1280px)',
    /** sidebar can sit beside the page (as icons) */
    sedang: '(min-width: 1024px)',
    /** tablet and up */
    tablet: '(min-width: 768px)',
} as const;

/**
 * Whether a media query matches, following resizes. The server (and the first
 * render after it) assumes the wide desktop layout the design was drawn for.
 */
export function useLayar(query: string): boolean {
    return useSyncExternalStore(
        (ubah) => {
            const m = window.matchMedia(query);
            m.addEventListener('change', ubah);

            return () => m.removeEventListener('change', ubah);
        },
        () => window.matchMedia(query).matches,
        () => true,
    );
}

export function cocok(query: string): boolean {
    return typeof window === 'undefined' || window.matchMedia(query).matches;
}
