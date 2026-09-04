<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title inertia>{{ config('app.name') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @else
        {{-- No built assets or hot dev server detected; include minimal stylesheet to prevent unstyled flash. --}}
        <link rel="stylesheet" href="{{ asset('resources/css/app.css') }}">
    @endif
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
