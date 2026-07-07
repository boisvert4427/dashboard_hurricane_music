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

document.addEventListener('DOMContentLoaded', initDateFields);
document.addEventListener('DOMContentLoaded', initCardLinks);
document.addEventListener('DOMContentLoaded', initChannelSummaryCharts);
document.addEventListener('turbo:load', initDateFields);
document.addEventListener('turbo:load', initCardLinks);
document.addEventListener('turbo:load', initChannelSummaryCharts);
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
