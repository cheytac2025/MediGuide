<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'MediGuide') | MediGuide</title>

        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">

        <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
        <link rel="stylesheet" href="{{ asset('css/mediguide.css') }}">
        @stack('head')
    </head>
    <body class="mg-body">
        <div class="mg-public">
            <x-public.header :active="$active ?? ''" />

            <main class="mg-public-main">
                @yield('content')
            </main>

            <footer class="mg-footer">
                <strong>Queen Mary Help of Christians Hospital</strong>
                Compassion. Care. For a Healthier Tomorrow.
            </footer>
        </div>

        <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
        @stack('scripts')
    </body>
</html>
