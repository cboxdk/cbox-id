<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cbox ID — Identity infrastructure for your applications</title>
    <meta name="description" content="Sign-in, organizations, enterprise SSO and access control in one identity platform. Use Cbox ID hosted or run it on your own infrastructure.">
    <meta name="theme-color" content="#faf9f6">
    <link rel="canonical" href="https://{{ config('marketing.host') }}/">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Cbox ID — Your users. Your rules.">
    <meta property="og:description" content="One identity layer for sign-in, organizations and access. Hosted or on your infrastructure.">
    <meta property="og:url" content="https://{{ config('marketing.host') }}/">
    <meta property="og:image" content="https://{{ config('marketing.host') }}/brand/cbox-icon-512.png">
    <link rel="icon" href="/brand/monograms/id.svg" type="image/svg+xml">
    <link rel="preload" href="/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    @vite('resources/css/marketing.css')
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="site-shell">
    <header class="site-header">
        <a class="brand" href="/" aria-label="Cbox ID home"><img src="/brand/monograms/id.svg" width="34" height="34" alt=""><span>Cbox <span class="brand-dot">·</span> ID</span></a>
        <nav aria-label="Main navigation"><a href="#platform">Platform</a><a href="#developers">Developers</a><a href="#deployment">Deployment</a><a href="https://cbox.dk/products/cbox-id/docs">Docs <span aria-hidden="true">↗</span></a></nav>
        <div class="header-actions"><a class="header-login" href="{{ $appUrl }}/login">Sign in <span aria-hidden="true">↗</span></a><a class="button button-primary" href="{{ $appUrl }}/start">Join early access</a></div>
    </header>
    <main id="main">
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow"><span class="eyebrow-line"></span> Early access</p>
                <h1 id="hero-title">Your users.<br>Your rules.<br><span>One identity layer.</span></h1>
                <p class="hero-description">Give people a way in. Keep control of what happens next. Sign-in, organizations and access, built into your applications.</p>
                <div class="hero-actions"><a class="button button-primary" href="{{ $appUrl }}/start">Join early access <span aria-hidden="true">↗</span></a><a class="text-link" href="https://cbox.dk/products/cbox-id/docs">Read the docs <span aria-hidden="true">→</span></a></div>
                <p class="hero-note">Join the waitlist for hosted early access. Already invited? Use your invitation on the signup page.</p>
            </div>
            <div class="identity-preview" aria-label="Illustrative identity workspace">
                <div class="preview-top"><span class="preview-monogram">ID</span><span>Identity workspace</span><span class="example-label">Example</span></div>
                <div class="preview-body">
                    <div class="preview-context"><span class="workspace-avatar">N</span><div><strong>Northstar</strong><span>Production environment</span></div><span class="context-dots" aria-hidden="true">···</span></div>
                    <div class="preview-heading"><span>Access overview</span><span class="tag">Production</span></div>
                    <div class="identity-person"><span class="person-avatar">AM</span><div><strong>Alex Morgan</strong><span>alex@example.com</span></div><span class="tag tag-blue">Member</span></div>
                    <div class="access-step"><span class="step-icon" aria-hidden="true">01</span><div><strong>Authenticate</strong><span>Passkey · User verified</span></div><span class="step-check" aria-hidden="true">✓</span></div>
                    <div class="access-step"><span class="step-icon" aria-hidden="true">02</span><div><strong>Resolve organization</strong><span>Northstar · Engineering</span></div><span class="step-check" aria-hidden="true">✓</span></div>
                    <div class="access-step"><span class="step-icon" aria-hidden="true">03</span><div><strong>Apply access policy</strong><span>Role · Resource · Permission</span></div><span class="step-check" aria-hidden="true">✓</span></div>
                    <div class="preview-result"><span class="result-dot" aria-hidden="true"></span><span>Identity verified. Access scoped.</span><code>session.created</code></div>
                </div>
                <div class="preview-bottom"><span>Every identity has a boundary.</span><span aria-hidden="true">↗</span></div>
            </div>
        </section>
        <div class="protocol-strip" aria-label="Supported integration standards"><span class="strip-label">Built on shared standards</span><span>OpenID Connect</span><span>OAuth 2.0</span><span>SAML 2.0</span><span>SCIM 2.0</span><span>WebAuthn</span></div>
        <section id="platform" class="platform-section section" aria-labelledby="platform-title">
            <div class="section-intro"><p class="eyebrow">A foundation, not another silo</p><h2 id="platform-title">Identity is more than<br>a sign-in screen.</h2><p>The first login is only the beginning. Keep users, organizations and permissions connected as your product grows.</p></div>
            <div class="capabilities">
                <article><span class="feature-number">01 / Authentication</span><h3>A way in for every user.</h3><p>Passkeys, passwords, magic links and social login. Add multi-factor authentication and connect an enterprise identity provider when your customers need it.</p><a class="text-link" href="https://cbox.dk/products/cbox-id/docs/getting-started/integrate-your-app">Explore sign-in <span aria-hidden="true">↗</span></a></article>
                <article><span class="feature-number">02 / Organizations</span><h3>Your customers have teams.</h3><p>Organizations, memberships, invitations and roles belong in your identity layer. Give each customer a home and keep their people and data separate.</p><a class="text-link" href="https://cbox.dk/products/cbox-id/docs/getting-started/organizations-in-your-app">Explore organizations <span aria-hidden="true">↗</span></a></article>
                <article><span class="feature-number">03 / Authorization</span><h3>Access with clear boundaries.</h3><p>Define roles and permissions. Scope credentials to the work they need to do. Review access and trace changes through the audit log.</p><a class="text-link" href="https://cbox.dk/products/cbox-id/docs/guides/roles">Explore access control <span aria-hidden="true">↗</span></a></article>
            </div>
        </section>
        <section id="developers" class="developer-section section" aria-labelledby="developers-title">
            <div class="developer-copy"><p class="eyebrow">Made to fit your stack</p><h2 id="developers-title">Your application.<br>Our identity plumbing.</h2><p>Keep your product’s own interface. Connect through a client SDK or use the protocol endpoints directly. Manage identity through APIs, with scoped access for tools and agents.</p><div class="sdk-list" aria-label="Client SDKs"><span>PHP / Laravel</span><span>JavaScript</span><span>React</span><span>Vue</span><span>Go</span><span>Python</span></div><a class="text-link" href="https://cbox.dk/products/cbox-id/docs">Find your integration <span aria-hidden="true">→</span></a></div>
            <div class="code-panel"><div class="code-panel-header"><span class="code-filename">OpenID Connect discovery</span><span class="code-language">HTTP</span></div><pre><code><span class="code-comment"># Discover your environment’s endpoints</span>
