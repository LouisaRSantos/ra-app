<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Remittance') · RA</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-page="@yield('page')">
@php($u = auth()->user())
<div class="shell">
    <aside class="side" id="side">
        <div class="side-brand">
            RA Remittance
            <button type="button" class="menu-btn" id="menu-btn" aria-label="Menu">Menu</button>
        </div>
        <nav class="side-nav">
            @canany(['remittances.view-own', 'remittances.view-all'])
                <div class="side-label">Collections</div>
                <a class="side-link {{ request()->routeIs('transactions.*') ? 'is-active' : '' }}" href="{{ route('transactions.index') }}">Transactions</a>
            @endcanany
            @can('files.view-public')
                <div class="side-label">Files</div>
                <a class="side-link {{ request()->routeIs('files.*') ? 'is-active' : '' }}" href="{{ route('files.index') }}">Public files</a>
            @endcan
            @canany(['users.manage', 'roles.manage'])
                <div class="side-label">Administration</div>
                @can('users.manage')
                    <a class="side-link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}" href="{{ route('admin.users.index') }}">Users</a>
                @endcan
                @can('roles.manage')
                    <a class="side-link {{ request()->routeIs('admin.roles.*') ? 'is-active' : '' }}" href="{{ route('admin.roles.index') }}">Roles</a>
                @endcan
            @endcanany
        </nav>
        <div class="side-user">
            <strong>{{ $u->name }}</strong>
            <span>{{ $u->getRoleNames()->first() ?? 'No role' }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </div>
    </aside>
    <main class="main">
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
