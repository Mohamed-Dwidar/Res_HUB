/*
 * Server side DataTables for every list (yajra/laravel-datatables on the server).
 * Loaded by layoutmodule::partials.datatable-assets (after jQuery + DataTables).
 *
 * <table class="data-table" data-datatable data-url="{{ route(...) }}" data-filters="#my-filters"
 *        data-order='[[1, "asc"]]' data-empty="No items found.">
 *   <thead><tr>
 *     <th data-data="name_html" data-name="name">Name</th>          (data-name = column used to order)
 *     <th data-data="actions" data-orderable="false"></th>
 *     <th data-data="created_at" data-class="whitespace-nowrap">Added</th>
 *   </tr></thead>
 * </table>
 *
 * The filters form (optional):
 *   - input[name=search]                -> DataTables search, while typing (Enter = now)
 *   - any other select / input          -> sent with the request, applied on change
 *   - [data-filter-tab][data-name][data-value] buttons -> set the hidden input with that name (status tabs)
 *   - [data-clear-button] (type=reset)  -> clears everything, disabled when nothing is applied, [data-count] badge
 *   - [data-initial="value"] on a filter -> its value when the list opens (e.g. from the URL), Clear still empties it
 *   - [data-filter-tab][data-initial-tab] -> that tab chosen when the list opens (Clear goes back to the default tab)
 *   - <th data-visible-if="field"> -> the column is shown only while that filter has a value (e.g. the reservations Renewal column)
 *   - input[type=hidden][data-filter-keep] (+ its [data-filter-tab] buttons) -> a view choice, not a filter: sent, but not counted
 *     by Clear and not reset by it (the Income report's Group by buttons)
 *
 * Each row has id="row-{id}" (DT_RowId from the server). window.dataTables is used by the ajax forms (custom.js)
 * to update / remove one row without reloading the page.
 */
