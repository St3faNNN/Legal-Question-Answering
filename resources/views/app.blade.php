<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'GraphRAG') }}</title>
        {{-- Apply the persisted theme before Vue mounts to avoid a flash of the wrong theme. --}}
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('theme');
                    var dark = t === 'dark' || (t !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>