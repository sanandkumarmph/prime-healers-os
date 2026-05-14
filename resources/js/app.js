import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

const shouldAutoSelectNumberInput = (element) => {
    if (!(element instanceof HTMLInputElement)) {
        return false;
    }

    if (element.type !== 'number') {
        return false;
    }

    if (element.disabled || element.readOnly) {
        return false;
    }

    if (element.dataset.autoSelectNumber === 'off') {
        return false;
    }

    if (element.hasAttribute('data-no-auto-select')) {
        return false;
    }

    if (element.classList.contains('no-auto-select')) {
        return false;
    }

    return true;
};

const selectNumberInputValue = (element) => {
    if (!shouldAutoSelectNumberInput(element)) {
        return;
    }

    window.requestAnimationFrame(() => {
        try {
            element.select();
        } catch (error) {
            // Ignore browsers that do not support selecting this input type.
        }
    });
};

document.addEventListener('focusin', (event) => {
    selectNumberInputValue(event.target);
});

document.addEventListener('click', (event) => {
    if (!shouldAutoSelectNumberInput(event.target)) {
        return;
    }

    if (document.activeElement !== event.target) {
        return;
    }

    selectNumberInputValue(event.target);
});
