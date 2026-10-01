const disabledInputClasses = ['bg-slate-100', 'text-slate-500', 'cursor-not-allowed'];

const setInputDisabledAppearance = (input, disabled) => {
    disabledInputClasses.forEach((className) => input.classList.toggle(className, disabled));
    input.classList.toggle('bg-white', !disabled);
};

const syncFreeMinutesState = (item) => {
    const checkbox = item.querySelector('.no-free-minutes-checkbox');
    const input = item.querySelector('.free-minutes-input');
    const isFree = item.querySelector('.free-parking-checkbox')?.checked ?? false;

    if (!checkbox || !input) {
        return;
    }

    input.disabled = isFree;
    input.readOnly = checkbox.checked;
    checkbox.disabled = isFree;
    setInputDisabledAppearance(input, isFree || checkbox.checked);

    if (isFree || checkbox.checked) {
        input.value = '0';
    }
};

const syncMaxRateState = (item) => {
    const checkbox = item.querySelector('.no-max-rate-checkbox');
    const input = item.querySelector('.max-rate-input');
    const conditionInputs = item.querySelectorAll('.max-rate-condition-input');
    const isFree = item.querySelector('.free-parking-checkbox')?.checked ?? false;

    if (!checkbox || !input) {
        return;
    }

    input.disabled = isFree || checkbox.checked;
    checkbox.disabled = isFree;
    setInputDisabledAppearance(input, isFree || checkbox.checked);

    if (isFree || checkbox.checked) {
        input.value = '';
    }

    conditionInputs.forEach((conditionInput) => {
        conditionInput.disabled = isFree || checkbox.checked;
        setInputDisabledAppearance(conditionInput, isFree || checkbox.checked);
    });
};

const syncCustomMaxRatePeriodState = (item) => {
    const periodInput = item.querySelector('[data-rate-field="max_rate_period"]');
    const customPeriodContainer = item.querySelector('.custom-max-rate-period-input');
    const customPeriodInput = item.querySelector('[data-rate-field="max_rate_period_minutes"]');
    const isFree = item.querySelector('.free-parking-checkbox')?.checked ?? false;
    const hasNoMaxRate = item.querySelector('.no-max-rate-checkbox')?.checked ?? false;

    if (!periodInput || !customPeriodContainer || !customPeriodInput) {
        return;
    }

    const isCustomPeriod = periodInput.value === 'entry_custom_hours';
    customPeriodContainer.classList.toggle('hidden', !isCustomPeriod);
    customPeriodInput.disabled = isFree || hasNoMaxRate || !isCustomPeriod;
    setInputDisabledAppearance(customPeriodInput, isFree || hasNoMaxRate);
};

const syncFreeParkingState = (item) => {
    const checkbox = item.querySelector('.free-parking-checkbox');
    const input = item.querySelector('.rate-input');
    const unitMinutesInput = item.querySelector('.rate-unit-minutes-input');
    const unitMinutesValue = item.querySelector('.free-rate-unit-minutes-value');
    const notice = item.querySelector('.free-rate-notice');

    if (!checkbox || !input) {
        return;
    }

    input.readOnly = checkbox.checked;
    setInputDisabledAppearance(input, checkbox.checked);

    if (checkbox.checked) {
        input.value = '0';
    }

    if (unitMinutesInput && unitMinutesValue) {
        unitMinutesInput.disabled = checkbox.checked;
        unitMinutesValue.disabled = !checkbox.checked;
        unitMinutesValue.value = unitMinutesInput.value;
        setInputDisabledAppearance(unitMinutesInput, checkbox.checked);
    }

    notice?.classList.toggle('hidden', !checkbox.checked);
};

export const initParkingSpotRates = (root) => {
    if (root.dataset.rateFormInitialized === 'true') {
        return;
    }

    const list = root.querySelector('[data-rate-list]');
    const template = root.querySelector('[data-rate-template]');
    const addButton = root.querySelector('[data-add-rate]');

    if (!list || !template || !addButton) {
        return;
    }

    root.dataset.rateFormInitialized = 'true';

    const configuredMaxRates = Number.parseInt(root.dataset.maxRates ?? '', 10);
    const maxRates = Number.isInteger(configuredMaxRates) && configuredMaxRates > 0 ? configuredMaxRates : 4;
    const rateItems = () => [...list.querySelectorAll('[data-rate-item]')];

    const renumberRates = () => {
        const items = rateItems();

        items.forEach((item, index) => {
            item.querySelector('.rate-number').textContent = index + 1;
            item.querySelectorAll('[data-rate-field]').forEach((field) => {
                field.name = `rates[${index}][${field.dataset.rateField}]`;
            });
            item.querySelectorAll('[data-rate-hidden-field]').forEach((field) => {
                field.name = `rates[${index}][${field.dataset.rateHiddenField}]`;
            });
            syncFreeParkingState(item);
            syncFreeMinutesState(item);
            syncMaxRateState(item);
            syncCustomMaxRatePeriodState(item);
        });

        list.querySelectorAll('[data-delete-rate]').forEach((button) => {
            button.classList.toggle('hidden', items.length <= 1);
            button.disabled = items.length <= 1;
        });

        addButton.classList.toggle('hidden', items.length >= maxRates);
        addButton.disabled = items.length >= maxRates;
    };

    addButton.addEventListener('click', () => {
        if (rateItems().length >= maxRates) {
            return;
        }

        const newItem = template.content.firstElementChild?.cloneNode(true);
        if (!newItem) {
            return;
        }

        list.appendChild(newItem);
        renumberRates();
    });

    list.addEventListener('click', (event) => {
        const deleteButton = event.target.closest('[data-delete-rate]');
        if (!deleteButton || !list.contains(deleteButton) || rateItems().length <= 1) {
            return;
        }

        deleteButton.closest('[data-rate-item]')?.remove();
        renumberRates();
    });

    list.addEventListener('change', (event) => {
        const control = event.target.closest('.free-parking-checkbox, .no-max-rate-checkbox, .no-free-minutes-checkbox, [data-rate-field="max_rate_period"]');
        if (!control || !list.contains(control)) {
            return;
        }

        const item = control.closest('[data-rate-item]');
        if (!item) {
            return;
        }

        syncFreeParkingState(item);
        syncFreeMinutesState(item);
        syncMaxRateState(item);
        syncCustomMaxRatePeriodState(item);
    });

    renumberRates();
};

const initParkingSpotRateForms = () => {
    document.querySelectorAll('[data-parking-spot-rates]').forEach(initParkingSpotRates);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initParkingSpotRateForms);
} else {
    initParkingSpotRateForms();
}

document.addEventListener('livewire:navigated', initParkingSpotRateForms);
