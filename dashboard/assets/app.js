import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

const DATE_VALUE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
let activeRequests = 0;
const channelSummaryCharts = new Map();
const channelSummaryTooltips = new Map();

function getLoader() {
    return document.querySelector('[data-app-loader]');
}

function setLoaderVisible(visible) {
    const loader = getLoader();
    if (!loader) {
        return;
    }

    loader.classList.toggle('is-visible', visible);
    loader.setAttribute('aria-hidden', visible ? 'false' : 'true');
}

function incrementLoader() {
    activeRequests += 1;
    setLoaderVisible(true);
}

function decrementLoader() {
    activeRequests = Math.max(0, activeRequests - 1);
    if (activeRequests === 0) {
        setLoaderVisible(false);
    }
}

function initDateFields() {
    document.querySelectorAll('[data-date-field]').forEach((wrapper) => {
        if (wrapper.dataset.bound === '1') {
            return;
        }

        const textInput = wrapper.querySelector('[data-date-field-input]');
        const nativeInput = wrapper.querySelector('[data-date-field-native]');
        const trigger = wrapper.querySelector('[data-date-field-trigger]');

        if (!textInput || !nativeInput || !trigger) {
            return;
        }

        const syncNativeFromText = () => {
            nativeInput.value = DATE_VALUE_PATTERN.test(textInput.value) ? textInput.value : '';
        };

        const syncTextFromNative = () => {
            textInput.value = nativeInput.value;
            textInput.dispatchEvent(new Event('input', { bubbles: true }));
        };

        syncNativeFromText();

        textInput.addEventListener('input', syncNativeFromText);
        nativeInput.addEventListener('change', syncTextFromNative);
        nativeInput.addEventListener('input', syncTextFromNative);
        trigger.addEventListener('click', () => {
            syncNativeFromText();

            if (typeof nativeInput.showPicker === 'function') {
                nativeInput.showPicker();
                return;
            }

            nativeInput.click();
        });

        wrapper.dataset.bound = '1';
    });
}

function initCardLinks() {
    document.querySelectorAll('[data-card-link]').forEach((card) => {
        if (card.dataset.cardLinkBound === '1') {
            return;
        }

        const navigate = () => {
            const url = card.dataset.cardLink;
            if (url) {
                window.location.href = url;
            }
        };

        card.addEventListener('click', (event) => {
            const target = event.target;
            if (target instanceof Element && target.closest('a, button, input, select, textarea, label')) {
                return;
            }

            navigate();
        });

        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                const target = event.target;
                if (target instanceof Element && target.closest('a, button, input, select, textarea, label')) {
                    return;
                }

                event.preventDefault();
                navigate();
            }
        });

        card.dataset.cardLinkBound = '1';
    });
}

function initCompetitiveValidation() {
    const form = document.getElementById('validation-bulk-form');
    if (!form || form.dataset.validationBound === '1') {
        return;
    }

    const statusInputs = Array.from(form.querySelectorAll('[data-validation-status-value]'));

    const syncStatusButtons = (container, value) => {
        container.querySelectorAll('[data-validation-status-button]').forEach((button) => {
            const isActive = button.getAttribute('data-validation-status-button') === value;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    const setAllStatuses = (value) => {
        statusInputs.forEach((input) => {
            input.value = value;
            const container = input.nextElementSibling;
            if (container && container.classList.contains('validation-status-group')) {
                syncStatusButtons(container, value);
            }
        });
    };

    form.querySelectorAll('[data-validation-bulk]').forEach((button) => {
        button.addEventListener('click', () => {
            setAllStatuses(button.getAttribute('data-validation-bulk'));
        });
    });

    form.querySelectorAll('[data-validation-status-button]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.getAttribute('data-validation-status-button');
            const group = button.closest('.validation-status-group');
            const input = group ? group.previousElementSibling : null;

            if (!input || !input.matches('[data-validation-status-value]')) {
                return;
            }

            input.value = value;
            syncStatusButtons(group, value);
        });
    });

    form.dataset.validationBound = '1';
}

