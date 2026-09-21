'use strict';

(() => {
    const root = document.querySelector('[data-stats-charts]');
    if (!(root instanceof HTMLElement)) return;

    let payload = null;
    try {
        payload = JSON.parse(root.dataset.statsCharts || '');
    } catch (_) {
        return;
    }
    if (!payload || typeof payload !== 'object') return;

    const ns = 'http://www.w3.org/2000/svg';

    const svgEl = (name, attrs = {}) => {
        const node = document.createElementNS(ns, name);
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
        return node;
    };

    const renderEmpty = (target) => {
        target.replaceChildren();
        const note = document.createElement('p');
        note.className = 'stats-chart-empty';
        note.textContent = 'Noch nicht genügend Daten für eine grafische Darstellung.';
        target.appendChild(note);
    };

    const renderLine = (target, rows, series, title) => {
        target.replaceChildren();
        if (!Array.isArray(rows) || rows.length === 0) {
            renderEmpty(target);
            return;
        }

        const width = 760;
        const height = 270;
        const left = 44;
        const right = 18;
        const top = 18;
        const bottom = 42;
        const plotW = width - left - right;
        const plotH = height - top - bottom;

        const values = [];
        rows.forEach((row) => series.forEach((item) => values.push(Number(row[item.key] || 0))));
        let max = Math.max(1, ...values);
        max = Math.ceil(max * 1.15);
        if (max < 4) max = 4;

        const svg = svgEl('svg', {
            viewBox: `0 0 ${width} ${height}`,
            role: 'img',
            'aria-label': title,
            class: 'stats-line-svg'
        });

        for (let i = 0; i <= 4; i += 1) {
            const y = top + (plotH * i / 4);
            const value = Math.round(max * (1 - i / 4));
            svg.appendChild(svgEl('line', {
                x1: left, y1: y, x2: width - right, y2: y,
                class: 'stats-grid-line'
            }));
            const label = svgEl('text', {
                x: left - 8, y: y + 4,
                'text-anchor': 'end',
                class: 'stats-axis-label'
            });
            label.textContent = String(value);
            svg.appendChild(label);
        }

        const xFor = (index) => rows.length <= 1
            ? left + plotW / 2
            : left + (plotW * index / (rows.length - 1));
        const yFor = (value) => top + plotH - (Math.max(0, value) / max * plotH);

        series.forEach((item, seriesIndex) => {
            const points = rows.map((row, index) => {
                const value = Number(row[item.key] || 0);
                return [xFor(index), yFor(value), value];
            });
            const polyline = svgEl('polyline', {
                points: points.map((p) => `${p[0]},${p[1]}`).join(' '),
                class: `stats-line stats-line-${seriesIndex + 1}`,
                fill: 'none'
            });
            svg.appendChild(polyline);

            points.forEach(([x, y, value], index) => {
                const dot = svgEl('circle', {
                    cx: x, cy: y, r: value > 0 ? 3.2 : 2,
                    class: `stats-dot stats-dot-${seriesIndex + 1}`
                });
                const tooltip = svgEl('title');
                tooltip.textContent = `${rows[index].label}: ${item.label} ${value}`;
                dot.appendChild(tooltip);
                svg.appendChild(dot);
            });
        });

        const labelIndexes = new Set([0, rows.length - 1]);
        if (rows.length > 2) labelIndexes.add(Math.floor((rows.length - 1) / 2));
        labelIndexes.forEach((index) => {
            const text = svgEl('text', {
                x: xFor(index),
                y: height - 13,
                'text-anchor': index === 0 ? 'start' : index === rows.length - 1 ? 'end' : 'middle',
                class: 'stats-axis-label'
            });
            text.textContent = rows[index].label;
            svg.appendChild(text);
        });

        target.appendChild(svg);

        if (series.length > 1) {
            const legend = document.createElement('div');
            legend.className = 'stats-chart-legend';
            series.forEach((item, index) => {
                const row = document.createElement('span');
                const swatch = document.createElement('i');
                swatch.className = `stats-legend-swatch stats-tone-${index + 1}`;
                row.append(swatch, document.createTextNode(item.label));
                legend.appendChild(row);
            });
            target.appendChild(legend);
        }
    };

    const renderBars = (target, rows, title) => {
        target.replaceChildren();
        if (!Array.isArray(rows) || rows.length === 0) {
            renderEmpty(target);
            return;
        }
        const max = Math.max(1, ...rows.map((row) => Number(row.value || 0)));
        const wrap = document.createElement('div');
        wrap.className = 'stats-bars';
        wrap.setAttribute('role', 'img');
        wrap.setAttribute('aria-label', title);

        rows.forEach((row, index) => {
            const item = document.createElement('div');
            item.className = 'stats-bar-row';

            const label = document.createElement('div');
            label.className = 'stats-bar-label';
            label.textContent = String(row.label || '');

            const track = document.createElement('div');
            track.className = 'stats-bar-track';
            const bar = document.createElement('div');
            bar.className = `stats-bar stats-tone-${(index % 4) + 1}`;
            const value = Number(row.value || 0);
            bar.style.width = `${value === 0 ? 0 : Math.max(4, value / max * 100)}%`;
            track.appendChild(bar);

            const count = document.createElement('strong');
            count.className = 'stats-bar-value';
            count.textContent = String(value);

            item.append(label, track, count);
            wrap.appendChild(item);
        });

        target.appendChild(wrap);
    };

    const renderStacked = (target, rows, title) => {
        target.replaceChildren();
        if (!Array.isArray(rows) || rows.length === 0) {
            renderEmpty(target);
            return;
        }
        const total = rows.reduce((sum, row) => sum + Number(row.value || 0), 0);
        const wrap = document.createElement('div');
        wrap.className = 'stats-stack-wrap';
        wrap.setAttribute('role', 'img');
        wrap.setAttribute('aria-label', title);

        const bar = document.createElement('div');
        bar.className = 'stats-stack';
        rows.forEach((row, index) => {
            const segment = document.createElement('div');
            segment.className = `stats-stack-segment stats-tone-${(index % 4) + 1}`;
            segment.style.width = total > 0 ? `${Number(row.value || 0) / total * 100}%` : '0%';
            segment.title = `${row.label}: ${row.value}`;
            bar.appendChild(segment);
        });

        const legend = document.createElement('div');
        legend.className = 'stats-stack-legend';
        rows.forEach((row, index) => {
            const item = document.createElement('div');
            const swatch = document.createElement('i');
            swatch.className = `stats-legend-swatch stats-tone-${(index % 4) + 1}`;
            const text = document.createElement('span');
            text.textContent = String(row.label || '');
            const count = document.createElement('strong');
            count.textContent = String(Number(row.value || 0));
            item.append(swatch, text, count);
            legend.appendChild(item);
        });

        wrap.append(bar, legend);
        target.appendChild(wrap);
    };

    const ticketTrend = root.querySelector('[data-chart="ticket-trend"]');
    const ticketStatus = root.querySelector('[data-chart="ticket-status"]');
    const assistant = root.querySelector('[data-chart="assistant"]');
    const faq = root.querySelector('[data-chart="faq"]');
    const selfServiceTrend = root.querySelector('[data-chart="self-service-trend"]');

    if (ticketTrend instanceof HTMLElement) {
        renderLine(ticketTrend, payload.daily || [], [
            {key: 'tickets', label: 'Neue Tickets'}
        ], 'Neue Tickets in den letzten 30 Tagen');
    }
    if (ticketStatus instanceof HTMLElement) {
        renderStacked(ticketStatus, payload.ticketStatus || [], 'Offene und erledigte Tickets');
    }
    if (assistant instanceof HTMLElement) {
        renderBars(assistant, payload.assistant30 || [], 'KI-Nutzung in den letzten 30 Tagen');
    }
    if (faq instanceof HTMLElement) {
        renderBars(faq, payload.faq30 || [], 'FAQ-Self-Service in den letzten 30 Tagen');
    }
    if (selfServiceTrend instanceof HTMLElement) {
        renderLine(selfServiceTrend, payload.daily || [], [
            {key: 'faqOpened', label: 'FAQ-Vorschlag geöffnet'},
            {key: 'faqHelpful', label: 'FAQ hat geholfen'}
        ], 'FAQ-Self-Service Verlauf in den letzten 30 Tagen');
    }
})();
