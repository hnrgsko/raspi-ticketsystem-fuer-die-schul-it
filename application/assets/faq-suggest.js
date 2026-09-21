'use strict';

(() => {
    const form = document.querySelector('form[data-support-ticket-form]');
    const panel = document.getElementById('faq-ticket-suggestions');
    if (!form || !panel) return;

    const description = form.querySelector('#description');
    const category = form.querySelector('#category_id');
    const device = form.querySelector('#device');
    const list = panel.querySelector('[data-faq-suggestion-list]');
    const status = panel.querySelector('[data-faq-search-status]');
    const csrf = document.body?.dataset.usageCsrf || '';

    if (!(description instanceof HTMLTextAreaElement)
        || !(category instanceof HTMLSelectElement)
        || !(device instanceof HTMLInputElement)
        || !(list instanceof HTMLElement)
        || !(status instanceof HTMLElement)
        || !csrf) return;

    let timer = 0;
    let controller = null;
    let signature = '';

    const usage = (metric) => {
        document.dispatchEvent(new CustomEvent('schulit-usage', {detail: {metric}}));
    };

    const showStatus = (message, kind = 'muted') => {
        status.textContent = message;
        status.className = 'faq-search-status ' + kind;
        status.hidden = false;
        panel.hidden = false;
    };

    const clear = () => {
        list.replaceChildren();
        status.textContent = '';
        status.hidden = true;
        panel.hidden = true;
    };

    const makeSuggestion = (item) => {
        const details = document.createElement('details');
        details.className = 'faq-ticket-suggestion';

        const summary = document.createElement('summary');
        summary.textContent = String(item.question || '');
        details.appendChild(summary);

        const body = document.createElement('div');
        body.className = 'faq-ticket-suggestion-answer';

        if (item.category_name) {
            const categoryLabel = document.createElement('span');
            categoryLabel.className = 'label';
            categoryLabel.textContent = String(item.category_name);
            body.appendChild(categoryLabel);
        }

        const answer = document.createElement('p');
        answer.textContent = String(item.answer || '');
        body.appendChild(answer);

        const helpful = document.createElement('button');
        helpful.type = 'button';
        helpful.className = 'secondary faq-helpful-button';
        helpful.textContent = 'Das hat geholfen – kein Ticket nötig';
        helpful.addEventListener('click', () => {
            usage('faq_suggestion_helpful');
            window.location.assign('/');
        });
        body.appendChild(helpful);
        details.appendChild(body);

        let opened = false;
        details.addEventListener('toggle', () => {
            if (details.open && !opened) {
                opened = true;
                usage('faq_suggestion_open');
            }
        });

        return details;
    };

    const search = async () => {
        const q = [device.value.trim(), description.value.trim()].filter(Boolean).join(' ');
        if (q.length < 4) {
            clear();
            return;
        }

        const nextSignature = category.value + '\n' + q;
        if (nextSignature === signature) return;
        signature = nextSignature;

        if (controller) controller.abort();
        controller = new AbortController();

        const data = new FormData();
        data.append('csrf', csrf);
        data.append('category_id', category.value);
        data.append('q', q);

        try {
            list.replaceChildren();
            showStatus('Passende FAQ werden gesucht …', 'muted');

            const response = await fetch('/faq-suggest.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                signal: controller.signal
            });
            if (!response.ok) {
                showStatus('Die FAQ-Suche ist gerade nicht verfügbar. Du kannst das Ticket trotzdem normal absenden.', 'warning');
                return;
            }
            const result = await response.json();
            const suggestions = Array.isArray(result.suggestions) ? result.suggestions : [];
            list.replaceChildren();

            if (suggestions.length === 0) {
                showStatus('Keine passende veröffentlichte FAQ gefunden.', 'muted');
                return;
            }

            status.hidden = true;
            suggestions.forEach((item) => list.appendChild(makeSuggestion(item)));
            panel.hidden = false;
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            showStatus('Die FAQ-Suche ist gerade nicht verfügbar. Du kannst das Ticket trotzdem normal absenden.', 'warning');
        }
    };

    const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 450);
    };

    description.addEventListener('input', schedule);
    device.addEventListener('input', schedule);
    category.addEventListener('change', schedule);
})();
