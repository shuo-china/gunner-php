"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.STAGE_DEGREE_RATES = exports.STAGE_MONEY_MULTIPLIERS = exports.STAGE_HEALTH_MULTIPLIERS = exports.PRECOMPUTED_STAGE_COUNT = void 0;
exports.validateStage = validateStage;
exports.stagePowerOfThree = stagePowerOfThree;
exports.getStageHealth = getStageHealth;
exports.getStageMoney = getStageMoney;
exports.getStageHealthPrecision = getStageHealthPrecision;
exports.getStageMoneyPrecision = getStageMoneyPrecision;
exports.getStageTreeCount = getStageTreeCount;
const DecimalAmount_1 = require("./DecimalAmount");
const EconomyConfig_1 = require("./EconomyConfig");
exports.PRECOMPUTED_STAGE_COUNT = 5000;
exports.STAGE_HEALTH_MULTIPLIERS = [1, 1.8, 3, 35];
exports.STAGE_MONEY_MULTIPLIERS = EconomyConfig_1.ECONOMY_CONFIG.gold.degreeMultipliers;
exports.STAGE_DEGREE_RATES = Object.freeze([
    Object.freeze([80, 20, 0, 0]), Object.freeze([50, 45, 5, 0]),
    Object.freeze([20, 55, 25, 0]), Object.freeze([5, 35, 60, 0]), Object.freeze([0, 15, 85, 0]),
]);
function table(first, growth = 3) {
    const values = [DecimalAmount_1.DecimalAmount.from(first)];
    for (let i = 1; i < exports.PRECOMPUTED_STAGE_COUNT; i++)
        values.push(values[i - 1].mul(growth).truncateSignificant(2));
    return values;
}
const health = table(250), money = table(EconomyConfig_1.ECONOMY_CONFIG.gold.firstStageBase, EconomyConfig_1.ECONOMY_CONFIG.gold.stageGrowth);
const healthCache = new Map(), moneyCache = new Map();
function validateStage(stageIndex, waveIndex = 0) {
    if (!Number.isSafeInteger(stageIndex) || stageIndex < 0)
        throw new Error('Stage index must be a nonnegative safe integer');
    if (!Number.isInteger(waveIndex) || waveIndex < 0 || waveIndex > 4)
        throw new Error('Wave index must be 0 through 4');
}
function stagePowerOfThree(exponent) {
    return stagePower(exponent, 3);
}
function stagePower(exponent, growth) {
    if (!Number.isSafeInteger(exponent) || exponent < 0)
        throw new Error('Invalid stage exponent');
    let value = DecimalAmount_1.DecimalAmount.from(1), base = DecimalAmount_1.DecimalAmount.from(growth);
    while (exponent > 0) {
        if (exponent % 2 !== 0)
            value = value.mul(base).truncateSignificant(2);
        base = base.mul(base).truncateSignificant(2);
        exponent = Math.floor(exponent / 2);
    }
    return value;
}
function stageValue(stageIndex, values, cache, growth = 3) {
    validateStage(stageIndex);
    if (stageIndex < values.length)
        return values[stageIndex];
    let value = cache.get(stageIndex);
    if (!value) {
        const last = values.length - 1;
        value = values[last].mul(stagePower(stageIndex - last, growth)).truncateSignificant(2);
        cache.set(stageIndex, value);
    }
    return value;
}
function getStageHealth(stageIndex) { return stageValue(stageIndex, health, healthCache); }
function getStageMoney(stageIndex) { return stageValue(stageIndex, money, moneyCache, EconomyConfig_1.ECONOMY_CONFIG.gold.stageGrowth); }
function getStageHealthPrecision(stageIndex) { validateStage(stageIndex); return stageIndex === 0 ? 5 : 2; }
function getStageMoneyPrecision(stageIndex) { validateStage(stageIndex); return stageIndex === 0 ? 5 : 2; }
function getStageTreeCount(stageIndex) {
    validateStage(stageIndex);
    return stageIndex >= 7 ? 100 : 30 + stageIndex * 10;
}
