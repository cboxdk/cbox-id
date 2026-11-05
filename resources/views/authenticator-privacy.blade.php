<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Cbox ID Authenticator — privacy and support</title>
</head>
<body>
<main>
    <h1>Cbox ID Authenticator: privacy and support</h1>
    <p>Cbox ID Authenticator connects an existing Cbox ID account to an iPhone so you can review, approve, deny and report sign-in and action requests.</p>
    <h2>Data on your phone</h2>
    <p>The app keeps account identifiers, account labels, access tokens, refresh tokens and a device signing key in iOS Keychain. The signing key and sign-in tokens stay on this device. Passwords are entered in the system authentication browser, not stored by the authenticator.</p>
    <h2>Data shared with your Cbox ID host</h2>
    <p>Your host receives an installation identifier, device name, platform, app version and your approval decisions. It records security events and the network address used for API requests. If you enable notifications, the host stores an encrypted push token and sends generic notifications through Firebase Cloud Messaging and Apple Push Notification service. Request details are fetched separately from your host.</p>
    <h2>Permissions</h2>
    <p>The camera scans connection codes. Face ID and the iPhone passcode are handled by iOS; the app receives a success or failure result and never receives biometric data. Notifications are optional: you can open the app to check for requests without enabling them. The app does not include advertising or advertising tracking.</p>
    <h2>Removing data and access</h2>
    <p>Use Settings in the app to remove an account from the phone. If a phone is lost, open My account → Trusted devices on your Cbox ID host and remove it there. Removal revokes the device's approval access. Security audit records and device revocation records may be retained by your host under its security and retention policy.</p>
    <p>To request deletion of your Cbox ID account and its server data, contact Cbox or the organization that operates your account. The authenticator connects existing accounts; it does not create Cbox ID accounts.</p>
    <h2>Support and privacy questions</h2>
    <p><a href="https://cbox.dk/contact">Contact Cbox</a>. For an organization-managed account, your administrator can also help with access, recovery and retention.</p>
</main>
</body>
</html>
