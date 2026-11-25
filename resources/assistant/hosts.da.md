# Lag og værter

Cbox ID svarer på to slags værter, og den, du kalder, afgør, hvad du kan nå. De fleste
spørgsmål om "det giver 404" skyldes en forespørgsel til den forkerte vært.

## To slags værter

| | Platformens rod | Et miljøs egen vært |
|---|---|---|
| Hostet adresse | `cboxid.com` | `<environment>.cboxauth.com` eller dit eget domæne |
| Selvhostet | den vært, du installerede på | en vært pr. miljø under dit eget domæne |
| Hvem logger ind | din organisations team og de operatører, der driver installationen | miljøets egne brugere: dine kunders folk |
| Er det en identitetsudbyder? | **Nej** på en installation med flere kunder: discovery, JWKS og token-endpointet svarer 404 | **Ja**: det er den `issuer` (udsteder), dine apps bruger |
| Konsoller | organisationskonsollen; for operatører platformadministration (`/admin`) og platformmiljøets egen konsol (`/admin/environment`) | miljøkonsollen (`/admin`) og dine kunders organisationskonsol |
| Administrations-API | `/api/v1/workspace`, `/api/v1/platform`, `/api/v1/me` | `/api/v1`, `/api/v1/me` |
| MCP | `/mcp`: organisationen, alle miljøer, du administrerer (for en operatør også platformens eget), din konto | `/mcp`: det miljø |

**Et miljø er sin egen identitetsudbyder.** Dets vært serverer OpenID Connect-discovery,
authorize- og token-endpoints, SAML og SCIM, de hostede loginsider og den Admin Portal,
dine kunders it-administratorer åbner. Alle apps, du bygger, logger folk ind mod den vært,
og `issuer` i `https://<environment>.cboxauth.com/.well-known/openid-configuration` er den
værdi, du giver et SDK.

**Roden er ikke.** På en installation med flere kunder afviser roden protokollerne med vilje,
så ingen sætter en app op mod en udsteder, der ikke hører til noget miljø. Se
IdP-overflade-spærren.
På en installation med én kunde *er* rodmiljøet produktet, og spærren gør ingenting.

## Fire lag

Administrations-API'et er delt i **lag** (på engelsk *planes*): hvem du handler som, og over
hvad. Hver handling hører til præcis ét lag, og laget afgør dens URL, hvilken nøgle eller
hvilket token der når den, og hvor dens MCP-værktøj står.

| Lag | Sti | Vært | Legitimation | Hvad det dækker |
|---|---|---|---|---|
| **Miljø** | `/api/v1/…` | miljøets | en hemmelig nøgle (`cbid_env_…`) eller et token, en person har delegeret | organisationer, brugere, applikationer, API'er, nøgler, login, styring, alt inden for ét miljø |
| **Organisation** | `/api/v1/workspace/…` | roden | en organisationsnøgle (`cbid_ws_…`) eller et teammedlems token | projekter, miljøer, teamet, nøgler |
| **Konto** | `/api/v1/me/…` | der, hvor personen logger ind | kun et token, personen har delegeret | én persons egen profil, sessioner, app-tilladelser, personlige nøgler, enheder |
| **Platform** | `/api/v1/platform/…` | roden | kun en platformoperatørs token | installationen: organisationer, miljøer, operatører |

Tabellen giver to regler:

- **Legitimation krydser aldrig lag.** En hemmelig nøgle er bundet til den vært, den blev
  oprettet til, og kan ikke kalde et andet miljø eller organisationens API. En
  organisationsnøgle kan ikke kalde et miljøs API.
- **Ingen nøgle handler som en person.** Konto- og platformlaget tager kun et token, en
  person har delegeret, så en lækket nøgle aldrig kan ændre nogens adgangskode eller oprette
  en operatør.

Hvert lag offentliggør sit OpenAPI-dokument uden nøgle:
`/api/v1/environment/openapi.yaml`, `/api/v1/workspace/openapi.yaml`,
`/api/v1/me/openapi.yaml` og `/api/v1/platform/openapi.yaml`. Oversigten over handlinger
genereres fra samme register.

## Ét login ved roden, mange miljøer

Når du driver en organisation, er du en person i platformens rod: du har ingen konto i dine
miljøer, du administrerer dem. Du logger derfor ind **én gang ved roden** (konsollen,
`cbox login` eller en MCP-klient) og angiver miljøet, hver gang du handler i et:

- **REST:** send miljølagets forespørgsel til roden med headeren `Cbox-Environment`, der
  indeholder miljøets id eller slug.
- **MCP:** ved rodens `/mcp` tager hvert miljøværktøj et påkrævet `environment`-argument.
  `whoami` viser de værdier, du må bruge.
- **CLI:** `cbox id use <environment>` vælger et miljø for denne vært; `--env` overstyrer det
  for én kommando.

Et miljø, du ikke har adgang til, eller en anden organisations miljø, svarer `not_found`.

**Platformens eget miljø**, rodmiljøet, hvor alle organisationsejere og operatører logger
ind, er bare endnu et miljø for en platformoperatør: dets konsol er `/admin` på rodens vært,
og over REST og MCP angives det som alle andre. Platformadministration er et område i den
samme konsol, `/admin`; operatørernes API har sin egen sti, `/api/v1/platform`. Ingen andre
kan nå nogen af dem. Se Administration af platformens eget miljø.

## Relateret

- [Organisationer og kunder](/ai-assistant/guides/id-workspaces): det hierarki, værterne betjener.
- Nøgler og tokens: hvilken legitimation du bruger hvor.
- Agenter og MCP: sådan forbinder du en agent til en af værterne.
- Egne domæner: sådan kører du et miljø på dit eget domæne.
