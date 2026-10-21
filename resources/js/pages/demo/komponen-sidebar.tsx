import { usePage } from '@inertiajs/react';
import { Sidebar } from '@/components/ui/sidebar';

// Preview for design/screens/Sidebar.png (248×1100, active = Ringkasan).
export default function KomponenSidebar() {
    const { panel } = usePage().props;

    if (!panel) {
        return null;
    }

    return (
        <div className="h-screen">
            <Sidebar active="main" panel={panel} tetap />
        </div>
    );
}