<span class="code-keyword">GET</span> https://&lt;your-environment&gt;.cboxid.com/.well-known/openid-configuration

<span class="code-comment"># One issuer. Standard endpoints.</span>
{
  <span class="code-key">"issuer"</span>: <span class="code-value">"https://&lt;your-environment&gt;.cboxid.com"</span>,
  <span class="code-key">"authorization_endpoint"</span>: <span class="code-value">"…/oauth/authorize"</span>,
  <span class="code-key">"token_endpoint"</span>: <span class="code-value">"…/oauth/token"</span>,
  <span class="code-key">"jwks_uri"</span>: <span class="code-value">"…/.well-known/jwks.json"</span>
}</code></pre><div class="code-panel-footer">Illustrative response · Use your environment’s issuer URL.</div></div>
        </section>
        <section id="deployment" class="deployment-section section" aria-labelledby="deployment-title">
            <div class="section-intro"><p class="eyebrow">Choose where it runs</p><h2 id="deployment-title">One platform.<br>Your choice of infrastructure.</h2><p>Use the hosted service to get started, or operate the identity platform in your own environment.</p></div>
            <div class="deployment-options"><article><div class="option-heading"><span class="option-icon" aria-hidden="true">↗</span><span class="eyebrow">Cbox hosted</span></div><h3>Build your product.<br>We run the platform.</h3><p>Create a workspace, configure your identity environment and connect your first application.</p><a class="button button-primary" href="{{ $appUrl }}/start">Join early access <span aria-hidden="true">↗</span></a></article><article><div class="option-heading"><span class="option-icon" aria-hidden="true">⌘</span><span class="eyebrow">Self-hosted</span></div><h3>Your environment.<br>Your operations.</h3><p>Deploy the application on your infrastructure. Inspect the source and review the Elastic License 2.0 terms before you deploy.</p><a class="button button-secondary" href="https://github.com/cboxdk/cbox-id">Explore the source <span aria-hidden="true">↗</span></a></article></div>
        </section>
        <section class="faq-section section" aria-labelledby="faq-title"><div><p class="eyebrow">Before you build</p><h2 id="faq-title">A few practical answers.</h2></div><div class="faq-list">
            <details><summary>Can I keep my own sign-in design?<span aria-hidden="true">+</span></summary><p>Yes. Use the Frontend API and client SDKs to build sign-in into your application, or use the hosted sign-in pages with your environment’s branding.</p></details>
            <details><summary>Does each customer get a separate environment?<span aria-hidden="true">+</span></summary><p>Environments separate identity configurations and issuers. Organizations group your customers’ teams within an environment. Choose the structure that fits your application.</p></details>
            <details><summary>Can I connect an enterprise identity provider?<span aria-hidden="true">+</span></summary><p>Yes. Cbox ID supports inbound SAML and OpenID Connect connections, alongside its own sign-in methods. Configure the connection in your environment.</p></details>
            <details><summary>Where do I manage an existing account?<span aria-hidden="true">+</span></summary><p>The application lives at <a href="{{ $appUrl }}/login">{{ parse_url($appUrl, PHP_URL_HOST) }}</a>. Your organization’s existing identity environment keeps its own login address.</p></details>
        </div></section>
        <section class="final-cta" aria-labelledby="cta-title"><div><p class="eyebrow">Start with a solid foundation</p><h2 id="cta-title">Make identity part<br>of your infrastructure.</h2></div><a class="button button-primary" href="{{ $appUrl }}/start">Join early access <span aria-hidden="true">↗</span></a></section>
    </main>
    <footer class="site-footer"><a class="brand" href="/" aria-label="Cbox ID home"><img src="/brand/monograms/id.svg" width="28" height="28" alt=""><span>Cbox <span class="brand-dot">·</span> ID</span></a><span class="footer-note">Identity infrastructure. Built by Cbox.</span><nav aria-label="Footer navigation"><a href="https://cbox.dk/products/cbox-id/docs">Documentation</a><a href="https://github.com/cboxdk/cbox-id">Source</a><a href="https://cbox.dk">Cbox <span aria-hidden="true">↗</span></a></nav></footer>
</div>
</body>
</html>
