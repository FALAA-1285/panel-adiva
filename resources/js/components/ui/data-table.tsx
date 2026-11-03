import { tableFeatures, useTable } from '@tanstack/react-table';
import type {
    CellData,
    ColumnDef,
    RowData,
    TableFeatures,
} from '@tanstack/react-table';
import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';

declare module '@tanstack/table-core' {
    interface ColumnMeta<
        in out TFeatures extends TableFeatures,
        in out TData extends RowData,
        TValue extends CellData = CellData,
    > {
        /** Right-align header and cells (Harga, Jumlah, Subtotal). */
        kanan?: boolean;
    }
}

export const fiturTabel = tableFeatures({});

export type KolomTabel<TData extends RowData> = ColumnDef<
    typeof fiturTabel,
    TData
>;

type DataTableProps<TData extends RowData> = {
    kolom: KolomTabel<TData>[];
    data: TData[];
    /** grid-template-columns copied from the page source, e.g. "minmax(0, 1fr) 100px 70px 110px". */
    template: string;
    idBaris?: (baris: TData) => string;
    terpilih?: (baris: TData) => boolean;
    onPilih?: (baris: TData) => void;
    className?: string;
};

/**
 * Narrow screens: the columns keep their widths from the design (the 1fr ones at
 * least 160px) and the table scrolls sideways in its panel instead of squashing.
 */
export function lebarMinimum(template: string): number {
    const kolom = template.match(/minmax\([^)]*\)|\S+/g) ?? [];

    return kolom.reduce((total, k) => {
        const px = /^(\d+(?:\.\d+)?)px$/.exec(k);

        return total + (px ? Number(px[1]) : 160) + 16;
    }, 36);
}

export function DataTable<TData extends RowData>({
    kolom,
    data,
    template,
    idBaris,
    terpilih,
    onPilih,
    className,
}: DataTableProps<TData>) {
    const table = useTable({
        features: fiturTabel,
        columns: kolom,
        data,
        getRowId: idBaris ? (baris) => idBaris(baris) : undefined,
    });

    const sel = (kanan?: boolean) =>
        cn(
            'flex min-w-0 flex-col gap-0.5',
            kanan ? 'items-end text-right' : 'items-start text-left',
        );

    return (
        // narrow screens: the table keeps its columns and scrolls sideways here
        <div className="max-xl:overflow-x-auto">
            <div
                role="table"
                className={cn(
                    'flex flex-col max-xl:min-w-(--lebar-min)',
                    className,
                )}
                style={
                    {
                        '--lebar-min': `${lebarMinimum(template)}px`,
                    } as CSSProperties
                }
            >
                {table.getHeaderGroups().map((grup) => (
                    <div
                        key={grup.id}
                        role="row"
                        // content-box as in the source: 38px + the 1px bottom border
                        className="box-content grid h-[38px] items-center gap-4 border-b border-line bg-subtle px-[18px]"
                        style={{ gridTemplateColumns: template }}
                    >
                        {grup.headers.map((header) => (
                            <div
                                key={header.id}
                                role="columnheader"
                                className={sel(
                                    header.column.columnDef.meta?.kanan,
                                )}
                            >
                                <span className="text-caption font-medium whitespace-nowrap text-muted">
                                    {header.isPlaceholder ? null : (
                                        <table.FlexRender header={header} />
                                    )}
                                </span>
                            </div>
                        ))}
                    </div>
                ))}
                {table.getRowModel().rows.map((row) => {
                    const aktif = terpilih?.(row.original) ?? false;
                    const kelas = cn(
                        'grid w-full items-center gap-4 border-b border-line-soft px-[18px] py-[11px] text-left text-ink',
                        aktif
                            ? 'bg-selected shadow-[inset_3px_0_0_var(--color-primary)]'
                            : 'bg-surface',
                        onPilih && !aktif && 'hover:bg-subtle',
                        'motion-safe:transition-colors motion-safe:duration-150',
                    );
                    const sel_ = row.getAllCells().map((cell) => (
                        <div
                            key={cell.id}
                            role="cell"
                            className={sel(cell.column.columnDef.meta?.kanan)}
                        >
                            <table.FlexRender cell={cell} />
                        </div>
                    ));

                    return onPilih ? (
                        <div
                            key={row.id}
                            role="row"
                            tabIndex={0}
                            aria-selected={aktif}
                            onClick={() => onPilih(row.original)}
                            onKeyDown={(e) => {
                                if (
                                    e.target === e.currentTarget &&
                                    (e.key === 'Enter' || e.key === ' ')
                                ) {
                                    e.preventDefault();
                                    onPilih(row.original);
                                }
                            }}
                            className={cn(
                                kelas,
                                'cursor-pointer border-x-0 border-t-0 focus-visible:ring-1 focus-visible:ring-primary focus-visible:outline-none',
                            )}
                            style={{ gridTemplateColumns: template }}
                        >
                            {sel_}
                        </div>
                    ) : (
                        <div
                            key={row.id}
                            role="row"
                            className={kelas}
                            style={{ gridTemplateColumns: template }}
                        >
                            {sel_}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
