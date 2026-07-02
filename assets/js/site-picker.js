(function () {
    var boot = window.__tncSitePickerBoot || {};
    var pickerUrl = boot.pickerUrl || '';
    var csrfToken = boot.csrfToken || '';

    var searchInput = document.getElementById('sitePickerSearch');
    var searchClearBtn = document.getElementById('sitePickerSearchClear');
    var searchMeta = document.getElementById('sitePickerSearchMeta');
    var searchBar = document.getElementById('sitePickerSearchBar');
    var searchTimer = null;
    var searchDefaultStatusHtml = searchMeta ? searchMeta.innerHTML : '';

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setSearchStatus(kind, message) {
        if (!searchMeta) {
            return;
        }
        searchMeta.classList.remove('is-found', 'is-empty');
        if (kind === 'default') {
            searchMeta.innerHTML = searchDefaultStatusHtml;
            return;
        }
        var iconClass = kind === 'empty' ? 'bi-exclamation-circle' : 'bi-check2-circle';
        if (kind === 'found') {
            searchMeta.classList.add('is-found');
        } else if (kind === 'empty') {
            searchMeta.classList.add('is-empty');
        }
        searchMeta.innerHTML = '<i class="bi ' + iconClass + '" aria-hidden="true"></i>'
            + '<span class="site-picker-search__status-text">' + escapeHtml(message) + '</span>';
    }

    function findCaseInsensitiveIndex(haystack, needle) {
        return normalizeSiteSearchText(haystack).indexOf(normalizeSiteSearchText(needle));
    }

    function renderSiteNameHighlight(card, query) {
        var nameEl = card ? card.querySelector('.site-card__name') : null;
        if (!nameEl) {
            return;
        }
        var name = getSiteCardName(card);
        if (!query) {
            nameEl.textContent = name;
            return;
        }
        var matchIndex = findCaseInsensitiveIndex(name, query);
        if (matchIndex < 0) {
            nameEl.textContent = name;
            return;
        }
        var matchLength = query.length;
        var before = name.slice(0, matchIndex);
        var matchText = name.slice(matchIndex, matchIndex + matchLength);
        var after = name.slice(matchIndex + matchLength);
        nameEl.innerHTML = escapeHtml(before)
            + '<mark class="site-picker-search-mark">' + escapeHtml(matchText) + '</mark>'
            + escapeHtml(after);
    }

    function normalizeSiteSearchText(value) {
        return String(value || '').trim().toLowerCase();
    }

    function siteSearchScore(name, query) {
        var normalizedName = normalizeSiteSearchText(name);
        var normalizedQuery = normalizeSiteSearchText(query);
        if (normalizedQuery === '') {
            return -1;
        }
        if (normalizedName === normalizedQuery) {
            return 0;
        }
        if (normalizedName.indexOf(normalizedQuery) === 0) {
            return 1;
        }
        if (normalizedName.indexOf(normalizedQuery) !== -1) {
            return 2;
        }
        return -1;
    }

    function getSiteCardName(card) {
        if (!card) {
            return '';
        }
        return String(card.getAttribute('data-site-name') || card.querySelector('.site-card__name')?.textContent || '').trim();
    }

    function reorderSiteCards() {
        var grid = document.getElementById('sitePickerGrid');
        if (!grid) {
            return;
        }
        var addCard = grid.querySelector('.site-picker-add');
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.site-picker-card:not(.site-picker-add)'));
        cards.sort(function (a, b) {
            var af = a.getAttribute('data-favorite') === '1' ? 0 : 1;
            var bf = b.getAttribute('data-favorite') === '1' ? 0 : 1;
            if (af !== bf) {
                return af - bf;
            }
            return (parseInt(a.getAttribute('data-sort-index') || '0', 10) || 0)
                - (parseInt(b.getAttribute('data-sort-index') || '0', 10) || 0);
        });
        cards.forEach(function (card) {
            if (addCard) {
                grid.insertBefore(card, addCard);
            } else {
                grid.appendChild(card);
            }
        });
    }

    function applySiteSearch(rawQuery) {
        var grid = document.getElementById('sitePickerGrid');
        if (!grid) {
            return;
        }
        var query = String(rawQuery || '').trim();
        var addCard = grid.querySelector('.site-picker-add');
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.site-picker-card:not(.site-picker-add)'));
        var matches = [];

        if (searchClearBtn) {
            searchClearBtn.classList.toggle('d-none', query === '');
        }
        if (searchBar) {
            searchBar.classList.toggle('is-active', query !== '');
            searchBar.classList.remove('is-empty');
        }

        cards.forEach(function (card) {
            card.classList.remove('is-search-match-first');
            var score = siteSearchScore(getSiteCardName(card), query);
            var isMatch = score >= 0;
            card.classList.toggle('d-none', query !== '' && !isMatch);
            if (query === '' || !isMatch) {
                renderSiteNameHighlight(card, '');
            }
            if (isMatch) {
                matches.push({
                    card: card,
                    score: score,
                    sortIndex: parseInt(card.getAttribute('data-sort-index') || '0', 10) || 0
                });
            }
        });

        if (query === '') {
            reorderSiteCards();
            setSearchStatus('default');
            return;
        }

        matches.sort(function (a, b) {
            if (a.score !== b.score) {
                return a.score - b.score;
            }
            return a.sortIndex - b.sortIndex;
        });

        matches.forEach(function (match) {
            if (addCard) {
                grid.insertBefore(match.card, addCard);
            } else {
                grid.appendChild(match.card);
            }
        });

        if (matches.length > 0) {
            matches[0].card.classList.add('is-search-match-first');
            matches.forEach(function (match) {
                renderSiteNameHighlight(match.card, query);
            });
        }

        if (matches.length === 0) {
            if (searchBar) {
                searchBar.classList.add('is-empty');
            }
            setSearchStatus('empty', 'ไม่พบไซต์ที่ตรงกับ «' + query + '»');
            return;
        }

        if (matches.length === 1) {
            setSearchStatus('found', 'พบ 1 ไซต์ · แสดงไว้ตำแหน่งแรก');
            return;
        }

        setSearchStatus('found', 'พบ ' + matches.length.toLocaleString('th-TH') + ' ไซต์ · เรียงจากตรงที่สุดไปน้อยที่สุด');
    }

    function setFavoriteUi(card, btn, isFavorite) {
        if (!card || !btn) {
            return;
        }
        card.setAttribute('data-favorite', isFavorite ? '1' : '0');
        btn.classList.toggle('is-favorite', isFavorite);
        btn.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
        btn.setAttribute('title', isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด');
        var icon = btn.querySelector('i');
        if (icon) {
            icon.className = isFavorite ? 'bi bi-star-fill' : 'bi bi-star';
        }
        var siteCard = card.querySelector('.site-card');
        if (siteCard) {
            siteCard.classList.toggle('site-card--favorite', isFavorite);
        }
        var siteName = card.querySelector('.site-card__name');
        if (siteName) {
            btn.setAttribute('aria-label', (isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มในรายการโปรด') + ': ' + siteName.textContent.trim());
        }
    }

    function beginSiteHubNavigation(link, event) {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#') {
            return;
        }
        var card = link.closest('.site-card');
        var pickerCard = link.closest('.site-picker-card');
        var grid = document.getElementById('sitePickerGrid');
        if (card && !card.classList.contains('is-navigating')) {
            card.classList.add('is-navigating');
            link.setAttribute('aria-busy', 'true');
        }
        if (pickerCard) {
            pickerCard.classList.add('is-active-nav');
        }
        if (grid) {
            grid.classList.add('is-site-navigating');
            grid.setAttribute('aria-busy', 'true');
        }
    }

    document.querySelectorAll('.site-card-link[href]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            beginSiteHubNavigation(link, event);
        });
    });

    document.querySelectorAll('.site-fav-btn').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (btn.disabled) {
                return;
            }
            var siteId = btn.getAttribute('data-site-id') || '';
            if (!siteId) {
                return;
            }
            btn.disabled = true;
            fetch(pickerUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                },
                body: new URLSearchParams({
                    toggle_site_favorite: '1',
                    site_id: siteId,
                    _csrf: csrfToken
                }).toString()
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        if (!res.ok || !data || !data.ok) {
                            var err = (data && data.error) ? data.error : 'request_failed';
                            throw new Error(err);
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    var card = btn.closest('.site-picker-card');
                    setFavoriteUi(card, btn, !!data.favorite);
                    if (searchInput && normalizeSiteSearchText(searchInput.value) !== '') {
                        applySiteSearch(searchInput.value);
                    } else {
                        reorderSiteCards();
                    }
                })
                .catch(function () {
                    alert('ไม่สามารถบันทึกรายการโปรดได้ กรุณาลองใหม่');
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    });

    function revealSitePicker() {
        var grid = document.getElementById('sitePickerGrid');
        if (!grid || !grid.classList.contains('site-picker-is-loading')) {
            return;
        }
        grid.querySelectorAll('.site-picker-skeleton').forEach(function (el) {
            el.remove();
        });
        grid.classList.remove('site-picker-is-loading');
        grid.setAttribute('aria-busy', 'false');
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                applySiteSearch(searchInput.value);
            }, 180);
        });
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                searchInput.value = '';
                applySiteSearch('');
                searchInput.blur();
            }
        });
    }

    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            applySiteSearch('');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.requestAnimationFrame(revealSitePicker);
        });
    } else {
        window.requestAnimationFrame(revealSitePicker);
    }

    if (!boot.canCreateSite) {
        return;
    }

    var catRows = document.getElementById('pickerCatRows');
    var addBtn = document.getElementById('pickerAddCatRow');
    var pctTotalEl = document.getElementById('pickerCatPctTotal');
    var modalEl = document.getElementById('sitePickerCreateModal');
    var nameInput = document.getElementById('picker_site_name');

    function parsePct(raw) {
        var s = String(raw || '').replace(/%/g, '').replace(/,/g, '').trim();
        if (s === '') {
            return 0;
        }
        var n = parseFloat(s);
        return isNaN(n) ? 0 : Math.max(0, n);
    }

    function updatePctTotal() {
        if (!pctTotalEl || !catRows) {
            return;
        }
        var sum = 0;
        catRows.querySelectorAll('input[name="category_budget_percent[]"]').forEach(function (input) {
            sum += parsePct(input.value);
        });
        sum = Math.round(sum * 100) / 100;
        pctTotalEl.textContent = 'รวม ' + sum + '% / 100%';
        pctTotalEl.classList.toggle('is-over', sum > 100.0001);
    }

    function syncRemoveButtons() {
        if (!catRows) {
            return;
        }
        var rows = catRows.querySelectorAll('.picker-cat-row');
        rows.forEach(function (row) {
            var btn = row.querySelector('.btn-remove-cat');
            if (!btn) {
                return;
            }
            var disabled = rows.length <= 1;
            btn.hidden = disabled;
            btn.disabled = disabled;
        });
    }

    function bindPctInputs(scope) {
        (scope || document).querySelectorAll('input[name="category_budget_percent[]"]').forEach(function (input) {
            if (input.dataset.pickerPctBound === '1') {
                return;
            }
            input.dataset.pickerPctBound = '1';
            input.addEventListener('input', updatePctTotal);
        });
    }

    function bindRemove(btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.picker-cat-row');
            if (!row || !catRows) {
                return;
            }
            if (catRows.querySelectorAll('.picker-cat-row').length <= 1) {
                return;
            }
            row.remove();
            syncRemoveButtons();
            updatePctTotal();
        });
    }

    if (addBtn && catRows) {
        addBtn.addEventListener('click', function () {
            var first = catRows.querySelector('.picker-cat-row');
            if (!first) {
                return;
            }
            var clone = first.cloneNode(true);
            clone.querySelectorAll('input').forEach(function (input) {
                input.value = '';
                delete input.dataset.pickerPctBound;
            });
            var removeBtn = clone.querySelector('.btn-remove-cat');
            if (removeBtn) {
                removeBtn.hidden = false;
                removeBtn.disabled = false;
                bindRemove(removeBtn);
            }
            catRows.appendChild(clone);
            bindPctInputs(clone);
            syncRemoveButtons();
            updatePctTotal();
            var catNameInput = clone.querySelector('input[name="category_name[]"]');
            if (catNameInput) {
                catNameInput.focus();
            }
        });
        catRows.querySelectorAll('.btn-remove-cat').forEach(bindRemove);
        bindPctInputs(catRows);
        syncRemoveButtons();
        updatePctTotal();
    }

    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            if (nameInput) {
                nameInput.focus();
            }
        });
    }

    if (boot.openCreateModal && modalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}());
