// Amounts arrive from the server as integer nanodollars (1 USD = 1e9).
// Rounding happens only here, for display; stored values stay exact.

const NANOS_PER_DOLLAR = 1_000_000_000;

export function formatUsd(nanos: number | null | undefined, decimals = 4): string {
    if (nanos === null || nanos === undefined) {
        return '';
    }

    const unit = 10 ** (9 - decimals);
    const scale = 10 ** decimals;
    const rounded = Math.round(Math.abs(nanos) / unit);
    const whole = Math.floor(rounded / scale);
    const fraction = String(rounded % scale).padStart(decimals, '0');
    const sign = nanos < 0 ? '-' : '';

    return decimals > 0 ? `${sign}$${whole.toLocaleString('en-US')}.${fraction}` : `${sign}$${whole.toLocaleString('en-US')}`;
}

export function exactUsd(nanos: number | null | undefined): string {
    if (nanos === null || nanos === undefined) {
        return '';
    }

    const abs = Math.abs(nanos);
    const sign = nanos < 0 ? '-' : '';

    return `${sign}$${Math.floor(abs / NANOS_PER_DOLLAR)}.${String(abs % NANOS_PER_DOLLAR).padStart(9, '0')}`;
}

export function formatTokens(count: number): string {
    return count.toLocaleString('en-US');
}
