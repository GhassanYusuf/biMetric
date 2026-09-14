import { Transition } from '@headlessui/react';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/*
 * These two used to come from @/Components/Icons, a nineteen-icon module that the
 * retired Inertia screens shared. This is the only file left that wanted any of it,
 * so they live here rather than keeping the module alive for two glyphs. The SPA has
 * its own icon set under spa/shared.
 */
function CheckCircleIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="currentColor" className={className} aria-hidden="true">
            <path
                fillRule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                clipRule="evenodd"
            />
        </svg>
    );
}

function XCircleIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="currentColor" className={className} aria-hidden="true">
            <path
                fillRule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z"
                clipRule="evenodd"
            />
        </svg>
    );
}

function Alert({ variant, message }) {
    const [show, setShow] = useState(false);

    useEffect(() => {
        setShow(true);
    }, [message]);

    if (!message) return null;

    const isSuccess = variant === 'success';
    const Icon = isSuccess ? CheckCircleIcon : XCircleIcon;

    return (
        <Transition
            show={show}
            appear
            enter="transition duration-200 ease-out"
            enterFrom="opacity-0 -translate-y-1"
            enterTo="opacity-100 translate-y-0"
            leave="transition duration-150 ease-in"
            leaveFrom="opacity-100"
            leaveTo="opacity-0"
        >
            <div
                className={`mb-3 flex items-start gap-2.5 rounded-lg px-4 py-3 text-sm ${
                    isSuccess ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'
                }`}
            >
                <Icon className={`mt-0.5 h-4 w-4 flex-shrink-0 ${isSuccess ? 'text-green-500' : 'text-red-500'}`} />
                <p className="flex-1">{message}</p>
                <button
                    type="button"
                    onClick={() => setShow(false)}
                    className={`flex-shrink-0 rounded p-0.5 focus:outline-none focus:ring-2 focus:ring-offset-1 ${
                        isSuccess
                            ? 'text-green-500 hover:bg-green-100 focus:ring-green-500'
                            : 'text-red-500 hover:bg-red-100 focus:ring-red-500'
                    }`}
                    aria-label="Dismiss"
                >
                    <svg viewBox="0 0 20 20" fill="none" className="h-3.5 w-3.5">
                        <path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                    </svg>
                </button>
            </div>
        </Transition>
    );
}

export default function FlashMessages() {
    const { flash } = usePage().props;

    if (!flash?.success && !flash?.error) {
        return null;
    }

    return (
        <div className="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
            <Alert variant="success" message={flash.success} />
            <Alert variant="error" message={flash.error} />
        </div>
    );
}
