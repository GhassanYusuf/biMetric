import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
        /*
         * .js as well as .jsx (2026-10-05). Tailwind only emits classes it finds
         * by reading source, and ELEVATION in shared/theme.js is a plain .js file
         * holding every shadow in the design system. Without this glob the four
         * tokens — card, raised, overlay and menu — were never compiled, so no
         * card, popover, command palette or dropdown had a shadow at all. It
         * surfaced as "the employee menu is invisible against the row below":
         * a white panel over white stripes with nothing to lift it.
         *
         * The file name is not a reliable signal. theme.js is a component of the
         * design system; if a class string lives there, Tailwind must see it.
         */
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
