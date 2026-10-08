(function () {
    'use strict';

    function getBootstrapConfig() {
        if (!window.AnonymousSalesExternalOrderBootstrap) {
            return null;
        }

        var cfg = window.AnonymousSalesExternalOrderBootstrap;
        if (!cfg || !cfg.routerUrl || !cfg.offerId) {
            return null;
        }

        return cfg;
    }

    function createElement(tag, styleText) {
        var node = document.createElement(tag);
        if (styleText) {
            node.setAttribute('style', styleText);
        }
        return node;
    }

    function apiRequest(routerUrl, action, payload) {
        var body = new URLSearchParams();
        body.set('action', action);
        Object.keys(payload || {}).forEach(function (key) {
            var value = payload[key];
            if (value !== null && value !== undefined) {
                body.set(key, String(value));
            }
        });

        return fetch(routerUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function buildDialog(config) {
        var backdrop = createElement('div', 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999998;display:none;');
        var dialog = createElement('div', 'position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(92vw,760px);max-height:88vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.25);z-index:999999;display:none;font-family:Segoe UI,Arial,sans-serif;');
        var body = createElement('div', 'padding:18px;');
        var closeBtn = createElement('button', 'position:absolute;right:10px;top:10px;border:0;background:#eef2f7;color:#1f2937;border-radius:8px;padding:4px 10px;cursor:pointer;font-weight:700;');
        var title = createElement('h2', 'margin:0 0 8px;font-size:24px;color:#101828;');
        var offerSummary = createElement('p', 'margin:0;color:#475467;line-height:1.5;');
        var desc = createElement('p', 'margin:10px 0 0;color:#334155;line-height:1.6;white-space:pre-wrap;');
        var resTitle = createElement('h3', 'margin:14px 0 8px;font-size:18px;color:#0f172a;');
        var resList = createElement('ul', 'margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px;');
        var actions = createElement('div', 'margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;');
        var buyBtn = createElement('button', 'border:0;border-radius:8px;background:#0b57d0;color:#fff;font-weight:700;padding:10px 14px;cursor:pointer;');
        var approvedBtn = createElement('button', 'border:1px solid #d0d5dd;border-radius:8px;background:#fff;color:#344054;font-weight:600;padding:10px 14px;cursor:pointer;');
        var status = createElement('div', 'margin-top:10px;font-size:13px;color:#4a4f55;line-height:1.5;');
        var approvedInfo = createElement('div', 'margin-top:8px;font-size:12px;color:#475467;line-height:1.5;white-space:pre-wrap;');
        var meta = createElement('div', 'display:none;margin-top:10px;padding:10px;border-radius:8px;background:#f7f9fb;color:#1f2937;font-size:12px;line-height:1.55;white-space:pre-wrap;');
        var paymentLink = createElement('a', 'display:none;text-decoration:none;display:inline-block;border-radius:8px;background:#0b57d0;color:#fff;font-weight:700;padding:9px 12px;');

        closeBtn.type = 'button';
        closeBtn.textContent = 'x';
        title.textContent = (config.offer && config.offer.name) ? config.offer.name : 'Offer';
        offerSummary.textContent = 'Price: $' + ((config.offer && config.offer.priceUsd) ? config.offer.priceUsd : '0.00') + ' USD';
        desc.textContent = (config.offer && config.offer.description) ? config.offer.description : '';
        resTitle.textContent = 'Included resources';
        buyBtn.type = 'button';
        buyBtn.textContent = 'Create order and pay';
        approvedBtn.type = 'button';
        approvedBtn.textContent = 'Check approved crypto';
        paymentLink.target = '_blank';
        paymentLink.rel = 'noopener noreferrer';
        paymentLink.textContent = 'Open payment page';
        status.textContent = 'Ready.';
        approvedInfo.textContent = 'Approved crypto list not checked yet.';

        (config.resources || []).forEach(function (resource) {
            var item = createElement('li', 'border:1px solid #e2e8f0;border-radius:8px;padding:10px;');
            var name = createElement('div', 'font-weight:700;color:#0f172a;');
            var details = createElement('div', 'font-size:12px;color:#475467;line-height:1.55;margin-top:4px;white-space:pre-wrap;');
            var parts = [];

            name.textContent = resource && resource.name ? resource.name : 'Resource';
            if (resource && resource.type) {
                parts.push('Type: ' + resource.type);
            }
            if (resource && resource.size) {
                parts.push('Size: ' + resource.size);
            }
            if (resource && resource.hash) {
                parts.push('Checksum: ' + resource.hash);
            }
            if (resource && resource.description) {
                parts.push(resource.description);
            }
            details.textContent = parts.join('\n');

            item.appendChild(name);
            item.appendChild(details);
            resList.appendChild(item);
        });

        function closeDialog() {
            backdrop.style.display = 'none';
            dialog.style.display = 'none';
        }

        function openDialog() {
            backdrop.style.display = 'block';
            dialog.style.display = 'block';
        }

        closeBtn.addEventListener('click', closeDialog);
        backdrop.addEventListener('click', closeDialog);

        approvedBtn.addEventListener('click', function () {
            approvedBtn.disabled = true;
            approvedInfo.textContent = 'Checking approved crypto...';

            apiRequest(config.routerUrl, 'check_approved_crypto', {
                offer_id: config.offerId,
                include_minimums: '1',
                price_currency: 'usd'
            }).then(function (response) {
                var coins = response && Array.isArray(response.approved_coins) ? response.approved_coins : [];
                var eligible = response && Array.isArray(response.eligible_coins) ? response.eligible_coins : [];
                var ineligible = response && Array.isArray(response.ineligible_coins) ? response.ineligible_coins : [];
                if (!response || !response.success) {
                    approvedInfo.textContent = response && response.message ? response.message : 'Could not fetch approved crypto list.';
                    return;
                }

                if (!coins.length) {
                    approvedInfo.textContent = 'No approved crypto returned by NOWPayments.';
                    return;
                }

                var text = 'Approved crypto (' + coins.length + '):\n' + coins.join(', ');
                text += '\n\nEligible for current offer amount (' + eligible.length + '):\n' + (eligible.length ? eligible.join(', ') : 'none');
                if (ineligible.length) {
                    text += '\n\nBelow minimum (' + ineligible.length + '):\n' + ineligible.join(', ');
                }
                approvedInfo.textContent = text;
            }).catch(function () {
                approvedInfo.textContent = 'Network error while checking approved crypto.';
            }).finally(function () {
                approvedBtn.disabled = false;
            });
        });

        buyBtn.addEventListener('click', function () {
            buyBtn.disabled = true;
            status.textContent = 'Creating order...';

            apiRequest(config.routerUrl, 'create_order', {
                offer_id: config.offerId,
                offer_page: window.location.href
            }).then(function (response) {
                if (!response || !response.success) {
                    status.textContent = response && response.message ? response.message : 'Order creation failed.';
                    return;
                }

                status.textContent = 'Order created successfully.';
                meta.style.display = 'block';
                meta.textContent = 'Order number: ' + (response.order_number || '-') + '\nClaim token: ' + (response.claim_token || '-');

                if (response.payment_url) {
                    paymentLink.style.display = 'inline-block';
                    paymentLink.href = response.payment_url;

                    var popup = window.open(response.payment_url, '_blank', 'noopener,noreferrer');
                    if (!popup) {
                        status.textContent += ' Popup blocked. Use the payment button.';
                    }
                } else {
                    paymentLink.style.display = 'none';
                    if (response.payment_address) {
                        status.textContent += ' Payment address: ' + response.payment_address;
                        meta.textContent += '\nPayment address: ' + response.payment_address;
                    }
                }
            }).catch(function () {
                status.textContent = 'Network error while creating order.';
            }).finally(function () {
                buyBtn.disabled = false;
            });
        });

        actions.appendChild(buyBtn);
        actions.appendChild(approvedBtn);
        actions.appendChild(paymentLink);

        body.appendChild(closeBtn);
        body.appendChild(title);
        body.appendChild(offerSummary);
        body.appendChild(desc);
        body.appendChild(resTitle);
        body.appendChild(resList);
        body.appendChild(actions);
        body.appendChild(status);
        body.appendChild(approvedInfo);
        body.appendChild(meta);
        dialog.appendChild(body);

        document.body.appendChild(backdrop);
        document.body.appendChild(dialog);

        return {
            open: openDialog,
            close: closeDialog
        };
    }

    var config = getBootstrapConfig();
    if (!config) {
        return;
    }

    var dialogApi = buildDialog(config);
    var api = {
        offerId: config.offerId,
        open: dialogApi.open,
        close: dialogApi.close,
        bind: function (selectorOrElement, eventName) {
            var eventToUse = eventName || 'click';
            var element = selectorOrElement;

            if (typeof selectorOrElement === 'string') {
                element = document.querySelector(selectorOrElement);
            }

            if (!element || !element.addEventListener) {
                return false;
            }

            element.addEventListener(eventToUse, function (event) {
                if (event && event.preventDefault) {
                    event.preventDefault();
                }
                dialogApi.open();
            });

            return true;
        }
    };

    window.AnonymousSalesExternalOrder = api;
    window.MFPROExternalOrder = api; // name used by embeds written for earlier versions
    if (typeof window.CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('anonymous-sales-external-order-ready', {
            detail: {
                offerId: config.offerId,
                api: api
            }
        }));
    }
})();
