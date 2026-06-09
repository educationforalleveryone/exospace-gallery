<nav x-data="{ open: false }" class="bg-gray-800 border-b border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('admin.dashboard') }}" class="text-2xl font-bold bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent">
                        Exospace
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>

                    <x-nav-link :href="route('admin.galleries.index')" :active="request()->routeIs('admin.galleries.*')">
                        {{ __('Galleries') }}
                    </x-nav-link>

                    <x-nav-link :href="route('admin.teams.index')" :active="request()->routeIs('admin.teams.*')">
                        {{ __('Teams') }}
                    </x-nav-link>

                    @if(auth()->check() && auth()->user()->plan === 'free')
                        @php
                            $navGalleryCount = auth()->user()->galleries()->count();
                            $navAtLimit = !auth()->user()->canCreateGallery();
                            $navNearLimit = auth()->user()->max_galleries > 0 && ($navGalleryCount / auth()->user()->max_galleries) >= 0.8;
                        @endphp
                        <div class="hidden sm:flex sm:items-center sm:ms-4">
                            <a href="/pricing"
                               class="inline-flex items-center px-3 py-1.5 {{ $navAtLimit ? 'bg-orange-600/20 border-orange-500/40 text-orange-300 hover:bg-orange-600/30' : 'bg-purple-600/20 border-purple-500/30 text-purple-400 hover:bg-purple-600/30 hover:border-purple-500/50' }} border rounded-lg transition-all duration-200 text-sm font-semibold group">
                                <svg class="w-3.5 h-3.5 mr-1.5 group-hover:scale-110 transition-transform flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
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

            <!-- Right side: Active Team Switcher + User Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 gap-3">

                {{-- Active Team Badge / Switcher --}}
                @auth
                @php
                    $currentTeam = auth()->user()->currentTeam();
                    $allTeams    = auth()->user()->ownedTeams->merge(auth()->user()->teams)->unique('id');
                @endphp
                @if($allTeams->isNotEmpty())
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open"
                            class="inline-flex items-center gap-2 px-3 py-1.5 bg-gray-700/60 hover:bg-gray-700 border border-gray-600 rounded-lg text-sm text-gray-300 transition">
                        @if($currentTeam)
                            <span class="w-2 h-2 rounded-full bg-green-400 flex-shrink-0"></span>
                            <span class="max-w-[120px] truncate font-medium">{{ $currentTeam->name }}</span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-gray-500 flex-shrink-0"></span>
                            <span class="text-gray-400">Personal</span>
                        @endif
                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute right-0 mt-2 w-52 bg-gray-800 border border-gray-700 rounded-xl shadow-2xl z-50 overflow-hidden">

                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-gray-500 text-xs uppercase tracking-wider font-medium">Switch context</p>
                        </div>

                        {{-- Personal --}}
                        <form action="{{ route('admin.teams.switch-personal') }}" method="POST">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2.5 hover:bg-gray-700 transition text-sm text-left {{ ! $currentTeam ? 'text-white' : 'text-gray-400' }}">
                                <span class="w-6 h-6 rounded-lg bg-gray-700 border border-gray-600 flex items-center justify-center text-xs font-bold">
                                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                                </span>
                                <span>Personal</span>
                                @if(! $currentTeam) <svg class="w-3.5 h-3.5 text-green-400 ml-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg> @endif
                            </button>
                        </form>

                        {{-- Teams --}}
                        @foreach($allTeams as $t)
                        <form action="{{ route('admin.teams.switch', $t) }}" method="POST">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2.5 hover:bg-gray-700 transition text-sm text-left {{ $currentTeam?->id === $t->id ? 'text-white' : 'text-gray-400' }}">
                                <span class="w-6 h-6 rounded-lg bg-gradient-to-br from-purple-700 to-indigo-700 flex items-center justify-center text-xs font-bold text-white flex-shrink-0">
                                    {{ strtoupper(substr($t->name, 0, 1)) }}
                                </span>
                                <span class="truncate">{{ $t->name }}</span>
                                @if($currentTeam?->id === $t->id) <svg class="w-3.5 h-3.5 text-green-400 ml-auto flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg> @endif
                            </button>
                        </form>
                        @endforeach

                        <div class="border-t border-gray-700 px-3 py-2">
                            <a href="{{ route('admin.teams.create') }}" class="flex items-center gap-2 text-xs text-purple-400 hover:text-purple-300 transition py-1">
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
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-300 bg-gray-800 hover:text-white hover:bg-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('admin.teams.index')">
                            {{ __('My Teams') }}
                        </x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-700 focus:outline-none focus:bg-gray-700 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('admin.galleries.index')" :active="request()->routeIs('admin.galleries.*')">
                {{ __('Galleries') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('admin.teams.index')" :active="request()->routeIs('admin.teams.*')">
                {{ __('Teams') }}
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-700">
            <div class="px-4">
                <div class="font-medium text-base text-gray-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-400">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">{{ __('Profile') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.teams.index')">{{ __('My Teams') }}</x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>