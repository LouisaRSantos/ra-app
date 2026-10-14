<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · RA</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="guest">
    <div class="guest-card">
        @yield('content')
    </div>
</div>
</body>
</html>
