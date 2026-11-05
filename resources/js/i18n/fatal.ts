/** The root error boundary must work even when Inertia and its catalogue have failed. */
const messages: Record<string, readonly [string, string, string, string]> = {
    en: [
        'Something went wrong',
        'This page could not be displayed. Reloading it starts over from the server, which resolves most causes.',
        'Reload this page',
        'Go to the dashboard',
    ],
    da: [
        'Der opstod en fejl',
        'Siden kunne ikke vises. Genindlæs den for at hente den fra serveren igen. Det løser de fleste fejl.',
        'Genindlæs siden',
        'Gå til dashboardet',
    ],
    de: [
        'Ein Fehler ist aufgetreten',
        'Diese Seite konnte nicht angezeigt werden. Laden Sie sie neu, um sie erneut vom Server abzurufen. Dadurch werden die meisten Fehler behoben.',
        'Seite neu laden',
        'Zum Dashboard',
    ],
    sv: [
        'Något gick fel',
        'Sidan kunde inte visas. Ladda om den för att hämta den från servern igen. Det löser de flesta fel.',
        'Ladda om sidan',
        'Gå till översikten',
    ],
    nb: [
        'Noe gikk galt',
        'Siden kunne ikke vises. Last den inn på nytt for å hente den fra serveren igjen. Det løser de fleste feil.',
        'Last inn siden på nytt',
        'Gå til oversikten',
    ],
    fi: [
        'Jokin meni pieleen',
        'Sivua ei voitu näyttää. Lataa se uudelleen palvelimelta. Tämä korjaa useimmat virheet.',
        'Lataa sivu uudelleen',
        'Siirry hallintapaneeliin',
    ],
    fr: [
        'Une erreur est survenue',
        'Cette page n’a pas pu être affichée. Rechargez-la pour la récupérer à nouveau depuis le serveur. Cela résout la plupart des erreurs.',
        'Recharger la page',
        'Accéder au tableau de bord',
    ],
    es: [
        'Se ha producido un error',
        'No se ha podido mostrar esta página. Recárgala para volver a obtenerla del servidor. Esto resuelve la mayoría de los errores.',
        'Recargar la página',
        'Ir al panel de control',
    ],
    it: [
        'Si è verificato un errore',
        'Impossibile visualizzare questa pagina. Ricaricala per recuperarla nuovamente dal server. In questo modo si risolve la maggior parte degli errori.',
        'Ricarica la pagina',
        'Vai alla dashboard',
    ],
    nl: [
        'Er is iets misgegaan',
        'Deze pagina kon niet worden weergegeven. Laad de pagina opnieuw om deze weer van de server op te halen. Dit verhelpt de meeste fouten.',
        'Pagina opnieuw laden',
        'Naar het dashboard',
    ],
};

export function fatalCopy(locale: string): readonly [string, string, string, string] {
    return messages[locale] ?? messages.en!;
}
