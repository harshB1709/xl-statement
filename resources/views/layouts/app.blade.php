<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'XL Statement' }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('icon.png') }}">
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('xl-theme');
                    var dark = stored === 'dark' || (stored !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen font-sans text-ink antialiased">
        <div class="mx-auto max-w-6xl px-4 py-8">
            <header class="mb-8 flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <img
                        src="{{ asset('favicon.svg') }}"
                        alt=""
                        width="48"
                        height="48"
                        class="mt-1 size-12 shrink-0 rounded-[14px] shadow-sm ring-1 ring-line"
                    >
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-muted">Private · offline</p>
                        <h1 class="mt-1 text-4xl font-semibold tracking-tight text-ink">XL Statement</h1>
                        <p class="mt-2 max-w-md text-sm text-muted">PDF bank statements → clean Excel workbooks, on this machine.</p>
                    </div>
                </div>

                <button
                    type="button"
                    id="theme-toggle"
                    class="inline-flex items-center gap-2 rounded-xl bg-surface px-3 py-2 text-sm font-medium text-ink ring-1 ring-line transition hover:bg-surface-2"
                    aria-label="Toggle color theme"
                    title="Toggle light / dark"
                >
                    <span class="dark:hidden" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                        </svg>
                    </span>
                    <span class="hidden dark:inline" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-4">
                            <circle cx="12" cy="12" r="4" />
                            <path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                        </svg>
                    </span>
                    <span class="hidden sm:inline dark:hidden">Dark</span>
                    <span class="hidden dark:sm:inline">Light</span>
                </button>
            </header>

            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
