<nav x-data="{ open: false }" aria-label="Primary" class="bg-ink-900/95 backdrop-blur border-b border-gray-800 relative z-40">
    <div class="max-w-page mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 group">
                        <span class="w-7 h-7 rounded-lg bg-brand-600 flex items-center justify-center shadow-sm group-hover:bg-brand-500 transition-colors" aria-hidden="true">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10l-2 1m0 0l-2-1m2 1v2.5M20 7l-2 1m2-1l-2-1m2 1v2.5M14 4l-2-1-2 1M4 7l2-1M4 7l2 1M4 7v2.5M12 21l-2-1m2 1l2-1m-2 1v-2.5M6 18l-2-1v-2.5M18 18l2-1v-2.5"/></svg>
                        </span>
                        <span class="logo-text text-lg">Exospace</span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                        <svg class="w-4 h-4 mr-1.5 opacity-70" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        {{ __('Dashboard') }}
                    </x-nav-link>

                    <x-nav-link :href="route('admin.galleries.index')" :active="request()->routeIs('admin.galleries.*')">
                        <svg class="w-4 h-4 mr-1.5 opacity-70" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        {{ __('Galleries') }}
                    </x-nav-link>

                    <x-nav-link :href="route('admin.artists.index')" :active="request()->routeIs('admin.artists.*')">
                        <svg class="w-4 h-4 mr-1.5 opacity-70" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        {{ __('Artists') }}
                    </x-nav-link>

                    <x-nav-link :href="route('admin.teams.index')" :active="request()->routeIs('admin.teams.*')">
                        <svg class="w-4 h-4 mr-1.5 opacity-70" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        {{ __('Teams') }}
                    </x-nav-link>

                    {{-- ITERATION 4: Master Control was reachable only by typing
                         the URL — no nav link existed for super-admins. --}}
                    @if(auth()->check() && auth()->user()->is_super_admin)
                        <x-nav-link :href="route('super.index')" :active="request()->routeIs('super.*')">
                            <svg class="w-4 h-4 mr-1.5 opacity-70" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
                            {{ __('Master Control') }}
                        </x-nav-link>
                    @endif

                    @if(auth()->check() && auth()->user()->plan === 'free')
                        @php
                            $navGalleryCount = auth()->user()->galleries()->count();
                            $navAtLimit = !auth()->user()->canCreateGallery();
                            $navNearLimit = auth()->user()->max_galleries > 0 && ($navGalleryCount / auth()->user()->max_galleries) >= 0.8;
                        @endphp
                        <div class="hidden lg:flex lg:items-center lg:ms-4">
                            <a href="/pricing"
                               class="inline-flex items-center px-3 h-8 {{ $navAtLimit ? 'bg-amber-500/10 border-amber-500/40 text-amber-400 hover:bg-amber-500/20' : 'bg-brand-500/10 border-brand-500/30 text-brand-400 hover:bg-brand-500/20 hover:border-brand-500/50' }} border rounded-lg transition-all duration-200 text-xs font-semibold group">
                                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                                @if($navAtLimit)
                                    Gallery limit reached
                                @elseif($navNearLimit)
                                    Almost at limit
                                @else
                                    Upgrade to Pro
                                @endif
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Right side: Active Team Switcher + Notifications + User Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 gap-3">

                {{-- M-12: Notification bell --}}
                @auth
                @php
                    $navNotifications = \App\Services\NotificationService::recent(auth()->user(), 5);
                    $navUnreadCount = \App\Services\NotificationService::unreadCount(auth()->user());
                @endphp
                <div x-data="{ notifOpen: false }" class="relative" @keydown.escape.window="notifOpen = false">
                    <button @click="notifOpen = !notifOpen"
                            class="relative inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-700/80 hover:border-gray-600 bg-white/[0.03] hover:bg-white/[0.07] text-gray-400 hover:text-white transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/80"
                            aria-label="Notifications"
                            id="notif-dropdown-trigger"
                            aria-haspopup="true"
                            :aria-expanded="notifOpen.toString()"
                            aria-controls="notif-dropdown-panel">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        @if($navUnreadCount > 0)
                            <span class="absolute -top-1 -right-1 flex items-center justify-center min-w-5 h-5 px-1 bg-red-500 text-white text-xs font-bold rounded-full">
                                {{ $navUnreadCount > 9 ? '9+' : $navUnreadCount }}
                            </span>
                        @endif
                    </button>

                    <div x-show="notifOpen" @click.outside="notifOpen = false" x-cloak
                         id="notif-dropdown-panel"
                         role="menu"
                         aria-labelledby="notif-dropdown-trigger"
                         style="display: none;"
                         class="absolute end-0 right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] menu-panel z-50">

                        <div class="px-4 py-3 border-b border-gray-700 flex items-center justify-between">
                            <p class="text-gray-300 text-sm font-semibold">Notifications</p>
                            @if($navUnreadCount > 0)
                                <form method="POST" action="{{ route('notifications.mark-all-read') }}">
                                    @csrf
                                    {{-- ITERATION-3: padded to a 32px touch target (was a bare text-xs link) --}}
                                    <button type="submit" class="inline-flex items-center h-8 px-2 -me-1 rounded-md text-xs font-medium text-brand-400 hover:text-brand-300 hover:bg-brand-500/10 transition">Mark all read</button>
                                </form>
                            @endif
                        </div>

                        <div class="max-h-96 overflow-y-auto">
                            @if($navNotifications->isEmpty())
                                <div class="px-4 py-8 text-center">
                                    <p class="text-sm text-gray-400">No notifications yet</p>
                                    <p class="hint-text mt-1">Activity about your galleries and teams will appear here.</p>
                                </div>
                            @else
                                @foreach($navNotifications as $notif)
                                    <div class="px-4 py-3 border-b border-gray-700/50 {{ $notif->isUnread() ? 'bg-brand-500/[0.07]' : '' }}">
                                        <form method="POST" action="{{ route('notifications.read', $notif) }}">
                                            @csrf
                                            <button type="submit" class="w-full text-left">
                                                <div class="flex items-start gap-2">
                                                    @if($notif->isUnread())
                                                        <span class="w-2 h-2 rounded-full bg-brand-400 mt-1.5 flex-shrink-0" aria-hidden="true"></span>
                                                    @else
                                                        <span class="w-2 h-2 rounded-full bg-gray-600 mt-1.5 flex-shrink-0" aria-hidden="true"></span>
                                                    @endif
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-sm font-medium text-gray-200">{{ $notif->title }}</p>
                                                        @if($notif->body)
                                                            <p class="text-xs text-gray-400 mt-0.5 line-clamp-2">{{ $notif->body }}</p>
                                                        @endif
                                                        <p class="text-xs text-gray-500 mt-1">{{ $notif->created_at->diffForHumans() }}</p>
                                                    </div>
                                                </div>
                                            </button>
                                        </form>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
                @endauth

                {{-- Active Team Badge / Switcher --}}
                @auth
                @php
                    $currentTeam = auth()->user()->currentTeam();
                    $allTeams    = auth()->user()->ownedTeams->merge(auth()->user()->teams)->unique('id');
                @endphp
                @if($allTeams->isNotEmpty())
                {{--
                    FIX: Added style="display:none" so the dropdown panel is hidden before Alpine.js
                    loads — without it the panel flashes open on every page load because x-cloak
                    alone requires the [x-cloak]{display:none} CSS rule to already be parsed, which
                    can race against the JS bundle.  The style attr is the guaranteed no-flash guard.
                    Also added @keydown.escape.window so the dropdown closes when pressing Escape.
                --}}
                <div x-data="{ teamOpen: false }" class="relative" @keydown.escape.window="teamOpen = false">
                    <button @click="teamOpen = !teamOpen"
                            class="inline-flex items-center gap-2 px-3 h-9 bg-white/[0.04] hover:bg-white/[0.08] border border-gray-700/80 rounded-lg text-sm text-gray-300 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/80"
                            id="team-dropdown-trigger"
                            aria-label="Switch team context"
                            aria-haspopup="true"
                            :aria-expanded="teamOpen.toString()"
                            aria-controls="team-dropdown-panel">
                        @if($currentTeam)
                            <span class="w-2 h-2 rounded-full bg-emerald-400 flex-shrink-0" aria-hidden="true"></span>
                            <span class="max-w-[120px] truncate font-medium">{{ $currentTeam->name }}</span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-gray-500 flex-shrink-0"></span>
                            <span class="text-gray-400">Personal</span>
                        @endif
                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="teamOpen" @click.outside="teamOpen = false" x-cloak
                         id="team-dropdown-panel"
                         role="menu"
                         aria-labelledby="team-dropdown-trigger"
                         style="display: none;"
                         class="absolute right-0 mt-2 w-52 menu-panel z-50">

                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="menu-header">Switch context</p>
                        </div>

                        {{-- Personal --}}
                        <form action="{{ route('admin.teams.switch-personal') }}" method="POST">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2.5 hover:bg-white/[0.05] transition text-sm text-left {{ ! $currentTeam ? 'text-white' : 'text-gray-400' }}">
                                <span class="w-6 h-6 rounded-lg bg-gray-700 border border-gray-600 flex items-center justify-center text-xs font-bold">
                                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                                </span>
                                <span>Personal</span>
                                @if(! $currentTeam) <svg class="w-3.5 h-3.5 text-emerald-400 ml-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg> @endif
                            </button>
                        </form>

                        {{-- Teams --}}
                        @foreach($allTeams as $t)
                        @php $tRole = auth()->user()->teamRole($t); @endphp
                        <form action="{{ route('admin.teams.switch', $t) }}" method="POST">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-white/[0.05] transition text-left {{ $currentTeam?->id === $t->id ? 'bg-white/[0.05]' : '' }}">
                                <span class="w-6 h-6 rounded-lg bg-brand-700 flex items-center justify-center text-xs font-semibold text-white flex-shrink-0">
                                    {{ strtoupper(substr($t->name, 0, 1)) }}
                                </span>
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm truncate {{ $currentTeam?->id === $t->id ? 'text-white' : 'text-gray-300' }}">{{ $t->name }}</span>
                                    <span class="block text-xs text-gray-500 capitalize">{{ $tRole }}</span>
                                </span>
                                @if($currentTeam?->id === $t->id)
                                    <svg class="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                @endif
                            </button>
                        </form>
                        @endforeach

                        <div class="border-t border-gray-700 px-3 py-2">
                            <a href="{{ route('admin.teams.create') }}" class="flex items-center gap-2 text-xs font-medium text-brand-400 hover:text-brand-300 transition py-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                New Team
                            </a>
                        </div>
                    </div>
                </div>
                @endif
                @endauth

                <!-- User Dropdown -->
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center gap-2 px-2.5 h-9 border border-gray-700/80 hover:border-gray-600 text-sm font-medium rounded-lg text-gray-300 bg-white/[0.03] hover:bg-white/[0.07] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/80 transition-all duration-150">
                            <span class="w-6 h-6 rounded-md bg-brand-600 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                            <span class="max-w-[120px] truncate">{{ Auth::user()->name }}</span>
                            <svg class="fill-current h-3.5 w-3.5 text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="px-4 py-2.5 border-b border-gray-700/60">
                            <p class="text-xs font-semibold text-gray-100 truncate">{{ Auth::user()->name }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ Auth::user()->email }}</p>
                        </div>
                        <x-dropdown-link :href="route('profile.edit')">
                            <svg class="w-4 h-4 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            {{ __('Profile') }}
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('billing.index')">
                            <svg class="w-4 h-4 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            {{ __('Billing') }}
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('admin.teams.index')">
                            <svg class="w-4 h-4 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            {{ __('My Teams') }}
                        </x-dropdown-link>
                        <div class="border-t border-gray-700/60 mt-1 pt-1">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    data-logout-link>
                                <svg class="w-4 h-4 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                {{ __('Sign out') }}
                            </x-dropdown-link>
                        </form>
                        </div>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open"
                        class="inline-flex items-center justify-center p-2 rounded-lg text-gray-400 hover:text-white hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/80 transition duration-150 ease-in-out"
                        id="mobile-nav-toggle"
                        :aria-label="open ? 'Close menu' : 'Open menu'"
                        :aria-expanded="open.toString()"
                        aria-controls="mobile-nav">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden" id="mobile-nav" role="menu" aria-labelledby="mobile-nav-toggle">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('admin.galleries.index')" :active="request()->routeIs('admin.galleries.*')">
                {{ __('Galleries') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('admin.artists.index')" :active="request()->routeIs('admin.artists.*')">
                {{ __('Artists') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('admin.teams.index')" :active="request()->routeIs('admin.teams.*')">
                {{ __('Teams') }}
            </x-responsive-nav-link>

            {{-- ITERATION-1 FIX: Master Control was missing from the mobile menu —
                 super-admins could not reach /master-control from a phone. --}}
            @if(auth()->check() && auth()->user()->is_super_admin)
                <x-responsive-nav-link :href="route('super.index')" :active="request()->routeIs('super.*')">
                    {{ __('Master Control') }}
                </x-responsive-nav-link>
            @endif

            {{-- ITERATION-2 FIX: Billing + plan upgrade were desktop-only
                 (user dropdown / nav chip). On a phone there was no way to
                 reach billing or upgrade at all. --}}
            <x-responsive-nav-link :href="route('billing.index')">
                {{ __('Billing') }}
            </x-responsive-nav-link>
            @if(auth()->check() && auth()->user()->plan === 'free')
                <x-responsive-nav-link :href="url('/pricing')">
                    {{ __('Upgrade to Pro') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-700">
            <div class="px-4">
                <div class="font-medium text-base text-gray-200 break-words">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-400 truncate">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">{{ __('Profile') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.teams.index')">{{ __('My Teams') }}</x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" data-logout-link>
                        {{ __('Sign out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>