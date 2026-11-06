<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Cbox ID — privacy and support</title>
    @vite(['resources/css/app.css'])
    <style>
        body { background: var(--bg); color: var(--text); font-family: var(--font-sans); line-height: 1.7; }
        main { max-width: 48rem; margin: 0 auto; padding: 3rem 1.5rem 5rem; }
        h1 { font-size: clamp(2rem, 5vw, 2.75rem); line-height: 1.15; font-weight: 650; margin: 1rem 0 1.5rem; }
        h2 { font-size: 1.25rem; font-weight: 600; margin: 2.5rem 0 .75rem; }
        p + p { margin-top: 1rem; }
        a { color: var(--accent); text-decoration: underline; text-underline-offset: .2em; }
    </style>
</head>
<body>
<main>
    <p style="color:var(--accent);font-weight:600">Cbox ID for iPhone</p>
    <h1>Privacy and support</h1>
    <p>Cbox ID connects an existing Cbox ID account to an iPhone so you can review, approve, deny and report sign-in and action requests. You can also keep verification codes for other services, without a Cbox account.</p>
    <h2>Data on your phone</h2>
    <p>The app keeps account identifiers, account labels, access tokens, refresh tokens and a device signing key in iOS Keychain. The signing key and sign-in tokens stay on this device. Passwords are entered in the system authentication browser, not stored by the authenticator.</p>
    <h2>Verification codes and encrypted backup</h2>
    <p>Verification codes (TOTP) also work offline without a Cbox account. Their setup keys and labels stay in this iPhone's Keychain and are never sent to Cbox or the services they protect. Codes copied to the clipboard stay on this device and expire automatically. An encrypted backup contains these codes, protected by the password you choose; the app never stores that password. You choose the backup destination in Files, which may include a cloud provider. The app does not sync codes automatically. Cbox cannot recover a forgotten backup password. Remove individual codes in Code details, and remove exported backup files from their chosen destinations yourself. Removing a Cbox connection does not remove your verification codes; revoking a phone on a Cbox host does not revoke its codes for other services.</p>
    <h2>Data shared with your Cbox ID host</h2>
    <p>Your host receives your account identifier, an installation identifier, device name, platform, app version and your approval decisions. It records security events and the network address used for API requests. If you enable notifications, the host stores an encrypted push token and sends generic notifications through Firebase Cloud Messaging and Apple Push Notification service. Request details are fetched separately from your host.</p>
    <p>The Firebase messaging components process installation identifiers and technical metadata, including device and software information, to register the app for push delivery. They may also send diagnostic and service-usage data to Google to operate and improve the messaging service. The app does not include Google Analytics or Crashlytics. These messaging components do not receive your TOTP setup keys, backup password or biometric data.</p>
    <h2>Permissions</h2>
    <p>The camera scans Cbox connection codes and TOTP setup QR codes. Face ID and the iPhone passcode are handled by iOS; the app receives a success or failure result and never receives biometric data. Notifications are optional: you can open the app to check for requests without enabling them. The app does not include advertising or advertising tracking.</p>
    <h2>Removing data and access</h2>
    <p>Use Settings in the app to remove an account from the phone. If a phone is lost, open My account → Trusted devices on your Cbox ID host and remove it there. Removal revokes the device's approval access. Security audit records and device revocation records may be retained by your host under its security and retention policy.</p>
    <p>To request deletion of your Cbox ID account and its server data, contact Cbox or the organization that operates your account. The authenticator connects existing accounts; it does not create Cbox ID accounts.</p>
    <h2>Support and privacy questions</h2>
    <p><a href="https://cbox.dk/contact">Contact Cbox</a>. For an organization-managed account, your administrator can also help with access, recovery and retention.</p>
</main>
</body>
</html>
