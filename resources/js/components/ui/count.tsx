import { cn } from '@/lib/utils';

type CountProps = {
    n: number;
    /** Strong = white on blue pill (Percakapan, bell); otherwise muted text. */
    kuat?: boolean;
    className?: string;
};

export function Count({ n, kuat = false, className }: CountProps) {
    return (
        <span
            className={cn(
                kuat
                    ? 'rounded-full bg-primary px-[7px] py-px text-tag font-semibold text-white'
                    : 'text-caption font-medium text-muted',
                className,
            )}
        >
            {n}
        </span>
    );
}
