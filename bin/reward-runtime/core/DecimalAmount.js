"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.DecimalAmount = exports.ORIGINAL_NUMBER_UNITS = void 0;
const alphabet = 'abcdefghijklmnopqrstuvwxyz';
exports.ORIGINAL_NUMBER_UNITS = Object.freeze([
    ...alphabet.split(''), ...alphabet.split('').reduce((units, first) => units.concat(alphabet.split('').map(last => first + last)), []),
]);
function strip(value) { return value.replace(/^0+/, '') || '0'; }
function compareDigits(a, b) { return a.length === b.length ? (a === b ? 0 : a < b ? -1 : 1) : a.length < b.length ? -1 : 1; }
function addDigits(a, b) {
    let carry = 0, result = '';
    for (let i = a.length - 1, j = b.length - 1; i >= 0 || j >= 0 || carry; i--, j--) {
        const digit = (i >= 0 ? +a[i] : 0) + (j >= 0 ? +b[j] : 0) + carry;
        result = String(digit % 10) + result;
        carry = Math.floor(digit / 10);
    }
    return result;
}
function subtractDigits(a, b) {
    let borrow = 0, result = '';
    for (let i = a.length - 1, j = b.length - 1; i >= 0; i--, j--) {
        let digit = +a[i] - (j >= 0 ? +b[j] : 0) - borrow;
        borrow = digit < 0 ? 1 : 0;
        if (borrow)
            digit += 10;
        result = String(digit) + result;
    }
    return strip(result);
}
function multiplyDigits(a, b) {
    const result = new Array(a.length + b.length).fill(0);
    for (let i = a.length - 1; i >= 0; i--)
        for (let j = b.length - 1; j >= 0; j--) {
            const at = i + j + 1, value = result[at] + (+a[i]) * (+b[j]);
            result[at] = value % 10;
            result[at - 1] += Math.floor(value / 10);
        }
    return strip(result.join(''));
}
class DecimalAmount {
    constructor(digits, exponent) {
        if (!/^-?\d+$/.test(digits) || !Number.isSafeInteger(exponent))
            throw new Error('Invalid decimal amount');
        const negative = digits[0] === '-';
        let coefficient = strip(negative ? digits.slice(1) : digits);
        if (coefficient === '0') {
            this.digits = '0';
            this.exponent = 0;
        }
        else {
            const trimmed = coefficient.replace(/0+$/, '');
            exponent += coefficient.length - trimmed.length;
            coefficient = trimmed;
            if (!Number.isSafeInteger(exponent))
                throw new Error('Invalid decimal exponent');
            this.digits = (negative ? '-' : '') + coefficient;
            this.exponent = exponent;
        }
        Object.freeze(this);
    }
    static from(value) {
        if (value instanceof DecimalAmount)
            return value;
        if (typeof value === 'object' && value !== null)
            return new DecimalAmount(value.digits, value.exponent);
        if (typeof value === 'number' && !Number.isFinite(value))
            throw new Error('Amount must be finite');
        const match = /^([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/.exec(String(value).trim());
        if (!match)
            throw new Error('Invalid decimal amount');
        return new DecimalAmount((match[1] === '-' ? '-' : '') + match[2] + (match[3] || ''), Number(match[4] || 0) - (match[3] || '').length);
    }
    get isZero() { return this.digits === '0'; }
    get isNegative() { return this.digits[0] === '-'; }
    get coefficient() { return this.isNegative ? this.digits.slice(1) : this.digits; }
    get scientificExponent() { return this.isZero ? 0 : this.exponent + this.coefficient.length - 1; }
    compare(input) {
        const other = DecimalAmount.from(input);
        if (this.isZero || other.isZero)
            return this.isZero ? (other.isZero ? 0 : other.isNegative ? 1 : -1) : this.isNegative ? -1 : 1;
        if (this.isNegative !== other.isNegative)
            return this.isNegative ? -1 : 1;
        const a = this.coefficient, b = other.coefficient, direction = this.isNegative ? -1 : 1;
        const magnitude = this.scientificExponent - other.scientificExponent;
        if (magnitude !== 0)
            return (magnitude < 0 ? -1 : 1) * direction;
        const width = Math.max(a.length, b.length);
        const comparison = compareDigits(a.padEnd(width, '0'), b.padEnd(width, '0'));
        return comparison === 0 ? 0 : comparison * direction;
    }
    add(input) {
        const other = DecimalAmount.from(input);
        if (other.isZero)
            return this;
        if (this.isZero)
            return other;
        const exponent = Math.min(this.exponent, other.exponent);
        const a = this.coefficient + '0'.repeat(this.exponent - exponent), b = other.coefficient + '0'.repeat(other.exponent - exponent);
        if (this.isNegative === other.isNegative)
            return new DecimalAmount((this.isNegative ? '-' : '') + addDigits(a, b), exponent);
        const comparison = compareDigits(a, b);
        if (comparison === 0)
            return DecimalAmount.from(0);
        const negative = comparison > 0 ? this.isNegative : other.isNegative;
        return new DecimalAmount((negative ? '-' : '') + (comparison > 0 ? subtractDigits(a, b) : subtractDigits(b, a)), exponent);
    }
    sub(input) {
        const other = DecimalAmount.from(input);
        return this.add(other.isZero ? other : new DecimalAmount(other.isNegative ? other.coefficient : '-' + other.digits, other.exponent));
    }
    mul(input) {
        const other = DecimalAmount.from(input);
        if (this.isZero || other.isZero)
            return DecimalAmount.from(0);
        return new DecimalAmount((this.isNegative !== other.isNegative ? '-' : '') + multiplyDigits(this.coefficient, other.coefficient), this.exponent + other.exponent);
    }
    scale(exponent) { return new DecimalAmount(this.digits, this.exponent + exponent); }
    truncateSignificant(precision) {
        if (!Number.isInteger(precision) || precision < 1)
            throw new Error('Precision must be a positive integer');
        const coefficient = this.coefficient;
        if (coefficient.length <= precision)
            return this;
        return new DecimalAmount((this.isNegative ? '-' : '') + coefficient.slice(0, precision), this.exponent + coefficient.length - precision);
    }
    discardDecimal() {
        if (this.exponent >= 0)
            return this;
        const coefficient = this.coefficient, length = coefficient.length + this.exponent;
        return length <= 0 ? DecimalAmount.from(0) : new DecimalAmount((this.isNegative ? '-' : '') + coefficient.slice(0, length), 0);
    }
    ratio(input) {
        const other = DecimalAmount.from(input);
        if (this.compare(0) <= 0 || other.compare(0) <= 0)
            return 0;
        if (this.compare(other) >= 0)
            return 1;
        const mantissa = (value) => Number(value.coefficient.slice(0, 15)) / Math.pow(10, Math.min(15, value.coefficient.length) - 1);
        return Math.max(0, Math.min(1, mantissa(this) / mantissa(other) * Math.pow(10, this.scientificExponent - other.scientificExponent)));
    }
    toNumber() { return Number(this.digits + 'e' + this.exponent); }
    toJSON() { return { digits: this.digits, exponent: this.exponent }; }
    toString() {
        if (this.isZero)
            return '0';
        const coefficient = this.coefficient, sign = this.isNegative ? '-' : '', point = coefficient.length + this.exponent;
        if (this.exponent >= 0 && point <= 21)
            return sign + coefficient + '0'.repeat(this.exponent);
        if (this.exponent < 0 && point > 0 && coefficient.length <= 21)
            return sign + coefficient.slice(0, point) + '.' + coefficient.slice(point);
        if (point <= 0 && point > -6 && coefficient.length <= 21)
            return sign + '0.' + '0'.repeat(-point) + coefficient;
        const fraction = coefficient.slice(1);
        return sign + coefficient[0] + (fraction ? '.' + fraction : '') + 'e' + (this.scientificExponent >= 0 ? '+' : '') + this.scientificExponent;
    }
    format(units = exports.ORIGINAL_NUMBER_UNITS, maxDigits = 5) {
        if (this.compare(0) <= 0)
            return '0';
        if (this.scientificExponent < 3)
            return this.discardDecimal().toString();
        const group = Math.floor(this.scientificExponent / 3), integerDigits = this.scientificExponent % 3 + 1;
        const significant = Math.max(1, Math.min(integerDigits + 2, Math.floor(maxDigits)));
        const coefficient = this.coefficient.slice(0, significant);
        if (group > units.length) {
            const fraction = coefficient.slice(1).replace(/0+$/, '');
            return coefficient[0] + (fraction ? '.' + fraction : '') + 'e+' + this.scientificExponent;
        }
        const padded = coefficient.padEnd(integerDigits, '0'), fraction = padded.slice(integerDigits).replace(/0+$/, '');
        return padded.slice(0, integerDigits) + (fraction ? '.' + fraction : '') + units[group - 1];
    }
}
exports.DecimalAmount = DecimalAmount;
