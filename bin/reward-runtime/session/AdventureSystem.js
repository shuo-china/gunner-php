"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.AdventureSystem = void 0;
exports.adventureRequiredExp = adventureRequiredExp;
exports.adventureCoreReward = adventureCoreReward;
exports.adventureOpenDay = adventureOpenDay;
const EconomyConfig_1 = require("../core/EconomyConfig");
const ContentConfig_1 = require("./ContentConfig");
const PetSystem_1 = require("./PetSystem");
const config = EconomyConfig_1.ECONOMY_CONFIG.adventure;
const HOUR_MS = 3600000;
const slotKey = (ref) => `${ref.kind}:${ref.index}`;
const integer = (n, min = 0, max = Number.MAX_SAFE_INTEGER) => Number.isSafeInteger(n) && n >= min && n <= max;
function time(now) {
    const date = new Date(now);
    if (!integer(now) || !Number.isFinite(date.getTime()) || date.getFullYear() > 9999)
        throw new Error('Invalid adventure time');
    return now;
}
function validRef(ref) { return !!ref && integer(ref.index, 0, ref.kind === 'owned' ? 15 : 2) && (ref.kind === 'owned' || ref.kind === 'selected'); }
function gradeAt(save, ref) { return validRef(ref) && save && Array.isArray(save[ref.kind]) ? save[ref.kind][ref.index] : -1; }
function dayKey(now) {
    const d = new Date(time(now));
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function validDay(day) {
    if (typeof day !== 'string')
        return false;
    if (day === '')
        return true;
    return /^\d{4}-\d{2}-\d{2}$/.test(day) && Number.isFinite(Date.parse(day)) && new Date(day).toISOString().slice(0, 10) === day;
}
function adventureRequiredExp(level) { return config.expQuadratic * level * level + config.expLinear * level + config.expBase; }
function adventureCoreReward(grades, hours) {
    const f = Math.fround, coefficient = f(config.petGradeCoreBonus);
    let sum = 0;
    for (const grade of grades)
        sum = f(sum + f(f(grade * coefficient) + 1));
    return Math.trunc(f(f(sum * config.corePerHour) * hours));
}
function adventureOpenDay(type, weekday) {
    return integer(type, 0, 2) && integer(weekday, 0, 6) && ContentConfig_1.ADVENTURE_RULES.openDays[type].includes(weekday);
}
const emptyMaps = () => Array.from({ length: 15 }, () => ({ startedAt: null, runId: 0, pets: [] }));
const copyMap = (map) => ({ ...map, pets: map.pets.map(pet => ({ ...pet })) });
const rewardOrder = (a, b) => a.kind === b.kind ? a.index - b.index : a.kind === 'owned' ? -1 : 1;
class AdventureSystem {
    constructor(nowMs = Date.now()) {
        this.revision = 0;
        this.data = { version: 1, level: 0, exp: 0, tickets: config.initialTickets, lastTicketChargeAt: time(nowMs),
            cores: [...config.initialCores], maps: emptyMaps(), adDate: '', adUsed: 0, nextRunId: 1 };
    }
    serialize() { return { ...this.data, cores: [...this.data.cores], maps: this.data.maps.map(copyMap) }; }
    ensureResourceFloor(ticketFloor, coreFloor) {
        if (!integer(ticketFloor) || !integer(coreFloor))
            return false;
        const tickets = Math.max(this.data.tickets, ticketFloor), cores = this.data.cores.map(value => Math.max(value, coreFloor));
        if (tickets === this.data.tickets && cores.every((value, index) => value === this.data.cores[index]))
            return false;
        this.data = { ...this.data, tickets, cores };
        this.revision++;
        return true;
    }
    restore(value, pets, nowMs) {
        if (nowMs !== undefined)
            time(nowMs);
        const d = value, fail = () => { throw new Error('Invalid adventure save'); };
        if (!d || d.version !== 1 || !integer(d.level, 0, config.maxLevel) || !integer(d.exp) || (d.level === config.maxLevel ? d.exp !== 0 : d.exp >= adventureRequiredExp(d.level))
            || !integer(d.tickets) || !integer(d.lastTicketChargeAt) || !Number.isFinite(new Date(d.lastTicketChargeAt).getTime())
            || !Array.isArray(d.cores) || d.cores.length !== 4 || d.cores.some(n => !integer(n))
            || !Array.isArray(d.maps) || d.maps.length !== 15 || !validDay(d.adDate) || !integer(d.adUsed)
            || !integer(d.nextRunId, 1))
            fail();
        const occupied = new Set(), runs = new Set();
        d.maps.forEach((map, i) => {
            if (!map || !Array.isArray(map.pets) || map.pets.length > ContentConfig_1.ADVENTURE_RULES.petSlotUnlockLevels.filter(level => level <= d.level).length || !integer(map.runId))
                fail();
            if (map.startedAt === null) {
                if (map.pets.length || map.runId !== 0)
                    fail();
                return;
            }
            if (!Number.isSafeInteger(map.startedAt) || !Number.isFinite(new Date(map.startedAt).getTime()) || !map.pets.length
                || map.runId < 1 || map.runId >= d.nextRunId || runs.has(map.runId) || ContentConfig_1.ADVENTURE_RULES.unlockLevels[i % 5] > d.level)
                fail();
            runs.add(map.runId);
            for (const ref of map.pets) {
                if (!validRef(ref) || !(0, PetSystem_1.isPetGrade)(ref.grade) || occupied.has(slotKey(ref)) || (pets && gradeAt(pets, ref) !== ref.grade))
                    fail();
                occupied.add(slotKey(ref));
            }
        });
        this.data = { ...d, cores: [...d.cores], maps: d.maps.map(copyMap) };
        this.revision++;
    }
    locks() { const refs = []; for (const map of this.data.maps)
        for (const { kind, index } of map.pets)
            refs.push({ kind, index }); return refs; }
    get cores() { return [...this.data.cores]; }
    coreCount(type) { return integer(type, 0, 3) ? this.data.cores[type] : 0; }
    get openPetSlots() { return ContentConfig_1.ADVENTURE_RULES.petSlotUnlockLevels.filter(level => level <= this.data.level).length; }
    index(type, index) { return integer(type, 0, 2) && integer(index, 0, 4) ? type * 5 + index : -1; }
    effectiveHours(index, plusPack) { return Math.max(0, config.durationHours[index] - (plusPack ? config.plusPackReduceHours : 0)); }
    ticketState(nowMs) {
        time(nowMs);
        const { tickets, lastTicketChargeAt } = this.data;
        if (tickets >= config.ticketMax)
            return { tickets, lastTicketChargeAt };
        const interval = config.ticketChargeHours * HOUR_MS, count = Math.max(0, Math.floor((nowMs - lastTicketChargeAt) / interval));
        if (!count)
            return { tickets, lastTicketChargeAt };
        const next = Math.min(config.ticketMax, tickets + count);
        return { tickets: next, lastTicketChargeAt: next === config.ticketMax ? nowMs : lastTicketChargeAt + count * interval };
    }
    refresh(nowMs) {
        const next = this.ticketState(nowMs), changed = next.tickets !== this.data.tickets || next.lastTicketChargeAt !== this.data.lastTicketChargeAt;
        if (changed) {
            Object.assign(this.data, next);
            this.revision++;
        }
        return changed;
    }
    usedAds(nowMs) { return dayKey(nowMs) > this.data.adDate ? 0 : this.data.adUsed; }
    availablePets(pets) {
        const occupied = new Set(this.locks().map(slotKey)), result = [];
        for (const kind of ['selected', 'owned'])
            pets[kind].forEach((grade, index) => {
                if ((0, PetSystem_1.isPetGrade)(grade) && !occupied.has(slotKey({ kind, index })))
                    result.push({ kind, index, grade });
            });
        return result;
    }
    autoSelect(pets) {
        return this.availablePets(pets).map((pet, order) => ({ pet, order }))
            .sort((a, b) => b.pet.grade - a.pet.grade || a.order - b.order).slice(0, this.openPetSlots).map(item => item.pet);
    }
    map(type, index, nowMs, plusPack = false) {
        time(nowMs);
        const flat = this.index(type, index);
        if (flat < 0)
            return null;
        const item = this.data.maps[flat], effectiveHours = this.effectiveHours(index, plusPack), baseHours = config.durationHours[index];
        const openToday = adventureOpenDay(type, new Date(nowMs).getDay()), unlocked = this.data.level >= ContentConfig_1.ADVENTURE_RULES.unlockLevels[index];
        const remainingMs = item.startedAt === null ? 0 : Math.max(0, item.startedAt + effectiveHours * HOUR_MS - nowMs);
        const status = !unlocked ? 'locked' : item.startedAt !== null ? remainingMs > 0 ? 'running' : 'finished' : openToday ? 'available' : 'closed';
        const highest = ContentConfig_1.ADVENTURE_RULES.unlockLevels.reduce((last, value, i) => value <= this.data.level ? i : last, 0);
        const ordered = [...item.pets].sort(rewardOrder);
        return { ...copyMap(item), type: type, index, status, baseHours, effectiveHours, unlockLevel: ContentConfig_1.ADVENTURE_RULES.unlockLevels[index] + 1,
            remainingMs, rewardCore: adventureCoreReward(ordered.map(pet => pet.grade), baseHours), rewardExp: baseHours * config.expPerHour * item.pets.length,
            openToday, adAvailable: !plusPack && (!ContentConfig_1.ADVENTURE_RULES.adHighestUnlockedOnly || index === highest) && status === 'running' && this.usedAds(nowMs) < config.adDailyLimit };
    }
    snapshot(pets, nowMs, plusPack = false) {
        const ticket = this.ticketState(nowMs), maps = this.data.maps.map((_, i) => this.map(Math.floor(i / 5), i % 5, nowMs, plusPack));
        const requiredExp = this.data.level === config.maxLevel ? 0 : adventureRequiredExp(this.data.level), mapAlert = maps.some(map => map.status === 'finished');
        const coreAlert = this.data.cores.some(count => count >= EconomyConfig_1.ECONOMY_CONFIG.relics.drawPrice);
        return { level: this.data.level, displayLevel: this.data.level + 1, exp: this.data.exp, requiredExp,
            expRatio: requiredExp ? this.data.exp / requiredExp : 1, maxLevel: config.maxLevel, tickets: ticket.tickets, ticketMax: config.ticketMax,
            ticketRemainingMs: ticket.tickets >= config.ticketMax ? 0 : Math.max(0, ticket.lastTicketChargeAt + config.ticketChargeHours * HOUR_MS - nowMs),
            cores: [...this.data.cores], openPetSlots: this.openPetSlots, adRemaining: Math.max(0, config.adDailyLimit - this.usedAds(nowMs)),
            maps, alert: mapAlert || coreAlert, mapAlert, coreAlert, availablePets: this.availablePets(pets), revision: this.revision };
    }
    preview(type, index, refs, pets, nowMs, plusPack = false) {
        var _a, _b, _c, _d;
        const map = this.map(type, index, nowMs, plusPack), available = new Map(this.availablePets(pets).map(pet => [slotKey(pet), pet]));
        const selected = refs.filter(validRef).map(ref => available.get(slotKey(ref)));
        let reason;
        if (!map)
            reason = 'invalid';
        else if (map.status !== 'available')
            reason = map.status;
        else if (!refs.length)
            reason = 'empty';
        else if (refs.length > this.openPetSlots)
            reason = 'slots';
        else if (selected.length !== refs.length || selected.some(pet => !pet) || new Set(refs.map(slotKey)).size !== refs.length)
            reason = 'petUnavailable';
        else if (this.ticketState(nowMs).tickets < refs.length)
            reason = 'tickets';
        const grades = selected.filter((pet) => !!pet).map(pet => pet.grade);
        return { effectiveHours: (_a = map === null || map === void 0 ? void 0 : map.effectiveHours) !== null && _a !== void 0 ? _a : 0, durationMs: ((_b = map === null || map === void 0 ? void 0 : map.effectiveHours) !== null && _b !== void 0 ? _b : 0) * HOUR_MS, ticketCost: refs.length,
            rewardCore: adventureCoreReward(grades, (_c = map === null || map === void 0 ? void 0 : map.baseHours) !== null && _c !== void 0 ? _c : 0), rewardExp: ((_d = map === null || map === void 0 ? void 0 : map.baseHours) !== null && _d !== void 0 ? _d : 0) * config.expPerHour * grades.length, canStart: !reason, ...(reason ? { reason } : {}) };
    }
    start(type, index, refs, pets, nowMs, plusPack = false) {
        const preview = this.preview(type, index, refs, pets, nowMs, plusPack);
        if (!preview.canStart)
            return { ok: false, reason: preview.reason };
        if (!integer(this.data.nextRunId + 1))
            return { ok: false, reason: 'limit' };
        this.refresh(nowMs);
        if (this.data.tickets >= config.ticketMax)
            this.data.lastTicketChargeAt = nowMs;
        this.data.tickets -= refs.length;
        const runId = this.data.nextRunId++;
        this.data.maps[this.index(type, index)] = { startedAt: nowMs, runId, pets: refs.map(ref => ({ kind: ref.kind, index: ref.index, grade: gradeAt(pets, ref) })) };
        this.revision++;
        return { ok: true, changed: true, runId, type: type, index };
    }
    claim(type, index, nowMs, plusPack = false) {
        const map = this.map(type, index, nowMs, plusPack);
        if (!map || map.status !== 'finished')
            return { ok: false, reason: map ? 'notFinished' : 'invalid' };
        if (!integer(this.data.cores[type] + map.rewardCore))
            return { ok: false, reason: 'walletLimit' };
        const beforeLevel = this.data.level, beforeExp = this.data.exp;
        this.data.cores[type] += map.rewardCore;
        if (this.data.level < config.maxLevel) {
            this.data.exp += map.rewardExp;
            while (this.data.level < config.maxLevel && this.data.exp >= adventureRequiredExp(this.data.level)) {
                this.data.exp -= adventureRequiredExp(this.data.level);
                this.data.level++;
            }
            if (this.data.level === config.maxLevel)
                this.data.exp = 0;
        }
        this.data.maps[this.index(type, index)] = { startedAt: null, runId: 0, pets: [] };
        this.revision++;
        return { ok: true, changed: true, beforeLevel, beforeExp, coreGain: map.rewardCore, expGain: map.rewardExp, type: type, index };
    }
    cancel(type, index) {
        const flat = this.index(type, index);
        if (flat < 0 || this.data.maps[flat].startedAt === null)
            return { ok: false, reason: flat < 0 ? 'invalid' : 'notRunning' };
        this.data.maps[flat] = { startedAt: null, runId: 0, pets: [] };
        this.revision++;
        return { ok: true, changed: true };
    }
    reduceByAd(type, index, expectedRunId, nowMs, plusPack = false) {
        const map = this.map(type, index, nowMs, plusPack);
        if (!map || map.runId !== expectedRunId || !integer(expectedRunId, 1))
            return { ok: false, reason: 'stale' };
        if (!map.adAvailable)
            return { ok: false, reason: 'unavailable' };
        const today = dayKey(nowMs), used = this.usedAds(nowMs);
        this.data.maps[this.index(type, index)].startedAt -= config.adReduceMinutes * 60000;
        if (today > this.data.adDate)
            this.data.adDate = today;
        this.data.adUsed = used + 1;
        this.revision++;
        return { ok: true, changed: true, runId: map.runId };
    }
    grantCores(type, amount) {
        if (!integer(type, 0, 3) || !integer(amount) || !integer(this.data.cores[type] + amount))
            return { ok: false, reason: 'walletLimit' };
        this.data.cores[type] += amount;
        if (amount)
            this.revision++;
        return { ok: true, changed: amount > 0 };
    }
    spendCores(type, amount) {
        if (!integer(type, 0, 3) || !integer(amount))
            return { ok: false, reason: 'invalid' };
        if (this.data.cores[type] < amount)
            return { ok: false, reason: 'currency' };
        this.data.cores[type] -= amount;
        if (amount)
            this.revision++;
        return { ok: true, changed: amount > 0 };
    }
}
exports.AdventureSystem = AdventureSystem;
