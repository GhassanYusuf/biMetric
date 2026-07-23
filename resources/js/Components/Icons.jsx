const base = 'h-4 w-4';

export function PencilIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path
                d="M13.5 3.5l3 3L6 17H3v-3L13.5 3.5z"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export function TrashIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path
                d="M4 6h12M8 6V4.5A1.5 1.5 0 0 1 9.5 3h1A1.5 1.5 0 0 1 12 4.5V6m-6.5 0 .6 9.4a1.5 1.5 0 0 0 1.5 1.4h4.8a1.5 1.5 0 0 0 1.5-1.4L14.5 6"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path d="M8.5 9v4.5M11.5 9v4.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function RefreshIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path
                d="M16 8.5A6 6 0 0 0 5.6 5.6L4 7.2M4 4v3.2h3.2M4 11.5A6 6 0 0 0 14.4 14.4L16 12.8M16 16v-3.2h-3.2"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export function SignalIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path d="M4 14v-2M8 14V9M12 14V6M16 14V3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function UserPlusIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <circle cx="8" cy="7" r="3" stroke="currentColor" strokeWidth="1.5" />
            <path d="M2.5 17c0-3 2.5-5 5.5-5s5.5 2 5.5 5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            <path d="M16 6.5v4M14 8.5h4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function UserMinusIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <circle cx="8" cy="7" r="3" stroke="currentColor" strokeWidth="1.5" />
            <path d="M2.5 17c0-3 2.5-5 5.5-5s5.5 2 5.5 5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            <path d="M14 8.5h4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function CheckCircleIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <circle cx="10" cy="10" r="7.25" stroke="currentColor" strokeWidth="1.5" />
            <path d="M7 10.2l2 2 4-4.4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

export function XCircleIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <circle cx="10" cy="10" r="7.25" stroke="currentColor" strokeWidth="1.5" />
            <path d="M7.5 7.5l5 5m0-5-5 5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function MinusCircleIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <circle cx="10" cy="10" r="7.25" stroke="currentColor" strokeWidth="1.5" />
            <path d="M7 10h6" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function PlusIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path d="M10 4.5v11M4.5 10h11" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function InboxIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path
                d="M3 11.5 5 4h10l2 7.5M3 11.5V15a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3.5M3 11.5h4.2c.3 0 .5.2.6.4l.4 1.2c.1.2.3.4.6.4h2.4c.3 0 .5-.2.6-.4l.4-1.2c.1-.2.3-.4.6-.4H17"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export function FingerprintIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path
                d="M10 4.5c-3 0-5.5 2.5-5.5 5.5v2.5M10 4.5c3 0 5.5 2.5 5.5 5.5v1M10 7c-1.7 0-3 1.3-3 3v3.5M10 7c1.7 0 3 1.3 3 3v1M10 9.5c-.8 0-1.5.7-1.5 1.5v3.5M10 9.5c.8 0 1.5.7 1.5 1.5v1"
                stroke="currentColor"
                strokeWidth="1.4"
                strokeLinecap="round"
            />
        </svg>
    );
}

export function XIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path d="M5 5l10 10M15 5 5 15" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function MailIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <rect x="3" y="5" width="14" height="10" rx="1.5" stroke="currentColor" strokeWidth="1.5" />
            <path d="M3.5 5.5 10 10.5l6.5-5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

export function BuildingIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <rect x="4" y="3" width="9" height="14" rx="1" stroke="currentColor" strokeWidth="1.5" />
            <path d="M7 6.5h.01M10 6.5h.01M7 9.5h.01M10 9.5h.01M7 12.5h.01M10 12.5h.01" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            <path d="M13 8h2.5a.5.5 0 0 1 .5.5V17h-3" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round" />
        </svg>
    );
}

export function CalendarIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <rect x="3" y="4.5" width="14" height="12" rx="1.5" stroke="currentColor" strokeWidth="1.5" />
            <path d="M3 8h14M6.5 3v3M13.5 3v3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function CreditCardIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <rect x="2.5" y="5" width="15" height="10" rx="1.5" stroke="currentColor" strokeWidth="1.5" />
            <path d="M2.5 8h15" stroke="currentColor" strokeWidth="1.5" />
            <path d="M5 11.5h3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}

export function ChevronDownIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} ${className}`}>
            <path d="M5 7.5 10 12.5 15 7.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

export function SpinnerIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={`${base} animate-spin ${className}`}>
            <circle cx="10" cy="10" r="7.25" stroke="currentColor" strokeWidth="1.5" strokeOpacity="0.25" />
            <path d="M17.25 10a7.25 7.25 0 0 0-7.25-7.25" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
    );
}