function initChannelSummaryCharts() {
    if (typeof window.Chart === 'undefined') {
        return;
    }

    document.querySelectorAll('[data-channel-summary-chart]').forEach((wrapper) => {
        if (!(wrapper instanceof HTMLElement)) {
            return;
        }

        const canvas = wrapper.querySelector('[data-channel-summary-canvas]');
        if (!(canvas instanceof HTMLCanvasElement)) {
            return;
        }

        const rawData = wrapper.dataset.channelSummaryData || '[]';
        let channels = [];

        try {
            channels = JSON.parse(rawData);
        } catch (error) {
            channels = [];
        }

        const existingChart = channelSummaryCharts.get(canvas);
        if (existingChart) {
            existingChart.destroy();
            channelSummaryCharts.delete(canvas);
        }

        if (!channels.length) {
            return;
        }

        const labels = channels.map((channel) => channel.label ?? '');
        const values = channels.map((channel) => Number(channel.current ?? 0));
        const colors = channels.map((channel) => channel.dot_color ?? '#94a3b8');
        const tooltipId = `channel-summary-tooltip-${Math.random().toString(36).slice(2, 10)}`;
        let tooltipEl = channelSummaryTooltips.get(canvas);

        if (!tooltipEl) {
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'channel-summary-tooltip';
            tooltipEl.id = tooltipId;
            tooltipEl.setAttribute('role', 'tooltip');
            tooltipEl.setAttribute('aria-hidden', 'true');
            document.body.appendChild(tooltipEl);
            channelSummaryTooltips.set(canvas, tooltipEl);
        }

        const chart = new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                interaction: {
                    mode: 'nearest',
                    intersect: true,
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        enabled: false,
                        external(context) {
                            const { chart, tooltip } = context;
                            const el = channelSummaryTooltips.get(chart.canvas);
                            if (!el) {
                                return;
                            }

                            if (!tooltip || tooltip.opacity === 0) {
                                el.style.opacity = '0';
                                el.setAttribute('aria-hidden', 'true');
                                return;
                            }

                            const channel = channels[tooltip.dataPoints?.[0]?.dataIndex ?? 0] || {};
                            const title = channel.label ?? tooltip.title?.[0] ?? '';
                            const amount = channel.value ?? tooltip.dataPoints?.[0]?.formattedValue ?? '';
                            const share = channel.share_global_value ?? '';

                            el.innerHTML = `
                                <div class="channel-summary-tooltip__title">${title}</div>
                                <div class="channel-summary-tooltip__value">${amount}</div>
                                <div class="channel-summary-tooltip__meta">${share}</div>
                            `;

                            const rect = chart.canvas.getBoundingClientRect();
                            const x = rect.left + tooltip.caretX;
                            const y = rect.top + tooltip.caretY;
                            el.style.opacity = '1';
                            el.setAttribute('aria-hidden', 'false');
                            el.style.left = `${x}px`;
                            el.style.top = `${y}px`;
                            el.style.transform = 'translate(-50%, -115%)';
                        },
                        callbacks: {
                            label(context) {
                                const channel = channels[context.dataIndex] || {};
                                const amount = channel.value ?? context.formattedValue;
                                const share = channel.share_global_value ?? '';
                                return share ? `${context.label}: ${amount} (${share})` : `${context.label}: ${amount}`;
                            },
                        },
                    },
                },
                animation: {
                    duration: 300,
                },
            },
        });

        channelSummaryCharts.set(canvas, chart);
    });
}

