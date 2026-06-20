// Game Vault frontend.
// jQuery for DOM stuff, fetch for the API calls so I can pass headers cleanly.

(function () {
    'use strict';

    // base path lets the page work whether it sits at / or /game-vault/
    var API_BASE = './api';

    var state = {
        playerId: 1,
    };

    // ---- API helpers ----------------------------------------------------

    function apiGet(path) {
        return fetch(API_BASE + path).then(handleResponse);
    }

    function apiPost(path, body, headers) {
        return fetch(API_BASE + path, {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, headers || {}),
            body: JSON.stringify(body),
        }).then(handleResponse);
    }

    function handleResponse(res) {
        return res.text().then(function (text) {
            var data = text ? JSON.parse(text) : null;
            if (!res.ok) {
                var err = new Error((data && data.message) || (data && data.error) || ('HTTP ' + res.status));
                err.status = res.status;
                err.body = data;
                throw err;
            }
            return data;
        });
    }

    // UUID v4-ish generator, good enough for an idempotency key on the client.
    function newIdempotencyKey() {
        var bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.from(bytes, function (b) {
            return b.toString(16).padStart(2, '0');
        }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
             + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    // ---- UI helpers -----------------------------------------------------

    var $banner = $('#banner');
    var bannerTimer = null;

    function showBanner(message, kind) {
        $banner.removeClass('hidden error success').addClass(kind).text(message);
        if (bannerTimer) { clearTimeout(bannerTimer); }
        bannerTimer = setTimeout(function () { $banner.addClass('hidden'); }, 4000);
    }

    function setGold(value) {
        $('#goldBadge').text(value + ' gold');
    }

    // ---- Loaders --------------------------------------------------------

    function loadPlayer() {
        return apiGet('/player.php?id=' + state.playerId).then(function (player) {
            setGold(player.gold);
        }).catch(function (err) {
            showBanner('Could not load player: ' + err.message, 'error');
        });
    }

    function loadItems() {
        var type = $('#typeFilter').val();
        var url = '/items.php' + (type ? '?type=' + encodeURIComponent(type) : '');
        return apiGet(url).then(renderItems).catch(function (err) {
            showBanner('Could not load items: ' + err.message, 'error');
        });
    }

    function renderItems(data) {
        var $grid = $('#itemGrid').empty();
        if (!data.items || data.items.length === 0) {
            $grid.html('<p class="muted">No items in this filter.</p>');
            return;
        }
        data.items.forEach(function (item) {
            var $card = $(
                '<article class="card">' +
                    '<div style="display:flex;justify-content:space-between;align-items:baseline">' +
                        '<h3></h3>' +
                        '<span class="rarity"></span>' +
                    '</div>' +
                    '<p class="muted"></p>' +
                    '<div class="row">' +
                        '<span class="gold-badge"></span>' +
                        '<button class="buy">Buy</button>' +
                    '</div>' +
                '</article>'
            );
            $card.find('h3').text(item.name);
            $card.find('.rarity').addClass(item.rarity).text(item.rarity);
            $card.find('.muted').text(item.type);
            $card.find('.gold-badge').text(item.price_gold + ' gold');
            $card.find('.buy').on('click', function () { buyItem(item, $(this)); });
            $grid.append($card);
        });
    }

    function buyItem(item, $btn) {
        $btn.prop('disabled', true).text('Buying...');
        var key = newIdempotencyKey();
        apiPost('/purchase.php?player_id=' + state.playerId,
                { item_id: item.id, quantity: 1 },
                { 'Idempotency-Key': key })
            .then(function (res) {
                setGold(res.purchase.gold_remaining);
                showBanner('Bought ' + res.purchase.item_name + '. ' +
                           res.purchase.gold_remaining + ' gold left.', 'success');
            })
            .catch(function (err) {
                showBanner(err.message, 'error');
            })
            .finally(function () {
                $btn.prop('disabled', false).text('Buy');
            });
    }

    function loadInventory() {
        var $list = $('#invList').html('<p class="muted">Loading...</p>');
        apiGet('/inventory.php?player_id=' + state.playerId + '&per_page=50')
            .then(function (data) {
                if (data.entries.length === 0) {
                    $list.html('<p class="muted">Nothing in inventory yet. Buy something from the Shop.</p>');
                    return;
                }
                var html = '<table class="inv"><thead><tr>' +
                    '<th>Item</th><th>Type</th><th>Rarity</th><th class="num">Qty</th><th>Acquired</th>' +
                    '</tr></thead><tbody>';
                data.entries.forEach(function (e) {
                    html += '<tr>' +
                        '<td>' + escape(e.name) + '</td>' +
                        '<td class="muted">' + escape(e.type) + '</td>' +
                        '<td><span class="rarity ' + e.rarity + '">' + escape(e.rarity) + '</span></td>' +
                        '<td class="num">' + e.quantity + '</td>' +
                        '<td class="muted">' + escape(e.acquired_at) + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
                $list.html(html);
            })
            .catch(function (err) {
                $list.html('<p class="banner error">' + escape(err.message) + '</p>');
            });
    }

    function escape(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    // ---- Wire up --------------------------------------------------------

    $(function () {
        $('#playerSelect').on('change', function () {
            state.playerId = parseInt($(this).val(), 10);
            loadPlayer();
            // refresh whichever tab is visible
            if ($('#tab-shop').hasClass('hidden')) {
                loadInventory();
            } else {
                loadItems();
            }
        });

        $('#typeFilter').on('change', loadItems);

        $('.tabs a').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            $('.tabs a').removeClass('active');
            $(this).addClass('active');
            $('.tab-pane').addClass('hidden');
            $('#tab-' + tab).removeClass('hidden');
            if (tab === 'inventory') { loadInventory(); }
        });

        loadPlayer();
        loadItems();
    });

})();
