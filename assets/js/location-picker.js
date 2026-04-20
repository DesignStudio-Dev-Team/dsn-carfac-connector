/**
 * DSN Woo Powerall — custom searchable pill multi-select.
 *
 * Progressive enhancement of a real <select multiple>. The <select> stays in
 * the DOM (visually hidden) so the form submits standard values; the widget
 * toggles <option selected> to match user interaction.
 *
 * Usage: mark up a <select multiple> with the CSS class `dsn-picker__native`
 * inside a wrapper `.dsn-picker`. On DOMContentLoaded every matching picker
 * initializes itself.
 */
(function () {
    'use strict';

    function init(root) {
        var select = root.querySelector('select.dsn-picker__native');
        if (!select) {
            return;
        }
        // Guard against double-init.
        if (root.dataset.dsnPickerReady === '1') {
            return;
        }
        root.dataset.dsnPickerReady = '1';

        var control   = document.createElement('div');
        control.className = 'dsn-picker__control';

        var pillsUl   = document.createElement('ul');
        pillsUl.className = 'dsn-picker__pills';

        var search    = document.createElement('input');
        search.type   = 'text';
        search.className = 'dsn-picker__search';
        search.autocomplete = 'off';
        search.spellcheck   = false;
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-haspopup', 'listbox');
        search.setAttribute('aria-expanded', 'false');
        search.placeholder = select.getAttribute('data-placeholder') || '';

        var dropdown = document.createElement('ul');
        dropdown.className = 'dsn-picker__dropdown';
        dropdown.setAttribute('role', 'listbox');
        dropdown.hidden = true;

        control.appendChild(pillsUl);
        control.appendChild(search);

        // Place the custom widget right after the hidden select.
        select.parentNode.insertBefore(control, select);
        select.parentNode.insertBefore(dropdown, select);

        var activeIndex = -1;

        function getOptions() {
            return Array.prototype.slice.call(select.options);
        }

        function isSelected(opt) {
            return opt.selected;
        }

        function selectOption(opt) {
            opt.selected = true;
            search.value = '';
            renderPills();
            renderDropdown();
            openDropdown();
            search.focus();
        }

        function deselectOption(opt) {
            opt.selected = false;
            renderPills();
            renderDropdown();
        }

        function renderPills() {
            pillsUl.textContent = '';
            getOptions().filter(isSelected).forEach(function (opt) {
                var li = document.createElement('li');
                li.className = 'dsn-picker__pill';

                var label = document.createElement('span');
                label.className = 'dsn-picker__pill-label';
                label.textContent = opt.textContent.trim() || opt.value;

                var btn  = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dsn-picker__pill-remove';
                btn.setAttribute('aria-label', 'Remove ' + (opt.textContent.trim() || opt.value));
                btn.textContent = '\u00d7'; // ×
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    deselectOption(opt);
                    search.focus();
                });

                li.appendChild(label);
                li.appendChild(btn);
                pillsUl.appendChild(li);
            });
        }

        function matchesQuery(opt, q) {
            if (!q) {
                return true;
            }
            var haystack = (opt.textContent + ' ' + opt.value).toLowerCase();
            return haystack.indexOf(q) !== -1;
        }

        function renderDropdown() {
            dropdown.textContent = '';
            activeIndex = -1;
            var q = search.value.trim().toLowerCase();
            var items = getOptions().filter(function (opt) {
                return !isSelected(opt) && matchesQuery(opt, q);
            });

            if (!items.length) {
                var empty = document.createElement('li');
                empty.className = 'dsn-picker__option-empty';
                empty.textContent = q
                    ? 'No matches for "' + search.value.trim() + '"'
                    : 'All locations are already selected.';
                dropdown.appendChild(empty);
                return;
            }

            items.forEach(function (opt, idx) {
                var li = document.createElement('li');
                li.className = 'dsn-picker__option';
                li.setAttribute('role', 'option');
                li.dataset.index = String(idx);
                li.textContent = opt.textContent.trim() || opt.value;
                li.addEventListener('mousedown', function (e) {
                    // mousedown instead of click so it fires before the input blur.
                    e.preventDefault();
                    selectOption(opt);
                });
                li.addEventListener('mouseenter', function () {
                    setActive(idx);
                });
                dropdown.appendChild(li);
            });
        }

        function setActive(idx) {
            var items = dropdown.querySelectorAll('.dsn-picker__option');
            if (!items.length) {
                activeIndex = -1;
                return;
            }
            if (idx < 0) idx = items.length - 1;
            if (idx >= items.length) idx = 0;
            activeIndex = idx;
            items.forEach(function (li, i) {
                li.classList.toggle('is-active', i === idx);
                if (i === idx) {
                    // Keep active option in view.
                    var liTop    = li.offsetTop;
                    var liBottom = liTop + li.offsetHeight;
                    if (liTop < dropdown.scrollTop) {
                        dropdown.scrollTop = liTop;
                    } else if (liBottom > dropdown.scrollTop + dropdown.clientHeight) {
                        dropdown.scrollTop = liBottom - dropdown.clientHeight;
                    }
                }
            });
        }

        function getActiveOption() {
            if (activeIndex < 0) {
                return null;
            }
            var q = search.value.trim().toLowerCase();
            var items = getOptions().filter(function (opt) {
                return !isSelected(opt) && matchesQuery(opt, q);
            });
            return items[activeIndex] || null;
        }

        function openDropdown() {
            dropdown.hidden = false;
            root.classList.add('is-open');
            search.setAttribute('aria-expanded', 'true');
        }

        function closeDropdown() {
            dropdown.hidden = true;
            root.classList.remove('is-open');
            search.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        // Clicking anywhere in the control focuses the search input.
        control.addEventListener('click', function (e) {
            if (e.target === control || e.target === pillsUl) {
                search.focus();
            }
        });

        search.addEventListener('focus', function () {
            renderDropdown();
            openDropdown();
        });

        search.addEventListener('input', function () {
            renderDropdown();
            openDropdown();
        });

        search.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                openDropdown();
                setActive(activeIndex + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                openDropdown();
                setActive(activeIndex - 1);
            } else if (e.key === 'Enter') {
                var opt = getActiveOption();
                if (opt) {
                    e.preventDefault();
                    selectOption(opt);
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            } else if (e.key === 'Backspace' && !search.value) {
                var selected = getOptions().filter(isSelected);
                if (selected.length) {
                    e.preventDefault();
                    deselectOption(selected[selected.length - 1]);
                }
            }
        });

        // Click outside closes the dropdown.
        document.addEventListener('mousedown', function (e) {
            if (!root.contains(e.target)) {
                closeDropdown();
            }
        });

        // Initial paint.
        renderPills();
        renderDropdown();
    }

    function boot() {
        var pickers = document.querySelectorAll('.dsn-picker');
        Array.prototype.forEach.call(pickers, init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
