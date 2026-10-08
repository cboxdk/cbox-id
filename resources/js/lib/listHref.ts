/**
 * A list page's own URL with its search term and page number set — and EVERY OTHER query
 * parameter kept.
 *
 * The other parameters are the list's filters (`?organization=` above all), and a search box
 * that rebuilt the URL from its own term alone quietly dropped them: typing into the search
 * on a list narrowed to one organization widened it back to every organization's rows while
 * the chip still said otherwise.
 */
export function listHref(search: string, page?: number, term = 'q'): string {
    const query = new URLSearchParams(window.location.search);

    if (search !== '') {
        query.set(term, search);
    } else {
        query.delete(term);
    }

    if (page !== undefined && page > 1) {
        query.set('page', String(page));
    } else {
        query.delete('page');
    }

    const rest = query.toString();

    return rest === '' ? window.location.pathname : `${window.location.pathname}?${rest}`;
}
