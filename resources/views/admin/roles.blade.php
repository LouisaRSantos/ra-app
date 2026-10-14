@extends('layouts.app')

@section('title', 'Roles')
@section('page', 'admin-roles')

@section('content')
    <div class="page-head">
        <div>
            <h1>Roles</h1>
            <p>Choose what each role is allowed to do. Admin always has every permission.</p>
        </div>
    </div>

    <div id="roles" style="display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
        <div class="panel"><div class="empty">Loading roles…</div></div>
    </div>

    <script>
        window.RA = {
            dataUrl: @json(route('admin.roles.data')),
            updateUrl: @json(route('admin.roles.update', ['role' => '__ID__'])),
        };
    </script>
@endsection
