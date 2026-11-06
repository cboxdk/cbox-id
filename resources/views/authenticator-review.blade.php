<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Cbox ID · App Review</title>
    @vite(['resources/css/app.css'])
</head>
<body style="background:var(--bg);color:var(--text);font-family:var(--font-sans);line-height:1.6">
<main style="max-width:44rem;margin:0 auto;padding:3rem 1.5rem 5rem">
    <p style="color:var(--accent);font-weight:600">Cbox ID · App Review</p>
    <h1 style="font-size:2rem;line-height:1.2;font-weight:650;margin:1rem 0">Try an approval on your iPhone</h1>
    <p style="color:var(--muted)">Signed in as {{ $email }}. This account contains no customer data. You can repeat this test whenever you need.</p>

    @error('review')
        <p role="alert" style="padding:1rem;border:1px solid var(--border);border-radius:.75rem;margin-top:1.5rem">{{ $message }}</p>
    @enderror

    <section style="margin-top:2rem;padding:1.5rem;background:var(--surface);border:1px solid var(--border);border-radius:1rem">
        <h2 style="font-size:1.25rem;font-weight:600">1. Connect your iPhone</h2>
        <p style="margin:.75rem 0 1.25rem">Open Trusted devices below. In the Cbox ID iPhone app, open the Cbox ID tab, tap + and scan the QR code. If you are using the same iPhone, tap the connection link beside the QR code instead. Sign in with the review credentials provided in App Store Connect.</p>
        <a href="{{ route('devices.mine') }}" target="_blank" rel="noopener" style="display:inline-block;border:1px solid var(--border);border-radius:.65rem;padding:.65rem 1rem;text-decoration:none">Open Trusted devices ↗</a>
        <p style="margin-top:1rem;color:var(--muted)">{{ $connected ? 'A phone is connected. You can start the test below.' : 'After connecting, return to this page and refresh it.' }}</p>
    </section>

    <section style="margin-top:1.25rem;padding:1.5rem;background:var(--surface);border:1px solid var(--border);border-radius:1rem">
        <h2 style="font-size:1.25rem;font-weight:600">2. Send a test approval</h2>
        <p style="margin:.75rem 0 1.25rem">The request goes only to this review account. Compare its REVIEW number below with the number in the app. Open the notification or the Cbox ID tab, then approve or deny it. Approval uses Face ID or your iPhone passcode.</p>
        <form action="{{ route('authenticator.review.send') }}" method="post">
            @csrf
            <button type="submit" style="background:var(--accent);color:var(--accent-foreground);border:0;border-radius:.65rem;padding:.75rem 1rem;font-weight:600;cursor:pointer">Send test approval</button>
        </form>
        <p style="margin-top:1rem;color:var(--muted)">Notifications are optional. Requests last five minutes. Approving this test does not sign you in to another service or grant access to data.</p>
    </section>

    <section style="margin-top:1.25rem;padding:1.5rem;border:1px solid var(--border);border-radius:1rem">
        <h2 style="font-size:1.25rem;font-weight:600">3. Check the result</h2>
        @if ($latest)
            @php
                $status = $latest->status->value;
                if ($status === 'pending' && $latest->expires_at->isPast()) {
                    $status = 'expired';
                }
            @endphp
            <p style="font-size:1.65rem;font-weight:600;margin:.75rem 0">{{ $latest->binding_message }}</p>
            <p>Status: <strong>{{ ucfirst($status) }}</strong></p>
            <p style="color:var(--muted)">Refresh after answering on your iPhone. If the request expired, send a new test.</p>
        @else
            <p style="margin:.75rem 0">No test has been sent yet.</p>
        @endif
        <a href="{{ route('authenticator.review.show') }}" style="display:inline-block;margin-top:1rem;color:var(--accent)">Refresh result</a>
    </section>
    <p style="margin-top:2rem;color:var(--muted)">TOTP codes and encrypted backup can also be tested without a Cbox account. Step-by-step instructions are included in App Store Connect.</p>
</main>
</body>
</html>
