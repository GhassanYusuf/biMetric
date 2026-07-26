import { useDeviceClass } from '../shared/useDeviceClass';
import { useUnreadCount } from '../shared/useUnreadCount';
import { TopBarProvider } from '../shared/topbar';
import CommandPalette, { useCommandPalette } from '../shared/CommandPalette';
import DesktopLayout from './DesktopLayout';
import MobileLayout from './MobileLayout';

// Picks the SEPARATE presentation chrome for the current device class. Both
// layouts share the same nav definition, auth, and the live unread-count hook,
// so behaviour stays identical while the structure differs (sidebar vs
// bottom nav) — exactly what the standards require.
//
// The palette and the top-bar slot provider sit here, above both chromes: the
// palette must escape every overflow container, and pages register their top-bar
// controls without caring which layout renders them.
export default function AppLayout() {
    const deviceClass = useDeviceClass();
    const unread = useUnreadCount();
    const palette = useCommandPalette();

    const openPalette = () => palette.setOpen(true);

    return (
        <TopBarProvider>
            {deviceClass === 'desktop' ? (
                <DesktopLayout unread={unread} onOpenPalette={openPalette} />
            ) : (
                <MobileLayout unread={unread} onOpenPalette={openPalette} />
            )}
            <CommandPalette open={palette.open} onClose={() => palette.setOpen(false)} />
        </TopBarProvider>
    );
}
