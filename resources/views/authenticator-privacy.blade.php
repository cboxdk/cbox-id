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
    <p>Verification codes (TOTP) also work offline without a Cbox account. Their setup keys and labels stay in this iPhone's Keychain and are never sent to Cbox or the services they protect. Codes copied to the clipboard stay on this device and expire automatically. An encrypted backup contains these codes, protected by the password you choose; the app never stores that password. You choose the backup destination in Files, which may include a cloud provider. The app does not sync codes automatically. Cbox cannot recover a forgotten backup password. Remove individual codes in Code details, and remove exported backup files from their chosen destinations yourself. Removing a Cbox connection does not remove your verification codes; revoking a phone on a Cbox host does not revoke its codes for other services.</p>
    <p>Your host receives an installation identifier, device name, platform, app version and your approval decisions. It records security events and the network address used for API requests. If you enable notifications, the host stores an encrypted push token and sends generic notifications through Firebase Cloud Messaging and Apple Push Notification service. Request details are fetched separately from your host.</p>
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
