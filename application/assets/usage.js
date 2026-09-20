'use strict';

(() => {
    const root = document.body;
    const csrf = root?.dataset.usageCsrf || '';
    if (!csrf) return;

    const record = (metric) => {
        if (!['assistant_inline_use', 'assistant_bubble_open', 'assistant_external_open'].includes(metric)) return;
        const data = new FormData();
        data.append('csrf', csrf);
        data.append('metric', metric);

        if (navigator.sendBeacon) {
            navigator.sendBeacon('/usage.php', data);
            return;
        }

        fetch('/usage.php', {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            keepalive: true
        }).catch(() => {});
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element
            ? event.target.closest('[data-usage-event]')
            : null;
        if (!target) return;
        const metric = target.getAttribute('data-usage-event') || '';
        record(metric);
    });

    document.addEventListener('schulit-usage', (event) => {
        const metric = event.detail && typeof event.detail.metric === 'string'
            ? event.detail.metric
            : '';
        record(metric);
    });

    let inlineFocusCounted = false;
    window.addEventListener('blur', () => {
        if (inlineFocusCounted) return;
        window.setTimeout(() => {
            const active = document.activeElement;
            if (active instanceof HTMLIFrameElement
                && active.dataset.usageFocus === 'assistant_inline_use') {
                inlineFocusCounted = true;
                record('assistant_inline_use');
            }
        }, 0);
    });
})();
