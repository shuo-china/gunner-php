"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.SkinSystem = void 0;
const SkinCatalog_1 = require("./SkinCatalog");
class SkinSystem {
    constructor() {
        this.owned = Array.from({ length: SkinCatalog_1.SKIN_COUNT }, (_, id) => id === 0);
        this.selectedId = 0;
        this.revision = 0;
    }
    get totals() {
        const totals = { damagePercent: 0, speedPercent: 0, moneyPercent: 0 };
        SkinCatalog_1.SKIN_CATALOG.forEach(skin => {
            if (!this.owned[skin.id])
                return;
            totals.damagePercent += skin.damagePercent;
            totals.speedPercent += skin.speedPercent;
            totals.moneyPercent += skin.moneyPercent;
        });
        return totals;
    }
    purchase(id, diamonds, spend) {
        if (!(0, SkinCatalog_1.isSkinId)(id))
            return { ok: false, reason: 'invalid' };
        if (this.owned[id])
            return { ok: false, reason: 'owned' };
        const price = (0, SkinCatalog_1.getSkin)(id).price;
        if (diamonds < price || !spend(price))
            return { ok: false, reason: 'diamonds' };
        this.owned[id] = true;
        this.revision++;
        return { ok: true, skinId: id, changed: true };
    }
    equip(id) {
        if (!(0, SkinCatalog_1.isSkinId)(id))
            return { ok: false, reason: 'invalid' };
        if (!this.owned[id])
            return { ok: false, reason: 'locked' };
        const changed = this.selectedId !== id;
        this.selectedId = id;
        if (changed)
            this.revision++;
        return { ok: true, skinId: id, changed };
    }
    snapshot(diamonds) {
        return { selectedId: this.selectedId, diamonds, owned: [...this.owned], totals: this.totals, revision: this.revision,
            items: SkinCatalog_1.SKIN_CATALOG.map(skin => ({ ...skin, owned: this.owned[skin.id], equipped: skin.id === this.selectedId, affordable: diamonds >= skin.price })) };
    }
    serialize() { return { owned: [...this.owned], selectedId: this.selectedId }; }
    restore(value) {
        const data = value;
        if (!data || !Array.isArray(data.owned) || data.owned.length !== SkinCatalog_1.SKIN_COUNT || data.owned.some(v => typeof v !== 'boolean')
            || data.owned[0] !== true || !(0, SkinCatalog_1.isSkinId)(data.selectedId) || !data.owned[data.selectedId])
            throw new Error('Invalid skin save');
        this.owned = [...data.owned];
        this.selectedId = data.selectedId;
        this.revision++;
    }
}
exports.SkinSystem = SkinSystem;
