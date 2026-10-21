import { cn } from '@/lib/utils';

type LangkahProps = {
    label: string[];
    /** Index of the current step; earlier steps keep a blue bar. */
    aktif: number;
};

/** Step bars above the Daftar and Lupa sandi forms. */
export function Langkah({ label, aktif }: LangkahProps) {
    return (
        <div
            className="grid gap-2"
            style={{
                gridTemplateColumns: `repeat(${label.length}, minmax(0, 1fr))`,
            }}
        >
            {label.map((l, i) => (
                <div key={l} className="flex flex-col gap-1.5">
                    <span
                        className={cn(
                            'h-[3px] rounded-[2px]',
                            i <= aktif ? 'bg-primary' : 'bg-line',
                        )}
                    />
                    <span
                        className={cn(
                            'text-caption whitespace-nowrap',
                            i === aktif
                                ? 'font-semibold text-ink'
                                : 'font-medium text-muted',
                        )}
                    >
                        {l}
                    </span>
                </div>
            ))}
        </div>
    );
}
