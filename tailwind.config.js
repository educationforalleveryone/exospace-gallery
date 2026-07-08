import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class', // Enable dark mode via class

    // C-10 FIX (Iter-005): Added './resources/js/**/*.{js,ts,vue,blade.php}'
    // to the content array. Previously, only Blade files were scanned — any
    // Tailwind class that exists only inside resources/js/** (Alpine
    // components, gallery JS DOM injection, the toast helper in layouts/app.blade.php)
    // would be purged from production CSS.
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,ts,vue,blade.php}',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
