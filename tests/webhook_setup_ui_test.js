'use strict';

// A small DOM/transport boundary substitute exercises the shipped JavaScript,
// including buttons inserted after footer rendering by WHMCS's AJAX interface.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../modules/gateways/btcpay/webhook-setup.js'), 'utf8');

function harness(respond = async () => ({ ok: true, json: async () => ({ success: true, message: 'Webhook ready.' }) })) {
    const listeners = new Map();
    const requests = [];
    const window = { location: new URL('https://billing.example.test/whmcs/renamed-admin/index.php') };
    const document = {
        addEventListener(name, callback) {
            const callbacks = listeners.get(name) || [];
            callbacks.push(callback);
            listeners.set(name, callbacks);
        },
        querySelector() { throw new Error('Must not inspect gateway forms during rendering'); },
        querySelectorAll() { throw new Error('Must not inspect gateway forms during rendering'); }
    };
    const fetch = async (url, options) => {
        requests.push({ url, options });
        return respond(url, options);
    };
    window.fetch = fetch;
    const context = vm.createContext({ window, document, fetch, URL, URLSearchParams, Error, TypeError });
    const load = () => vm.runInContext(source, context, { filename: 'webhook-setup.js' });
    load();
    return {
        listeners, requests, load,
        async dispatch(type, event) {
            for (const callback of listeners.get(type) || []) { await callback(event); }
        }
    };
}

function controls(setupUrl = 'https://billing.example.test/whmcs/modules/gateways/btcpay/manage.php') {
    const result = {
        textContent: '',
        className: '',
        set innerHTML(value) { throw new Error('Status must never be inserted as HTML: ' + value); }
    };
    const container = {
        querySelector(selector) {
            assert.equal(selector, '[data-btcpay-webhook-result]');
            return result;
        }
    };
    const button = {
        disabled: false,
        dataset: { setupUrl, setupToken: 'SESSION-CSRF-TOKEN' },
        get form() { throw new Error('Setup must not read or submit unsaved form fields'); },
        closest(selector) {
            if (selector === '[data-btcpay-webhook-setup]') { return button; }
            assert.equal(selector, '[data-btcpay-webhook-controls]');
            return container;
        }
    };
    const event = {
        // A nested icon/text wrapper still resolves the dynamically added button.
        target: { closest(selector) { assert.equal(selector, '[data-btcpay-webhook-setup]'); return button; } },
        prevented: false,
        preventDefault() { this.prevented = true; }
    };
    return { button, result, event };
}

