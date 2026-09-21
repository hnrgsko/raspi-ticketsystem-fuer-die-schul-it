'use strict';

(() => {
    const root = document.querySelector('[data-development-update-live]');
    if (!(root instanceof HTMLElement)) return;

    const progress = root.querySelector('[data-dev-progress]');
    const progressText = root.querySelector('[data-dev-progress-text]');
    const current = root.querySelector('[data-dev-current]');
    const steps = root.querySelector('[data-dev-steps]');
    const final = root.querySelector('[data-dev-final]');
    const reload = root.querySelector('[data-dev-reload]');

    const render = (status) => {
        const state = String(status.state || 'idle');
        const percent = Math.max(0, Math.min(100, Number(status.progress || 0)));
        const completed = new Set(Array.isArray(status.completed_steps) ? status.completed_steps : []);
        const currentKey = String(status.current_step || '');

        if (progress instanceof HTMLElement) {
            progress.style.width = percent + '%';
            progress.setAttribute('aria-valuenow', String(percent));
        }
        if (progressText instanceof HTMLElement) {
            progressText.textContent = percent + ' %';
        }
        if (current instanceof HTMLElement) {
            current.textContent = String(status.current_label || status.message || 'Bereit');
        }

        if (steps instanceof HTMLElement && Array.isArray(status.steps)) {
            steps.replaceChildren();
            status.steps.forEach((item) => {
                if (!item || typeof item !== 'object') return;
                const key = String(item.key || '');
                const row = document.createElement('li');
                if (completed.has(key)) row.className = 'done';
                else if (key === currentKey && state === 'running') row.className = 'current';

                const icon = document.createElement('span');
                icon.className = 'dev-step-icon';
                icon.textContent = completed.has(key) ? '✓' : (key === currentKey && state === 'running' ? '●' : '○');

                const label = document.createElement('span');
                label.textContent = String(item.label || key);

                row.append(icon, label);
                steps.appendChild(row);
            });
        }

        root.dataset.state = state;

        if (final instanceof HTMLElement) {
            if (state === 'success') {
                final.hidden = false;
                final.className = 'development-update-final success';
                final.textContent = String(status.message || 'Update erfolgreich abgeschlossen.');
            } else if (state === 'failed') {
                final.hidden = false;
                final.className = 'development-update-final error';
                final.textContent = String(status.message || 'Update fehlgeschlagen.');
            } else {
                final.hidden = true;
            }
        }

        if (reload instanceof HTMLElement) {
            reload.hidden = state !== 'success';
        }

        return state;
    };

    const poll = async () => {
        try {
            const response = await fetch('/admin/development-update-status.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });
            if (!response.ok) throw new Error('status unavailable');
            const status = await response.json();
            const state = render(status);
            if (state === 'running') {
                window.setTimeout(poll, 1000);
            }
        } catch (_) {
            if (root.dataset.state === 'running') {
                window.setTimeout(poll, 1500);
            }
        }
    };

    if (root.dataset.state === 'running') {
        poll();
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.querySelector('input[name="action"][value="start_development_update"]')) {
            window.setTimeout(poll, 700);
        }
    });
})();
