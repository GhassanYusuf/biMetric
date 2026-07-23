import { SpinnerIcon } from '@/Components/Icons';

const variants = {
    default: 'border-gray-200 text-gray-700 hover:border-gray-300 hover:bg-gray-50',
    accent: 'border-indigo-200 text-indigo-700 hover:border-indigo-300 hover:bg-indigo-50',
    warning: 'border-amber-200 text-amber-700 hover:border-amber-300 hover:bg-amber-50',
    danger: 'border-red-200 text-red-700 hover:border-red-300 hover:bg-red-50',
};

export default function TableActionButton({
    icon: Icon,
    children,
    variant = 'default',
    loading = false,
    disabled = false,
    className = '',
    ...props
}) {
    return (
        <button
            type="button"
            disabled={disabled || loading}
            className={`inline-flex items-center gap-1.5 rounded-md border bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 disabled:pointer-events-none disabled:opacity-40 ${variants[variant]} ${className}`}
            {...props}
        >
            {loading ? <SpinnerIcon className="h-3.5 w-3.5" /> : Icon && <Icon className="h-3.5 w-3.5" />}
            <span>{children}</span>
        </button>
    );
}
