@extends('layouts.guest')

@section('title', 'Change password')

@section('content')
    <h1>Set your password</h1>
    <p>
        @if (auth()->user()->must_change_password)
            Your administrator gave you a temporary password. Choose your own to continue.
        @else
            Choose a new password.
        @endif
    </p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        @method('PUT')
        <div class="field">
            <label for="current_password">Current password</label>
            <input class="input" id="current_password" type="password" name="current_password" required autocomplete="current-password">
            @error('current_password')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password">New password</label>
            <input class="input" id="password" type="password" name="password" required autocomplete="new-password">
            <div class="hint">At least 10 characters, with letters and numbers.</div>
            @error('password')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button class="btn btn-primary" type="submit">Save password</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" style="margin-top:16px">
        @csrf
        <button class="link-btn" type="submit">Sign out</button>
    </form>
@endsection
