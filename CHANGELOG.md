# Changelog

All notable changes to Cbox ID (the deployable identity platform app) are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Confirmed security issues and their fixes are cross-referenced under **Security** below.

## [Unreleased]

### Added

- **Service connections keep working under `CBOX_ID_REALM_PLATFORM_CLIENTS_ONLY`.** The client the platform creates for an organization's service connection is admitted in the platform's own environment: created by the service-connection feature and served for as long as an unrevoked connection names it. A customer's client with the same name is still refused.
- **Operator screens and two realm refusals in every language.** The break-glass passkey link, the operator plane's passkey prompts and errors, `organization_apps_not_allowed` and `not_available_in_the_realm` are translated into the 21 languages the console carries.
- **`/switch-app` on the login host.** An app's "switch app" link names the issuer; on the login host it now sends the person to the console's switcher through the console's own sign-in instead of a 404.
- **`cboxdk/laravel-id` `^2.6`** (membership-only deprovisioning per environment, the phishing-resistant `acr`). The operator plane asks the framework's `AuthenticationContextClass::PhishingResistant` directly.
- **An invitation that names an app lands at the app when its stored return address no longer holds**, instead of in the console: the address is still not followed, and the invitee starts at the one origin the app is registered on. With the app gone, the console as before.
- **Fixed: `/continue` on a customer environment's host passes the customer console's door** (it was registered beside it), and the environment console no longer reads the session row twice on every request while the per-organization SSO mandate is off.
- **`login_hint` that names somebody else opens the account chooser.** When `/oauth/authorize` carries an email `login_hint` and the browser is signed in with another address, the person is shown the account chooser (pick the account the app meant, or add it; the sign-in form is filled in with the hint) and the request resumes with whoever they chose. The same address, in any letter case, continues as before. Under `prompt=none` the answer is `login_required`. Not applied with `prompt=login` (which already asks for a sign-in) or a login ticket.
- **The signed-out page offers the way back to the app.** An RP-initiated logout that ends on the hosted signed-out page now shows "Back to {app}" when the request named its client (`client_id`, or the audience of `id_token_hint`) and that client is an environment-wide app an administrator registered: the link is the app's catalog `launch_url`, else the single origin of its redirect URIs. An organization's own client, a self-registered client and an unknown id get no link.
- **A fresh operator passkey for two more root sign-in settings.** With `CBOX_ID_OPERATORS_PHISHING_RESISTANT` on, creating an SSO connection (`sso.connections.create`) and turning a social provider back on (`signin.social.enable`) in the platform's own environment now ask for a passkey confirmation no older than the re-confirm window, like the other root sign-in settings. Their danger class is unchanged (`write`), so API keys, approvals and customers' own environments behave as before.
- **With a login host, a console session held for a password change or a second factor comes back to the page it was opening.** The console sends the person to `{login host}/password/change` or `{login host}/settings` with `return_to` naming the console page (GET requests only). After the password change the browser returns there through `/auth/start`; the security page's "Back to …" leads there. `return_to` is validated on the login host as before: an address on another origin is dropped.
