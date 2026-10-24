"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.resolveRuntimeEnvironment = resolveRuntimeEnvironment;
function resolveRuntimeEnvironment(injected, preview) {
    if (injected === undefined)
        return preview ? 'test' : 'production';
    if (injected === null || typeof injected !== 'object' || Array.isArray(injected))
        return 'production';
    const environment = injected.environment;
    return environment === 'test' ? 'test' : 'production';
}
