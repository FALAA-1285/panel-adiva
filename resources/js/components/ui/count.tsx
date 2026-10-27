import { cn } from '@/lib/utils';

type CountProps = {
    n: number;
    /** Strong = white on the blue pill (Percakapan, bell); otherwise a soft pill. */
    kuat?: boolean;
    className?: string;
};

export function Count({ n, kuat = false, className }: CountProps) {
    return (
        <span
            className={cn(
                kuat
                    ? 'rounded-full bg-primary px-[7px] py-px text-tag font-semibold text-white'
                    : 'rounded-full bg-primary-soft px-[7px] py-px text-tag font-semibold text-primary-ink',
                className,
            )}
        >
            {n}
        </span>
    );
}
