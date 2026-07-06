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

document.addEventListener('DOMContentLoaded', initDateFields);
document.addEventListener('DOMContentLoaded', initCardLinks);
document.addEventListener('turbo:load', initDateFields);
document.addEventListener('turbo:load', initCardLinks);
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
    activeRequests = 0;
    setLoaderVisible(false);
});
document.addEventListener('turbo:submit-end', decrementLoader);
document.addEventListener('turbo:fetch-request-error', decrementLoader);
