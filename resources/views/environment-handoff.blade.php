{{--
    The account → environment handoff, carried in a form BODY rather than a URL.

    It used to be a redirect to `https://{env}/admin/handoff?token=…`. A bearer credential
    in a query string is written everywhere a URL goes: the browser history, the access
    log of every proxy and load balancer in front of the environment host, any error
    tracker that records request URLs, and the `Referer` of whatever the landing page
    loads next. The token is single-use and short-lived, which limits the damage — but a
    token that sits in a log until it is redeemed is a token whoever reads the log can
    race the browser to.

    So this page is a self-submitting POST, the same shape as the SAML HTTP-POST binding:
    a form aimed at the environment host, submitted by one nonce'd script, with a
    `<noscript>` button for a browser that runs none. Plain Blade with no layout, because
    it exists for the instant before it submits itself — no bundle, no Inertia, nothing
    that could load a third-party resource and leak a Referer while the token is on the
    page. Its policy travels with it ({@see \App\Http\Controllers\EnvironmentHandoffController}).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Opening {{ $environmentName }}…</title>
</head>
<body>
    <form method="post" action="{{ $action }}">
        @foreach ($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <noscript>
            <p>Continue to open {{ $environmentName }}.</p>
            <button type="submit">Continue</button>
        </noscript>
    </form>
    <script nonce="{{ $nonce }}">document.forms[0].submit();</script>
</body>
</html>
