"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.WeaponInventory = exports.STAR_SUCCESS_PERCENT = exports.MAX_STAR_LEVEL = exports.AD_WEAPON_DURATION_MS = exports.EQUIPMENT_CAPACITY = exports.BACKPACK_CAPACITY = void 0;
exports.getStarBonusPercent = getStarBonusPercent;
exports.getStarPercent = getStarPercent;
exports.getStarMultiplier = getStarMultiplier;
exports.getWeaponStarDamage = getWeaponStarDamage;
exports.getStarSuccessPercent = getStarSuccessPercent;
const DecimalAmount_1 = require("../core/DecimalAmount");
const OriginalInfVal_1 = require("../core/OriginalInfVal");
const EconomyConfig_1 = require("../core/EconomyConfig");
const WeaponCatalog_1 = require("../core/WeaponCatalog");
const ContentConfig_1 = require("./ContentConfig");
const ContentUnlockManager_1 = require("./ContentUnlockManager");
exports.BACKPACK_CAPACITY = 16;
exports.EQUIPMENT_CAPACITY = 3;
exports.AD_WEAPON_DURATION_MS = EconomyConfig_1.ECONOMY_CONFIG.weapons.offer.durationSeconds * 1000;
exports.MAX_STAR_LEVEL = 20;
exports.STAR_SUCCESS_PERCENT = Object.freeze([100, 90, 80, 70, 60, 50, 40, 30, 20, 10, 10, 10, 10, 10, 5, 5, 5, 1, 1, 1]);
const MAX_DATE_MS = 8640000000000000;
function integer(value, min = 0, max = Number.MAX_SAFE_INTEGER) {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= min && value <= max;
}
function copyItem(item) { return item ? { ...item } : null; }
function dateKey(timestamp) {
    const date = new Date(timestamp);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
const STAR_BONUSES = (() => {
    const values = [DecimalAmount_1.DecimalAmount.from(0)];
    let multiplier = 1;
    for (let level = 1; level <= exports.MAX_STAR_LEVEL; level++) {
        multiplier = multiplier * Math.pow(1.07, level - 1) * 1.5;
        values.push(DecimalAmount_1.DecimalAmount.from(((multiplier - 1) * 100).toPrecision(17)).truncateSignificant(2));
    }
    return Object.freeze(values);
})();
function getStarBonusPercent(level) {
    if (!integer(level, 0, exports.MAX_STAR_LEVEL))
        throw new Error('Invalid star level');
    return STAR_BONUSES[level];
}
function getStarPercent(level) {
    return OriginalInfVal_1.OriginalInfVal.fromAmount(getStarBonusPercent(level), 2).add(100);
}
function getStarMultiplier(level) { return getStarPercent(level).div(100).amount; }
function getWeaponStarDamage(weaponId, level) {
    const weapon = (0, WeaponCatalog_1.getWeapon)(weaponId), percent = getStarPercent(level);
    const precision = weapon.damage.split(/[eE]/)[0].replace(/^[+-]/, '').length;
    const damage = OriginalInfVal_1.OriginalInfVal.fromAmount(DecimalAmount_1.DecimalAmount.from(weapon.damage), precision);
    return level === 0 ? damage.amount : damage.mul(percent).div(100).amount;
}
function getStarSuccessPercent(level, failures) {
    if (!integer(level, 0, exports.MAX_STAR_LEVEL) || !integer(failures))
        throw new Error('Invalid star state');
    if (level === exports.MAX_STAR_LEVEL)
        return null;
    return level >= 17 ? Math.min(5, exports.STAR_SUCCESS_PERCENT[level] + failures) : exports.STAR_SUCCESS_PERCENT[level];
}
class WeaponInventory {
    constructor(options = {}) {
        var _a, _b;
        this.pendingAd = null;
        this.notices = [];
        this.revisionValue = 0;
        this.environment = options.environment === 'test' ? 'test' : 'production';
        this.content = (_a = options.content) !== null && _a !== void 0 ? _a : new ContentUnlockManager_1.ContentUnlockManager((0, ContentConfig_1.resolveContentConfig)(this.environment));
        if (this.content.config.environment !== this.environment)
            throw new Error('Content configuration environment mismatch');
        this.clock = options.now || Date.now;
        this.rng = options.rng || Math.random;
        this.onMutation = options.onMutation;
        this.getTutorialState = options.getTutorialState;
        this.isRewardedAdBusy = (_b = options.isRewardedAdBusy) !== null && _b !== void 0 ? _b : (() => false);
        this.state = options.initialState === undefined ? WeaponInventory.defaults() : WeaponInventory.validate(options.initialState);
        if (this.environment === 'test') {
            this.state.diamonds = Math.max(this.content.config.testResources.diamonds, this.state.diamonds);
            this.state.starGems = Math.max(this.content.config.testResources.starGems, this.state.starGems);
        }
        else
            this.state.testTimeOffsetMs = 0;
        this.expire();
    }
    static defaults() {
        const collection = new Array(WeaponCatalog_1.WEAPON_COUNT).fill(false);
        collection[EconomyConfig_1.ECONOMY_CONFIG.newGame.starterWeaponId] = true;
        return { catalogVersion: WeaponCatalog_1.WEAPON_CATALOG_VERSION, backpack: new Array(exports.BACKPACK_CAPACITY).fill(null), equipment: [{ weaponId: EconomyConfig_1.ECONOMY_CONFIG.newGame.starterWeaponId, locked: false, pendingAd: false }, null, null],
            collection, diamonds: EconomyConfig_1.ECONOMY_CONFIG.newGame.diamonds, starGems: EconomyConfig_1.ECONOMY_CONFIG.newGame.starGems, stageValueMax: 0, starLevels: [0, 0, 0], starFailures: [0, 0, 0],
            autoEnabled: false, autoMergeDegree: 6, dailyAdDate: '', dailyAdCount: 0, adGunExpiresAt: 0,
            adGunOffered: false, adGunEnabled: true, firstMerge: false, nextAdId: 1, testTimeOffsetMs: 0 };
    }
    static validate(value) {
        if (!value || typeof value !== 'object')
            throw new Error('Invalid weapon save');
        const candidate = value;
        if (candidate.catalogVersion !== WeaponCatalog_1.WEAPON_CATALOG_VERSION)
            throw new Error('Unsupported weapon catalog version');
        const items = (list, length, allowPending) => {
            if (!Array.isArray(list) || list.length !== length)
                throw new Error('Invalid weapon slots');
            return list.map(item => {
                if (item === null)
                    return null;
                if (!item || !(0, WeaponCatalog_1.isWeaponId)(item.weaponId) || typeof item.locked !== 'boolean' || typeof item.pendingAd !== 'boolean' ||
                    (item.pendingAd && (!allowPending || item.locked)))
                    throw new Error('Invalid weapon item');
                return { weaponId: item.weaponId, locked: item.locked, pendingAd: item.pendingAd };
            });
        };
        const backpack = items(candidate.backpack, exports.BACKPACK_CAPACITY, true), equipment = items(candidate.equipment, exports.EQUIPMENT_CAPACITY, false);
        if (!equipment.some(Boolean) || backpack.filter(item => item && item.pendingAd).length > 1)
            throw new Error('Invalid equipped or pending weapon count');
        const bools = (list, length) => {
            if (!Array.isArray(list) || list.length !== length || list.some(item => typeof item !== 'boolean'))
                throw new Error('Invalid collection');
            return list.slice();
        };
        const numbers = (list, max) => {
            if (!Array.isArray(list) || list.length !== exports.EQUIPMENT_CAPACITY || list.some(item => !Number.isSafeInteger(item)))
                throw new Error('Invalid star slots');
            return list.map(item => Math.max(0, Math.min(max, item)));
        };
        const collection = bools(candidate.collection, WeaponCatalog_1.WEAPON_COUNT);
        for (const item of [...backpack, ...equipment])
            if (item && !item.pendingAd && !collection[item.weaponId])
                throw new Error('Owned weapon missing from collection');
        for (const field of ['diamonds', 'starGems', 'stageValueMax', 'adGunExpiresAt', 'nextAdId', 'testTimeOffsetMs']) {
            if (!integer(candidate[field], 0, Number.MAX_SAFE_INTEGER))
                throw new Error(`Invalid weapon save ${field}`);
        }
        if (!integer(candidate.autoMergeDegree, 0, 6) || typeof candidate.dailyAdDate !== 'string' ||
            !/^(?:|\d{4}-\d{2}-\d{2})$/.test(candidate.dailyAdDate))
            throw new Error('Invalid weapon time state');
        if (!integer(candidate.dailyAdCount))
            throw new Error('Invalid daily weapon count');
        for (const field of ['autoEnabled', 'adGunOffered', 'adGunEnabled', 'firstMerge'])
            if (typeof candidate[field] !== 'boolean')
                throw new Error('Invalid weapon flag');
        const pending = backpack.some(item => item && item.pendingAd);
        if (pending !== (candidate.adGunExpiresAt > 0))
            throw new Error('Invalid pending weapon expiration');
        return { catalogVersion: WeaponCatalog_1.WEAPON_CATALOG_VERSION, backpack, equipment, collection,
            diamonds: candidate.diamonds, starGems: candidate.starGems, stageValueMax: candidate.stageValueMax,
            starLevels: numbers(candidate.starLevels, exports.MAX_STAR_LEVEL), starFailures: numbers(candidate.starFailures, Number.MAX_SAFE_INTEGER),
            autoEnabled: candidate.autoEnabled, autoMergeDegree: candidate.autoMergeDegree,
            dailyAdDate: candidate.dailyAdDate, dailyAdCount: candidate.dailyAdCount, adGunExpiresAt: candidate.adGunExpiresAt,
            adGunOffered: candidate.adGunOffered, adGunEnabled: candidate.adGunEnabled,
            firstMerge: candidate.firstMerge, nextAdId: candidate.nextAdId, testTimeOffsetMs: candidate.testTimeOffsetMs };
    }
    get revision() { return this.revisionValue; }
    get isTestEnvironment() { return this.environment === 'test'; }
    get diamonds() { return this.state.diamonds; }
    get stageValueMax() { return this.state.stageValueMax; }
    grantDiamonds(amount) {
        if (!integer(amount) || !integer(this.state.diamonds + amount))
            return this.fail('invalidAmount');
        if (amount > 0) {
            this.state.diamonds += amount;
            this.changed();
        }
        return { ok: true };
    }
    spendDiamonds(amount) {
        if (!integer(amount))
            return this.fail('invalidAmount');
        if (this.state.diamonds < amount)
            return this.fail('diamonds');
        if (amount > 0) {
            this.state.diamonds -= amount;
            this.changed();
        }
        return { ok: true };
    }
    get starGems() { return this.state.starGems; }
    get now() { return this.clock() + this.state.testTimeOffsetMs; }
    changed(forceBalanceReload = false, action) {
        this.revisionValue++;
        if (this.onMutation)
            this.onMutation(forceBalanceReload, action);
    }
    tutorialActive() { var _a; return ((_a = this.getTutorialState) === null || _a === void 0 ? void 0 : _a.call(this).active) === true; }
    fail(reason) { return { ok: false, reason }; }
    randomIndex(count) {
        const value = this.rng();
        if (!Number.isFinite(value) || value < 0 || value >= 1)
            throw new Error('Weapon RNG must return [0,1)');
        return Math.floor(value * count);
    }
    freeSlot() { return this.state.backpack.indexOf(null); }
    policy(id) { return this.content.get(id, { stageValueMax: this.state.stageValueMax }); }
    unlocked() { return this.policy('weapons').unlocked; }
    lockUnlocked() { return this.policy('weaponLock').unlocked; }
    starUnlocked() { return this.policy('weaponStar').unlocked; }
    dailyAdUnlocked() { return this.policy('dailyAdWeapon').unlocked; }
    dailyAdUsed() { return this.state.dailyAdDate === dateKey(this.now) ? this.state.dailyAdCount : 0; }
    autoUnlocked() { return this.unlocked() && this.policy('autoMerge').unlocked; }
    highestGrade() {
        return this.state.collection.reduce((highest, owned, id) => owned ? Math.max(highest, (0, WeaponCatalog_1.getWeaponGrade)(id)) : highest, 0);
    }
    maxAutoGrade() { return Math.min(6, this.state.autoMergeDegree, this.highestGrade() - 1); }
    collect(weaponId, forceShow = false) {
        const isNew = !this.state.collection[weaponId];
        this.state.collection[weaponId] = true;
        if (isNew || forceShow)
            this.notices.push({ weaponId, isNew, forceShow });
        return { ok: true, weaponId, isNew, forceShow };
    }
    expire() {
        let changed = false;
        if (!this.content.isAdEnabled('weapon-unlock') && this.state.adGunExpiresAt > 0) {
            this.state.backpack = this.state.backpack.map(item => (item === null || item === void 0 ? void 0 : item.pendingAd) ? null : item);
            this.state.adGunExpiresAt = 0;
            changed = true;
        }
        if (this.state.adGunExpiresAt > 0 && this.now >= this.state.adGunExpiresAt && !this.pendingAd && !this.isRewardedAdBusy()) {
            this.state.backpack = this.state.backpack.map(item => item && item.pendingAd ? null : item);
            this.state.adGunExpiresAt = 0;
            changed = true;
        }
        return changed;
    }
    tick() { if (this.expire())
        this.changed(); }
    snapshot() {
        this.tick();
        return { unlocked: this.unlocked(), unlockMessage: this.policy('weapons').message,
            lockMessage: this.policy('weaponLock').message, starMessage: this.policy('weaponStar').message,
            backpack: this.state.backpack.map(copyItem), equipment: this.state.equipment.map(copyItem), collection: this.state.collection.slice(),
            diamonds: this.state.diamonds, starGems: this.state.starGems, stageValueMax: this.state.stageValueMax,
            lockUnlocked: this.lockUnlocked(), starUnlocked: this.starUnlocked(), stars: this.state.starLevels.map((level, index) => ({
                level, failureCount: this.state.starFailures[index], cost: level === exports.MAX_STAR_LEVEL ? null : (level + 1) * 50,
                successPercent: getStarSuccessPercent(level, this.state.starFailures[index]), bonusPercent: getStarBonusPercent(level),
                nextBonusPercent: level === exports.MAX_STAR_LEVEL ? null : getStarBonusPercent(level + 1), multiplier: getStarMultiplier(level),
            })),
            auto: { unlocked: this.autoUnlocked(), enabled: this.autoUnlocked() && this.state.autoEnabled,
                maxMaterialGrade: this.maxAutoGrade(), configuredMaxGrade: this.state.autoMergeDegree },
            dailyAdAvailable: this.dailyAdUnlocked() && this.dailyAdUsed() < EconomyConfig_1.ECONOMY_CONFIG.weapons.dailyAd.dailyLimit,
            dailyAdUnlocked: this.dailyAdUnlocked(),
            pendingAd: this.pendingAd ? { ...this.pendingAd } : null, adGunExpiresAt: this.state.adGunExpiresAt,
            firstMerge: this.state.firstMerge, revision: this.revisionValue };
    }
    serialize() {
        return { ...this.state, backpack: this.state.backpack.map(copyItem), equipment: this.state.equipment.map(copyItem),
            collection: this.state.collection.slice(), starLevels: this.state.starLevels.slice(), starFailures: this.state.starFailures.slice() };
    }
    drainNotifications() { return this.notices.splice(0); }
    draw() {
        var _a;
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        const tutorial = (_a = this.getTutorialState) === null || _a === void 0 ? void 0 : _a.call(this);
        if ((tutorial === null || tutorial === void 0 ? void 0 : tutorial.active) && (tutorial.step !== 'gunBuy' || tutorial.drawCount >= ContentConfig_1.WEAPON_TUTORIAL_RULES.drawCount))
            return this.fail('tutorialStep');
        this.tick();
        const at = this.freeSlot();
        if (at < 0)
            return this.fail('backpackFull');
        if (this.state.diamonds < WeaponCatalog_1.DRAW_PRICE)
            return this.fail('notEnoughDiamonds');
        const weights = EconomyConfig_1.ECONOMY_CONFIG.weapons.drawGradePercents;
        const activeGrades = weights.map((weight, grade) => ({ weight, grade })).filter(item => item.weight > 0);
        let grade = (tutorial === null || tutorial === void 0 ? void 0 : tutorial.active) ? ContentConfig_1.WEAPON_TUTORIAL_RULES.drawGrade : activeGrades[0].grade;
        if (!(tutorial === null || tutorial === void 0 ? void 0 : tutorial.active) && activeGrades.length > 1) {
            let roll = this.randomIndex(100);
            for (const entry of activeGrades) {
                if (roll < entry.weight) {
                    grade = entry.grade;
                    break;
                }
                roll -= entry.weight;
            }
        }
        const candidates = (0, WeaponCatalog_1.getWeaponIdsForGrade)(grade), weaponId = candidates[this.randomIndex(candidates.length)];
        this.state.diamonds -= WeaponCatalog_1.DRAW_PRICE;
        this.state.backpack[at] = { weaponId, locked: false, pendingAd: false };
        const result = this.collect(weaponId);
        this.changed(false, { type: 'draw', weaponId });
        return result;
    }
    swapBackpack(a, b) {
        var _a, _b;
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        this.tick();
        if (!integer(a, 0, exports.BACKPACK_CAPACITY - 1) || !integer(b, 0, exports.BACKPACK_CAPACITY - 1) || a === b)
            return this.fail('invalidSlot');
        if (((_a = this.state.backpack[a]) === null || _a === void 0 ? void 0 : _a.pendingAd) || ((_b = this.state.backpack[b]) === null || _b === void 0 ? void 0 : _b.pendingAd))
            return this.fail('pendingAd');
        [this.state.backpack[a], this.state.backpack[b]] = [this.state.backpack[b], this.state.backpack[a]];
        this.changed();
        return { ok: true };
    }
    moveBackpack(from, to, displacedTo = from) {
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        if (!integer(from, 0, exports.BACKPACK_CAPACITY - 1) || !integer(to, 0, exports.BACKPACK_CAPACITY - 1) ||
            !integer(displacedTo, 0, exports.BACKPACK_CAPACITY - 1) || from === to || displacedTo === to)
            return this.fail('invalidSlot');
        const source = this.state.backpack[from], target = this.state.backpack[to];
        if (!source)
            return this.fail('emptySlot');
        if (target && displacedTo !== from && this.state.backpack[displacedTo])
            return this.fail('occupiedSlot');
        this.state.backpack[from] = null;
        if (target)
            this.state.backpack[displacedTo] = target;
        this.state.backpack[to] = source;
        this.changed();
        return { ok: true, weaponId: source.weaponId };
    }
    equip(backpackIndex, slotIndex) {
        var _a, _b;
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        const tutorial = (_a = this.getTutorialState) === null || _a === void 0 ? void 0 : _a.call(this);
        if ((tutorial === null || tutorial === void 0 ? void 0 : tutorial.active) && (tutorial.step !== 'gunEquip' || ((_b = this.state.backpack[backpackIndex]) === null || _b === void 0 ? void 0 : _b.weaponId) !== ContentConfig_1.WEAPON_TUTORIAL_RULES.mergeWeaponId))
            return this.fail('tutorialStep');
        this.tick();
        if (!integer(backpackIndex, 0, exports.BACKPACK_CAPACITY - 1) || !integer(slotIndex, 0, exports.EQUIPMENT_CAPACITY - 1))
            return this.fail('invalidSlot');
        const item = this.state.backpack[backpackIndex];
        if (!item)
            return this.fail('emptySlot');
        if (item.pendingAd)
            return this.fail('pendingAd');
        this.state.backpack[backpackIndex] = this.state.equipment[slotIndex];
        this.state.equipment[slotIndex] = item;
        this.changed(true, { type: 'equip', weaponId: item.weaponId });
        return { ok: true, weaponId: item.weaponId };
    }
    unequip(slotIndex) {
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        if (this.tutorialActive())
            return this.fail('tutorialStep');
        this.tick();
        if (!integer(slotIndex, 0, exports.EQUIPMENT_CAPACITY - 1))
            return this.fail('invalidSlot');
        const item = this.state.equipment[slotIndex];
        if (!item)
            return this.fail('emptySlot');
        if (this.state.equipment.filter(Boolean).length <= 1)
            return this.fail('lastWeapon');
        const at = this.freeSlot();
        if (at < 0)
            return this.fail('backpackFull');
        this.state.backpack[at] = item;
        this.state.equipment[slotIndex] = null;
        this.changed(true);
        return { ok: true, weaponId: item.weaponId };
    }
    swapEquipment(a, b) {
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        if (this.tutorialActive())
            return this.fail('tutorialStep');
        if (!integer(a, 0, exports.EQUIPMENT_CAPACITY - 1) || !integer(b, 0, exports.EQUIPMENT_CAPACITY - 1) || a === b)
            return this.fail('invalidSlot');
        if (!this.state.equipment[a])
            return this.fail('emptySlot');
        [this.state.equipment[a], this.state.equipment[b]] = [this.state.equipment[b], this.state.equipment[a]];
        this.changed(true);
        return { ok: true };
    }
    toggleLock(location) {
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        if (this.tutorialActive())
            return this.fail('tutorialStep');
        this.tick();
        if (!this.lockUnlocked())
            return this.fail('locked');
        const list = location.kind === 'backpack' ? this.state.backpack : location.kind === 'equipment' ? this.state.equipment : null;
        if (!list || !integer(location.index, 0, list.length - 1))
            return this.fail('invalidSlot');
        const item = list[location.index];
        if (!item)
            return this.fail('emptySlot');
        if (item.pendingAd)
            return this.fail('pendingAd');
        list[location.index] = { ...item, locked: !item.locked };
        this.changed();
        return { ok: true };
    }
    merge(from, to) {
        var _a;
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        const tutorial = (_a = this.getTutorialState) === null || _a === void 0 ? void 0 : _a.call(this);
        if ((tutorial === null || tutorial === void 0 ? void 0 : tutorial.active) && tutorial.step !== 'gunMerge')
            return this.fail('tutorialStep');
        this.tick();
        const reason = this.mergeFailure(from, to);
        if (reason)
            return this.fail(reason);
        const grade = (0, WeaponCatalog_1.getWeaponGrade)(this.state.backpack[from].weaponId);
        if ((tutorial === null || tutorial === void 0 ? void 0 : tutorial.active) && grade !== ContentConfig_1.WEAPON_TUTORIAL_RULES.drawGrade)
            return this.fail('tutorialStep');
        const candidates = (0, WeaponCatalog_1.getWeaponIdsForGrade)(grade + 1);
        const weaponId = (tutorial === null || tutorial === void 0 ? void 0 : tutorial.active) ? ContentConfig_1.WEAPON_TUTORIAL_RULES.mergeWeaponId : candidates[this.randomIndex(candidates.length)];
        this.state.backpack[from] = null;
        this.state.backpack[to] = { weaponId, locked: false, pendingAd: false };
        const result = this.collect(weaponId);
        this.state.firstMerge = true;
        this.offerAdWeapon();
        this.changed(false, { type: 'merge', weaponId });
        return result;
    }
    mergeFailure(from, to) {
        if (!integer(from, 0, exports.BACKPACK_CAPACITY - 1) || !integer(to, 0, exports.BACKPACK_CAPACITY - 1) || from === to)
            return 'invalidSlot';
        const a = this.state.backpack[from], b = this.state.backpack[to];
        if (!a || !b)
            return 'emptySlot';
        if (a.pendingAd || b.pendingAd)
            return 'pendingAd';
        if (a.locked || b.locked)
            return 'protected';
        const grade = (0, WeaponCatalog_1.getWeaponGrade)(a.weaponId);
        if (grade !== (0, WeaponCatalog_1.getWeaponGrade)(b.weaponId))
            return 'differentGrade';
        return grade >= WeaponCatalog_1.WEAPON_GRADES.length - 1 ? 'maxGrade' : null;
    }
    offerAdWeapon() {
        if (this.tutorialActive())
            return;
        if (!this.content.isAdEnabled('weapon-unlock'))
            return;
        if (!this.state.firstMerge || this.state.adGunOffered || !this.state.adGunEnabled || this.highestGrade() >= EconomyConfig_1.ECONOMY_CONFIG.weapons.offer.maximumOwnedGradeExclusive)
            return;
        const at = this.freeSlot();
        if (at < 0)
            return;
        this.state.backpack[at] = { weaponId: WeaponCatalog_1.AD_WEAPON_ID, locked: false, pendingAd: true };
        this.state.adGunExpiresAt = this.now + exports.AD_WEAPON_DURATION_MS;
        this.state.adGunOffered = true;
    }
    setAutoMerge(enabled) {
        if (this.tutorialActive())
            return this.fail('tutorialStep');
        this.tick();
        if (typeof enabled !== 'boolean')
            return this.fail('invalidValue');
        if (enabled && !this.autoUnlocked())
            return this.fail('locked');
        this.state.autoEnabled = enabled;
        this.changed();
        return { ok: true };
    }
    autoMergePair(from, to, fromWeaponId, toWeaponId) {
        if (this.tutorialActive())
            return this.fail('tutorialStep');
        this.tick();
        const reason = this.autoMergePairFailure({ fromIndex: from, toIndex: to, fromWeaponId, toWeaponId });
        if (reason)
            return this.fail(reason);
        return this.merge(from, to);
    }
    findAutoMergePair(excludedBackpackIndices = []) {
        if (this.tutorialActive())
            return null;
        if (!this.autoUnlocked() || !this.state.autoEnabled)
            return null;
        const limit = this.maxAutoGrade(), excluded = new Set(excludedBackpackIndices);
        for (let grade = 0; grade <= limit; grade++) {
            const pair = [];
            for (let index = 0; index < exports.BACKPACK_CAPACITY; index++) {
                const item = this.state.backpack[index];
                if (!excluded.has(index) && item && !item.locked && !item.pendingAd && (0, WeaponCatalog_1.getWeaponGrade)(item.weaponId) === grade)
                    pair.push(index);
                if (pair.length === 2)
                    return { fromIndex: pair[1], toIndex: pair[0],
                        fromWeaponId: item.weaponId, toWeaponId: this.state.backpack[pair[0]].weaponId };
            }
        }
        return null;
    }
    isAutoMergePairValid(pair) { return this.autoMergePairFailure(pair) === null; }
    autoMergePairFailure(pair) {
        if (this.tutorialActive())
            return 'tutorialStep';
        if (!this.autoUnlocked() || !this.state.autoEnabled)
            return 'autoInactive';
        const { fromIndex: from, toIndex: to, fromWeaponId, toWeaponId } = pair;
        if (!integer(from, 0, exports.BACKPACK_CAPACITY - 1) || !integer(to, 0, exports.BACKPACK_CAPACITY - 1) || from === to)
            return 'invalidSlot';
        const a = this.state.backpack[from], b = this.state.backpack[to];
        if (!a || !b || a.weaponId !== fromWeaponId || b.weaponId !== toWeaponId)
            return 'noPair';
        const limit = this.maxAutoGrade();
        if ((0, WeaponCatalog_1.getWeaponGrade)(a.weaponId) > limit || (0, WeaponCatalog_1.getWeaponGrade)(b.weaponId) > limit)
            return 'noPair';
        return this.mergeFailure(from, to);
    }
    autoMergeStep(excludedBackpackIndices = []) {
        if (this.tutorialActive())
            return this.fail('tutorialStep');
        this.tick();
        if (!this.autoUnlocked() || !this.state.autoEnabled)
            return this.fail('autoInactive');
        const pair = this.findAutoMergePair(excludedBackpackIndices);
        return pair ? this.autoMergePair(pair.fromIndex, pair.toIndex, pair.fromWeaponId, pair.toWeaponId) : this.fail('noPair');
    }
    upgradeStar(slotIndex) {
        if (!this.unlocked())
            return { ok: false, reason: 'weaponsLocked', status: 'locked' };
        if (this.tutorialActive())
            return { ok: false, reason: 'tutorialStep', status: 'locked' };
        if (!integer(slotIndex, 0, exports.EQUIPMENT_CAPACITY - 1))
            return { ok: false, reason: 'invalidSlot', status: 'invalid' };
        if (!this.starUnlocked())
            return { ok: false, reason: 'locked', status: 'locked' };
        const level = this.state.starLevels[slotIndex];
        if (level >= exports.MAX_STAR_LEVEL)
            return { ok: false, reason: 'max', status: 'max' };
        const cost = (level + 1) * 50;
        if (this.state.starGems < cost)
            return { ok: false, reason: 'notEnoughGem', status: 'notEnoughGem' };
        const success = this.randomIndex(100) < getStarSuccessPercent(level, this.state.starFailures[slotIndex]);
        this.state.starGems -= cost;
        if (success) {
            this.state.starLevels[slotIndex]++;
            this.state.starFailures[slotIndex] = 0;
        }
        else
            this.state.starFailures[slotIndex] = Math.min(Number.MAX_SAFE_INTEGER, this.state.starFailures[slotIndex] + 1);
        this.changed(success);
        return { ok: true, status: success ? 'success' : 'failure' };
    }
    beginAd(kind) {
        if (!['daily', 'weapon'].includes(kind))
            return this.fail('invalidAd');
        if (!this.unlocked())
            return this.fail('weaponsLocked');
        if (this.tutorialActive())
            return this.fail('tutorialStep');
        if (!this.content.isAdEnabled(kind === 'daily' ? 'weapon-daily' : 'weapon-unlock'))
            return this.fail('adsDisabled');
        this.tick();
        if (this.pendingAd || this.isRewardedAdBusy())
            return this.fail('adInProgress');
        if (kind === 'daily') {
            if (!this.dailyAdUnlocked())
                return this.fail('locked');
            if (this.dailyAdUsed() >= EconomyConfig_1.ECONOMY_CONFIG.weapons.dailyAd.dailyLimit)
                return this.fail('dailyUsed');
            if (this.freeSlot() < 0)
                return this.fail('backpackFull');
        }
        if (kind === 'weapon' && !this.state.backpack.some(item => item && item.pendingAd))
            return this.fail('noAdWeapon');
        if (this.state.nextAdId >= Number.MAX_SAFE_INTEGER)
            return this.fail('adTokenExhausted');
        const token = `weapon-ad-${this.state.nextAdId++}`;
        this.pendingAd = { token, kind };
        this.changed();
        return { ok: true, token };
    }
    completeAd(token, outcome) {
        const pending = this.pendingAd;
        if (!pending || pending.token !== token)
            return this.fail('staleAd');
        if (!['success', 'cancel', 'failure'].includes(outcome))
            return this.fail('invalidAdOutcome');
        if (this.tutorialActive()) {
            this.pendingAd = null;
            this.expire();
            this.changed();
            return this.fail('tutorialStep');
        }
        const allowed = this.unlocked() && this.content.isAdEnabled(pending.kind === 'daily' ? 'weapon-daily' : 'weapon-unlock');
        if (!allowed || (pending.kind === 'daily' && !this.dailyAdUnlocked())) {
            this.pendingAd = null;
            this.expire();
            this.changed();
            return this.fail(allowed ? 'locked' : 'adsDisabled');
        }
        if (outcome !== 'success') {
            this.pendingAd = null;
            this.expire();
            this.changed();
            return this.fail(outcome === 'cancel' ? 'adCancelled' : 'adFailed');
        }
        let result = { ok: true };
        if (pending.kind === 'daily') {
            const at = this.freeSlot();
            if (this.dailyAdUsed() >= EconomyConfig_1.ECONOMY_CONFIG.weapons.dailyAd.dailyLimit)
                result = this.fail('dailyUsed');
            else if (at < 0)
                result = this.fail('backpackFull');
            else {
                const candidates = (0, WeaponCatalog_1.getWeaponIdsForGrade)(EconomyConfig_1.ECONOMY_CONFIG.weapons.dailyAd.grade), weaponId = candidates[this.randomIndex(candidates.length)];
                this.state.backpack[at] = { weaponId, locked: false, pendingAd: false };
                this.state.dailyAdCount = this.dailyAdUsed() + 1;
                this.state.dailyAdDate = dateKey(this.now);
                result = this.collect(weaponId);
            }
        }
        else {
            const at = this.state.backpack.findIndex(item => item && item.pendingAd);
            if (at < 0)
                result = this.fail('noAdWeapon');
            else {
                const weaponId = this.state.backpack[at].weaponId;
                this.state.backpack[at] = { weaponId, locked: false, pendingAd: false };
                this.state.adGunExpiresAt = 0;
                result = this.collect(weaponId, true);
            }
        }
        this.pendingAd = null;
        this.expire();
        this.changed();
        return result;
    }
    recordStageValue(value) {
        if (integer(value) && value > this.state.stageValueMax) {
            this.state.stageValueMax = value;
            this.changed();
        }
    }
    configureTest(config) {
        if (this.environment !== 'test')
            return this.fail('testOnly');
        if ((config.starLevels && (config.starLevels.length !== exports.EQUIPMENT_CAPACITY || config.starLevels.some(level => !integer(level, 0, exports.MAX_STAR_LEVEL)))) ||
            (config.starFailures && (config.starFailures.length !== exports.EQUIPMENT_CAPACITY || config.starFailures.some(count => !integer(count)))))
            return this.fail('invalidTestConfiguration');
        const next = this.serialize();
        for (const field of ['stageValueMax', 'diamonds', 'starGems', 'autoEnabled', 'autoMergeDegree', 'adGunEnabled']) {
            if (config[field] !== undefined)
                next[field] = config[field];
        }
        if (config.starLevels)
            next.starLevels = [...config.starLevels];
        if (config.starFailures)
            next.starFailures = [...config.starFailures];
        try {
            this.state = WeaponInventory.validate(next);
        }
        catch (_) {
            return this.fail('invalidTestConfiguration');
        }
        this.expire();
        this.changed();
        return { ok: true };
    }
    grantTestWeapon(weaponId) {
        if (this.environment !== 'test')
            return this.fail('testOnly');
        if (!(0, WeaponCatalog_1.isWeaponId)(weaponId))
            return this.fail('invalidWeapon');
        this.tick();
        const at = this.freeSlot();
        if (at < 0)
            return this.fail('backpackFull');
        this.state.backpack[at] = { weaponId, locked: false, pendingAd: false };
        const result = this.collect(weaponId);
        this.changed();
        return result;
    }
    refillTestResources() {
        if (!this.isTestEnvironment)
            return this.fail('testOnly');
        const resources = this.content.config.testResources;
        this.state.diamonds = Math.max(this.state.diamonds, resources.diamonds);
        this.state.starGems = Math.max(this.state.starGems, resources.starGems);
        this.changed();
        return { ok: true };
    }
    grantTestStarGems(amount) {
        if (this.environment !== 'test')
            return this.fail('testOnly');
        return this.grantStarGems(amount);
    }
    grantStarGems(amount) {
        if (!integer(amount) || !integer(this.state.starGems + amount))
            return this.fail('invalidAmount');
        if (amount > 0) {
            this.state.starGems += amount;
            this.changed();
        }
        return { ok: true };
    }
    advanceTestTime(milliseconds) {
        if (this.environment !== 'test')
            return this.fail('testOnly');
        if (!integer(milliseconds) || !integer(this.state.testTimeOffsetMs + milliseconds) ||
            !integer(this.now + milliseconds, 0, MAX_DATE_MS - exports.AD_WEAPON_DURATION_MS))
            return this.fail('invalidTime');
        this.state.testTimeOffsetMs += milliseconds;
        this.expire();
        this.changed();
        return { ok: true };
    }
}
exports.WeaponInventory = WeaponInventory;
