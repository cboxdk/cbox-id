# Organisationer og kunder

Læs denne først. To ting i Cbox ID er organisationer, og konsollen holder dem adskilt efter,
hvor de står:

- din **organisation** er din egen Cbox-konto: den ejer identitetsplatforme, miljøer, et team og en
  regning. Den er altid det første led i topbjælken;
- en **kundeorganisation** er et team hos *dine* kunder inde i et af dine miljøer. Den hører
  til miljøet og står under **Organisationer**.

## Kæden

```
Organisation → Identitetsplatform → Miljø → Kundeorganisation → Subjekt
```

Læst fra venstre mod højre er det én sætning: *du* ejer *produkter*, hvert produkt har
*faser*, hver fase rummer *dine kunders teams*, og hvert team har *personer*.

| Lag | Hvad det er | Hvem det hører til |
|---|---|---|
| **Organisation** | Dig. Cbox' kunde, der betaler, og det team, dine kolleger bliver medlem af for at administrere dine produkter. | Cbox ID |
| **Identitetsplatform** | Login til ét af dine produkter: brugere, loginside, miljøer og abonnement. To produkter kan faktureres hver for sig fra én organisation. API'et beholder navnet `projects`. | Din organisation |
| **Miljø** | En fase af produktet: produktion, testmiljø. En skarp grænse med sine egne brugere, signeringsnøgler, udsteder, design og loginside. | Din identitetsplatform |
| **Kundeorganisation** | En af dine kunders virksomheder eller teams. Et træ i vilkårlig dybde, så en koncern kan rumme virksomheder og en virksomhed afdelinger. | Dit miljø |
| **Subjekt** | En person, der logger ind i dit produkt. | Dit miljøs brugere |

## Én slags række, to steder

**Din organisation og en kundeorganisation er den samme slags række.** Tidligere lå der en
særskilt `Account`-model over organisationen med sit eget navn, status, medlemmer og
rolleord: to rækker for én kunde og to svar på, hvem der må handle for dem. De blev ved med
at være uenige, så den række blev fjernet i stedet for at blive bragt i overensstemmelse.
Din organisation ligger i platformens rodmiljø, og dit team er almindelige subjekter med et
medlemskab af den.

Derfor når ét login alt, hvad du administrerer. Begge er en række i `organizations`; hvor
rækken ligger, afgør, hvilken af dem det er:

- I **platformens rod** er rækken **en organisation**: en kunde hos Cbox. Før v3 kaldte
  konsollen den et *workspace*.
- I **dit eget miljø** er rækken **en kundeorganisation**: et af dine kunders teams. På dit
  miljøs egen vært findes kun den slags, så siderne skriver bare *organisation*.

**Sådan kender du forskel: din organisation kan eje identitetsplatforme. En kundeorganisation gør ikke.**
Har det, du ser på, identitetsplatforme, miljøer og en regning, er det din organisation. Har det
medlemmer, roller og SSO-forbindelser og ligger inde i ét miljø, er det en af dine kunder.

## Hvilken konsol er du i

Du administrerer de to i to konsoller.

### Organisationskonsollen

På platformens rodvært i en hostet installation (`cboxid.com`), logget ind som dig selv.
Menuen viser din organisation og intet andet:

| Område | Sider |
|---|---|
| **Organisation** | Oversigt, Identitetsplatforme (når organisationen ejer en), Team, API-nøgler, Miljøets domæner, Fakturering, Organisationsindstillinger |
| **Team sign-in** | Single sign-on, Godkendelsespolitik: hvordan dit eget team logger ind i Cbox |
| **Revisionslog** | Revisionslog |
| **Konto** | Sikkerhed, Sessioner og aktivitet |

Siderne, der administrerer slutbrugere (roller, tilladelser, applikationer, webhooks, hooks,
tokenboks, integrationer, adgangsgennemgange og resten), står ikke i denne menu. Ved
platformens rod ville de administrere din organisations egen post i Cbox' miljø, som kun dit
team logger ind i. Dit produkts brugere, apps og roller hører til i en miljøkonsol.

Siderne er **skjult i menuen, ikke omdirigeret.** Af tre grunde:

