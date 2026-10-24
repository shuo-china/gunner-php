"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.FishSystem = exports.isFishGrade = exports.FISH_CATALOG = exports.FISH_DRAW_PRICE = exports.FISH_CAPACITY = exports.FISH_COUNT = void 0;
const EconomyConfig_1 = require("../core/EconomyConfig");
exports.FISH_COUNT = 13;
exports.FISH_CAPACITY = 20;
exports.FISH_DRAW_PRICE = EconomyConfig_1.ECONOMY_CONFIG.fish.drawPrice;
function roundEven(value) {
    const floor = Math.floor(value), fraction = value - floor;
    return fraction < .5 ? floor : fraction > .5 ? floor + 1 : floor % 2 === 0 ? floor : floor + 1;
}
function roundSignificant(value) {
    if (value === 0)
        return 0;
    const scale = Math.pow(10, 2 - Math.trunc(Math.log10(Math.abs(value))) - 1);
    return roundEven(roundEven(value * scale) / scale);
}
exports.FISH_CATALOG = Object.freeze(['D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'X', 'XX', 'XXX', 'Z', 'ZZ', 'ZZZ'].map((grade, id) => Object.freeze({
    id, grade, name: `${grade}级鱼`,
    damagePercent: roundSignificant(Math.fround(Math.fround(Math.pow(3, id)) * 4)),
    criticalChancePercent: Math.max(0, Math.min(100, roundSignificant(Math.fround(Math.pow(2.5, id))))),
    criticalDamagePercent: roundSignificant(Math.fround(Math.fround(Math.pow(2.5, id)) * 8)),
})));
const isFishGrade = (value) => Number.isInteger(value) && value >= 0 && value < exports.FISH_COUNT;
exports.isFishGrade = isFishGrade;
const emptySave = () => ({ inventory: [], collected: Array(exports.FISH_COUNT).fill(false), autoEnabled: false, freeDrawDate: '', freeDrawCount: 0 });
function isDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value))
        return false;
    const date = new Date(`${value}T00:00:00Z`);
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}
class FishSystem {
    constructor() {
        this.data = emptySave();
        this.revision = 0;
    }
    get totals() {
        return this.data.inventory.reduce((total, grade) => {
            const fish = exports.FISH_CATALOG[grade];
            total.damagePercent += fish.damagePercent;
            total.criticalChancePercent += fish.criticalChancePercent;
            total.criticalDamagePercent += fish.criticalDamagePercent;
            return total;
        }, { damagePercent: 0, criticalChancePercent: 0, criticalDamagePercent: 0 });
    }
    serialize() { return { ...this.data, inventory: [...this.data.inventory], collected: [...this.data.collected] }; }
    restore(value) {
        const d = value;
        if (!d || !Array.isArray(d.inventory) || d.inventory.length > exports.FISH_CAPACITY || d.inventory.some(v => !(0, exports.isFishGrade)(v))
            || !Array.isArray(d.collected) || d.collected.length !== exports.FISH_COUNT || d.collected.some(v => typeof v !== 'boolean')
            || typeof d.autoEnabled !== 'boolean' || typeof d.freeDrawDate !== 'string' || (d.freeDrawDate !== '' && !isDate(d.freeDrawDate))
            || !Number.isSafeInteger(d.freeDrawCount) || d.freeDrawCount < 0)
            throw new Error('Invalid fish save');
        this.data = { inventory: [...d.inventory], collected: [...d.collected], autoEnabled: d.autoEnabled,
            freeDrawDate: d.freeDrawDate, freeDrawCount: d.freeDrawCount };
        for (const grade of d.inventory)
            this.data.collected[grade] = true;
        this.revision++;
    }
    dailyUsed(today) {
        return isDate(today) && today > this.data.freeDrawDate ? 0 : this.data.freeDrawCount;
    }
    snapshot(diamonds, today, autoAvailable = true) {
        const freeDrawCount = this.dailyUsed(today), totals = this.totals;
        return { ...this.serialize(), ...totals, diamonds, revision: this.revision, capacity: exports.FISH_CAPACITY, drawPrice: exports.FISH_DRAW_PRICE,
            freeDrawCount, criticalChancePercent: Math.min(100, totals.criticalChancePercent), criticalDamagePercent: 125 + totals.criticalDamagePercent,
            freeDrawRemaining: Math.max(0, EconomyConfig_1.ECONOMY_CONFIG.fish.freeDrawsPerDay - freeDrawCount),
            freeWithoutAdRemaining: Math.max(0, EconomyConfig_1.ECONOMY_CONFIG.fish.noAdDrawsPerDay - freeDrawCount),
            freeDrawNeedsAd: freeDrawCount >= EconomyConfig_1.ECONOMY_CONFIG.fish.noAdDrawsPerDay && freeDrawCount < EconomyConfig_1.ECONOMY_CONFIG.fish.freeDrawsPerDay,
            autoAvailable, autoPair: this.autoPair(autoAvailable) };
    }
    autoPair(available = true) {
        if (!available || !this.data.autoEnabled)
            return null;
        for (let grade = 0; grade < exports.FISH_COUNT - 1; grade++) {
            const first = this.data.inventory.indexOf(grade), second = this.data.inventory.indexOf(grade, first + 1);
            if (first >= 0 && second >= 0)
                return { from: second, to: first, grade };
        }
        return null;
    }
    append(grade) {
        const discovered = !this.data.collected[grade];
        this.data.inventory.push(grade);
        this.data.collected[grade] = true;
        this.revision++;
        return { ok: true, changed: true, grade, index: this.data.inventory.length - 1, ...(discovered ? { discovered: grade } : {}) };
    }
    draw(kind, diamonds, today, random = Math.random, adRewarded = false) {
        if ((kind !== 'free' && kind !== 'diamonds') || !isDate(today))
            return { ok: false, reason: 'invalid' };
        if (this.data.inventory.length >= exports.FISH_CAPACITY)
            return { ok: false, reason: 'full' };
        if (kind === 'diamonds' && (!Number.isSafeInteger(diamonds) || diamonds < exports.FISH_DRAW_PRICE))
            return { ok: false, reason: 'currency' };
        const count = this.dailyUsed(today);
        if (kind === 'free') {
            if (count >= EconomyConfig_1.ECONOMY_CONFIG.fish.freeDrawsPerDay)
                return { ok: false, reason: 'dailyLimit' };
            if (count >= EconomyConfig_1.ECONOMY_CONFIG.fish.noAdDrawsPerDay && !adRewarded)
                return { ok: false, reason: 'adRequired' };
        }
        const roll = random();
        if (!Number.isFinite(roll) || roll < 0 || roll > 1)
            return { ok: false, reason: 'invalid' };
        const base = kind === 'free' ? EconomyConfig_1.ECONOMY_CONFIG.fish.freeBaseGrade : EconomyConfig_1.ECONOMY_CONFIG.fish.premiumBaseGrade;
        let grade = base, cumulative = 0;
        for (let i = 0; i < EconomyConfig_1.ECONOMY_CONFIG.fish.drawGradePercents.length; i++) {
            cumulative += EconomyConfig_1.ECONOMY_CONFIG.fish.drawGradePercents[i];
            if (Math.fround(roll * 100) < cumulative) {
                grade = base + i;
                break;
            }
        }
        if (kind === 'free') {
            this.data.freeDrawDate = today > this.data.freeDrawDate ? today : this.data.freeDrawDate;
            this.data.freeDrawCount = count + 1;
        }
        return this.append(grade);
    }
    merge(from, to, expectedGrade, automatic = false, expectedRevision) {
        if (expectedRevision !== undefined && expectedRevision !== this.revision)
            return { ok: false, reason: 'stale' };
        if (!Number.isInteger(from) || !Number.isInteger(to) || from === to || from < 0 || to < 0
            || from >= this.data.inventory.length || to >= this.data.inventory.length)
            return { ok: false, reason: 'invalid' };
        const grade = this.data.inventory[from];
        if (expectedGrade !== undefined && expectedGrade !== grade)
            return { ok: false, reason: 'stale' };
        if (this.data.inventory[to] !== grade)
            return { ok: false, reason: 'grade' };
        if (grade === exports.FISH_COUNT - 1)
            return { ok: false, reason: 'maxGrade' };
        if (automatic && !this.data.autoEnabled)
            return { ok: false, reason: 'grade' };
        this.data.inventory.splice(Math.max(from, to), 1);
        this.data.inventory.splice(Math.min(from, to), 1);
        return this.append(grade + 1);
    }
    remove(index, expectedGrade, expectedRevision) {
        if (expectedRevision !== undefined && expectedRevision !== this.revision)
            return { ok: false, reason: 'stale' };
        if (!Number.isInteger(index) || index < 0 || index >= this.data.inventory.length)
            return { ok: false, reason: 'invalid' };
        if (expectedGrade !== undefined && this.data.inventory[index] !== expectedGrade)
            return { ok: false, reason: 'stale' };
        const grade = this.data.inventory[index];
        this.data.inventory.splice(index, 1);
        this.revision++;
        return { ok: true, changed: true, grade, index };
    }
    setAuto(enabled) {
        if (typeof enabled !== 'boolean')
            return { ok: false, reason: 'invalid' };
        const changed = this.data.autoEnabled !== enabled;
        this.data.autoEnabled = enabled;
        if (changed)
            this.revision++;
        return { ok: true, changed };
    }
}
exports.FishSystem = FishSystem;
