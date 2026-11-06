/*
 * The design system's combobox, in place of a native <select> (which the Cbox design system
 * does not use). Every single select on the page becomes a button that opens a listbox, with a
 * type-ahead search field once there are more than a handful of options (or when
 * `data-combobox="search"` asks for one). `data-native` opts a select out. Selects Livewire
 * renders or re-renders, and rows a form adds, are picked up as they appear. The select stays in the form, visually hidden, so it still submits,
 * still validates `required`, and a page without JavaScript still works.
 *
 * Keyboard: Enter, Space or the arrow keys open it; arrows move; Enter picks; Escape closes;
 * typing filters (or jumps to the first match when there is no search field).
 *
 * A `<select multiple data-combobox>` becomes a multi-select: picking an option toggles it and the
 * list stays open; the button lists what is picked. With `data-combobox-create` the search field
 * also offers to add what was typed as a new option (a value no event has carried yet).
 *
 * A picker over something that grows with the customer base (`data-combobox-source="<url>"`) is
 * a type-ahead (L4): the page renders only the chosen option, and what is typed is sent to the
 * URL as `?q=`, which answers `{"results": [{"value": "…", "label": "…"}]}` — the first matches,
 * from an index. Nothing ever lists every customer.
 */

const SEARCH_THRESHOLD = 5;
const REMOTE_DELAY_MS = 180;
// The hosted portal is Danish or English; the console is English.
const DANISH = (document.documentElement.lang || '').toLowerCase().startsWith('da');
const TEXT = DANISH
    ? { search: 'Søg…', searchIn: 'Søg i ', options: 'valg', empty: 'Ingen match.', add: 'Tilføj', none: 'Ingen valgt' }
    : { search: 'Search…', searchIn: 'Search ', options: 'options', empty: 'No matches.', add: 'Add', none: 'None picked' };
let sequence = 0;

function optionsOf(select) {
    const items = [];

    for (const node of select.children) {
        if (node instanceof HTMLOptGroupElement) {
            items.push({ group: node.label });
            for (const option of node.children) {
                items.push({ option });
            }
        } else if (node instanceof HTMLOptionElement) {
            items.push({ option: node });
        }
    }

    return items;
}

