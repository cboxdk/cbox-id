# Kom i gang med din app og dine brugere

Du starter på Free uden kreditkort eller betalingsoplysninger. Login, SSO for dine kunder,
SCIM og revisionslog er med; planens rammer begrænser forbrug og kapacitet. Signals har et
inkluderet forbrug, og mere kapacitet får du kun ved selv at opgradere. Når du opretter en
konto, godkender du ingen betaling, og der starter ikke automatisk et betalt abonnement.
I den tidlige adgang kan det stadig kræve en invitation at blive optaget.

## Dit team og dine kunder er to forskellige ting

Din **organisation** er det team, der administrerer Cbox ID. En **identitetsplatform** leverer login til dit produkt,
og dets **miljøer** holder identitetsdata og opsætning adskilt. En **kundeorganisation** er
en kunde inde i det produkt. Inviter ikke dine kunder ind i din egen organisation for at
give dem adgang til din app.

Enkeltpersoner, dine egne medarbejdere og kundeorganisationer kan bruge det samme produkt.
En person kan være medlem af flere organisationer med forskellige roller. Din app skal
tjekke den aktuelle organisation og tilladelse ved hver beskyttet forespørgsel. Cbox leverer
identitet og medlemskab; det kan ikke vide, hvilke data i din forretning der hører til
hvilken kunde. Skifter personen organisation, skal adgangen verificeres på ny.

## Få login til at virke først

Vælg **Opret en identitetsplatform** på organisationens **Oversigt**, hvis du har brug for
login til dit eget produkt. Angiv produktnavnet og vælg et abonnement. Free kræver ingen
betaling; et betalt abonnement gennemgås og bekræftes under Fakturering. Du behøver ikke
en identitetsplatform for at administrere dit eget team.

Åbn din identitetsplatforms miljø, og vælg **Kom i gang**. Vælg framework, opret en app, registrer
dens præcise callback- og logout-adresser, og følg quickstarten. Hold klienthemmeligheden
på serveren. Test login og logout med en konto, der ikke bruges i produktion, før du
kobler rigtige brugere på.

Tjeklisten ser, når en person har fået et token eller har været med i en session. Det
viser, at identiteten virker, ikke at hele din integration er færdig. Tjek også koblingen
til lokale konti, sessioner, logout, tilbagekaldelse af tokens, og at en kunde ikke kan se
en anden kundes data. En HTTPS-omdirigering, der er sat op, beviser ikke alene, at du er
klar til produktion.

## Har du allerede brugere?

Behold dine lokale bruger-id'er, relationer og eksisterende medlemskaber. Sanctum håndterer
sessioner og API-tokens; Passport er en OAuth-server. Ingen af dem er selve
brugerdatabasen. Du kan skifte, hvor brugerne logger ind, og beholde resten som i dag.
At flytte Passports klienter, scopes og tokens er et særskilt skridt.

Brug en kobling, du stoler på, eller lad brugeren selv logge ind og forbinde kontoen. Slå
aldrig konti sammen, bare fordi e-mailadressen er den samme. Vil du flytte brugerne
gradvist, kan **Login via ældre system** kalde dit godkendte, signerede HTTP-endpoint første
gang en identitet, der ikke er flyttet endnu, logger ind. Dit endpoint skal anvende den
gamle kontos status og sikkerhedsregler, ikke kun tjekke adgangskoden. Planlæg, hvordan
eksisterende totrinsbekræftelse bevares eller erstattes, og øv gendannelse. Når brugeren er
flyttet, verificerer Cbox ID de næste login; den gamle adgangskode virker ikke længere som
reserve. Tjek først, hvilke PHP- og Laravel-versioner det aktuelle SDK understøtter.

## Lad en kunde logge ind med sit firmalogin

Med **Single sign-on** logger en kundes medarbejdere ind med den konto, de allerede har i
Microsoft Entra, Okta eller et andet firmasystem. Opsæt forbindelsen inde i kundens
organisation. Kundens it-afdeling giver dig udbyderens indstillinger, og du tester
forbindelsen, før du gør den påkrævet. Andre kunder kan blive ved med at logge ind som
normalt.

Det er noget andet end dit eget teams adgang til Cbox' administrationskonsol. Et verificeret
e-maildomæne må ikke alene give medlemskab af en organisation eller administratorrettigheder.

## Lad kundens it-afdeling styre, hvem der kommer og går

Med **Katalogsynkronisering (SCIM)** tilføjer og fjerner kundens it-afdeling medlemmer fra
sit eget katalog. Det styrer adgang; det logger ikke folk ind. SSO og SCIM vælges hver for
sig. Test både at tilføje et medlem og at fjerne adgangen igen, også sessioner og API-adgang
i din app. Fjerner du et medlemskab hos én kunde, må det ikke slette medlemskaber, der ikke
har med den kunde at gøre.

## Forstå revisionslog og overvågning

Revisionsloggen viser, hvad der er sket: ændringer i identitet og adgang i Cbox bliver
registreret. Handlinger i din app skal have deres egne hændelser. Sikkerhedsovervågningen
bruger de signaler, der er til rådighed, til at finde mistænkelig aktivitet; en linje i
revisionsloggen er ikke i sig selv et tegn på et brud.

Gennemgå hvilke SDK-hændelser, id'er og opbevaringstider der understøttes, før du slår
signaler fra din app til. Send aldrig adgangskoder, tokens eller rå request bodies. Et
inkluderet forbrug betyder ikke ubegrænset forbrug, og heller ikke at indsamling og alle
detektorer allerede kører. Tjek den faktiske dækning, hvor nye data er, og hvilke regler der
gælder, før du stoler på en vurdering.

## Få hjælp uden at miste overblikket

Brug Assistant i produktet til at finde en vejledning eller se, hvor langt din opsætning er.
Den må kun læse de miljøer, du har adgang til, og skal henvise til den relevante
dokumentation. Har du brug for et menneske, så fortsæt til den menneskelige support, der er
sat op. En status, der er ufuldstændig eller ikke kan hentes, må ikke vises som en færdig
opsætning.

Når e-mails om at komme i gang er slået til, skal de handle om den konkrete identitetsplatforms næste
skridt og stoppe, når målet er nået, også hvis en kollega nåede det. Produkthjælp og tips
er adskilt fra nødvendige mails om konto og sikkerhed og følger dine præferencer for
kommunikation. Du opgraderer kun, når du selv vælger det.
