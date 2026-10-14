@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in</h1>
    <p>Accounts are created by an administrator.</p>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">Sign in</button>
    </form>
@endsection
