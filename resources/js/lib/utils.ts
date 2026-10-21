import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// Teach tailwind-merge the design tokens (resources/css/app.css), otherwise it
// reads `text-tag` as a colour and drops it next to `text-white`.
const twMerge = extendTailwindMerge({
    extend: {
        theme: {
            text: [
                'key',
                'mini',
                'tag',
                'caption',
                'meta',
                'body',
                'base',
                'panel',
                'page',
                'stat',
            ],
            radius: ['tag', 'chip', 'nav', 'control', 'card'],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
