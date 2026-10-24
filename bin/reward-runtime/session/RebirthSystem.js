"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.RebirthSystem = exports.REBIRTH_ANIMATION_SECONDS = void 0;
const DecimalAmount_1 = require("../core/DecimalAmount");
const EconomyConfig_1 = require("../core/EconomyConfig");
const OriginalInfVal_1 = require("../core/OriginalInfVal");
const RebirthEconomy_1 = require("./RebirthEconomy");
const ContentUnlockManager_1 = require("./ContentUnlockManager");
const UpgradeEconomy_1 = require("./UpgradeEconomy");
exports.REBIRTH_ANIMATION_SECONDS = 2;
let instanceSerial = 0;
function validDate(value) { return typeof value === 'string' && /^(?:|\d{4}-\d{2}-\d{2})$/.test(value); }
function today(clock) {
    const date = new Date(clock());
    if (!Number.isFinite(date.getTime()))
        throw new Error('Invalid rebirth clock');
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
class RebirthSystem {
    constructor(clock = Date.now, content = new ContentUnlockManager_1.ContentUnlockManager()) {
        this.clock = clock;
        this.content = content;
        this.data = { ruby: OriginalInfVal_1.OriginalInfVal.fromAmount(DecimalAmount_1.DecimalAmount.from(EconomyConfig_1.ECONOMY_CONFIG.newGame.ruby)), levels: [0, 0, 0], count: 0, adUsedDate: '', adUsedCount: 0 };
        this.phaseValue = 'idle';
        this.elapsed = 0;
        this.kind = 'normal';
        this.pendingToken = null;
        this.instanceId = ++instanceSerial;
        this.requestSerial = 0;
        this.rewardPercent = EconomyConfig_1.ECONOMY_CONFIG.rebirth.defaultRewardPercent;
    }
    get animating() { return this.phaseValue === 'animating'; }
    get adPending() { return this.phaseValue === 'adPending'; }
    get count() { return this.data.count; }
    get levels() { return this.data.levels.slice(); }
    adUsedToday() { return this.data.adUsedDate === today(this.clock) ? this.data.adUsedCount : 0; }
    snapshot(position, history) {
        var _a, _b;
        const progress = { stageValueMax: history, currentStageIndex: position.stageIndex };
        const entry = this.content.get('rebirth', progress), action = this.content.get('rebirthAction', progress);
        const adEnabled = this.content.isAdEnabled('rebirth');
        const adAvailable = adEnabled && this.adUsedToday() < EconomyConfig_1.ECONOMY_CONFIG.rebirth.adDailyLimit;
        return { ruby: this.data.ruby.amount, count: this.data.count,
            upgrades: UpgradeEconomy_1.UPGRADE_TYPES.map((type, index) => {
                var _a, _b;
                const level = this.data.levels[index], price = (_b = (_a = (0, RebirthEconomy_1.getRebirthPrice)(level)) === null || _a === void 0 ? void 0 : _a.amount) !== null && _b !== void 0 ? _b : null;
                return { type, level, value: (0, RebirthEconomy_1.getRebirthBonus)(type, level).amount, price,
                    affordable: entry.unlocked && this.phaseValue === 'idle' && price !== null && this.data.ruby.amount.compare(price) >= 0 };
            }), reward: this.reward(position).amount,
            enhancedReward: this.reward(position, true).amount,
            unlocked: entry.unlocked,
            canRebirth: entry.unlocked && action.unlocked && this.phaseValue === 'idle',
            adAvailable, adEnabled, unlockMessage: (_a = entry.message) !== null && _a !== void 0 ? _a : '', actionMessage: (_b = action.message) !== null && _b !== void 0 ? _b : '',
            phase: this.phaseValue, animationElapsed: this.elapsed };
    }
    reward(position, enhanced = false) {
        const floor = this.content.config.testResources.rebirthRewardFloorStage - 1;
        const effective = this.content.config.bypassProgression && position.stageIndex < floor
            ? { stageIndex: floor, waveIndex: 0 } : position;
        return (0, RebirthEconomy_1.getRebirthReward)(effective, this.rewardPercent, enhanced);
    }
    canExecute(position) {
        return this.content.isUnlocked('rebirth') && this.content.isUnlocked('rebirthAction', { currentStageIndex: position.stageIndex });
    }
    refillTestResources() {
        if (this.content.config.environment !== 'test' || !this.content.config.testResources.fundRebirthUpgrades || this.phaseValue !== 'idle')
            return false;
        const budget = (0, RebirthEconomy_1.remainingRebirthUpgradeBudget)(this.data.levels);
        if (this.data.ruby.amount.compare(budget) >= 0)
            return false;
        this.data = { ...this.data, ruby: OriginalInfVal_1.OriginalInfVal.fromAmount(budget) };
        return true;
    }
    configureModifiers(modifiers) {
        if (modifiers.rewardPercent === undefined)
            return;
        if (!Number.isSafeInteger(modifiers.rewardPercent) || modifiers.rewardPercent < 0)
            throw new Error('Invalid rebirth reward percent');
        this.rewardPercent = modifiers.rewardPercent;
    }
    request(kind, position) {
        if (kind !== 'normal' && kind !== 'ad')
            return { ok: false, reason: 'invalid' };
        if (this.phaseValue !== 'idle')
            return { ok: false, reason: 'busy' };
        if (!this.canExecute(position))
            return { ok: false, reason: 'locked' };
        if (kind === 'ad' && !this.content.isAdEnabled('rebirth'))
            return { ok: false, reason: 'adsDisabled' };
        if (this.data.count >= Number.MAX_SAFE_INTEGER)
            return { ok: false, reason: 'countLimit' };
        if (kind === 'ad' && this.adUsedToday() >= EconomyConfig_1.ECONOMY_CONFIG.rebirth.adDailyLimit)
            return { ok: false, reason: 'adUsed' };
        this.kind = kind;
        this.elapsed = 0;
        if (kind === 'normal') {
            this.phaseValue = 'animating';
            return { ok: true };
        }
        this.pendingToken = `rebirth:${this.instanceId}:${++this.requestSerial}`;
        this.phaseValue = 'adPending';
        return { ok: true, token: this.pendingToken };
    }
    completeAd(token, outcome, position) {
        if (this.phaseValue !== 'adPending' || token !== this.pendingToken)
            return { ok: false, reason: 'staleAd' };
        if (!['success', 'cancel', 'failure'].includes(outcome))
            return { ok: false, reason: 'invalid' };
        this.pendingToken = null;
        this.phaseValue = 'idle';
        if (outcome !== 'success')
            return { ok: false, reason: outcome };
        if (!this.content.isAdEnabled('rebirth'))
            return { ok: false, reason: 'adsDisabled' };
        if (!this.canExecute(position))
            return { ok: false, reason: 'locked' };
        if (this.adUsedToday() >= EconomyConfig_1.ECONOMY_CONFIG.rebirth.adDailyLimit)
            return { ok: false, reason: 'adUsed' };
        this.phaseValue = 'animating';
        this.elapsed = 0;
        return { ok: true };
    }
    cancelAd() {
        if (this.phaseValue === 'adPending') {
            this.pendingToken = null;
            this.phaseValue = 'idle';
        }
    }
    advance(dt) {
        if (!this.animating || !Number.isFinite(dt) || dt <= 0)
            return false;
        this.elapsed = Math.min(exports.REBIRTH_ANIMATION_SECONDS, this.elapsed + dt);
        return this.elapsed + 1e-10 >= exports.REBIRTH_ANIMATION_SECONDS;
    }
    prepareCompletion(position) {
        if (!this.animating || this.elapsed + 1e-10 < exports.REBIRTH_ANIMATION_SECONDS)
            throw new Error('Rebirth is not ready');
        const reward = this.reward(position, this.kind === 'ad');
        return { ruby: this.data.ruby.add(reward), count: this.data.count + 1,
            adUsedDate: this.kind === 'ad' ? today(this.clock) : this.data.adUsedDate,
            adUsedCount: this.kind === 'ad' ? this.adUsedToday() + 1 : this.data.adUsedCount };
    }
    commitCompletion(completion) {
        this.data = { ...this.data, ...completion };
        this.phaseValue = 'idle';
        this.elapsed = 0;
        this.pendingToken = null;
        this.kind = 'normal';
    }
    purchase(type) {
        if (!(0, UpgradeEconomy_1.isUpgradeType)(type) || this.phaseValue !== 'idle' || !this.content.isUnlocked('rebirth'))
            return false;
        const index = UpgradeEconomy_1.UPGRADE_TYPES.indexOf(type), price = (0, RebirthEconomy_1.getRebirthPrice)(this.data.levels[index]);
        if (!price || this.data.ruby.amount.compare(price.amount) < 0)
            return false;
        const ruby = this.data.ruby.sub(price), levels = this.data.levels.slice();
        levels[index]++;
        this.data = { ...this.data, ruby, levels };
        return true;
    }
    serialize() { return { ruby: this.data.ruby.toJSON(), levels: this.data.levels.slice(), count: this.data.count, adUsedDate: this.data.adUsedDate, adUsedCount: this.data.adUsedCount }; }
    restore(value) {
        const saved = value;
        if (!saved || !Array.isArray(saved.levels) || saved.levels.length !== 3 || !saved.levels.every(RebirthEconomy_1.isRebirthLevel) ||
            typeof saved.count !== 'number' || !Number.isSafeInteger(saved.count) || saved.count < 0 || !validDate(saved.adUsedDate))
            throw new Error('Invalid rebirth save');
        const ruby = OriginalInfVal_1.OriginalInfVal.fromSerialized(saved.ruby);
        const adUsedCount = saved.adUsedCount;
        if (typeof adUsedCount !== 'number' || !Number.isSafeInteger(adUsedCount) || adUsedCount < 0)
            throw new Error('Invalid rebirth ad count');
        if (ruby.amount.isNegative || ruby.amount.digits.length > 100000)
            throw new Error('Invalid rebirth wallet');
        this.data = { ruby, levels: saved.levels.slice(), count: saved.count, adUsedDate: saved.adUsedDate, adUsedCount };
    }
    configureTest(config) {
        var _a, _b, _c;
        if (this.phaseValue !== 'idle')
            return false;
        try {
            const current = this.serialize();
            let ruby = current.ruby;
            if (config.ruby !== undefined) {
                const amount = DecimalAmount_1.DecimalAmount.from(config.ruby);
                ruby = OriginalInfVal_1.OriginalInfVal.fromAmount(amount).toJSON();
            }
            this.restore({ ruby, levels: (_a = config.levels) !== null && _a !== void 0 ? _a : current.levels, count: (_b = config.count) !== null && _b !== void 0 ? _b : current.count,
                adUsedDate: (_c = config.adUsedDate) !== null && _c !== void 0 ? _c : current.adUsedDate,
                adUsedCount: config.adUsedDate === undefined ? current.adUsedCount : (config.adUsedDate ? Math.max(1, EconomyConfig_1.ECONOMY_CONFIG.rebirth.adDailyLimit) : 0) });
            return true;
        }
        catch (_) {
            return false;
        }
    }
}
exports.RebirthSystem = RebirthSystem;
