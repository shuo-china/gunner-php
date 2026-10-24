"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.UPGRADE_SOURCE = exports.MAX_UPGRADE_LEVEL = exports.UPGRADE_TYPES = void 0;
exports.isUpgradeType = isUpgradeType;
exports.isUpgradeLevel = isUpgradeLevel;
exports.getUpgradePrice = getUpgradePrice;
exports.getUpgradeValue = getUpgradeValue;
exports.getUpgradeMultiplier = getUpgradeMultiplier;
exports.fullUpgradeBudget = fullUpgradeBudget;
const DecimalAmount_1 = require("../core/DecimalAmount");
const OriginalInfVal_1 = require("../core/OriginalInfVal");
const EconomyConfig_1 = require("../core/EconomyConfig");
exports.UPGRADE_TYPES = ['power', 'speed', 'money'];
exports.MAX_UPGRADE_LEVEL = 10000;
exports.UPGRADE_SOURCE = Object.freeze({
    version: 'Cat Gunner 1.1.61', priceGrowthG17: '1.3799999999999999', valueGrowthG17: '1.0800000000000001',
    latePriceLevel: 5000, holdDelay: 0.4, holdInterval: 0.1, minimumAttackInterval: 0.05, maximumAttackInterval: 2,
});
let prices, values, budget;
let moneyValues;
function ensureTables() {
    if (prices && values)
        return;
    const priceTable = [], valueTable = [];
    const growth = DecimalAmount_1.DecimalAmount.from(exports.UPGRADE_SOURCE.priceGrowthG17), valueGrowth = DecimalAmount_1.DecimalAmount.from(exports.UPGRADE_SOURCE.valueGrowthG17);
    let accumulator = DecimalAmount_1.DecimalAmount.from(50), value = DecimalAmount_1.DecimalAmount.from(100);
    for (let level = 0; level <= exports.MAX_UPGRADE_LEVEL; level++) {
        priceTable.push(accumulator.discardDecimal().truncateSignificant(3));
        valueTable.push(value);
        accumulator = accumulator.mul(level < exports.UPGRADE_SOURCE.latePriceLevel ? growth : 2).truncateSignificant(17).truncateSignificant(4);
        value = value.mul(valueGrowth).truncateSignificant(17).truncateSignificant(3);
    }
    prices = Object.freeze(priceTable);
    values = Object.freeze(valueTable);
}
function isUpgradeType(type) { return exports.UPGRADE_TYPES.includes(type); }
function isUpgradeLevel(level) { return typeof level === 'number' && Number.isInteger(level) && level >= 0 && level <= exports.MAX_UPGRADE_LEVEL; }
function validateLevel(level) { if (!isUpgradeLevel(level))
    throw new Error('Invalid upgrade level'); }
function getUpgradePrice(level) {
    validateLevel(level);
    if (level === exports.MAX_UPGRADE_LEVEL)
        return null;
    ensureTables();
    return prices[level];
}
function getUpgradeValue(type, level) {
    validateLevel(level);
    if (!isUpgradeType(type))
        throw new Error('Invalid upgrade type');
    if (type === 'speed')
        return DecimalAmount_1.DecimalAmount.from(100 + 8 * level);
    if (type === 'money') {
        if (!moneyValues) {
            const table = [], growth = DecimalAmount_1.DecimalAmount.from(EconomyConfig_1.ECONOMY_CONFIG.gold.upgradeGrowthG17);
            let value = DecimalAmount_1.DecimalAmount.from(EconomyConfig_1.ECONOMY_CONFIG.gold.upgradeBasePercent);
            for (let i = 0; i <= exports.MAX_UPGRADE_LEVEL; i++) {
                table.push(value);
                value = value.mul(growth).truncateSignificant(17).truncateSignificant(3);
            }
            moneyValues = Object.freeze(table);
        }
        return moneyValues[level];
    }
    ensureTables();
    return values[level];
}
function getUpgradeMultiplier(type, level) {
    return OriginalInfVal_1.OriginalInfVal.fromAmount(getUpgradeValue(type, level), level === 0 ? 5 : 3);
}
function fullUpgradeBudget() {
    if (budget)
        return budget;
    ensureTables();
    let total = DecimalAmount_1.DecimalAmount.from(0);
    for (let level = 0; level < exports.MAX_UPGRADE_LEVEL; level++)
        total = total.add(prices[level]);
    budget = total.mul(3);
    return budget;
}