- **Intet bliver efterladt.** Har du registreret en app eller en webhook der før denne
  ændring, kan du stadig åbne den via URL'en og slette den. En omdirigering ville have gjort
  den umulig at nå uden at fjerne den.
- **Én kontrol afgør adgangen.** Siderne er stadig beskyttet af samme tjek som alle andre
  sider i konsollen. En omdirigering ville være endnu en kontrol, der skulle være enig med
  den første på hver route for altid; et filter på, hvad menuen viser, kan ikke låse nogen
  ude.
- **Siden siger, hvad den er.** Åbner du den via URL'en, viser den en besked over indholdet:
  siden administrerer din organisations egen post i Cbox, ikke dit produkt, med et link til
  Identitetsplatforme.

Organisationskonsollens **Oversigt** er organisationens forside på `/orgs/{org}/overview`.
Den har ingen miljøoversigt og ingen Kom i gang-side; de hører til dit produkts miljø.

**Ny identitetsplatform** beder om produktnavnet og et abonnement, når installationen
tilbyder dem. Det billigste er valgt på forhånd. Oprettelsen kræver en frisk bekræftelse
af din identitet og opretter også produktionsmiljøet. Et betalt abonnement gennemgås med
moms og bekræftes under **Fakturering**.

En operatør og en installation med én kunde (hvor rodmiljøet *er* produktet) beholder alle
sider der. Dine kunder får den smallere konsol nedenfor.

### Din kundes organisationskonsol

På dit miljøs egen vært får en person, der logger ind i dit produkt, login og sine egne
sider og intet andet:

| Område | Sider |
|---|---|
| **Oversigt** | Godkendelser (anmodninger om at handle på deres vegne) |
| **Konto** | Sikkerhed, Sessioner og aktivitet, Mine API-nøgler (når en af dine apps tilbyder dem), Betroede enheder (når enhedsmodulet er slået til) |

`/dashboard` sender dem dér til deres konto. Deres organisation administreres to andre
steder:

- **Af dig** i miljøkonsollen på app-værten: åbn organisationen under **Organisationer** for
  medlemmer, invitationer, roller, single sign-on, domæner, katalogsynkronisering,
  revisionslog og appens revisionslogge.
- **Af deres it-administrator** via et **opsætningslink** (`/setup/{token}`), som du sender
  fra organisationens side. Det udløber efter en tid og dækker det, du vælger: single
  sign-on, domæner, katalogsynkronisering, certifikater, revisionsloggen.

Siderne i en kundekonsol (Medlemmer, Roller, Single sign-on, Domæner,
Katalogsynkronisering, Revisionslog) svarer 404 på dit miljøs vært, også ved ændringer, og
en agent, der er logget ind, får ingen værktøjer til dem dér.

En selvhostet installation med flere kunder kan slå den faste kundekonsol til igen med
`CBOX_ID_TENANT_CUSTOMER_CONSOLE=true`. En kundes administrator får så en administrationsportal
til sin it-afdeling på dit miljøs vært, præcis Medlemmer, Single sign-on, Domæner,
Katalogsynkronisering, Roller og Revisionslog, plus sine egne sider:

| Område | Sider |
|---|---|
| **Oversigt** | Oversigt (organisationens tal og seneste aktivitet), Godkendelser (anmodninger om at handle på deres vegne) |
| **Medlemmer og roller** | Medlemmer, Roller |
| **Login** | Single sign-on, Domæner, Katalogsynkronisering |
| **Revisionslog** | Revisionslog, og Appens revisionslogge, når organisationens plan har dem |
| **Konto** | Sikkerhed, Sessioner og aktivitet, Mine API-nøgler (når en af dine apps tilbyder dem), Betroede enheder (når enhedsmodulet er slået til) |

Alt andet er administration af dit produkt og hører til i miljøkonsollen: applikationer og
API'er, webhooks, hooks, tokenboksen, adgangsgennemgange, rollekonflikter, udgående
provisionering, logstrømme, socialt login, godkendelsespolitik, udseende og design, forbrug,
indstillinger, tilladelser, medlemmers API-nøgler, Kom i gang-siden og modulernes sider
(loginaktivitet, compliance, integrationer, oversigt over betroede enheder,
risikohændelser). Tilladelser er det, dine apps håndhæver, så det er dig, der opretter nye;
din kunde bygger stadig roller af de tilladelser, der findes, på siden Roller.