function initDetailDrilldowns() {
    const primarySelect = document.querySelector('[data-primary-group-select]');
    const secondarySelect = document.querySelector('[data-secondary-group-select]');

    if (primarySelect && secondarySelect && !primarySelect.dataset.secondarySyncBound) {
        const equivalentSecondary = {
            idart: 'product',
            brand: 'brand',
            channel: 'channel',
            category: 'category',
        };
        const synchronizeGroupOptions = () => {
            const forbiddenValue = equivalentSecondary[primarySelect.value] || '';
            Array.from(secondarySelect.options).forEach((option) => {
                option.disabled = option.value !== '' && option.value === forbiddenValue;
            });
            if (secondarySelect.value === forbiddenValue) {
                secondarySelect.value = '';
            }
        };

        primarySelect.dataset.secondarySyncBound = 'true';
        primarySelect.addEventListener('change', synchronizeGroupOptions);
        synchronizeGroupOptions();
    }

    const numberFormatter = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
    const percentFormatter = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    const createComparisonCell = (current, previous, suffix = '') => {
        const cell = document.createElement('div');
        cell.className = 'comparison-cell';

        const currentLine = document.createElement('strong');
        currentLine.textContent = `${numberFormatter.format(Number(current) || 0)}${suffix}`;
        cell.appendChild(currentLine);

        const previousLine = document.createElement('small');
        previousLine.append(`N-1 ${numberFormatter.format(Number(previous) || 0)}${suffix}`);
        const progression = document.createElement('span');
        progression.className = 'metric-progression delta-neutral';
        if (Number(previous)) {
            const delta = ((Number(current) - Number(previous)) / Math.abs(Number(previous))) * 100;
            progression.textContent = `${delta > 0 ? '+' : ''}${percentFormatter.format(delta)} %`;
            progression.className = `metric-progression ${delta > 0 ? 'delta-up' : (delta < 0 ? 'delta-down' : 'delta-neutral')}`;
        } else {
            progression.textContent = '--';
        }
        previousLine.appendChild(progression);
        cell.appendChild(previousLine);

        return cell;
    };

    const renderDrilldown = (container, rows, sortKey = 'total_ht', sortDirection = 'desc') => {
        container.replaceChildren();
        if (!rows.length) {
            const empty = document.createElement('p');
            empty.className = 'detail-drilldown__message';
            empty.textContent = 'Aucun détail pour cette ligne.';
            container.appendChild(empty);
            return;
        }

        const head = document.createElement('div');
        head.className = 'detail-drilldown__head';
        const columns = [
            { key: 'label', label: 'Deuxième niveau', type: 'text' },
            { key: 'quantity', label: 'Qté', type: 'number' },
            { key: 'previous_quantity', label: 'Qté N-1', type: 'number' },
            { key: 'quantity_delta', label: 'Var. Qté', type: 'delta', current: 'quantity', previous: 'previous_quantity' },
            { key: 'total_ht', label: 'CA HT', type: 'number' },
            { key: 'previous_total_ht', label: 'CA N-1', type: 'number' },
            { key: 'total_ht_delta', label: 'Var. CA', type: 'delta', current: 'total_ht', previous: 'previous_total_ht' },
            { key: 'margin_ht', label: 'Marge', type: 'number' },
            { key: 'previous_margin_ht', label: 'Marge N-1', type: 'number' },
            { key: 'margin_ht_delta', label: 'Var. marge', type: 'delta', current: 'margin_ht', previous: 'previous_margin_ht' },
            { key: 'stock_pamp_value', label: 'Valeur stock', type: 'number' },
            { key: 'previous_stock_pamp_value', label: 'Stock N-1', type: 'number' },
            { key: 'stock_delta', label: 'Var. stock', type: 'delta', current: 'stock_pamp_value', previous: 'previous_stock_pamp_value' },
        ];
        columns.forEach((column) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `detail-drilldown__sort${sortKey === column.key ? ' is-active' : ''}`;
            button.textContent = `${column.label} ${sortKey === column.key ? (sortDirection === 'asc' ? '▲' : '▼') : '↕'}`;
            button.setAttribute('aria-label', `Trier par ${column.label}`);
            button.addEventListener('click', () => {
                const nextDirection = sortKey === column.key && sortDirection === 'desc' ? 'asc' : 'desc';
                renderDrilldown(container, rows, column.key, nextDirection);
            });
            head.appendChild(button);
        });
        container.appendChild(head);

        const selectedColumn = columns.find((column) => column.key === sortKey) || columns[2];
        const sortedRows = [...rows].sort((left, right) => {
            let comparison;
            if (selectedColumn.type === 'text') {
                comparison = String(left[sortKey] || left.key || '').localeCompare(
                    String(right[sortKey] || right.key || ''),
                    'fr',
                    { sensitivity: 'base', numeric: true },
                );
            } else {
                const value = (row) => {
                    if (selectedColumn.type !== 'delta') {
                        return Number(row[sortKey]) || 0;
                    }
                    const previous = Number(row[selectedColumn.previous]) || 0;
                    return previous ? ((Number(row[selectedColumn.current]) - previous) / Math.abs(previous)) * 100 : -Infinity;
                };
                comparison = value(left) - value(right);
            }
            return sortDirection === 'asc' ? comparison : -comparison;
        });

        sortedRows.forEach((row) => {
            const item = document.createElement('div');
            item.className = 'detail-drilldown__item';
            const identity = document.createElement('div');
            identity.className = 'detail-drilldown__identity';
            const label = document.createElement('strong');
            label.textContent = row.label || row.key || 'Sans libellé';
            identity.appendChild(label);
            if (row.code && row.code !== row.label) {
                const code = document.createElement('small');
                code.textContent = row.code;
                identity.appendChild(code);
            }
            item.appendChild(identity);
            const appendValue = (value, suffix = '', previous = false) => {
                const cell = document.createElement('strong');
                cell.className = previous ? 'metric-previous' : 'metric-current';
                cell.textContent = `${numberFormatter.format(Number(value) || 0)}${suffix}`;
                item.appendChild(cell);
            };
            const appendDelta = (current, previous) => {
                const delta = document.createElement('span');
                delta.className = 'metric-progression delta-neutral';
                if (Number(previous)) {
                    const value = ((Number(current) - Number(previous)) / Math.abs(Number(previous))) * 100;
                    delta.textContent = `${value > 0 ? '+' : ''}${percentFormatter.format(value)} %`;
                    delta.className = `metric-progression ${value > 0 ? 'delta-up' : (value < 0 ? 'delta-down' : 'delta-neutral')}`;
                } else {
                    delta.textContent = '--';
                }
                item.appendChild(delta);
            };
            appendValue(row.quantity);
            appendValue(row.previous_quantity, '', true);
            appendDelta(row.quantity, row.previous_quantity);
            appendValue(row.total_ht, ' €');
            appendValue(row.previous_total_ht, ' €', true);
            appendDelta(row.total_ht, row.previous_total_ht);
            appendValue(row.margin_ht, ' €');
            appendValue(row.previous_margin_ht, ' €', true);
            appendDelta(row.margin_ht, row.previous_margin_ht);
            appendValue(row.stock_pamp_value, ' €');
            appendValue(row.previous_stock_pamp_value, ' €', true);
            appendDelta(row.stock_pamp_value, row.previous_stock_pamp_value);
            container.appendChild(item);
        });
    };

    document.querySelectorAll('[data-detail-drilldown-url]').forEach((row) => {
        if (row.dataset.drilldownBound) {
            return;
        }
        row.dataset.drilldownBound = 'true';

        const toggle = async () => {
            const detailRow = row.nextElementSibling;
            const container = detailRow?.querySelector('[data-detail-drilldown-content]');
            if (!detailRow || !container) {
                return;
            }

            const opening = detailRow.hidden;
            detailRow.hidden = !opening;
            row.classList.toggle('is-expanded', opening);
            row.setAttribute('aria-expanded', opening ? 'true' : 'false');
            if (!opening || container.dataset.loaded || container.dataset.loading) {
                return;
            }

            container.dataset.loading = 'true';
            container.textContent = 'Chargement du détail…';
            incrementLoader();
            try {
                const response = await fetch(row.dataset.detailDrilldownUrl, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Réponse invalide');
                }
                renderDrilldown(container, payload.rows || []);
                container.dataset.loaded = 'true';
            } catch (error) {
                container.textContent = 'Impossible de charger ce deuxième niveau.';
                container.classList.add('detail-drilldown--error');
            } finally {
                delete container.dataset.loading;
                decrementLoader();
            }
        };

        row.addEventListener('click', (event) => {
            if (!event.target.closest('a, button, input, select')) {
                toggle();
            }
        });
        row.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initDateFields);
document.addEventListener('DOMContentLoaded', initCardLinks);
document.addEventListener('DOMContentLoaded', initCompetitiveValidation);
document.addEventListener('DOMContentLoaded', initChannelSummaryCharts);
document.addEventListener('DOMContentLoaded', initDetailDrilldowns);
document.addEventListener('turbo:load', initDateFields);
document.addEventListener('turbo:load', initCardLinks);
document.addEventListener('turbo:load', initCompetitiveValidation);
document.addEventListener('turbo:load', initChannelSummaryCharts);
document.addEventListener('turbo:load', initDetailDrilldowns);
document.addEventListener('turbo:before-fetch-request', incrementLoader);
document.addEventListener('turbo:submit-start', incrementLoader);
document.addEventListener('turbo:load', () => {
    activeRequests = 0;
    setLoaderVisible(false);
});
document.addEventListener('turbo:render', () => {
    if (activeRequests === 0) {
        setLoaderVisible(false);
    }
});
document.addEventListener('turbo:before-cache', () => {
    channelSummaryCharts.forEach((chart) => chart.destroy());
    channelSummaryCharts.clear();
    channelSummaryTooltips.forEach((tooltip) => tooltip.remove());
    channelSummaryTooltips.clear();
    activeRequests = 0;
    setLoaderVisible(false);
});
document.addEventListener('turbo:submit-end', decrementLoader);
document.addEventListener('turbo:fetch-request-error', decrementLoader);
