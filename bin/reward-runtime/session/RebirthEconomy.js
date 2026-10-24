"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.MAX_REBIRTH_UPGRADE_LEVEL = exports.REBIRTH_UPGRADE_TYPES = void 0;
exports.isRebirthLevel = isRebirthLevel;
exports.getRebirthReward = getRebirthReward;
exports.getRebirthPrice = getRebirthPrice;
exports.remainingRebirthUpgradeBudget = remainingRebirthUpgradeBudget;
exports.getRebirthBonus = getRebirthBonus;
exports.getRebirthPercent = getRebirthPercent;
const OriginalInfVal_1 = require("../core/OriginalInfVal");
const DecimalAmount_1 = require("../core/DecimalAmount");
const EconomyConfig_1 = require("../core/EconomyConfig");
exports.REBIRTH_UPGRADE_TYPES = ['power', 'speed', 'money'];
exports.MAX_REBIRTH_UPGRADE_LEVEL = 20000;
const hundred = OriginalInfVal_1.OriginalInfVal.fromInt(100);
const growth = OriginalInfVal_1.OriginalInfVal.fromDouble(1.05);
const latePriceGrowth = OriginalInfVal_1.OriginalInfVal.fromDouble(1.002);
const prices = [];
const bonuses = new Map();
const moneyBonuses = new Map();
let latePriceFactor = OriginalInfVal_1.OriginalInfVal.fromInt(1);
let remainingBudgets;
function isRebirthLevel(level) {
    return typeof level === 'number' && Number.isInteger(level) && level >= 0 && level <= exports.MAX_REBIRTH_UPGRADE_LEVEL;
}
function validateLevel(level) { if (!isRebirthLevel(level))
    throw new Error('Invalid rebirth upgrade level'); }
function validateType(type) { if (!exports.REBIRTH_UPGRADE_TYPES.includes(type))
    throw new Error('Invalid rebirth upgrade type'); }
function getRebirthReward(position, rewardPercent = EconomyConfig_1.ECONOMY_CONFIG.rebirth.defaultRewardPercent, enhanced = false) {
    if (!Number.isSafeInteger(position.stageIndex) || position.stageIndex < 0 || !Number.isInteger(position.waveIndex) || position.waveIndex < 0 || position.waveIndex > 4 ||
        !Number.isSafeInteger(rewardPercent) || rewardPercent < 0)
        throw new Error('Invalid rebirth reward input');
    if (position.stageIndex + 1 < EconomyConfig_1.ECONOMY_CONFIG.rebirth.minimumStage)
        return OriginalInfVal_1.OriginalInfVal.fromInt(0);
    const progress = position.stageIndex * 5 + position.waveIndex;
    if (!Number.isSafeInteger(progress))
        throw new Error('Invalid rebirth progress');
    const reward = OriginalInfVal_1.OriginalInfVal.fromInt(progress).pow(EconomyConfig_1.ECONOMY_CONFIG.rebirth.progressPower).mul(OriginalInfVal_1.OriginalInfVal.fromDouble(Math.sqrt(progress)))
        .mul(OriginalInfVal_1.OriginalInfVal.fromInt(rewardPercent)).div(hundred).toPrecision(5).toPrecision(5);
    return enhanced ? reward.mul(EconomyConfig_1.ECONOMY_CONFIG.rebirth.adTotalMultiplier) : reward;
}
function getRebirthPrice(level) {
    validateLevel(level);
    if (level === exports.MAX_REBIRTH_UPGRADE_LEVEL)
        return null;
    for (let index = prices.length; index <= level; index++) {
        let price = OriginalInfVal_1.OriginalInfVal.fromDouble(Math.pow(index + 1, 1.6) * 3);
        if (index > 15000) {
            latePriceFactor = latePriceFactor.mul(latePriceGrowth).toPrecision(4);
            price = price.mul(latePriceFactor);
        }
        prices.push(price.discardDecimal().toPrecision(3));
    }
    return prices[level];
}
function remainingRebirthUpgradeBudget(levels) {
    if (levels.length !== exports.REBIRTH_UPGRADE_TYPES.length || !levels.every(isRebirthLevel))
        throw new Error('Invalid rebirth levels');
    if (!remainingBudgets) {
        const amounts = new Array(exports.MAX_REBIRTH_UPGRADE_LEVEL + 1);
        amounts[exports.MAX_REBIRTH_UPGRADE_LEVEL] = DecimalAmount_1.DecimalAmount.from(0);
        for (let next = exports.MAX_REBIRTH_UPGRADE_LEVEL - 1; next >= 0; next--)
            amounts[next] = amounts[next + 1].add(getRebirthPrice(next).amount);
        remainingBudgets = Object.freeze(amounts);
    }
    return levels.reduce((total, level) => total.add(remainingBudgets[level]), DecimalAmount_1.DecimalAmount.from(0));
}
function getRebirthBonus(type, level) {
    validateLevel(level);
    validateType(type);
    if (type === 'speed')
        return OriginalInfVal_1.OriginalInfVal.fromInt(5 * level).toPrecision(5);
    const cache = type === 'money' ? moneyBonuses : bonuses;
    let value = cache.get(level);
    if (!value) {
        const factor = type === 'money' ? OriginalInfVal_1.OriginalInfVal.fromDouble(EconomyConfig_1.ECONOMY_CONFIG.gold.rebirthUpgradeGrowth) : growth;
        value = factor.pow(level).mul(hundred).sub(hundred).discardDecimal().toPrecision(3);
        cache.set(level, value);
    }
    return value;
}
function getRebirthPercent(type, level) {
    return getRebirthBonus(type, level).add(hundred);
}