De sider er **ikke til stede** i denne konsol, ikke bare skjult i menuen: deres URL'er svarer
404, også ved ændringer. I modsætning til din organisationskonsol bliver intet efterladt, for
de samme poster kan stadig nås fra din miljøkonsol på `/admin`. Én liste styrer både menuen
og 404-svaret, så de to ikke kan være uenige
([`CustomerConsole`](https://github.com/cboxdk/cbox-id/blob/main/app/Platform/Console/CustomerConsole.php)).

Tidligere skulle dine kunders organisationskonsol og miljøkonsollen tilbyde de samme
funktioner, og et doctor-tjek fejlede, hvis de ikke gjorde. Den regel er væk: konsollerne er
forskellige med vilje nu.

### En miljøkonsol

På `/admin` på miljøets egen vært, som du når fra **Identitetsplatforme** med **Åbn konsollen** eller fra
miljøvælgeren i topbjælken i enhver konsol. Menuen handler om dit produkt og bruger de ord,
andre identitetsplatforme bruger om de samme ting. Intet i den handler om din
Cbox-organisation, og alt det, dine kunders egen konsol udelader, er her.

En besked over indholdet forklarer, at du administrerer de personer, der logger ind i dit
produkt, ikke dit eget team. **Tilbage til …** åbner organisationens forside.

| Område | Sider |
|---|---|
| **Hjem** | Oversigt, Kom i gang |
| **Brugere og organisationer** | Brugere, Organisationer (dine kundeorganisationer) |
| **Godkendelse** | Loginmetoder, Godkendelsespolitik, Socialt login, Single sign-on, Domæner, Katalogsynkronisering, Radar, Betroede enheder (enhedsmodulet) |
| **Adgangskontrol** | Roller, Tilladelser, Detaljeret adgangskontrol, Adgangsgennemgange, Rollekonflikter |
| **Udviklere** | Applikationer, API'er, API-nøgler, Webhooks, Hooks, Funktionsflag, Pipes |
| **Integrationer** | Katalog, Forbindelser (integrationsmodulet) |
| **AI-agenter** | Agenter, Godkendelser, Tilslut |
| **Design** | Design (logintemaet, logo og favicon; med white-label-modulet også navn, afsender og farver) |
| **Overvågning** | Revisionslog, Logstrømme, Forbrug, Loginaktivitet, Revisionsspor, Eksport og opbevaring, Risikohændelser (hver fra sit modul) |
| **Avanceret** | Administratorer og support, Tokenboks, Udgående provisionering, SAML-apps, Login via ældre system |
| **Indstillinger** | Indstillinger |

**Godkendelse** rummer alle de veje, folk kommer *ind* ad; de udgående retninger (SAML-apps,
der stoler på dette miljø, provisionering ud til andre systemer) står under **Avanceret**
sammen med resten af det, der sættes op én gang og sjældent ændres. **Adgangskontrol** står
lige ved siden af: hvad folk må, når de er kommet ind. URL'erne blev ikke ændret, da sider
fik nye navne eller blev flyttet, så bogmærker og links virker stadig. Find vej viser de
almindelige opgaver klik for klik.

## Sådan skifter du mellem dem

Alle konsoller har den samme topbjælke: hvor du er, til venstre, og søgning og din konto til
højre.

```
Acme ▾  /  Checkout ▾  /  Produktion ▾ [PRODUKTION]  /  Alle organisationer ▾
```

- **Organisation ▾** viser de organisationer, du er medlem af. I organisationskonsollen
  skifter du til den, du vælger; i en miljøkonsol åbner den organisationskonsollen, hvor din
  session er. En organisation uden identitetsplatforme viser sit navn her uden noget efter.
- **Identitetsplatform ▾** viser din organisations identitetsplatforme. Vælger du en, åbnes det miljø dér, der
  svarer til det, du er i: produktion for produktion.
- **Miljø ▾** viser de miljøer i identitetsplatformen, du må administrere, hver med sin type. Vælger du
  et, åbnes **den samme side** dér: Brugere i test bliver til Brugere i produktion. En side om
  én post (en bruger, en app) åbner i stedet listen, og en side, som det andet miljø ikke
  har, åbner dets Oversigt.
- I organisationskonsollen er intet miljø valgt, så det andet led er **Miljøer ▾**: alle
  miljøer, du må åbne, grupperet efter identitetsplatform.
- **Alle organisationer ▾**, kun i en miljøkonsol, filtrerer alle sider til én af dine
  kundeorganisationer. Det er en søgning, fordi et miljø kan rumme tusinder.

Kun det, du må åbne, vises. En Læser ser ingen miljøer, og et medlem med adgang til nogle
miljøer ser dem. En anden organisations identitetsplatforme vises først, når du skifter til den. Hver
menu får et søgefelt, når den har mere end otte punkter.

At skifte miljø går gennem den samme signerede overlevering som **Åbn konsollen**; siden, du
skal lande på, følger med, og miljøet afviser ethvert mål, der ikke er en af dets egne
konsolsider.

**Kontomenuen** er avataren øverst til højre: **Organisationsindstillinger** (hvis du må
ændre dem), **Konto**, **Skift konto**, **Platformadministrator** (kun operatører), **Tema** og
**Log ud**. I en miljøkonsol åbner kontolinkene på rodværten, fordi dit eget login hører til
Cbox ID, ikke til miljøet.

**Menuen viser sine navne som standard.** Med nålen øverst i menuen folder du den sammen til
ikoner; valget huskes i denne browser.

### Platformadministration

Den, der driver installationen, når platformens sider (organisationer, miljøer, træet af
kundeorganisationer, operatører, forbrug, køer) som området **Platform** i platformmiljøets
konsol, `/admin` på rodværten: den samme konsol, menu og ⌘K som platformmiljøets egne
indstillinger. Fra organisationskonsollen går **Platformadministrator** i kontomenuen dertil.
Linjen over hver Platform-side siger **Platformadministration: Hele installationen**, i
advarselsfarverne, fordi et klik dér rammer alle organisationer på installationen. Du peger
platformsiderne mod et andet miljø under **Platform › Miljøer**. På en installation med én
kunde er de samme sider et **Platform**-område i konsollens menu.

## Hvor du lander efter login

En person med adgang til organisationens administration lander på **Organisation › Oversigt**.
Her findes teamet, login, fakturering og aktivitet. Hvis du må oprette en identitetsplatform,
kan du starte herfra; du behøver ikke en for at administrere dit eget team. Miljøerne ligger
under **Identitetsplatforme** med **Åbn konsollen** ved siden af. Login på `cboxid.com` åbner
ikke automatisk et miljøs konsol. Uden adgang til administration lander du på din egen konto.

Den eneste undtagelse er et login, der startede i et miljøs konsol. Åbner du
`acme.cboxauth.com/admin/users` uden at være logget ind, logger du ind på `cboxid.com` og
lander tilbage på den side. Det virker kun inden for 15 minutter, efter du blev sendt til
login, og kun i den browser, der blev sendt.

## Log ud

**Log ud** logger dig ud af den sammenhæng, du er i, alle de steder, den nåede.

- **Dit Cbox ID-login** (organisationskonsollen, Konto, platformkonsollen og alle de
  miljøkonsoller, du åbnede derfra): logger du ud af en af dem, logger du ud af dem alle, for
  alle konti i browseren og i alle faner. Du lander på **Du er logget ud**, og alle konsoller
  beder dig logge ind igen.
- **En kundes egne brugere** på kundens vært (dens loginside, dens Konto-side, dens apps'
  logout): logger de ud dér, slutter kun deres egen session. Det lukker aldrig miljøkonsollen
  på samme vært, og lukker du konsollen, logges de aldrig ud.

Login på dine andre enheder forbliver logget ind. Vil du også afslutte dem, så brug **Log
andre sessioner ud** under Konto.

## Relateret

- Samlet identitet: beslutningen om, hvorfor den særskilte kontorække blev fjernet i stedet
  for at blive beholdt ved siden af.
- Nøgler: de nøgler, hver konsol udsteder.
- Integrer din app: sådan registrerer du en app i et miljø.
