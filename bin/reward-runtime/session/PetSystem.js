"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.PetSystem = exports.isPetGrade = exports.PET_CATALOG = exports.PET_EQUIPMENT_SIZE = exports.PET_BACKPACK_SIZE = exports.PET_DRAW_PRICE = exports.PET_COUNT = void 0;
const OriginalInfVal_1 = require("../core/OriginalInfVal");
const EconomyConfig_1 = require("../core/EconomyConfig");
exports.PET_COUNT = 10;
exports.PET_DRAW_PRICE = EconomyConfig_1.ECONOMY_CONFIG.pets.drawPrice;
exports.PET_BACKPACK_SIZE = 16;
exports.PET_EQUIPMENT_SIZE = 3;
exports.PET_CATALOG = (() => {
    let money = OriginalInfVal_1.OriginalInfVal.fromInt(EconomyConfig_1.ECONOMY_CONFIG.gold.petFirstBonusPercent), speed = 2;
    return ['D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'Z', 'ZZ', 'ZZZ'].map((grade, id) => {
        if (id) {
            money = money.mul(EconomyConfig_1.ECONOMY_CONFIG.gold.petGradeGrowth).toPrecision(2).discardDecimal();
            speed += id * 2;
        }
        return Object.freeze({ id, grade, money, moveSpeed: speed });
    });
})();
const isPetGrade = (value) => Number.isInteger(value) && value >= 0 && value < exports.PET_COUNT;
exports.isPetGrade = isPetGrade;
const emptySave = () => ({ owned: Array(16).fill(-1), selected: Array(3).fill(-1), collected: Array(10).fill(false), coins: EconomyConfig_1.ECONOMY_CONFIG.newGame.petCoins, autoEnabled: false, lastDailyAdDate: '', dailyAdCount: 0 });
function validDay(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value))
        return false;
    const date = new Date(`${value}T00:00:00Z`);
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}
class PetSystem {
    constructor() {
        this.data = emptySave();
        this.revision = 0;
        this.locks = new Set();
    }
    setLocks(refs) {
        const locks = new Set();
        for (const ref of refs) {
            if (!this.array(ref) || this.gradeAt(ref) < 0)
                throw new Error('Invalid pet lock');
            locks.add(`${ref.kind}:${ref.index}`);
        }
        if (locks.size !== this.locks.size || refs.some(ref => !this.locks.has(`${ref.kind}:${ref.index}`))) {
            this.locks = locks;
            this.revision++;
        }
    }
    isLocked(ref) { return !!ref && this.locks.has(`${ref.kind}:${ref.index}`); }
    gradeAt(ref) { const a = this.array(ref); return a ? a[ref.index] : -1; }
    get moneyMultiplier() {
        let total = OriginalInfVal_1.OriginalInfVal.fromInt(0);
        for (const grade of this.data.selected)
            if (grade >= 0)
                total = total.add(exports.PET_CATALOG[grade].money);
        return total.add(100);
    }
    get moveSpeedPercent() { return this.data.selected.reduce((sum, grade) => sum + (grade < 0 ? 0 : exports.PET_CATALOG[grade].moveSpeed), 100); }
    get autoLimit() { return Math.min(6, this.data.collected.lastIndexOf(true) - 1); }
    autoPair() {
        if (!this.data.autoEnabled)
            return null;
        for (let grade = 0; grade <= this.autoLimit; grade++) {
            const matches = this.data.owned.map((value, index) => value === grade && !this.isLocked({ kind: 'owned', index }) ? index : -1).filter(index => index >= 0);
            const [first = -1, second = -1] = matches;
            if (first >= 0 && second >= 0)
                return { from: first, to: second, grade };
        }
        return null;
    }
    snapshot(diamonds, gold = '0', today = '', autoAvailable = true) {
        return { ...this.serialize(), diamonds, gold, revision: this.revision, moneyPercent: this.moneyMultiplier.amount.format(),
            moveSpeedPercent: this.moveSpeedPercent, autoLimit: this.autoLimit, autoAvailable,
            autoPair: autoAvailable ? this.autoPair() : null, dailyAdRemaining: this.dailyAdRemaining(today),
            ownedLocked: this.data.owned.map((_, index) => this.isLocked({ kind: 'owned', index })),
            selectedLocked: this.data.selected.map((_, index) => this.isLocked({ kind: 'selected', index })) };
    }
    serialize() { return { ...this.data, owned: [...this.data.owned], selected: [...this.data.selected], collected: [...this.data.collected] }; }
    restore(value) {
        const d = value;
        const validSlots = (a, size) => Array.isArray(a) && a.length === size && a.every(x => x === -1 || (0, exports.isPetGrade)(x));
        if (!d || !validSlots(d.owned, 16) || !validSlots(d.selected, 3) || !Array.isArray(d.collected) || d.collected.length !== 10
            || d.collected.some(x => typeof x !== 'boolean') || !Number.isSafeInteger(d.coins) || d.coins < 0
            || typeof d.autoEnabled !== 'boolean' || typeof d.lastDailyAdDate !== 'string'
            || (d.lastDailyAdDate !== '' && !validDay(d.lastDailyAdDate))
            || !Number.isSafeInteger(d.dailyAdCount) || d.dailyAdCount < 0)
            throw new Error('Invalid pet save');
        this.data = { owned: [...d.owned], selected: [...d.selected], collected: [...d.collected], coins: d.coins, autoEnabled: d.autoEnabled,
            lastDailyAdDate: d.lastDailyAdDate, dailyAdCount: d.dailyAdCount };
        for (const grade of [...d.owned, ...d.selected])
            if (grade >= 0)
                this.data.collected[grade] = true;
        this.revision++;
    }
    array(ref) {
        if (!ref || !['owned', 'selected'].includes(ref.kind) || !Number.isInteger(ref.index))
            return null;
        const a = this.data[ref.kind];
        return ref.index >= 0 && ref.index < a.length ? a : null;
    }
    discover(grade) {
        const fresh = !this.data.collected[grade];
        this.data.collected[grade] = true;
        this.revision++;
        return { ok: true, changed: true, grade, ...(fresh ? { discovered: grade } : {}) };
    }
    draw(currency, diamonds) {
        if (currency !== 'diamonds' && currency !== 'petCoins')
            return { ok: false, reason: 'invalid' };
        const index = this.data.owned.indexOf(-1);
        if (index < 0)
            return { ok: false, reason: 'full' };
        if ((currency === 'petCoins' ? this.data.coins : diamonds) < exports.PET_DRAW_PRICE)
            return { ok: false, reason: 'currency' };
        if (currency === 'petCoins')
            this.data.coins -= exports.PET_DRAW_PRICE;
        this.data.owned[index] = EconomyConfig_1.ECONOMY_CONFIG.pets.drawGrade;
        return this.discover(EconomyConfig_1.ECONOMY_CONFIG.pets.drawGrade);
    }
    grantCoins(amount) {
        if (!Number.isSafeInteger(amount) || amount < 0 || !Number.isSafeInteger(this.data.coins + amount))
            return { ok: false, reason: 'walletLimit' };
        this.data.coins += amount;
        if (amount)
            this.revision++;
        return { ok: true, changed: amount > 0 };
    }
    dailyAdRemaining(today) {
        const used = validDay(today) && today > this.data.lastDailyAdDate ? 0 : this.data.dailyAdCount;
        return Math.max(0, EconomyConfig_1.ECONOMY_CONFIG.pets.dailyAd.dailyLimit - used);
    }
    claimDailyAd(today) {
        if (!validDay(today))
            return { ok: false, reason: 'invalid' };
        const index = this.data.owned.indexOf(-1);
        if (index < 0)
            return { ok: false, reason: 'full' };
        if (!this.dailyAdRemaining(today))
            return { ok: false, reason: 'dailyLimit' };
        const used = today > this.data.lastDailyAdDate ? 0 : this.data.dailyAdCount;
        this.data.lastDailyAdDate = today > this.data.lastDailyAdDate ? today : this.data.lastDailyAdDate;
        this.data.dailyAdCount = used + 1;
        this.data.owned[index] = EconomyConfig_1.ECONOMY_CONFIG.pets.dailyAd.grade;
        return this.discover(EconomyConfig_1.ECONOMY_CONFIG.pets.dailyAd.grade);
    }
    move(from, to, expected, swapVacancy) {
        const a = this.array(from), b = this.array(to);
        if (!a || !b)
            return { ok: false, reason: 'invalid' };
        if (this.isLocked(from) || this.isLocked(to) || (swapVacancy !== undefined && this.isLocked({ kind: 'owned', index: swapVacancy })))
            return { ok: false, reason: 'locked' };
        if (a[from.index] < 0)
            return { ok: false, reason: 'empty' };
        if (expected !== undefined && a[from.index] !== expected)
            return { ok: false, reason: 'stale' };
        if (a === b && from.index === to.index)
            return { ok: true, changed: false };
        if (swapVacancy !== undefined) {
            if (from.kind !== 'owned' || to.kind !== 'owned' || !Number.isInteger(swapVacancy) || swapVacancy < 0 || swapVacancy >= 16
                || (swapVacancy !== from.index && a[swapVacancy] !== -1))
                return { ok: false, reason: 'stale' };
            const moving = a[from.index], displaced = a[to.index];
            a[from.index] = -1;
            a[swapVacancy] = displaced;
            a[to.index] = moving;
        }
        else
            [a[from.index], b[to.index]] = [b[to.index], a[from.index]];
        this.revision++;
        return { ok: true, changed: true };
    }
    unequip(index) {
        if (this.isLocked({ kind: 'selected', index }))
            return { ok: false, reason: 'locked' };
        const to = this.data.owned.indexOf(-1);
        if (to < 0)
            return { ok: false, reason: 'full' };
        return this.move({ kind: 'selected', index }, { kind: 'owned', index: to });
    }
    merge(from, to, expected, automatic = false) {
        if (!Number.isInteger(from) || !Number.isInteger(to) || from === to || from < 0 || to < 0 || from >= 16 || to >= 16)
            return { ok: false, reason: 'invalid' };
        if (this.isLocked({ kind: 'owned', index: from }) || this.isLocked({ kind: 'owned', index: to }))
            return { ok: false, reason: 'locked' };
        const grade = this.data.owned[from];
        if (grade < 0 || this.data.owned[to] !== grade)
            return { ok: false, reason: 'grade' };
        if (expected !== undefined && expected !== grade)
            return { ok: false, reason: 'stale' };
        if (grade === exports.PET_COUNT - 1)
            return { ok: false, reason: 'maxGrade' };
        if (automatic && (!this.data.autoEnabled || grade > this.autoLimit))
            return { ok: false, reason: 'grade' };
        this.data.owned[from] = -1;
        this.data.owned[to] = grade + 1;
        return this.discover(grade + 1);
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
exports.PetSystem = PetSystem;
