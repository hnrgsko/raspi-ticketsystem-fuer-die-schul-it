'use strict';

(() => {
    const widget = document.getElementById('assistant-widget');
    const slot = document.getElementById('assistant-widget-frame');
    const close = document.getElementById('assistant-widget-close');

    if (!widget || !slot || !close) return;

    const summary = widget.querySelector('summary');
    const url = slot.dataset.chatUrl || '';

    // Defense in depth: the same URL shape is validated server-side.
    const aisDialog = /^https:\/\/app\.ais-chat\.schule\/ua\/characters\/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\/dialog\?inviteCode=[A-Za-z0-9_-]{1,128}$/;
    if (!aisDialog.test(url) || /\s/.test(url)) return;

    close.hidden = false;
    let loaded = false;
    let opener = summary;

    const loadFrame = () => {
        if (loaded) return;
        const frame = document.createElement('iframe');
        frame.title = 'AIS.chat KI-Assistent';
        frame.referrerPolicy = 'no-referrer';
        frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups');
        frame.src = url;
        slot.appendChild(frame);
        loaded = true;
    };

    widget.addEventListener('toggle', () => {
        if (widget.open) loadFrame();
    });

    document.querySelectorAll('[data-assistant-open]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            opener = link;
            widget.open = true;
            loadFrame();
            close.focus();
        });
    });

    if (summary) {
        summary.addEventListener('click', () => {
            opener = summary;
        });
    }

    const dismiss = () => {
        widget.open = false;
        if (opener && typeof opener.focus === 'function') opener.focus();
    };

    close.addEventListener('click', dismiss);
    widget.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && widget.open) {
            event.preventDefault();
            dismiss();
        }
    });
})();
