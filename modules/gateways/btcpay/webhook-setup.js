(function () {
    'use strict';

    // Footer output may be rendered more than once. Never attach duplicate handlers.
    if (window.btcpayWebhookSetupLoaded) {
        return;
    }
    window.btcpayWebhookSetupLoaded = true;
    class SetupError extends Error {}

    // Deliberately no submit listener, AJAX interception, or work on page load.
    document.addEventListener('click', async function (event) {
        const button = event.target.closest && event.target.closest('[data-btcpay-webhook-setup]');
        if (!button || button.disabled) {
            return;
        }
        event.preventDefault();
        const controls = button.closest('[data-btcpay-webhook-controls]');
        const status = controls && controls.querySelector('[data-btcpay-webhook-result]');
        if (!status) {
            return;
        }

        button.disabled = true;
        status.className = 'text-info';
        status.textContent = 'Setting up webhook using saved settings…';
        try {
            const url = new URL(button.dataset.setupUrl, window.location.href);
            if (url.origin !== window.location.origin) {
                throw new SetupError('Open WHMCS using its configured System URL, or use the connection page to set up the webhook.');
            }
            const response = await fetch(url.href, {
                method: 'POST',
                credentials: 'same-origin',
                mode: 'same-origin',
                redirect: 'error',
                headers: { 'Accept': 'application/json' },
                body: new URLSearchParams({
                    action: 'setup',
                    responseFormat: 'json',
                    token: button.dataset.setupToken
                })
            });
            let result;
            try {
                result = await response.json();
            } catch (error) {
                throw new SetupError('No valid setup response. Reopen the connection page and check the webhook status before retrying.');
            }
            if (!result || typeof result.success !== 'boolean' || typeof result.message !== 'string') {
                throw new SetupError('No valid setup response. Reopen the connection page and check the webhook status before retrying.');
            }
            if (!response.ok || !result.success) {
                throw new SetupError(result.message);
            }
            // Only status is returned; API keys and webhook secrets never enter the DOM.
            status.className = 'text-success';
            status.textContent = result.message;
        } catch (error) {
            status.className = 'text-danger';
            status.textContent = error instanceof SetupError ? error.message :
                'Unable to finish setup. Check the connection page before retrying.';
        } finally {
            button.disabled = false;
        }
    });
}());
