<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Remittance Extractor')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="max-w-4xl mx-auto px-4 py-8">
        <header class="mb-6">
            <a href="{{ route('extractions.index') }}" class="text-lg font-semibold">Remittance Invoice Extractor</a>
        </header>
        <main>
            @yield('content')
        </main>
    </div>
    @stack('scripts')
</body>
</html>
