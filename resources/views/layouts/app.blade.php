<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $appName = config('app.name', 'MotoLotz');
        $pageTitle = filled($title) ? $title.' | '.$appName : $appName;
        $pageDescription = $description ?? 'バイク駐輪場の料金、営業時間、場所を検索できるMotoLotz。';
        $canonicalUrl = $canonical ?? url()->current();
        $socialImage = $image ?? asset('images/motolotz-logo.png');
    @endphp

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <link rel="icon" href="{{ asset('images/motolotz-favicon.png') }}" type="image/png">
    @if ($robots)
        <meta name="robots" content="{{ $robots }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $appName }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $socialImage }}">
    <meta name="twitter:card" content="summary_large_image">
    @stack('meta')

    <!-- Fonts -->
    <link href="https://fonts.bunny.net" rel="preconnect">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @stack('link')

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @php
        $advertisingTestMode = config('advertising.test_mode');
        $adsenseClient = config('advertising.adsense.client');
        $hasConfiguredAdSlot = (request()->routeIs('home') && filled(config('advertising.adsense.slots.home_footer')))
            || (request()->routeIs('parking_spot.show') && filled(config('advertising.adsense.slots.parking_spot_footer')))
            || (request()->routeIs('search') && filled(config('advertising.adsense.slots.search_footer')));
        $shouldLoadAdSenseScript = ! $advertisingTestMode && filled($adsenseClient)
            && (config('advertising.verification_enabled') || (config('advertising.enabled') && $hasConfiguredAdSlot));
    @endphp
    @if ($shouldLoadAdSenseScript)
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ urlencode($adsenseClient) }}"
            crossorigin="anonymous"></script>
    @endif
    @stack('script')
</head>

<body class="font-sans antialiased">
    <div
        @class([
            'bp-page flex min-h-screen flex-col',
            'lg:h-dvh lg:min-h-0 lg:overflow-hidden' => request()->routeIs('search'),
        ])>
        @include('layouts.navigation')

        <!-- Page Heading -->
        @isset($header)
            <header class="border-b border-slate-200 bg-white/90 shadow-sm shadow-slate-200/60">
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endisset

        <!-- Page Content -->
        <main @class(['flex-1', 'lg:min-h-0' => request()->routeIs('search')])>
            {{ $slot }}
        </main>

        <footer class="shrink-0 border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-5 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <span>サービス維持のため、ページ内の一部に広告を掲載することがあります。</span>
                <nav class="flex flex-wrap gap-x-4 gap-y-2 font-semibold" aria-label="フッターナビゲーション">
                    <a class="text-slate-600 hover:text-emerald-700" href="{{ route('terms') }}">利用規約</a>
                    <a class="text-slate-600 hover:text-emerald-700" href="{{ route('privacy') }}">プライバシーポリシー</a>
                    <a class="text-slate-600 hover:text-emerald-700" href="{{ route('contact') }}">お問い合わせ</a>
                </nav>
            </div>
        </footer>
    </div>
</body>

</html>
