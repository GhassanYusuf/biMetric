const variants = {
    neutral: 'bg-gray-100 text-gray-600',
    info: 'bg-blue-50 text-blue-700',
    success: 'bg-green-50 text-green-700',
    error: 'bg-red-50 text-red-700',
    warning: 'bg-amber-50 text-amber-700',
};

export default function Badge({ variant = 'neutral', className = '', children }) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${variants[variant]} ${className}`}
        >
            {children}
        </span>
    );
}
