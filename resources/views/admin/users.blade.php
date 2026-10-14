@extends('layouts.app')

@section('title', 'Users')
@section('page', 'admin-users')

@section('content')
    <div class="page-head">
        <div>
            <h1>Users</h1>
            <p>Create accounts, assign roles, disable access, reset passwords.</p>
        </div>
        <button class="btn btn-primary" id="btn-new" type="button">New user</button>
    </div>

    <div class="panel">
        <div class="toolbar">
            <input class="input" id="f-q" type="search" placeholder="Search name or email" aria-label="Search">
            <select class="select" id="f-role" aria-label="Role">
                <option value="">All roles</option>
                @foreach ($roles as $role)
                    <option>{{ $role }}</option>
                @endforeach
            </select>
            <select class="select" id="f-status" aria-label="Status">
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
            </select>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>
                </thead>
                <tbody id="rows"></tbody>
            </table>
        </div>
        <div class="more" id="more" hidden><button class="btn btn-sm" id="btn-more" type="button">Load more</button></div>
    </div>

    <dialog class="modal" id="dlg-user">
        <form id="form-user">
            <div class="modal-head" id="dlg-user-title">New user</div>
            <div class="modal-body">
                <div class="field">
                    <label for="u-name">Name</label>
                    <input class="input" id="u-name" name="name" maxlength="255" required>
                    <div class="err" data-err="name"></div>
                </div>
                <div class="field">
                    <label for="u-email">Email</label>
                    <input class="input" id="u-email" name="email" type="email" maxlength="255" required>
                    <div class="err" data-err="email"></div>
                </div>
                <div class="field">
                    <label for="u-role">Role</label>
                    <select class="select" id="u-role" name="role" style="width:100%" required>
                        @foreach ($roles as $role)
                            <option>{{ $role }}</option>
                        @endforeach
                    </select>
                    <div class="err" data-err="role"></div>
                </div>
                <div class="field" id="g-password">
                    <label for="u-password">Temporary password</label>
                    <input class="input" id="u-password" name="password" type="text" autocomplete="off">
                    <div class="hint">Share it with the user. They must replace it at first sign in. Min 10 characters, letters and numbers.</div>
                    <div class="err" data-err="password"></div>
                </div>
                <div class="field" id="g-active" hidden>
                    <label><input type="checkbox" id="u-active" checked> Account active</label>
                </div>
                <div class="err" data-err="_"></div>
            </div>
            <div class="modal-foot">
                <button class="btn" type="button" data-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="dlg-pw">
        <form id="form-pw">
            <div class="modal-head">Reset password</div>
            <div class="modal-body">
                <p id="pw-who" style="margin-top:0"></p>
                <div class="field">
                    <label for="p-password">New temporary password</label>
                    <input class="input" id="p-password" name="password" type="text" autocomplete="off" required>
                    <div class="hint">The user is signed out everywhere and must set their own password.</div>
                    <div class="err" data-err="password"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" type="button" data-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Reset</button>
            </div>
        </form>
    </dialog>

    <script>
        window.RA = {
            dataUrl: @json(route('admin.users.data')),
            storeUrl: @json(route('admin.users.store')),
            updateUrl: @json(route('admin.users.update', ['user' => '__ID__'])),
            passwordUrl: @json(route('admin.users.password', ['user' => '__ID__'])),
        };
    </script>
@endsection
