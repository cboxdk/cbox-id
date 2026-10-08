---
title: Identity provider guides
weight: 70
description: What to create in Okta, Microsoft Entra ID, Google Workspace or any SAML, OpenID Connect or SCIM provider, and which value goes into which field of the setup portal.
---

# Identity provider guides

The setup portal shows the values to copy into your identity provider, labelled with your
provider's own field names, and the steps to follow. These pages say the same with a little
more context, so you can prepare before you open the link.

Identity providers rename and move their admin screens often. The click paths here were
current when written; if a menu has moved, search your provider's admin console for the
field name, which changes far less often.

| Provider | Enterprise SSO | Directory Sync |
|---|---|---|
| [Okta](okta.md) | SAML | SCIM |
| [Microsoft Entra ID](entra-id.md) | SAML | SCIM |
| [Google Workspace](google-workspace.md) | SAML | Not over SCIM; see the page |
| OneLogin, JumpCloud, PingFederate | SAML, guided in the portal ([field names below](#onelogin-jumpcloud-and-pingfederate)) | OneLogin and JumpCloud: SCIM, guided in the portal |
| Anything else | [SAML 2.0](generic-saml.md) or [OpenID Connect](generic-oidc.md) | [SCIM 2.0](generic-scim.md) |

## OneLogin, JumpCloud and PingFederate

The portal walks you through these step by step. The fields it fills in:

| Provider | The portal's entity ID goes in | The portal's ACS URL goes in | Fixed settings | You bring back |
|---|---|---|---|---|
| OneLogin ("SAML Custom Connector (Advanced)") | Audience (EntityID) | Recipient, and ACS (Consumer) URL; the portal also gives the value for **ACS (Consumer) URL Validator** | SAML nameID format: Email; SAML initiator: Service Provider | The SSO tab's **Issuer URL** |
| JumpCloud (Custom Application) | SP Entity ID | ACS URLs | SAMLSubject NameID: `email`; SAMLSubject NameID Format: `urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress` | The file from **Export Metadata** |
| PingFederate (SP Connection) | Partner's Entity ID (Connection ID) | Assertion Consumer Service URL, binding POST | SAML_SUBJECT: `mail` | The connection's metadata export |

For Directory Sync, OneLogin takes the **SCIM Base URL** and **SCIM Bearer Token** in its
"SCIM Provisioner with SAML (SCIM v2 Enterprise)" app, and JumpCloud takes the **Base URL**
and **Token Key** on the SSO application's Identity Management tab, under SCIM 2.0.

## Related

- [Enterprise SSO](../sso.md) — the portal's five steps.
- [Directory Sync](../directory-sync.md) — the portal's SCIM setup.
