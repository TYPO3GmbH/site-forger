/**
 * The name of a sprint board is edited in its title, see templates/sprint/_board_header.html.twig: the pen beside
 * it ("data-rename-trigger"), or a click or "Enter" on the name ("data-rename"), turns it into a field. "Enter" or
 * leaving the field saves the name at the address of "data-rename", "Escape" keeps the old one. The address of
 * the board follows its name, so the page goes on to the new one.
 */
function edit(name: HTMLElement): void {
    const old = (name.textContent ?? '').trim();
    const field = Object.assign(document.createElement('input'), {
        type: 'text',
        className: 'sprint-board-name-field',
        value: old,
        maxLength: 200,
    });
    field.setAttribute('aria-label', 'Name of the board');
    const pen = name.parentElement?.querySelector<HTMLElement>('[data-rename-trigger]') ?? null;
    name.hidden = true;
    if (pen !== null) {
        pen.hidden = true;
    }
    name.after(field);
    field.focus();
    field.select();

    let done = false;
    const close = (text: string): void => {
        done = true;
        name.textContent = text;
        name.hidden = false;
        if (pen !== null) {
            pen.hidden = false;
        }
        field.remove();
    };
    const save = async (): Promise<void> => {
        if (done) {
            return;
        }
        const value = field.value.trim();
        if (value === old || value === '') {
            close(old);
            return;
        }
        done = true;
        field.disabled = true;
        try {
            const body = new URLSearchParams({ name: value, _token: name.dataset.renameToken ?? '' });
            const response = await fetch(name.dataset.rename ?? '', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const answer = await response.json() as { name?: string; url?: string; error?: string };
            if (!response.ok || answer.name === undefined) {
                throw new Error(answer.error ?? 'The name was not saved.');
            }
            // The address of the board follows its name: go on there, where every link of the page fits again
            if (answer.url !== undefined && answer.url !== window.location.pathname) {
                window.location.replace(answer.url + window.location.search);
                return;
            }
            close(answer.name);
            document.title = document.title.replace(old, answer.name);
            const crumb = document.querySelector('.breadcrumb [aria-current="page"]');
            if (crumb !== null && (crumb.textContent ?? '').trim() === old) {
                crumb.textContent = answer.name;
            }
        } catch (error) {
            close(old);
            name.title = error instanceof Error ? error.message : 'The name was not saved.';
            name.classList.add('is-failed');
        }
    };

    field.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            void save();
        } else if (event.key === 'Escape') {
            close(old);
        }
    });
    field.addEventListener('blur', () => void save());
}

function start(event: Event): void {
    const target = event.target as Element | null;
    // The pen beside the name, or the name itself
    const pen = target?.closest('[data-rename-trigger]');
    const name = pen?.parentElement?.querySelector<HTMLElement>('[data-rename]')
        ?? target?.closest<HTMLElement>('[data-rename]');
    if (name === null || name === undefined) {
        return;
    }
    if (event instanceof KeyboardEvent && event.key !== 'Enter') {
        return;
    }
    event.preventDefault();
    name.classList.remove('is-failed');
    edit(name);
}

document.addEventListener('click', start);
document.addEventListener('keydown', start);

// A module of its own, so its functions do not meet those of other scripts
export {};
