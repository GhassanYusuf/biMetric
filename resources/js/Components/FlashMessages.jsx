import { CheckCircleIcon, XCircleIcon } from '@/Components/Icons';
import { Transition } from '@headlessui/react';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