function enhance(select) {
    // A multiple select is enhanced only when it asks to be.
    if ((select.multiple && !('combobox' in select.dataset)) || 'native' in select.dataset) {
        return;
    }
    const multi = select.multiple;
    const creatable = 'comboboxCreate' in select.dataset;
    const enhanced = select.parentElement && select.parentElement.classList.contains('cbx-combo');
    if (enhanced && select.dataset.comboboxReady === '1') {
        return;
    }
    if (enhanced) {
        // Its options or attributes were redrawn under it: build it again from what is there now.
        unwrap(select);
    }

    select.dataset.comboboxReady = '1';
    const id = 'cbx-combo-' + (++sequence);
    const items = optionsOf(select);
    const optionCount = items.filter((item) => item.option).length;
    const remote = select.dataset.comboboxSource || '';
    const searchable = creatable || remote !== '' || select.dataset.combobox === 'search' || optionCount > SEARCH_THRESHOLD;

    const wrap = document.createElement('div');
    wrap.className = 'cbx-combo';
    // Keep the select's place in the layout (its width and flex), not its look.
    ['width', 'minWidth', 'maxWidth', 'flex', 'flexGrow', 'flexBasis', 'margin'].forEach((prop) => {
        if (select.style[prop]) {
            wrap.style[prop] = select.style[prop];
        }
    });
    if (select.style.height && parseInt(select.style.height, 10) <= 28) {
        wrap.classList.add('cbx-combo--sm');
    }
    if (select.classList.contains('cbx-input--sm')) {
        wrap.classList.add('cbx-combo--sm');
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'cbx-combo-btn';
    button.setAttribute('role', 'combobox');
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', id + '-list');
    button.disabled = select.disabled;

    // The select's accessible name carries over: its <label>, or its aria-label.
    const label = select.getAttribute('aria-label') || (select.labels && select.labels[0] ? select.labels[0].textContent.trim().split('\n')[0] : '');
    if (label) {
        button.setAttribute('aria-label', label);
    }

    const value = document.createElement('span');
    value.className = 'cbx-combo-value';
    button.append(value);
    button.insertAdjacentHTML('beforeend', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>');

    const pop = document.createElement('div');
    pop.className = 'cbx-combo-pop';
    pop.hidden = true;

    let search = null;
    if (searchable) {
        search = document.createElement('input');
        search.type = 'search';
        search.className = 'cbx-combo-search';
        search.placeholder = TEXT.search;
        search.setAttribute('aria-label', TEXT.searchIn + (label || TEXT.options));
        search.setAttribute('aria-controls', id + '-list');
        search.autocomplete = 'off';
        pop.append(search);
    }

    const list = document.createElement('ul');
    list.className = 'cbx-combo-list';
    list.id = id + '-list';
    list.setAttribute('role', 'listbox');
    if (multi) {
        list.setAttribute('aria-multiselectable', 'true');
    }
    if (label) {
        list.setAttribute('aria-label', label);
    }
    pop.append(list);

    const empty = document.createElement('p');
    empty.className = 'cbx-combo-empty';
    empty.textContent = TEXT.empty;
    empty.hidden = true;
    pop.append(empty);

    // Offers to add what was typed, for a select that takes new values.
    let create = null;
    if (creatable) {
        create = document.createElement('button');
        create.type = 'button';
        create.className = 'cbx-combo-create';
        create.hidden = true;
        pop.append(create);
    }

    const rows = [];

    function build(entries) {
        list.replaceChildren();
        rows.length = 0;
        entries.forEach((item, index) => {
            const li = document.createElement('li');
            if (item.group !== undefined) {
                li.className = 'cbx-combo-group';
                li.setAttribute('role', 'presentation');
                li.textContent = item.group;
                rows.push({ li, group: true });
            } else {
                li.className = 'cbx-combo-opt';
                li.id = id + '-opt-' + index;
                li.setAttribute('role', 'option');
                li.textContent = item.option.textContent.trim();
                if (item.option.value === '') {
                    li.classList.add('is-placeholder');
                }
                if (item.option.disabled) {
                    li.setAttribute('aria-disabled', 'true');
                }
                rows.push({ li, option: item.option });
            }
            list.append(li);
        });
    }

    build(items);

    select.parentNode.insertBefore(wrap, select);
    wrap.append(select, button, pop);
    select.classList.add('cbx-combo-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    let active = -1;

    const visible = () => rows.filter((row) => row.option && !row.li.hidden && !row.option.disabled);

    function sync() {
        const picked = Array.from(select.selectedOptions).filter((option) => option.value !== '');
        const selected = multi ? picked[0] : select.options[select.selectedIndex];
        const text = multi
            ? (picked.length > 0 ? picked.map((option) => option.textContent.trim()).join(', ') : (select.dataset.placeholder || TEXT.none))
            : (selected ? selected.textContent.trim() : '');
        // Only on a change: a write is a mutation, and the observer below would come round again.
        if (value.textContent !== text) {
            value.textContent = text;
        }
        value.classList.toggle('is-placeholder', !selected || selected.value === '');
        rows.forEach((row) => {
            if (row.option) {
                const selectedNow = row.option.selected ? 'true' : 'false';
                if (row.li.getAttribute('aria-selected') !== selectedNow) {
                    row.li.setAttribute('aria-selected', selectedNow);
                }
            }
        });
    }

    function setActive(row) {
        rows.forEach((r) => r.li.classList.remove('is-active'));
        active = row ? rows.indexOf(row) : -1;
        if (row) {
            row.li.classList.add('is-active');
            row.li.scrollIntoView({ block: 'nearest' });
            (search || button).setAttribute('aria-activedescendant', row.li.id);
        } else {
            (search || button).removeAttribute('aria-activedescendant');
        }
    }

    function filter(query) {
        const q = query.trim().toLowerCase();
        let shown = 0;
        rows.forEach((row) => {
            if (row.option) {
                row.li.hidden = q !== '' && !row.li.textContent.toLowerCase().includes(q);
                if (!row.li.hidden) {
                    shown++;
                }
            }
        });
        // A group label shows only while one of its options does.
        rows.forEach((row, i) => {
            if (row.group) {
                let any = false;
                for (let j = i + 1; j < rows.length && !rows[j].group; j++) {
                    any = any || !rows[j].li.hidden;
                }
                row.li.hidden = !any;
            }
        });
        if (create) {
            const typed = query.trim();
            const exists = Array.from(select.options).some((option) => option.value.toLowerCase() === typed.toLowerCase());
            create.hidden = typed === '' || exists;
            create.textContent = TEXT.add + ' “' + typed + '”';
            create.dataset.value = typed;
            shown += create.hidden ? 0 : 1;
        }
        empty.hidden = shown > 0;
        setActive(visible()[0] || null);
    }

    function add(text) {
        if (text === '') {
            return;
        }
        const option = document.createElement('option');
        option.value = text;
        option.textContent = text;
        option.selected = true;
        select.append(option);
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function open() {
        if (button.disabled || !pop.hidden) {
            return;
        }
        document.querySelectorAll('.cbx-combo-pop:not([hidden])').forEach((other) => other.dispatchEvent(new Event('cbx-close')));
        pop.hidden = false;
        wrap.classList.add('is-open');
        button.setAttribute('aria-expanded', 'true');
        if (search) {
            search.value = '';
            remote ? lookup('') : filter('');
            search.focus();
        }
        setActive(rows.find((row) => row.option && row.option.selected) || visible()[0] || null);
    }

    function close(refocus) {
        if (pop.hidden) {
            return;
        }
        pop.hidden = true;
        wrap.classList.remove('is-open');
        button.setAttribute('aria-expanded', 'false');
        setActive(null);
        if (refocus) {
            button.focus();
        }
    }

    function choose(row) {
        if (!row || !row.option || row.option.disabled) {
            return;
        }
        if (multi) {
            // Toggle, and keep the list open for the next pick.
            row.option.selected = !row.option.selected;
            sync();
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }
        const changed = select.value !== row.option.value;
        select.value = row.option.value;
        sync();
        close(true);
        if (changed) {
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function move(delta) {
        const options = visible();
        if (options.length === 0) {
            return;
        }
        const current = options.indexOf(rows[active]);
        const next = current === -1 ? (delta > 0 ? 0 : options.length - 1) : Math.max(0, Math.min(options.length - 1, current + delta));
        setActive(options[next]);
    }

    function keys(event) {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                pop.hidden ? open() : move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                pop.hidden ? open() : move(-1);
                break;
            case 'Home':
                if (!pop.hidden) { event.preventDefault(); setActive(visible()[0] || null); }
                break;
            case 'End':
                if (!pop.hidden) { event.preventDefault(); const v = visible(); setActive(v[v.length - 1] || null); }
                break;
            case 'Enter':
                event.preventDefault();
                if (!pop.hidden && create && !create.hidden && (active === -1 || visible().length === 0)) {
                    add(create.dataset.value || '');
                    break;
                }
                pop.hidden ? open() : choose(rows[active]);
                break;
            case ' ':
                if (event.target === button) {
                    event.preventDefault();
                    pop.hidden ? open() : choose(rows[active]);
                }
                break;
            case 'Escape':
                if (!pop.hidden) {
                    event.preventDefault();
                    event.stopPropagation();
                    close(true);
                }
                break;
            case 'Tab':
                close(false);
                break;
            default:
                // No search field: typing jumps to the first option starting with that letter.
                if (!search && event.key.length === 1 && !event.metaKey && !event.ctrlKey) {
                    const hit = visible().find((row) => row.li.textContent.toLowerCase().startsWith(event.key.toLowerCase()));
                    if (hit) {
                        if (pop.hidden) { choose(hit); } else { setActive(hit); }
                    }
                }
        }
    }

    button.addEventListener('click', () => (pop.hidden ? open() : close(true)));
    button.addEventListener('keydown', keys);
    if (search) {
        search.addEventListener('keydown', keys);
        search.addEventListener('input', () => (remote ? lookup(search.value) : filter(search.value)));
    }

    // A remote picker asks its source for the first matches; the chosen option always stays.
    let lookupTimer = null;
    let lookupSeq = 0;
    function lookup(query) {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(async () => {
            const mine = ++lookupSeq;
            let data = null;
            try {
                const response = await fetch(remote + (remote.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query.trim()), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                data = response.ok ? await response.json() : null;
            } catch (error) {
                data = null;
            }
            if (mine !== lookupSeq || !data || !Array.isArray(data.results)) {
                return;
            }
            const chosen = select.options[select.selectedIndex];
            Array.from(select.options).forEach((option) => {
                if (option !== chosen && option.value !== '') {
                    option.remove();
                }
            });
            data.results.forEach((result) => {
                if (!chosen || chosen.value !== String(result.value)) {
                    const option = new Option(String(result.label), String(result.value));
                    Object.entries(result.data || {}).forEach(([key, value]) => {
                        option.dataset[key] = value === null ? '' : String(value);
                    });
                    select.add(option);
                }
            });
            build(optionsOf(select));
            sync();
            empty.hidden = visible().length > 0;
            setActive(visible()[0] || null);
        }, REMOTE_DELAY_MS);
    }
    if (create) {
        create.addEventListener('mousedown', (event) => event.preventDefault());
        create.addEventListener('click', () => add(create.dataset.value || ''));
    }
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        const li = event.target instanceof Element ? event.target.closest('.cbx-combo-opt') : null;
        choose(rows.find((row) => row.li === li));
    });
    list.addEventListener('mousemove', (event) => {
        const li = event.target instanceof Element ? event.target.closest('.cbx-combo-opt') : null;
        const row = rows.find((r) => r.li === li);
        if (row && rows.indexOf(row) !== active && row.option && !row.option.disabled) {
            setActive(row);
        }
    });
    pop.addEventListener('cbx-close', () => close(false));
    const outside = (event) => {
        if (!wrap.isConnected) {
            document.removeEventListener('pointerdown', outside);
        } else if (!wrap.contains(event.target)) {
            close(false);
        }
    };
    document.addEventListener('pointerdown', outside);
    // Native validation focuses the hidden select: hand focus to the button instead.
    select.addEventListener('focus', () => button.focus());
    select.addEventListener('invalid', () => wrap.classList.add('is-invalid'));
    select.addEventListener('change', () => { sync(); wrap.classList.remove('is-invalid'); });
    if (select.form) {
        select.form.addEventListener('reset', () => setTimeout(sync));
    }

    select.cboxSync = sync;
    sync();
}

/** Put a select back where its combobox was, so it can be built again. */
function unwrap(select) {
    const wrap = select.parentElement;
    wrap.parentNode.insertBefore(select, wrap);
    wrap.remove();
    select.classList.remove('cbx-combo-native');
    select.removeAttribute('aria-hidden');
    select.removeAttribute('tabindex');
    delete select.dataset.comboboxReady;
}

export function enhanceComboboxes(root = document) {
    root.querySelectorAll('select:not([multiple]):not([data-native]), select[multiple][data-combobox]:not([data-native])').forEach(enhance);
}

// For rows a form adds after load (the observer below finds them too).
window.cboxComboboxes = enhanceComboboxes;

function start() {
    enhanceComboboxes();

    // Livewire swaps and patches markup on every round trip: a select it draws, or whose options
    // it changes, is built again on the next frame, so none is ever left as a native select.
    let pending = false;
    const stale = new Set();
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            const node = mutation.target.nodeType === Node.TEXT_NODE ? mutation.target.parentElement : mutation.target;
            const select = node instanceof Element ? node.closest('select') : null;
            // A remote picker changes its own options as it searches: that is not a redraw.
            if (select && select.dataset.comboboxReady === '1' && !select.dataset.comboboxSource) {
                stale.add(select);
            }
        }
        if (pending) {
            return;
        }
        pending = true;
        requestAnimationFrame(() => {
            pending = false;
            stale.forEach((select) => {
                if (select.isConnected && select.parentElement && select.parentElement.classList.contains('cbx-combo')) {
                    unwrap(select);
                }
            });
            stale.clear();
            enhanceComboboxes();
            // A value set from script (Livewire's wire:model) fires no event: show it anyway.
            document.querySelectorAll('select[data-combobox-ready="1"]').forEach((select) => select.cboxSync && select.cboxSync());
        });
    }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['disabled', 'label'] });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
