"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.DRAW_PROBABILITIES = exports.DRAW_PRICE = exports.AD_WEAPON_ID = exports.WEAPON_COUNT = exports.WEAPON_CATALOG_VERSION = exports.WEAPON_GRADES = exports.WEAPON_CATEGORY_BY_TYPE = exports.WEAPON_CATEGORY_NAMES = exports.WEAPON_CATEGORIES = exports.WEAPON_TYPES = void 0;
exports.isWeaponId = isWeaponId;
exports.getWeapon = getWeapon;
exports.getWeaponGrade = getWeaponGrade;
exports.getWeaponGradeName = getWeaponGradeName;
exports.getWeaponCatalog = getWeaponCatalog;
exports.getWeaponIdsForGrade = getWeaponIdsForGrade;
const WeaponData_1 = require("./WeaponData");
const EconomyConfig_1 = require("./EconomyConfig");
exports.WEAPON_TYPES = ['SINGLE', 'LASER', 'PIERCE', 'SHOTGUN', 'EXPLOSIVE', 'MISSILE', 'BLAST_SNIPER'];
exports.WEAPON_CATEGORIES = ['bow', 'crossbow', 'glove', 'staff', 'fan', 'handgun', 'cannon'];
exports.WEAPON_CATEGORY_NAMES = Object.freeze({
    bow: '弓', crossbow: '弩', glove: '拳套', staff: '法杖', fan: '扇子', handgun: '火铳', cannon: '火炮',
});
exports.WEAPON_CATEGORY_BY_TYPE = Object.freeze({
    SINGLE: 'bow', SHOTGUN: 'crossbow', PIERCE: 'glove', EXPLOSIVE: 'staff',
    LASER: 'fan', BLAST_SNIPER: 'handgun', MISSILE: 'cannon',
});
exports.WEAPON_GRADES = ['D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'X', 'XX', 'XXX', 'Z', 'ZZ', 'ZZZ'];
exports.WEAPON_CATALOG_VERSION = 4;
exports.WEAPON_COUNT = 65;
const CATALOG = Object.freeze(WeaponData_1.WEAPON_DATA.map(weapon => Object.freeze({ ...weapon })));
const GRADE_IDS = Object.freeze(exports.WEAPON_GRADES.map((_, grade) => Object.freeze(CATALOG.filter(w => w.grade === grade).map(w => w.id))));
exports.AD_WEAPON_ID = EconomyConfig_1.ECONOMY_CONFIG.weapons.offer.weaponId;
exports.DRAW_PRICE = EconomyConfig_1.ECONOMY_CONFIG.weapons.drawPrice;
exports.DRAW_PROBABILITIES = Object.freeze(EconomyConfig_1.ECONOMY_CONFIG.weapons.drawGradePercents.map((percent, index) => Object.freeze({ grade: exports.WEAPON_GRADES[index], percent })));
function isWeaponId(value) {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value < exports.WEAPON_COUNT;
}
function getWeapon(id) {
    if (!isWeaponId(id) || !CATALOG[id] || CATALOG[id].id !== id)
        throw new Error(`Unknown weapon: ${id}`);
    return CATALOG[id];
}
function getWeaponGrade(id) {
    return getWeapon(id).grade;
}
function getWeaponGradeName(id) { return exports.WEAPON_GRADES[getWeaponGrade(id)]; }
function getWeaponCatalog() { return CATALOG; }
function getWeaponIdsForGrade(grade) {
    if (!Number.isInteger(grade) || !GRADE_IDS[grade])
        throw new Error(`Unknown weapon grade: ${grade}`);
    return GRADE_IDS[grade];
}
