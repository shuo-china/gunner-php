"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.OriginalInfVal = void 0;
exports.subtractOriginalHealth = subtractOriginalHealth;
const DecimalAmount_1 = require("./DecimalAmount");
const MAX_SERIALIZED_PRECISION = 100000;
function validatePrecision(precision) {
    if (!Number.isSafeInteger(precision) || precision < 1 || precision > MAX_SERIALIZED_PRECISION)
        throw new Error('Invalid InfVal precision');
}
function input(value) {
    return value instanceof OriginalInfVal ? value : Number.isInteger(value) ? OriginalInfVal.fromInt(value) : OriginalInfVal.fromDouble(value);
}
function integerQuotient(numerator, denominator) {
    const divisor = DecimalAmount_1.DecimalAmount.from(denominator), multiples = Array.from({ length: 10 }, (_, i) => divisor.mul(i));
    let remainder = DecimalAmount_1.DecimalAmount.from(0), quotient = '';
    for (const digit of numerator) {
        remainder = remainder.mul(10).add(+digit);
        let q = 9;
        while (q > 0 && remainder.compare(multiples[q]) < 0)
            q--;
        quotient += q;
        remainder = remainder.sub(multiples[q]);
    }
    return quotient.replace(/^0+/, '') || '0';
}
class OriginalInfVal {
    constructor(amount, precision, zeroExponent = 1 - precision) {
        validatePrecision(precision);
        this.amount = amount.truncateSignificant(precision);
        this.zeroExponent = zeroExponent;
        this.precision = amount.isZero ? Math.max(1, 1 - zeroExponent) : precision;
        Object.freeze(this);
    }
    static fromInt(value) {
        if (!Number.isSafeInteger(value))
            throw new Error('InfVal integer must be safe and finite');
        return new OriginalInfVal(DecimalAmount_1.DecimalAmount.from(value), String(Math.abs(value)).length);
    }
    static fromDouble(value) {
        if (!Number.isFinite(value))
            throw new Error('InfVal double must be finite');
        return new OriginalInfVal(DecimalAmount_1.DecimalAmount.from(value.toPrecision(17)), 17);
    }
    static fromAmount(amount, precision) {
        const coefficientLength = amount.digits.replace('-', '').length;
        return new OriginalInfVal(amount, precision === undefined ? Math.max(coefficientLength, amount.scientificExponent + 1) : precision);
    }
    static fromSerialized(value) {
        if (typeof value !== 'object' || value === null)
            throw new Error('Invalid serialized InfVal');
        const saved = value;
        if (typeof saved.digits !== 'string' || !/^-?\d+$/.test(saved.digits) || saved.digits.length > MAX_SERIALIZED_PRECISION + 1 ||
            typeof saved.exponent !== 'number' || !Number.isSafeInteger(saved.exponent) || typeof saved.precision !== 'number')
            throw new Error('Invalid serialized InfVal');
        validatePrecision(saved.precision);
        const amount = DecimalAmount_1.DecimalAmount.from({ digits: saved.digits, exponent: saved.exponent });
        if (amount.truncateSignificant(saved.precision).compare(amount) !== 0)
            throw new Error('InfVal amount exceeds its saved precision');
        if (amount.isZero && saved.exponent > 0 && saved.precision !== 1)
            throw new Error('Invalid zero InfVal precision');
        return new OriginalInfVal(amount, saved.precision, amount.isZero && saved.exponent > 0 ? saved.exponent : 1 - saved.precision);
    }
    get rawExponent() { return this.amount.isZero ? this.zeroExponent : this.amount.scientificExponent + 1 - this.precision; }
    rawCoefficient() {
        return this.amount.digits.replace('-', '').padEnd(this.precision, '0');
    }
    truncateAt(exponent) {
        if (this.amount.isZero || this.amount.exponent >= exponent)
            return this.amount;
        const digits = this.amount.digits.replace('-', ''), keep = digits.length + this.amount.exponent - exponent;
        return keep <= 0 ? DecimalAmount_1.DecimalAmount.from(0) : DecimalAmount_1.DecimalAmount.from({ digits: (this.amount.isNegative ? '-' : '') + digits.slice(0, keep), exponent });
    }
    sum(other, subtract) {
        const precision = Math.max(this.precision, other.precision);
        if (this.amount.isZero)
            return subtract ? new OriginalInfVal(other.amount.mul(-1), other.precision, other.rawExponent).toPrecision(precision) : other.toPrecision(precision);
        if (other.amount.isZero)
            return this.toPrecision(precision);
        const exponent = Math.max(this.amount.scientificExponent, other.amount.scientificExponent) + 1 - precision;
        const left = this.truncateAt(exponent), right = other.truncateAt(exponent);
        const amount = subtract ? left.sub(right) : left.add(right);
        return amount.isZero ? new OriginalInfVal(amount, 1, exponent).toPrecision(precision) : new OriginalInfVal(amount, precision);
    }
    add(value) { return this.sum(input(value), false); }
    sub(value) { return this.sum(input(value), true); }
    mul(value) {
        const other = input(value);
        return new OriginalInfVal(this.amount.mul(other.amount), Math.max(this.precision, other.precision));
    }
    div(value) {
        const other = input(value), precision = Math.max(this.precision, other.precision);
        if (other.amount.isZero)
            throw new Error('InfVal division by zero');
        if (this.amount.isZero)
            return new OriginalInfVal(this.amount, precision);
        const coefficient = integerQuotient(this.rawCoefficient() + '0'.repeat(precision), other.rawCoefficient());
        const sign = this.amount.isNegative !== other.amount.isNegative ? '-' : '';
        return new OriginalInfVal(DecimalAmount_1.DecimalAmount.from({ digits: sign + coefficient, exponent: this.rawExponent - other.rawExponent - precision }), precision);
    }
    pow(power, conservePrecision = true) {
        if (!Number.isSafeInteger(power) || Math.abs(power) > 2147483647)
            throw new Error('Invalid InfVal integer power');
        if (power === 0)
            return OriginalInfVal.fromInt(1).toPrecision(this.precision);
        if (this.amount.isZero || this.amount.compare(1) === 0)
            return this;
        let value = this, result = OriginalInfVal.fromInt(1), remaining = Math.abs(power);
        while (remaining > 0) {
            if (remaining % 2)
                result = result.mul(value);
            value = value.mul(value);
            remaining = Math.floor(remaining / 2);
        }
        if (power < 0)
            result = OriginalInfVal.fromInt(1).toPrecision(this.precision).div(result);
        return conservePrecision ? result.toPrecision(this.precision) : result;
    }
    toPrecision(precision) {
        return new OriginalInfVal(this.amount, precision, this.amount.isZero ? this.rawExponent + this.precision - precision : 1 - precision);
    }
    discardDecimal() {
        if (this.rawExponent >= 0)
            return this;
        const amount = this.amount.discardDecimal();
        return new OriginalInfVal(amount, amount.isZero ? 1 : Math.max(1, amount.scientificExponent + 1));
    }
    format(units = DecimalAmount_1.ORIGINAL_NUMBER_UNITS, maxDigits = 5) { return this.amount.format(units, maxDigits); }
    toJSON() {
        return { ...this.amount.toJSON(), exponent: this.amount.isZero && this.rawExponent > 0 ? this.rawExponent : this.amount.exponent, precision: this.precision };
    }
    toString() { return this.amount.toString(); }
}
exports.OriginalInfVal = OriginalInfVal;
function subtractOriginalHealth(health, healthPrecision, damage, damagePrecision) {
    const remaining = OriginalInfVal.fromAmount(health, healthPrecision)
        .sub(OriginalInfVal.fromAmount(damage, damagePrecision));
    return { health: remaining.amount.isNegative ? DecimalAmount_1.DecimalAmount.from(0) : remaining.amount, precision: remaining.precision };
}
