import { usePage } from '@inertiajs/react';
import { Topbar } from '@/components/ui/topbar';

// Preview for design/screens/Topbar.png (1192×64).
export default function KomponenTopbar() {
    const { panel } = usePage().props;

    if (!panel) {
        return null;
    }

    return <Topbar panel={panel} />;
}
