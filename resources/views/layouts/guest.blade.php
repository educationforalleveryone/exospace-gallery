<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-100 antialiased bg-gray-900">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900">
            <div>
                <a href="/" class="text-3xl font-bold bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent">
                    Exospace
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-8 bg-gray-800 border border-gray-700 shadow-2xl overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
            
            <div class="mt-6 text-center">
                <a href="/" class="text-sm text-gray-400 hover:text-gray-300 transition">
                    ← Back to Home
                </a>
            </div>
        </div>

        <!-- Cookie Banner -->
        @include('layouts.partials.cookie-banner')

    </body>
</html>