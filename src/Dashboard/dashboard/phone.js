import {esc} from './format.js';

const PHONE_COUNTRIES = [
    {code: 'PT', name: 'Portugal', flag: '🇵🇹', dialCode: '351', minLength: 9, maxLength: 9, groups: [3, 3, 3], sample: '912 345 678'},
    {code: 'ES', name: 'Espanha', flag: '🇪🇸', dialCode: '34', minLength: 9, maxLength: 9, groups: [3, 3, 3], sample: '612 345 678'},
    {code: 'FR', name: 'França', flag: '🇫🇷', dialCode: '33', minLength: 9, maxLength: 9, groups: [1, 2, 2, 2, 2], sample: '6 12 34 56 78'},
    {code: 'IT', name: 'Itália', flag: '🇮🇹', dialCode: '39', minLength: 9, maxLength: 10, groups: [3, 3, 4], sample: '312 345 6789'},
    {code: 'DE', name: 'Alemanha', flag: '🇩🇪', dialCode: '49', minLength: 10, maxLength: 11, groups: [3, 3, 4, 4], sample: '151 234 56789'},
    {code: 'GB', name: 'Reino Unido', flag: '🇬🇧', dialCode: '44', minLength: 10, maxLength: 10, groups: [4, 3, 3], sample: '7911 123 456'},
];

const DEFAULT_COUNTRY = 'PT';

export function renderPhoneControl({value = '', configField = '', repeatField = '', placeholder = 'Número'} = {}) {
    const parsed = parseStoredPhone(value);
    const country = phoneCountry(parsed.countryCode);
    const fieldAttr = configField !== '' ? ` data-config-field="${esc(configField)}"` : '';
    const repeatAttr = repeatField !== '' ? ` data-repeat-field="${esc(repeatField)}"` : '';

    return `
        <div class="vstack gap-1" data-phone-control${fieldAttr}${repeatAttr}>
            <div class="input-group">
                <select class="form-select" data-phone-country aria-label="País" style="max-width: 16rem;">
                    ${PHONE_COUNTRIES.map(option => `
                        <option value="${esc(option.code)}" ${option.code === country.code ? 'selected' : ''}>
                            ${esc(`${option.flag} ${option.name} (+${option.dialCode})`)}
                        </option>
                    `).join('')}
                </select>
                <input
                    class="form-control"
                    type="tel"
                    inputmode="tel"
                    autocomplete="tel-national"
                    data-phone-local
                    placeholder="${esc(placeholderForCountry(country.code, placeholder))}"
                    value="${esc(formatLocalNumber(country.code, parsed.localDigits))}">
            </div>
            <div class="invalid-feedback d-none" data-phone-feedback></div>
        </div>`;
}

export function normalizePhoneControl(control) {
    if (!control) {
        return '';
    }

    clearPhoneControlError(control);
    const countryCode = control.querySelector('[data-phone-country]')?.value || DEFAULT_COUNTRY;
    const localDigits = phoneDigits(control.querySelector('[data-phone-local]')?.value || '');
    if (localDigits === '') {
        return '';
    }

    const validation = validatePhone(countryCode, localDigits);
    if (!validation.valid) {
        setPhoneControlError(control, validation.message);
        throw new Error(validation.message);
    }

    return `+${validation.country.dialCode}${localDigits}`;
}

export function syncPhoneControl(target) {
    const control = target.matches?.('[data-phone-control]') ? target : target.closest('[data-phone-control]');
    if (!control) return;

    const countryCode = control.querySelector('[data-phone-country]')?.value || DEFAULT_COUNTRY;
    const input = control.querySelector('[data-phone-local]');
    if (!input) return;

    input.value = formatLocalNumber(countryCode, phoneDigits(input.value || ''));
    input.placeholder = placeholderForCountry(countryCode, input.placeholder || 'Número');
    clearPhoneControlError(control);
}

export function resetPhoneControls(root) {
    for (const control of root.querySelectorAll('[data-phone-control]')) {
        clearPhoneControlError(control);
        syncPhoneControl(control);
    }
}

function parseStoredPhone(value) {
    const raw = String(value || '').trim();
    if (raw === '') {
        return {countryCode: DEFAULT_COUNTRY, localDigits: ''};
    }

    if (raw.startsWith('+')) {
        const digits = phoneDigits(raw);
        const country = [...PHONE_COUNTRIES]
            .sort((a, b) => b.dialCode.length - a.dialCode.length)
            .find(option => digits.startsWith(option.dialCode));

        if (country) {
            return {
                countryCode: country.code,
                localDigits: digits.slice(country.dialCode.length),
            };
        }
    }

    return {
        countryCode: DEFAULT_COUNTRY,
        localDigits: phoneDigits(raw),
    };
}

function validatePhone(countryCode, localDigits) {
    const country = phoneCountry(countryCode);
    if (localDigits.length < country.minLength || localDigits.length > country.maxLength) {
        return {
            valid: false,
            country,
            message: `${country.name}: número deve ter entre ${country.minLength} e ${country.maxLength} dígitos.`,
        };
    }

    return {valid: true, country};
}

function setPhoneControlError(control, message) {
    const input = control.querySelector('[data-phone-local]');
    const feedback = control.querySelector('[data-phone-feedback]');
    if (input) {
        input.classList.add('is-invalid');
    }
    if (feedback) {
        feedback.textContent = message;
        feedback.classList.remove('d-none');
    }
}

function clearPhoneControlError(control) {
    const input = control.querySelector('[data-phone-local]');
    const feedback = control.querySelector('[data-phone-feedback]');
    if (input) {
        input.classList.remove('is-invalid');
    }
    if (feedback) {
        feedback.textContent = '';
        feedback.classList.add('d-none');
    }
}

function phoneCountry(code) {
    return PHONE_COUNTRIES.find(country => country.code === code) || PHONE_COUNTRIES[0];
}

function placeholderForCountry(code, fallback) {
    const country = phoneCountry(code);
    return country.sample || fallback;
}

function formatLocalNumber(code, digits) {
    const country = phoneCountry(code);
    if (digits === '') {
        return '';
    }

    const chunks = [];
    let offset = 0;
    for (const size of country.groups) {
        if (offset >= digits.length) break;
        chunks.push(digits.slice(offset, offset + size));
        offset += size;
    }
    if (offset < digits.length) {
        chunks.push(digits.slice(offset));
    }

    return chunks.join(' ');
}

function phoneDigits(value) {
    return String(value || '').replace(/\D+/g, '');
}
