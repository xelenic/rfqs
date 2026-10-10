{{--
    Where a new account's welcome email leads: pick a password, and in. See
    Auth\SetPasswordController.

    Expects: $token, $email (from the link).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set your password · {{ \App\Models\Setting::companyName() }} Admin</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
    @include('layouts._preloader')
    <div class="auth-shell">
        <div class="auth-card">
            <div class="text-center mb-4">
                <span class="brand-mark d-inline-flex mb-2" style="width:44px;height:44px;font-size:1rem;">RFQ</span>
                <h1 class="h4 fw-bold mb-1">Set your password</h1>
                <p class="text-muted-soft mb-0">Welcome to {{ \App\Models\Setting::companyName() }} — choose a password to sign in with.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger py-2">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('password.set.store') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input type="email" class="form-control" id="email" name="email" value="{{ old('email', $email) }}" required readonly autocomplete="username">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">New password</label>
                    <input type="password" class="form-control" id="password" name="password" required minlength="8" autofocus autocomplete="new-password">
                    <div class="form-text">At least 8 characters.</div>
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required minlength="8" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-100">Set password and sign in</button>
            </form>
        </div>
    </div>
</body>
</html>
