<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Exospace') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

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

            <div class="w-full mt-6 flex flex-col lg:flex-row lg:items-start lg:justify-center lg:gap-10 lg:max-w-4xl px-4 sm:px-0">

                {{-- Feature sidebar (lg+ only) --}}
                <div class="hidden lg:flex flex-col justify-center gap-5 lg:w-72 flex-shrink-0 pt-6 pb-2">
                    <p class="text-xs text-gray-500 uppercase tracking-wider font-semibold">Why Exospace</p>
                    <div class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-lg bg-purple-600/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-200">Immersive 3D galleries</p>
                                <p class="text-xs text-gray-500 mt-0.5">Museum-quality walkthroughs your audience can explore in a browser</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-lg bg-indigo-600/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-200">Ready in minutes</p>
                                <p class="text-xs text-gray-500 mt-0.5">Upload images, pick a room layout, get a shareable link instantly</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-lg bg-blue-600/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-200">Team collaboration</p>
                                <p class="text-xs text-gray-500 mt-0.5">Invite collaborators and manage galleries across workspaces</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-lg bg-green-600/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-200">Real-time analytics</p>
                                <p class="text-xs text-gray-500 mt-0.5">See who views your exhibitions and when</p>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-gray-600 mt-2">Free to start — no credit card needed.</p>
                </div>

                {{-- Auth card --}}
                <div class="w-full sm:max-w-md lg:w-96 px-6 py-8 bg-gray-800/90 border border-gray-700/80 shadow-2xl overflow-hidden sm:rounded-xl flex-shrink-0 relative">
    <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-purple-500 to-transparent"></div>
                    {{ $slot }}
                </div>
            </div>
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