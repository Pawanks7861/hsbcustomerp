export function formatInr(value) {
    const number = Number(value ?? 0);
    const safe = Number.isFinite(number) ? number : 0;

    return `₹${safe.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

export function formatQty(value, unit = '') {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    const text = Number.isFinite(number)
        ? number.toLocaleString('en-IN', { maximumFractionDigits: 3 })
        : String(value);

    return unit ? `${text} ${unit}` : text;
}

export function lineAmount(quantity, rate) {
    if (quantity === '' || quantity === null || quantity === undefined) {
        return null;
    }

    if (rate === '' || rate === null || rate === undefined) {
        return null;
    }

    const qty = Number(quantity);
    const rateValue = Number(rate);

    if (!Number.isFinite(qty) || !Number.isFinite(rateValue)) {
        return null;
    }

    return Math.round((qty * rateValue + Number.EPSILON) * 100) / 100;
}
