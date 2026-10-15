<!DOCTYPE html>
<html lang="es-AR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#1F2A37">
        @if (! empty($noindex))
            <meta name="robots" content="noindex, nofollow">
            <meta name="referrer" content="no-referrer">
        @endif
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <x-inertia::head>
            <title>{{ config('app.name', 'FerreteroSoft') }}</title>
        </x-inertia::head>
    </head>
    <body class="antialiased">
        <x-inertia::app />
    </body>
</html>
