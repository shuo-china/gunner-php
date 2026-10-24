"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.OfflineProgressEngine = exports.OFFLINE_SOURCE = void 0;
exports.effectiveMaxOfflineSeconds = effectiveMaxOfflineSeconds;
exports.computeOfflineElapsed = computeOfflineElapsed;
exports.calculateOfflineClearInfo = calculateOfflineClearInfo;
exports.runOfflineProgress = runOfflineProgress;
exports.runOfflineProgressAsync = runOfflineProgressAsync;
const DecimalAmount_1 = require("../core/DecimalAmount");
const EconomyConfig_1 = require("../core/EconomyConfig");
const OriginalInfVal_1 = require("../core/OriginalInfVal");
const StageBalance_1 = require("../core/StageBalance");
const StageProgression_1 = require("./StageProgression");
const UpgradeEconomy_1 = require("./UpgradeEconomy");
exports.OFFLINE_SOURCE = Object.freeze({
    maxIterations: 30000, frameBudgetMs: 60, remainingSecondsEpsilon: 0.05,
    stageTimeLimit: 180, minActionSeconds: 0.1, minTraversalPerTree: 0.05,
    minimumMoveMultiplier: 0.01, maximumKillSeconds: 1e9, maximumUpgradeWaitSeconds: 31536000,
    maximumConvertedValue: 1e15, pierceTargets: 10, laserTargets: 10, blastTargets: 5,
});
const f = Math.fround;
const zero = () => OriginalInfVal_1.OriginalInfVal.fromInt(0);
const hundred = () => OriginalInfVal_1.OriginalInfVal.fromInt(100);
const clamp = (n, low, high) => Math.max(low, Math.min(high, n));
const clock = () => typeof performance !== 'undefined' ? performance.now() : Date.now();
function effectiveMaxOfflineSeconds(extraMinutes = 0) {
    if (!Number.isFinite(extraMinutes) || extraMinutes < 0)
        throw new Error('Invalid offline extension');
    return f(f(EconomyConfig_1.ECONOMY_CONFIG.offline.maxOfflineSeconds) + f(extraMinutes * 60));
}
function computeOfflineElapsed(lastSeenMs, nowMs, extraMinutes = 0) {
    if (lastSeenMs === null || lastSeenMs === undefined || !Number.isFinite(lastSeenMs) || !Number.isFinite(nowMs))
        return null;
    const absentSeconds = f((nowMs - lastSeenMs) / 1000);
    if (!Number.isFinite(absentSeconds))
        return null;
    const elapsedSeconds = clamp(absentSeconds, 0, effectiveMaxOfflineSeconds(extraMinutes));
    return elapsedSeconds < EconomyConfig_1.ECONOMY_CONFIG.offline.minimumAbsentSeconds ? null
        : { elapsedSeconds, absentSeconds, isCapped: f(elapsedSeconds + 1) < absentSeconds };
}
function toInfVal(value) {
    return OriginalInfVal_1.OriginalInfVal.fromDouble(!Number.isFinite(value) || value > exports.OFFLINE_SOURCE.maximumConvertedValue
        ? exports.OFFLINE_SOURCE.maximumConvertedValue : value);
}
function modifiers(overrides = {}) {
    const result = {
        rebirthDamage: hundred(), rebirthSpeed: hundred(), rebirthMoney: hundred(),
        skinDamage: 100, skinSpeed: 100, skinMoney: 100, petMoney: hundred(), petSpeed: 100,
        fishDamage: 0, fishCriticalChance: 0, fishCriticalDamage: 0,
        relicDamage: hundred(), relicCriticalDamage: hundred(), relicMoney: hundred(), buffPack: false,
        ...overrides,
    };
    for (const value of Object.values(result)) {
        if (typeof value === 'number' && (!Number.isFinite(value) || value < 0))
            throw new Error('Invalid offline modifier');
        if (value instanceof OriginalInfVal_1.OriginalInfVal && value.amount.isNegative)
            throw new Error('Invalid offline modifier');
    }
    return result;
}
function averageDegree(wave, multipliers) {
    let value = 0;
    for (let i = 0; i < 3; i++)
        value += (StageBalance_1.STAGE_DEGREE_RATES[wave][i] / 100) * multipliers[i];
    return value;
}
function targetCount(weapon) {
    switch (weapon.type) {
        case 'LASER': return exports.OFFLINE_SOURCE.laserTargets;
        case 'PIERCE': return exports.OFFLINE_SOURCE.pierceTargets;
        case 'SHOTGUN': return Math.max(1, weapon.pelletCount);
        case 'EXPLOSIVE':
        case 'MISSILE':
        case 'BLAST_SNIPER': return exports.OFFLINE_SOURCE.blastTargets;
        default: return 1;
    }
}
function weaponDamage(weapon) {
    const coefficient = weapon.damage.split(/[eE]/)[0].replace(/^[+-]/, '').replace('.', '');
    return OriginalInfVal_1.OriginalInfVal.fromAmount(DecimalAmount_1.DecimalAmount.from(weapon.damage), coefficient.length);
}
function speedPercent(level, stage, m) {
    return clamp((0, UpgradeEconomy_1.getUpgradeValue)('speed', level).toNumber() + Math.trunc(m.rebirthSpeed.amount.toNumber())
        + m.skinSpeed - 200 - stage * 30, 100, 10000);
}
function clearInfo(levels, stage, wave, history, weapons, m, buff) {
    const count = (0, StageBalance_1.getStageTreeCount)(stage) * (wave + 1);
    const stageHealth = OriginalInfVal_1.OriginalInfVal.fromAmount((0, StageBalance_1.getStageHealth)(stage), (0, StageBalance_1.getStageHealthPrecision)(stage));
    let health = stageHealth.mul(toInfVal(averageDegree(wave, StageBalance_1.STAGE_HEALTH_MULTIPLIERS)));
    if (health.amount.compare(0) <= 0)
        health = OriginalInfVal_1.OriginalInfVal.fromInt(1);
    const bossHealth = wave === 4 ? stageHealth.mul(toInfVal(StageBalance_1.STAGE_HEALTH_MULTIPLIERS[3])) : zero();
    const chance = clamp(f(f(m.fishCriticalChance) / 100), 0, 1);
    const power = (0, UpgradeEconomy_1.getUpgradeMultiplier)('power', levels[0]), speed = speedPercent(levels[1], stage, m);
    const movement = Math.max(f(exports.OFFLINE_SOURCE.minimumMoveMultiplier), f(f(m.petSpeed) / 100));
    const replay = stage * 10 + wave < history;
    const minAction = f(f(f(exports.OFFLINE_SOURCE.minActionSeconds) / movement) * (replay ? 0.5 : 1));
    let rate = 0, bossDps = zero();
    for (const weapon of weapons) {
        let damage = weaponDamage(weapon).mul(power).div(100).mul(m.rebirthDamage).div(100).discardDecimal();
        damage = damage.mul(m.skinDamage).div(100).discardDecimal();
        damage = damage.mul(100 + m.fishDamage).div(100).discardDecimal();
        damage = damage.mul(m.relicDamage).div(100).discardDecimal().mul(buff).discardDecimal();
        const critical = damage.mul(125 + Math.trunc(m.fishCriticalDamage)).div(100).discardDecimal()
            .mul(m.relicCriticalDamage).div(100).discardDecimal();
        const expected = damage.mul(toInfVal(f(1 - chance))).add(critical.mul(toInfVal(chance)));
        if (expected.amount.compare(0) <= 0)
            continue;
        const interval = clamp(f(f(f(f(weapon.attackInterval) / speed) * 100) / buff), f(UpgradeEconomy_1.UPGRADE_SOURCE.minimumAttackInterval), f(UpgradeEconomy_1.UPGRADE_SOURCE.maximumAttackInterval));
        if (expected.amount.compare(health.amount) >= 0)
            rate += targetCount(weapon) / Math.max(interval, minAction);
        else {
            const ratio = health.div(expected).amount.toNumber();
            const hits = Math.max(1, Number.isFinite(ratio) ? Math.ceil(ratio) : exports.OFFLINE_SOURCE.maximumKillSeconds);
            const killTime = f(Math.min(hits * interval, exports.OFFLINE_SOURCE.maximumKillSeconds));
            rate += 1 / Math.max(killTime, minAction);
        }
        if (wave === 4)
            bossDps = bossDps.add(expected.div(toInfVal(interval)));
    }
    if (rate <= 0)
        return { combatTime: Infinity, regularKillRate: rate };
    const traversal = f(f(f(exports.OFFLINE_SOURCE.minTraversalPerTree) / movement) * (replay ? 0.5 : 1));
    const regularTime = Math.max(f(count / rate), f(traversal * count));
    let bossTime = 0;
    if (wave === 4) {
        if (bossDps.amount.compare(0) <= 0)
            return { combatTime: Infinity, regularKillRate: rate };
        const duration = bossHealth.div(bossDps).amount.toNumber();
        if (!Number.isFinite(duration))
            return { combatTime: Infinity, regularKillRate: rate };
        bossTime = Math.max(f(duration), minAction);
    }
    const combatTime = f(regularTime + bossTime);
    return { combatTime: !Number.isFinite(combatTime) || combatTime > exports.OFFLINE_SOURCE.stageTimeLimit ? Infinity : combatTime,
        regularKillRate: rate };
}
function calculateOfflineClearInfo(input) {
    const engine = new OfflineProgressEngine(input);
    return engine.currentClearInfo();
}
class OfflineProgressEngine {
    constructor(input) {
        var _a, _b, _c;
        this.iterations = 0;
        this.cleared = 0;
        (0, StageProgression_1.validateStagePosition)(input);
        if (input.levels.length !== 3 || !input.levels.every(UpgradeEconomy_1.isUpgradeLevel))
            throw new Error('Invalid offline upgrade levels');
        if (!Number.isSafeInteger(input.stageValueMax) || input.stageValueMax < 0)
            throw new Error('Invalid offline history');
        if (!Number.isFinite(input.elapsedSeconds) || input.elapsedSeconds < 0 ||
            (input.absentSeconds !== undefined && (!Number.isFinite(input.absentSeconds) || input.absentSeconds < 0)))
            throw new Error('Invalid offline duration');
        if (input.gold.amount.isNegative || ((_a = input.moneyAccum) === null || _a === void 0 ? void 0 : _a.amount.isNegative))
            throw new Error('Invalid offline wallet');
        this.levels = [input.levels[0], input.levels[1], input.levels[2]];
        this.startLevels = [...this.levels];
        this.startGold = this.money = input.gold;
        this.accumulated = (_b = input.moneyAccum) !== null && _b !== void 0 ? _b : zero();
        this.stage = input.stageIndex;
        this.wave = input.waveIndex;
        this.history = input.stageValueMax;
        this.timeLeft = f(input.elapsedSeconds);
        const absentSeconds = f((_c = input.absentSeconds) !== null && _c !== void 0 ? _c : input.elapsedSeconds);
        this.elapsed = { elapsedSeconds: this.timeLeft, absentSeconds, isCapped: f(this.timeLeft + 1) < absentSeconds };
        this.m = modifiers(input.modifiers);
        this.buff = this.m.buffPack ? EconomyConfig_1.ECONOMY_CONFIG.offline.permanentBuffMultiplier : 1;
        this.weapons = input.weapons.filter((weapon) => weapon !== null).map(weapon => ({ ...weapon }));
    }
    currentClearInfo() { return { ...this.values().info }; }
    get iterationCount() { return this.iterations; }
    get remainingSeconds() { return this.timeLeft; }
    get result() { return this.completed ? this.copyResult(this.completed) : null; }
    price(level) {
        const price = (0, UpgradeEconomy_1.getUpgradePrice)(level);
        return price ? OriginalInfVal_1.OriginalInfVal.fromAmount(price, 3) : null;
    }
    buyAffordable() {
        while (true) {
            let selected = -1;
            for (let i = 0; i < 3; i++) {
                if (this.levels[i] < UpgradeEconomy_1.MAX_UPGRADE_LEVEL && (selected < 0 || this.levels[i] < this.levels[selected]))
                    selected = i;
            }
            if (selected < 0)
                return;
            const price = this.price(this.levels[selected]);
            if (this.money.amount.compare(price.amount) < 0)
                return;
            this.money = this.money.sub(price);
            this.levels[selected]++;
        }
    }
    values() {
        var _a;
        const key = `${this.levels.join(',')}:${this.stage}:${this.wave}`;
        if (((_a = this.cached) === null || _a === void 0 ? void 0 : _a.key) === key)
            return this.cached;
        const info = clearInfo(this.levels, this.stage, this.wave, this.history, this.weapons, this.m, this.buff);
        const base = OriginalInfVal_1.OriginalInfVal.fromAmount((0, StageBalance_1.getStageMoney)(this.stage), (0, StageBalance_1.getStageMoneyPrecision)(this.stage));
        const perTree = base.mul(toInfVal(averageDegree(this.wave, EconomyConfig_1.ECONOMY_CONFIG.gold.degreeMultipliers)));
        const count = (0, StageBalance_1.getStageTreeCount)(this.stage) * (this.wave + 1);
        const perStage = perTree.mul(count).add(this.wave === 4 ? base.mul(toInfVal(EconomyConfig_1.ECONOMY_CONFIG.gold.degreeMultipliers[3])) : zero());
        this.cached = { key, info, perSecond: info.regularKillRate <= 0 ? zero() : this.applyMoney(perTree).mul(toInfVal(info.regularKillRate)),
            perStage: this.applyMoney(perStage) };
        return this.cached;
    }
    applyMoney(value) {
        const m = this.m;
        return value.mul((0, UpgradeEconomy_1.getUpgradeMultiplier)('money', this.levels[2])).div(100).mul(m.rebirthMoney).div(100)
            .mul(m.skinMoney).div(100).mul(Math.trunc(m.petMoney.amount.toNumber())).div(100)
            .mul(m.relicMoney).div(100).mul(this.buff);
    }
    addIncome(value) {
        this.money = this.money.add(value);
        this.accumulated = this.accumulated.add(value);
    }
    waitForUpgrade(perSecond) {
        if (perSecond.amount.compare(0) <= 0)
            return Infinity;
        let cheapest = null;
        for (const level of this.levels) {
            const price = this.price(level);
            if (price && (!cheapest || price.amount.compare(cheapest.amount) < 0))
                cheapest = price;
        }
        if (!cheapest || cheapest.amount.compare(0) <= 0)
            return Infinity;
        if (this.money.amount.compare(cheapest.amount) >= 0)
            return 0;
        return f(Math.min(cheapest.sub(this.money).div(perSecond).amount.toNumber(), exports.OFFLINE_SOURCE.maximumUpgradeWaitSeconds));
    }
    iteration() {
        this.buyAffordable();
        const { info, perSecond, perStage } = this.values();
        const clearDuration = Number.isFinite(info.combatTime)
            ? f(f(info.combatTime * f(EconomyConfig_1.ECONOMY_CONFIG.offline.clearTimeScale)) + f(EconomyConfig_1.ECONOMY_CONFIG.offline.stageClearDelaySeconds)) : Infinity;
        if (Number.isFinite(clearDuration) && clearDuration <= this.timeLeft) {
            this.addIncome(perStage);
            this.timeLeft = f(this.timeLeft - clearDuration);
            const next = (0, StageProgression_1.nextStagePosition)({ stageIndex: this.stage, waveIndex: this.wave });
            if ((0, StageProgression_1.stageValue)(next) <= this.history) {
                this.stage = next.stageIndex;
                this.wave = next.waveIndex;
                this.cleared++;
            }
            return;
        }
        if (!Number.isFinite(clearDuration)) {
            const wait = this.waitForUpgrade(perSecond);
            if (Number.isFinite(wait) && wait <= this.timeLeft) {
                this.addIncome(perSecond.mul(toInfVal(wait)));
                this.timeLeft = f(this.timeLeft - wait);
                return;
            }
        }
        if (perSecond.amount.compare(0) > 0)
            this.addIncome(perSecond.mul(toInfVal(this.timeLeft)));
        this.finish();
    }
    finish() {
        this.buyAffordable();
        this.completed = { ...this.elapsed, moneyGained: this.money.sub(this.startGold), moneyFinal: this.money,
            moneyAccumFinal: this.accumulated, levels: [...this.levels],
            levelsGained: [this.levels[0] - this.startLevels[0], this.levels[1] - this.startLevels[1], this.levels[2] - this.startLevels[2]],
            stageIndex: this.stage, waveIndex: this.wave, stageValueMax: this.history, stagesCleared: this.cleared };
    }
    copyResult(result) {
        return { ...result, levels: [...result.levels], levelsGained: [...result.levelsGained] };
    }
    step(frameBudgetMs = exports.OFFLINE_SOURCE.frameBudgetMs, now = clock) {
        if (frameBudgetMs <= 0 || Number.isNaN(frameBudgetMs))
            throw new Error('Invalid offline frame budget');
        if (this.completed)
            return this.copyResult(this.completed);
        const started = now();
        let ran = false;
        while (!this.completed) {
            if (this.timeLeft <= f(exports.OFFLINE_SOURCE.remainingSecondsEpsilon) || this.iterations >= exports.OFFLINE_SOURCE.maxIterations) {
                this.finish();
                break;
            }
            if (ran && now() - started >= frameBudgetMs)
                break;
            this.iterations++;
            this.iteration();
            ran = true;
        }
        return this.result;
    }
}
exports.OfflineProgressEngine = OfflineProgressEngine;
function runOfflineProgress(input) {
    return new OfflineProgressEngine(input).step(Infinity);
}
async function runOfflineProgressAsync(input, options) {
    const engine = new OfflineProgressEngine(input);
    let result;
    while (!(result = engine.step(options.frameBudgetMs, options.now)))
        await options.yieldFrame();
    return result;
}