(function (jq) {
    // no browser alert() when the server fails, the table shows a message instead
    jq.fn.dataTable.ext.errMode = 'none';

    // [{ table, filterValues }] of the page
    var lists = [];

    function rowOf(id) {
        var tr = document.getElementById('row-' + id);
        if (!tr) {
            return null;
        }
        var list = lists.find(function (item) { return item.table.table().node().contains(tr); });
        return list ? { tr: tr, list: list } : null;
    }

    function icons() {
        createIcons({ icons: window.icons, 'stroke-width': 1.5, nameAttr: 'data-lucide' });
    }

    // "Showing 1 to 9 of 9 entries" after removing a row (the server sends the counts only on a draw,
    // row().remove() lowers the filtered count, the total is lowered here when the record was deleted)
    function refreshInfo(table, deleted) {
        var settings = table.settings()[0];
        if (deleted) {
            settings._iRecordsTotal = Math.max(0, settings._iRecordsTotal - 1);
        }
        var info = table.page.info();
        var rows = table.table().body().querySelectorAll('tr').length;
        var text = 'Showing ' + (info.start + 1) + ' to ' + (info.start + rows) + ' of ' + info.recordsDisplay + ' entries';
        if (info.recordsDisplay !== info.recordsTotal) {
            text += ' (filtered from ' + info.recordsTotal + ' total entries)';
        }
        var infoEl = table.table().container().querySelector('.dt-info');
        if (infoEl) {
            infoEl.textContent = text;
        }
    }

    window.dataTables = {
        has: function (id) {
            return !!rowOf(id);
        },
        // the filters of the list that shows the row, sent with the form so the server knows if the row still matches
        filters: function (id) {
            var row = rowOf(id);
            return row ? row.list.filterValues() : {};
        },
        // data = the same columns as the list (from the server), null = remove the row
        // (deleted, or it does not match the list filters any more)
        update: function (id, data, deleted) {
            var row = rowOf(id);
            if (!row) {
                return false;
            }
            if (!data) {
                return this.remove(id, deleted);
            }
            row.list.table.row(row.tr).data(data);
            icons();
            row.tr.classList.remove('row-flash');
            void row.tr.offsetWidth;
            row.tr.classList.add('row-flash');
            return true;
        },
        remove: function (id, deleted) {
            var row = rowOf(id);
            if (!row) {
                return false;
            }
            var table = row.list.table;
            row.tr.classList.add('row-removing');
            setTimeout(function () {
                table.row(row.tr).remove();
                row.tr.remove();
                if (!table.table().body().querySelector('tr')) {
                    table.draw(false);   // the page is empty now: load it again (previous page / "no data")
                } else {
                    refreshInfo(table, deleted);
                }
            }, 300);
            return true;
        },
        // reload the rows of every list on the page (after adding), keeps the current page
        reload: function () {
            lists.forEach(function (list) { list.table.draw(false); });
            return lists.length > 0;
        },
    };

    function initDataTable(tableEl) {
        var form = tableEl.dataset.filters ? document.querySelector(tableEl.dataset.filters) : null;
        var searchInput = form ? form.querySelector('[name=search]') : null;
        var clearButton = form ? form.querySelector('[data-clear-button]') : null;
        // a filter chosen when the list opens (not "selected" in the HTML: the form reset of Clear would bring it back)
        if (form) {
            form.querySelectorAll('[data-initial]').forEach(function (el) { el.value = el.dataset.initial; });
            form.querySelectorAll('[data-filter-tab][data-initial-tab]').forEach(function (tab) {
                form.querySelector('input[type=hidden][name="' + tab.dataset.name + '"]').value = tab.dataset.value;
                form.querySelectorAll('[data-filter-tab][data-name="' + tab.dataset.name + '"]').forEach(function (other) {
                    other.classList.toggle('active', other === tab);
                });
            });
        }

        var columns = Array.prototype.map.call(tableEl.querySelectorAll('thead th'), function (th) {
            return {
                data: th.dataset.data,
                name: th.dataset.name || th.dataset.data,
                orderable: th.dataset.orderable !== 'false' && !!th.dataset.name,
                searchable: false,
                className: th.dataset.class || '',
                visible: !th.dataset.visibleIf || !!(form && form.querySelector('[name="' + th.dataset.visibleIf + '"]') && form.querySelector('[name="' + th.dataset.visibleIf + '"]').value),
            };
        });
        // th[data-visible-if]: shown while its filter has a value (no redraw: the next draw follows the change); read once here,
        // DataTables takes a hidden column's th out of the page
        var visibleIf = Array.prototype.map.call(tableEl.querySelectorAll('thead th'), function (th) { return th.dataset.visibleIf || ''; });
        function columnsVisibility() {
            if (!form) return;
            visibleIf.forEach(function (name, index) {
                if (!name) return;
                var field = form.querySelector('[name="' + name + '"]');
                table.column(index).visible(!!(field && field.value), false);
            });
        }

        // the filters to send (all named fields except the search box)
        function filterValues() {
            var values = {};
            if (form) {
                Array.prototype.forEach.call(form.elements, function (el) {
                    if (el.name && el.name !== 'search' && el.type !== 'submit' && el.type !== 'reset' && el.type !== 'button') {
                        // a checkbox filter (2026-11-06, reports "Include future"): its value only while ticked
                        values[el.name] = el.type === 'checkbox' ? (el.checked ? el.value : '') : el.value;
                    }
                });
            }
            return values;
        }

        var table = jq(tableEl).DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: tableEl.dataset.url,
                data: function (d) {
                    Object.assign(d, filterValues());
                },
            },
            columns: columns,
            order: tableEl.dataset.order ? JSON.parse(tableEl.dataset.order) : [],
            pageLength: Number(tableEl.dataset.pageLength || 15),
            lengthMenu: [10, 15, 25, 50, 100],
            search: { search: searchInput ? searchInput.value : '' },
            layout: { topStart: null, topEnd: null, bottomStart: ['pageLength', 'info'], bottomEnd: 'paging' },
            language: {
                emptyTable: tableEl.dataset.empty || 'No data found.',
                zeroRecords: 'Nothing matches your search.',
                processing: 'Loading...',
                lengthMenu: '_MENU_ per page',
            },
            drawCallback: icons,
        });

        lists.push({
            table: table,
            filterValues: function () {
                return Object.assign({ search: searchInput ? searchInput.value.trim() : '' }, filterValues());
            },
        });

        // counts sent with the rows (json "counts": {name: n}) → [data-counter="name"] (e.g. the reservations Today tab)
        // html sent with the rows (json "html": {name: html}) → [data-html="name"] (e.g. the payments summary line)
        table.on('xhr', function (e, settings, json) {
            Object.keys((json && json.counts) || {}).forEach(function (name) {
                document.querySelectorAll('[data-counter="' + name + '"]').forEach(function (el) { el.textContent = json.counts[name]; });
            });
            Object.keys((json && json.html) || {}).forEach(function (name) {
                document.querySelectorAll('[data-html="' + name + '"]').forEach(function (el) { el.innerHTML = json.html[name]; });
            });
        });

        table.on('error', function (e, settings, techNote, message) {
            console.error('DataTables:', message);
            var columnsCount = tableEl.querySelectorAll('thead th').length;
            tableEl.querySelector('tbody').innerHTML = '<tr><td colspan="' + columnsCount + '" class="empty-state text-danger">Could not load the data, please try again.</td></tr>';
        });

        if (!form) {
            return;
        }

        function updateClearButton() {
            if (!clearButton) {
                return;
            }
            var count = (searchInput && searchInput.value.trim() ? 1 : 0);
            var values = filterValues();
            // the date field (layoutmodule::partials.date-range-filter) = ONE filter: date_from only (with the tab it set to All),
            // not counted while on its default day (a list without tabs)
            var dateBox = form.querySelector('[data-date-range]');
            var dateSet = !!values.date_from && !(dateBox && values.date_from === dateBox.dataset.dateDefault && values.date_to === values.date_from)
                && !(dateBox && dateBox.dataset.dateInitial === values.date_from + ',' + values.date_to);
            Object.keys(values).forEach(function (name) {
                if (form.querySelector('input[name="' + name + '"][data-filter-keep]')) {
                    return;
                }
                if (name === 'date_to' || (name === 'date_from' && !dateSet) || (name === 'tab' && dateSet)) {
                    return;
                }
                count += values[name] !== '' ? 1 : 0;
            });
            clearButton.disabled = count === 0;
            var badge = clearButton.querySelector('[data-count]');
            if (badge) {
                badge.textContent = count;
                badge.hidden = count === 0;
            }
        }

        // search while typing (small delay), Enter = now
        var timer;
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { table.search(searchInput.value).draw(); }, 400);
            });
        }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearTimeout(timer);
            table.search(searchInput ? searchInput.value : '').draw();
            updateClearButton();
        });

        // selects / other inputs
        form.addEventListener('change', function (e) {
            if (e.target !== searchInput) {
                columnsVisibility();
                table.draw();
            }
            updateClearButton();
        });
        form.addEventListener('input', updateClearButton);

        // status tabs: set the hidden input of the same name
        form.querySelectorAll('[data-filter-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                form.querySelector('input[type=hidden][name="' + tab.dataset.name + '"]').value = tab.dataset.value;
                form.querySelectorAll('[data-filter-tab][data-name="' + tab.dataset.name + '"]').forEach(function (other) {
                    other.classList.toggle('active', other === tab);
                });
                table.draw();
                updateClearButton();
            });
        });

        form.addEventListener('reset', function () {
            setTimeout(function () {
                // hidden inputs are not reset by the browser
                form.querySelectorAll('input[type=hidden]:not([data-filter-keep])').forEach(function (input) { input.value = ''; });
                form.querySelectorAll('[data-filter-tab]').forEach(function (tab) {
                    if (form.querySelector('input[name="' + tab.dataset.name + '"][data-filter-keep]')) return;
                    tab.classList.toggle('active', tab.dataset.value === '');
                });
                clearTimeout(timer);
                columnsVisibility();
                table.search('').draw();
                updateClearButton();
            });
        });

        updateClearButton();
    }

    document.querySelectorAll('table[data-datatable]').forEach(initDataTable);
})(jQuery.noConflict(true));
