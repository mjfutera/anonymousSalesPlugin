(function () {
    'use strict';

    var config = window.AnonymousSales;
    if (!config || !config.routerUrl) {
        return;
    }

    var pendingOrders = 0;

    window.addEventListener('beforeunload', function (event) {
        if (pendingOrders > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    function apiRequest(action, payload) {
        var data = new URLSearchParams();
        data.set('action', action);

        Object.keys(payload || {}).forEach(function (key) {
            if (payload[key] !== undefined && payload[key] !== null) {
                data.set(key, payload[key]);
            }
        });

        return fetch(config.routerUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: data.toString()
        }).then(function (r) { return r.json(); });
    }

    function byId(id) {
        return document.getElementById(id);
    }

    // Orders live only in this browser: there is no e-mail to recover them from. The
    // last order is kept in localStorage so closing the dialog does not lose it.
    var STORAGE_KEY = 'anonymousSalesOrders';
    var LEGACY_STORAGE_KEY = 'mfproAnonymousSalesOrders'; // key used by earlier versions

    function readStoredOrders() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || window.localStorage.getItem(LEGACY_STORAGE_KEY) || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function storeOrder(orderNumber, claimToken) {
        if (!orderNumber || !claimToken) { return; }
        try {
            var orders = readStoredOrders().filter(function (o) { return o && o.number !== orderNumber; });
            orders.unshift({ number: orderNumber, token: claimToken, at: Date.now() });
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(orders.slice(0, 20)));
        } catch (e) { /* private mode or storage disabled */ }
    }

    function findStoredOrder(orderNumber) {
        var orders = readStoredOrders();
        for (var i = 0; i < orders.length; i++) {
            if (orderNumber ? orders[i].number === orderNumber : true) { return orders[i]; }
        }
        return null;
    }

    // NOWPayments sends the buyer back with ?anonymous_sales_order=<number> (no token in that URL).
    var returnedOrderNumber = (function () {
        try { return new URLSearchParams(window.location.search).get('anonymous_sales_order') || new URLSearchParams(window.location.search).get('mfpro_order') || ''; } catch (e) { return ''; }
    }());

    function renderApprovedCryptoStatus(targetNode, response) {
        if (!targetNode) {
            return;
        }

        targetNode.innerHTML = '';
        targetNode.style.whiteSpace = '';

        if (!response || !response.success) {
            targetNode.textContent = response && response.message ? response.message : 'Could not fetch approved crypto list.';
            return;
        }

        var coins = Array.isArray(response.approved_coins) ? response.approved_coins : [];
        if (!coins.length) {
            targetNode.textContent = 'No approved crypto returned by NOWPayments.';
            return;
        }

        var eligible = Array.isArray(response.eligible_coins) ? response.eligible_coins : null;
        var ineligible = (Array.isArray(response.ineligible_coins) && response.ineligible_coins.length) ? response.ineligible_coins : null;

        function makeGroup(list, label, chipClass) {
            var group = document.createElement('div');
            group.className = 'anonymous-sales-coin-group';
            var lbl = document.createElement('span');
            lbl.className = 'anonymous-sales-coin-group-label';
            lbl.textContent = label;
            group.appendChild(lbl);
            var wrap = document.createElement('div');
            wrap.className = 'anonymous-sales-coin-wrap';
            list.forEach(function (coin) {
                var chip = document.createElement('span');
                chip.className = 'anonymous-sales-coin-chip' + (chipClass ? ' ' + chipClass : '');
                chip.textContent = coin;
                wrap.appendChild(chip);
            });
            group.appendChild(wrap);
            targetNode.appendChild(group);
        }

        if (eligible && ineligible) {
            makeGroup(eligible, 'Eligible for this price (' + eligible.length + ')', 'anonymous-sales-coin-chip--eligible');
            makeGroup(ineligible, 'Below minimum (' + ineligible.length + ')', 'anonymous-sales-coin-chip--ineligible');
        } else if (eligible) {
            makeGroup(coins, 'Accepted cryptocurrencies (' + coins.length + ')', '');
            makeGroup(eligible, 'Eligible for this price (' + eligible.length + ')', 'anonymous-sales-coin-chip--eligible');
        } else {
            makeGroup(coins, 'Accepted cryptocurrencies (' + coins.length + ')', '');
        }
    }

    function openDialog(dialog) {
        dialog.classList.add('is-open');
        dialog.classList.add('anonymousSP-is-open');
        if (dialog.classList.contains('anonymousSP-buy-dialog')) {
            dialog.classList.add('anonymousSP-buy-is-open');
        }
        dialog.removeAttribute('hidden');
    }

    function closeDialog(dialog) {
        dialog.classList.remove('is-open');
        dialog.classList.remove('anonymousSP-is-open');
        dialog.classList.remove('anonymousSP-buy-is-open');
        dialog.setAttribute('hidden', 'hidden');
    }

    function copyTextToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var temp = document.createElement('textarea');
            temp.value = text;
            temp.setAttribute('readonly', 'readonly');
            temp.style.position = 'fixed';
            temp.style.top = '-1000px';
            document.body.appendChild(temp);
            temp.select();

            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(temp);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('Copy command failed'));
                }
            } catch (e) {
                document.body.removeChild(temp);
                reject(e);
            }
        });
    }

    function initResourceBrowser(browser) {
        var items = Array.from(browser.querySelectorAll('[data-anonymous-sales-resource-item]'));
        if (!items.length) {
            return;
        }

        var searchInput = browser.querySelector('[data-anonymous-sales-resource-search]');
        var perPageSelect = browser.querySelector('[data-anonymous-sales-resource-per-page]');
        var totalTarget = browser.querySelector('[data-anonymous-sales-resource-total]');
        var pageTarget = browser.querySelector('[data-anonymous-sales-resource-page]');
        var emptyTarget = browser.querySelector('[data-anonymous-sales-resource-empty]');
        var prevBtn = browser.querySelector('[data-anonymous-sales-resource-prev]');
        var nextBtn = browser.querySelector('[data-anonymous-sales-resource-next]');
        var paginationWrap = browser.querySelector('[data-anonymous-sales-resource-pagination]');

        var defaultPerPage = parseInt(browser.getAttribute('data-default-per-page') || '5', 10);
        if (!Number.isFinite(defaultPerPage) || defaultPerPage <= 0) {
            defaultPerPage = 5;
        }

        if (perPageSelect && !perPageSelect.value) {
            perPageSelect.value = String(defaultPerPage);
        }

        if (totalTarget) {
            totalTarget.textContent = String(items.length);
        }

        var state = {
            query: '',
            perPage: perPageSelect ? parseInt(perPageSelect.value || String(defaultPerPage), 10) : defaultPerPage,
            page: 1
        };

        function setAccordionState(item, expanded) {
            var toggle = item.querySelector('[data-anonymous-sales-resource-toggle]');
            var panel = item.querySelector('[data-anonymous-sales-resource-panel]');
            var hint = item.querySelector('[data-anonymous-sales-resource-hint]');

            if (toggle) {
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
            if (panel) {
                if (expanded) {
                    panel.removeAttribute('hidden');
                } else {
                    panel.setAttribute('hidden', 'hidden');
                }
            }
            if (hint) {
                hint.textContent = expanded ? '' : 'click for details';
            }

            item.classList.toggle('is-expanded', expanded);
        }

        function closeAllAccordions() {
            items.forEach(function (item) {
                setAccordionState(item, false);
            });
        }

        function filteredItems() {
            if (!state.query) {
                return items;
            }

            return items.filter(function (item) {
                var haystack = (item.getAttribute('data-anonymous-sales-resource-search-text') || '').toLowerCase();
                return haystack.indexOf(state.query) !== -1;
            });
        }

        function render() {
            closeAllAccordions();

            if (!Number.isFinite(state.perPage) || state.perPage <= 0) {
                state.perPage = defaultPerPage;
            }

            var filtered = filteredItems();
            var total = filtered.length;
            var pages = Math.max(1, Math.ceil(total / state.perPage));
            if (state.page > pages) {
                state.page = pages;
            }
            if (state.page < 1) {
                state.page = 1;
            }

            var start = (state.page - 1) * state.perPage;
            var end = start + state.perPage;
            var visibleItems = filtered.slice(start, end);
            var visibleSet = new Set(visibleItems);

            items.forEach(function (item) {
                item.hidden = !visibleSet.has(item);
            });

            visibleItems.forEach(function (item, index) {
                var orderNode = item.querySelector('[data-anonymous-sales-resource-order]');
                if (orderNode) {
                    orderNode.textContent = String(start + index + 1) + '.';
                }
            });

            if (pageTarget) {
                pageTarget.textContent = 'Page ' + state.page + ' / ' + pages;
            }
            if (emptyTarget) {
                emptyTarget.hidden = total > 0;
            }
            if (prevBtn) {
                prevBtn.disabled = state.page <= 1 || total === 0;
            }
            if (nextBtn) {
                nextBtn.disabled = state.page >= pages || total === 0;
            }
            if (paginationWrap) {
                paginationWrap.hidden = total === 0;
            }
        }

        items.forEach(function (item) {
            var toggle = item.querySelector('[data-anonymous-sales-resource-toggle]');
            var panel = item.querySelector('[data-anonymous-sales-resource-panel]');
            if (!toggle || !panel) {
                return;
            }

            toggle.addEventListener('click', function () {
                var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                closeAllAccordions();
                if (!isExpanded) {
                    setAccordionState(item, true);
                }
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                state.query = searchInput.value.trim().toLowerCase();
                state.page = 1;
                render();
            });
        }

        if (perPageSelect) {
            perPageSelect.addEventListener('change', function () {
                state.perPage = parseInt(perPageSelect.value || String(defaultPerPage), 10);
                state.page = 1;
                render();
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                state.page -= 1;
                render();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                state.page += 1;
                render();
            });
        }

        render();
    }

    function initAcceptedCryptoBlock(form) {
        if (!form) {
            return;
        }

        var panel = form.closest('[data-anonymous-sales-buy-panel]') || form.parentElement;
        if (!panel) {
            return;
        }

        var targetNode = panel.querySelector('[data-anonymous-sales-accepted-crypto-content]');
        if (!targetNode) {
            return;
        }

        var offerId = form.getAttribute('data-offer-id');
        if (!offerId) {
            targetNode.textContent = 'Offer ID is missing.';
            return;
        }

        targetNode.textContent = 'Loading accepted cryptocurrencies...';

        apiRequest('check_approved_crypto', {
            offer_id: offerId,
            include_minimums: '1',
            price_currency: 'usd'
        }).then(function (response) {
            renderApprovedCryptoStatus(targetNode, response);
        }).catch(function () {
            targetNode.textContent = 'Network error while loading accepted cryptocurrencies.';
        });
    }

    document.querySelectorAll('[data-anonymous-sales-open-dialog]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-anonymous-sales-open-dialog');
            var dialog = byId(id);
            if (dialog) {
                openDialog(dialog);
            }
        });
    });

    document.querySelectorAll('[data-anonymous-sales-close-dialog]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-anonymous-sales-close-dialog');
            var dialog = byId(id);
            if (dialog) {
                closeDialog(dialog);
            }
        });
    });

    document.querySelectorAll('[data-anonymous-sales-order-meta]').forEach(function (metaBox) {
        metaBox.addEventListener('click', function () {
            var numberNode = metaBox.querySelector('[data-anonymous-sales-order-number]');
            var tokenNode = metaBox.querySelector('[data-anonymous-sales-claim-token]');
            var hintNode = metaBox.querySelector('[data-anonymous-sales-copy-hint]');
            var orderNumber = numberNode ? numberNode.textContent.trim() : '';
            var claimToken = tokenNode ? tokenNode.textContent.trim() : '';

            if (!orderNumber || orderNumber === '-' || !claimToken || claimToken === '-') {
                return;
            }

            var text = 'Order number: ' + orderNumber + '\nClaim token: ' + claimToken;
            copyTextToClipboard(text).then(function () {
                metaBox.classList.add('is-copied');
                if (hintNode) {
                    hintNode.textContent = 'Copied to clipboard.';
                }

                window.setTimeout(function () {
                    metaBox.classList.remove('is-copied');
                    if (hintNode) {
                        hintNode.textContent = 'Click this whole box to copy order number and claim token.';
                    }
                }, 1400);
            }).catch(function () {
                if (hintNode) {
                    hintNode.textContent = 'Copy failed. Please copy manually.';
                }
            });
        });
    });

    document.querySelectorAll('[data-anonymous-sales-resource-browser]').forEach(function (browser) {
        initResourceBrowser(browser);
    });

    document.querySelectorAll('[data-anonymous-sales-create-order-form]').forEach(function (form) {
        var statusBox = form.querySelector('[data-anonymous-sales-order-status]');
        var createBtn = form.querySelector('[data-anonymous-sales-create-order-btn]');
        var panel = form.closest('[data-anonymous-sales-buy-panel]') || form.parentElement;
        initAcceptedCryptoBlock(form);

        // ── In-dialog status check & PDF download ─────────────────────────────
        var checkStatusBtn = panel ? panel.querySelector('[data-anonymous-sales-buy-check-status-btn]') : null;
        var statusResultBox = panel ? panel.querySelector('[data-anonymous-sales-buy-status-result]') : null;
        var downloadWrap = panel ? panel.querySelector('[data-anonymous-sales-buy-download-wrap]') : null;
        var downloadLink = panel ? panel.querySelector('[data-anonymous-sales-buy-download-link]') : null;
        var statusCheckWrap = panel ? panel.querySelector('[data-anonymous-sales-buy-status-check-wrap]') : null;

        function buildDownloadUrl(orderNumber, claimToken) {
            return config.routerUrl + '?action=download_pdf&order_number=' + encodeURIComponent(orderNumber) + '&claim_token=' + encodeURIComponent(claimToken) + '&download=1';
        }

        function handleStatusResponse(response, orderNumber, claimToken) {
            if (!statusResultBox) { return; }
            statusResultBox.style.display = 'block';

            if (!response || !response.success) {
                statusResultBox.textContent = response && response.message ? response.message : 'Could not fetch status.';
                return;
            }

            statusResultBox.textContent = 'Payment status: ' + response.status;

            if (response.download_ready && downloadWrap && downloadLink) {
                if (pendingOrders > 0) { pendingOrders--; }
                var tabCloseHint = panel ? panel.querySelector('[data-anonymous-sales-tab-close-hint]') : null;
                if (tabCloseHint) { tabCloseHint.setAttribute('hidden', 'hidden'); }
                var url = buildDownloadUrl(orderNumber, claimToken);
                downloadLink.href = url;
                downloadWrap.style.display = 'block';
                // Auto-trigger download
                var autoLink = document.createElement('a');
                autoLink.href = url;
                autoLink.target = '_blank';
                autoLink.rel = 'noopener noreferrer';
                document.body.appendChild(autoLink);
                autoLink.click();
                document.body.removeChild(autoLink);
            } else if (downloadWrap) {
                downloadWrap.style.display = 'none';
            }
        }

        if (checkStatusBtn) {
            checkStatusBtn.addEventListener('click', function () {
                var numberNode = panel ? panel.querySelector('[data-anonymous-sales-order-number]') : null;
                var tokenNode = panel ? panel.querySelector('[data-anonymous-sales-claim-token]') : null;
                var orderNumber = numberNode ? numberNode.textContent.trim() : '';
                var claimToken = tokenNode ? tokenNode.textContent.trim() : '';

                if (!orderNumber || orderNumber === '-' || !claimToken || claimToken === '-') {
                    if (statusResultBox) {
                        statusResultBox.style.display = 'block';
                        statusResultBox.textContent = 'Create an order first.';
                    }
                    return;
                }

                checkStatusBtn.disabled = true;
                checkStatusBtn.textContent = 'Checking...';
                if (statusResultBox) { statusResultBox.style.display = 'none'; }
                if (downloadWrap) { downloadWrap.style.display = 'none'; }

                apiRequest('check_status', {
                    order_number: orderNumber,
                    claim_token: claimToken
                }).then(function (response) {
                    handleStatusResponse(response, orderNumber, claimToken);
                }).catch(function () {
                    if (statusResultBox) {
                        statusResultBox.style.display = 'block';
                        statusResultBox.textContent = 'Network error while checking status.';
                    }
                }).finally(function () {
                    checkStatusBtn.disabled = false;
                    checkStatusBtn.textContent = 'Check payment status';
                });
            });
        }
        // ──────────────────────────────────────────────────────────────────────

        var approvedCryptoInfo = document.createElement('div');
        approvedCryptoInfo.className = 'anonymous-sales-crypto-info';
        approvedCryptoInfo.textContent = 'Approved crypto list not checked yet.';

        var checkApprovedBtn = document.createElement('button');
        checkApprovedBtn.type = 'button';
        checkApprovedBtn.className = 'anonymous-sales-secondary';
        checkApprovedBtn.textContent = 'Check approved crypto';

        if (createBtn && createBtn.parentNode) {
            createBtn.parentNode.insertBefore(checkApprovedBtn, createBtn.nextSibling);
            createBtn.parentNode.appendChild(approvedCryptoInfo);
        }

        checkApprovedBtn.addEventListener('click', function () {
            checkApprovedBtn.disabled = true;
            approvedCryptoInfo.textContent = 'Checking approved crypto...';

            apiRequest('check_approved_crypto', {
                offer_id: form.getAttribute('data-offer-id') || '',
                include_minimums: '1',
                price_currency: 'usd'
            }).then(function (response) {
                renderApprovedCryptoStatus(approvedCryptoInfo, response);
            }).catch(function () {
                approvedCryptoInfo.textContent = 'Network error while checking approved crypto.';
            }).finally(function () {
                checkApprovedBtn.disabled = false;
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var offerId = form.getAttribute('data-offer-id');
            var offerPage = form.getAttribute('data-offer-page') || window.location.href;

            createBtn.disabled = true;
            statusBox.textContent = 'Creating order...';

            apiRequest('create_order', {
                offer_id: offerId,
                offer_page: offerPage
            }).then(function (response) {
                if (!response || !response.success) {
                    statusBox.textContent = response && response.message ? response.message : 'Order creation failed.';
                    return;
                }

                var paymentUrl = response.payment_url || '';
                var paymentAddress = response.payment_address || '';
                var msg = 'Order created. Number: ' + response.order_number + ' | Token: ' + response.claim_token;
                if (paymentUrl) {
                    msg += ' | Payment URL generated.';
                } else if (paymentAddress) {
                    msg += ' | Payment address generated: ' + paymentAddress;
                }
                statusBox.textContent = msg;

                var numberTarget = panel ? panel.querySelector('[data-anonymous-sales-order-number]') : null;
                var tokenTarget = panel ? panel.querySelector('[data-anonymous-sales-claim-token]') : null;
                var payTarget = panel ? panel.querySelector('[data-anonymous-sales-payment-url]') : null;
                var metaTarget = panel ? panel.querySelector('[data-anonymous-sales-order-meta]') : null;
                if (numberTarget) {
                    numberTarget.textContent = response.order_number;
                }
                if (tokenTarget) {
                    tokenTarget.textContent = response.claim_token;
                }
                storeOrder(response.order_number, response.claim_token);
                if (metaTarget) {
                    metaTarget.style.display = 'block';
                    metaTarget.classList.add('is-attention');
                }
                var tabCloseHint = panel ? panel.querySelector('[data-anonymous-sales-tab-close-hint]') : null;
                if (tabCloseHint) {
                    tabCloseHint.removeAttribute('hidden');
                }
                pendingOrders++;
                if (payTarget) {
                    payTarget.setAttribute('href', paymentUrl);
                    payTarget.style.display = paymentUrl ? 'inline-block' : 'none';
                }

                if (paymentUrl) {
                    var win = window.open(paymentUrl, '_blank', 'noopener,noreferrer');
                    if (!win) {
                        statusBox.textContent += ' Popup blocked. Use the Payment link below.';
                    }
                }
            }).catch(function () {
                statusBox.textContent = 'Network error while creating order.';
            }).finally(function () {
                createBtn.disabled = false;
            });
        });
    });

    document.querySelectorAll('[data-anonymous-sales-check-status-form]').forEach(function (form) {
        var statusBox = form.querySelector('[data-anonymous-sales-check-status-result]');
        var checkBtn = form.querySelector('[data-anonymous-sales-check-status-btn]');
        var downloadBtn = form.querySelector('[data-anonymous-sales-download-btn]');

        function renderStatus(response) {
            if (!response || !response.success) {
                statusBox.textContent = response && response.message ? response.message : 'Order not found.';
                if (downloadBtn) {
                    downloadBtn.style.display = 'none';
                }
                return;
            }

            statusBox.textContent = 'Order ' + response.order_number + ' status: ' + response.status;

            if (downloadBtn) {
                var number = form.querySelector('[name="order_number"]').value;
                var token = form.querySelector('[name="claim_token"]').value;
                downloadBtn.href = config.routerUrl + '?action=download_pdf&order_number=' + encodeURIComponent(number) + '&claim_token=' + encodeURIComponent(token);
                downloadBtn.style.display = response.download_ready ? 'inline-block' : 'none';
            }
        }

        function checkStatus() {
            var orderNumber = form.querySelector('[name="order_number"]').value.trim();
            var claimToken = form.querySelector('[name="claim_token"]').value.trim();

            if (!orderNumber || !claimToken) {
                statusBox.textContent = 'Order number and token are required.';
                return;
            }

            checkBtn.disabled = true;
            statusBox.textContent = 'Checking status...';

            apiRequest('check_status', {
                order_number: orderNumber,
                claim_token: claimToken
            }).then(renderStatus).catch(function () {
                statusBox.textContent = 'Network error while checking status.';
            }).finally(function () {
                checkBtn.disabled = false;
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            checkStatus();
        });

        // Prefill from the return URL or from the last order made in this browser.
        var numberInput = form.querySelector('[name="order_number"]');
        var tokenInput = form.querySelector('[name="claim_token"]');
        var stored = findStoredOrder(returnedOrderNumber);
        if (returnedOrderNumber && numberInput && !numberInput.value) {
            numberInput.value = returnedOrderNumber;
        }
        if (stored && numberInput && tokenInput && !tokenInput.value && (!numberInput.value || numberInput.value === stored.number)) {
            numberInput.value = stored.number;
            tokenInput.value = stored.token;
            if (returnedOrderNumber) {
                checkStatus();
            }
        }
    });
})();
