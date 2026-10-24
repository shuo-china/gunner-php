"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.SKIN_CATALOG = exports.SKIN_COUNT = void 0;
exports.isSkinId = isSkinId;
exports.getSkin = getSkin;
const EconomyConfig_1 = require("../core/EconomyConfig");
exports.SKIN_COUNT = 25;
exports.SKIN_CATALOG = Object.freeze(Array.from({ length: exports.SKIN_COUNT }, (_, id) => Object.freeze({ id, number: id + 1, price: id * 100, damagePercent: id * 16, speedPercent: id * 10, moneyPercent: id * EconomyConfig_1.ECONOMY_CONFIG.gold.skinBonusPerIndex })));
function isSkinId(id) { return typeof id === 'number' && Number.isInteger(id) && id >= 0 && id < exports.SKIN_COUNT; }
function getSkin(id) {
    if (!isSkinId(id))
        throw new Error('Invalid skin id');
    return exports.SKIN_CATALOG[id];
}