async function main() {
    const ui = harness();
    assert.deepEqual([...ui.listeners.keys()], ['click'], 'Only an explicit click is observed, never native submit/AJAX events');
    assert.equal(ui.requests.length, 0, 'Rendering makes no API request');
    ui.load();
    assert.equal(ui.listeners.get('click').length, 1, 'Repeated footer rendering never duplicates click handlers');
    assert.equal(ui.requests.length, 0, 'Repeated rendering makes no API request');
    const unrelated = { target: { closest: () => null }, preventDefault() { throw new Error('Must not intercept other controls'); } };
    await ui.dispatch('click', unrelated);
    await ui.dispatch('submit', unrelated);
    await ui.dispatch('ajaxComplete', unrelated);
    await ui.dispatch('click', { target: {} });
    assert.equal(ui.requests.length, 0, 'Other controls and completed native saves never register a webhook');

    const dynamic = controls();
    await ui.dispatch('click', dynamic.event);
    assert.equal(ui.requests.length, 1, 'An explicit click on a dynamically inserted button makes one request');
    assert.equal(dynamic.event.prevented, true, 'Only the plugin button click is handled');
    assert.equal(dynamic.button.disabled, false, 'Button is re-enabled after completion');
    assert.equal(dynamic.result.textContent, 'Webhook ready.');
    assert.equal(dynamic.result.className, 'text-success', 'Successful setup is visibly green');
    const request = ui.requests[0];
    assert.equal(request.url, dynamic.button.dataset.setupUrl);
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.mode, 'same-origin');
    assert.equal(request.options.redirect, 'error', 'Do not follow a login/cross-origin redirect during a mutating request');
    assert.equal(request.options.headers.Accept, 'application/json');
    assert.deepEqual(Object.fromEntries(new URLSearchParams(request.options.body)), {
        action: 'setup', responseFormat: 'json', token: 'SESSION-CSRF-TOKEN'
    }, 'The backend receives only action/format/CSRF, not posted API keys, store IDs or secrets');

    for (const url of ['https://other.example.test/manage.php', '//other.example.test/manage.php']) {
        const foreign = controls(url);
        const originUi = harness();
        await originUi.dispatch('click', foreign.event);
        assert.equal(originUi.requests.length, 0, 'Refuse to send the admin token to a different origin');
        assert.match(foreign.result.textContent, /System URL|connection page/i);
        assert.equal(foreign.button.disabled, false, 'Cross-origin rejection does not leave a disabled button');
    }

    let finish;
    const pendingUi = harness(() => new Promise(resolve => { finish = resolve; }));
    const pending = controls();
    const firstClick = pendingUi.dispatch('click', pending.event);
    assert.equal(pending.button.disabled, true, 'Disable the button while its request is pending');
    assert.equal(pending.result.className, 'text-info', 'Pending setup is not presented as success');
    await pendingUi.dispatch('click', pending.event);
    assert.equal(pendingUi.requests.length, 1, 'A double click cannot create a second concurrent request');
    finish({ ok: true, json: async () => ({ success: true, message: 'Webhook ready.' }) });
    await firstClick;
    assert.equal(pending.button.disabled, false);

    for (const ok of [true, false]) {
        const message = '<img src=x onerror=alert(1)> Setup failed safely.';
        const failureUi = harness(async () => ({ ok, json: async () => ({ success: false, message }) }));
        const failed = controls();
        await failureUi.dispatch('click', failed.event);
        assert.equal(failed.result.textContent, message, 'Server error messages remain inert text');
        assert.equal(failed.result.className, 'text-danger', 'A failed setup is visibly distinct from success');
        assert.equal(failed.button.disabled, false, 'The admin can retry after a reported failure');
    }

    for (const respond of [
        async () => { throw new Error('NEVER-DISPLAY-TRANSPORT-DETAIL'); },
        async () => ({ ok: false, json: async () => { throw new Error('NEVER-DISPLAY-HTML-LOGIN-PAGE'); } }),
        async () => ({ ok: true, json: async () => ({ unexpected: 'NEVER-DISPLAY-UNEXPECTED-PAYLOAD' }) })
    ]) {
        const failureUi = harness(respond);
        const failed = controls();
        await failureUi.dispatch('click', failed.event);
        assert.notEqual(failed.result.textContent, '');
        assert.doesNotMatch(failed.result.textContent, /NEVER-DISPLAY/, 'Untrusted transport/JSON errors are not exposed');
        assert.match(failed.result.textContent, /connection page|status/i, 'Ambiguous failures direct the admin to inspect setup status');
        assert.equal(failed.button.disabled, false, 'The button recovers after failed or ambiguous responses');
        assert.equal(failed.result.className, 'text-danger', 'Ambiguous responses never show green success');
    }

    let success = false;
    const retryUi = harness(async () => ({ ok: success, json: async () => ({
        success, message: success ? 'Webhook ready.' : 'Setup failed.'
    }) }));
    const retry = controls();
    await retryUi.dispatch('click', retry.event);
    assert.equal(retry.result.className, 'text-danger');
    success = true;
    await retryUi.dispatch('click', retry.event);
    assert.equal(retry.result.className, 'text-success', 'A successful retry replaces the previous error styling');
    assert.equal(retry.result.textContent, 'Webhook ready.');

    process.stdout.write('Explicit webhook JavaScript click, native-save isolation, same-origin and safe-status tests passed.\n');
}

main().catch(error => {
    process.stderr.write(error.stack + '\n');
    process.exitCode = 1;
});
