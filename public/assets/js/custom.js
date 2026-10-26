// Sidebar defaults to open; the toggle button switches it and the choice is
// remembered. Must load before themes/dagger.js, which reads "compactMenu" on init.
if (localStorage.getItem("compactMenu") === null) {
    localStorage.setItem("compactMenu", "false");
}

// Disable dagger.js hover-to-expand so a collapsed sidebar stays collapsed.
window.addEventListener("mouseover", function (e) {
    if (e.target.closest && e.target.closest(".side-menu")) {
        e.stopPropagation();
    }
}, true);

// dagger.js forces the compact menu on resize below 1600px; keep only the
// mobile-menu reset so the user's choice is respected.
document.addEventListener("DOMContentLoaded", function () {
    window.onresize = function () {
        $(".side-menu").first().removeClass("side-menu--mobile-menu-open");
        $(".close-mobile-menu").first().removeClass("close-mobile-menu--mobile-menu-open");
    };
});

// Forms with data-confirm="message" open a confirm modal before submitting (approve, reject, delete, save, ...).
// Optional: data-confirm-title, data-confirm-button (confirm button text),
// data-confirm-variant = success | warning | danger (button color + icon, default danger).
// Forms with data-ajax are sent by ajax (ajaxSubmit), the others normally.
function confirmForm(form, onConfirm) {
    var variants = {
        success: { color: "--color-success", icon: "question" },
        warning: { color: "--color-pending", icon: "warning" },
        danger: { color: "--color-danger", icon: "warning" },
    };
    var variant = variants[form.dataset.confirmVariant] || variants.danger;
    var color = getComputedStyle(document.documentElement).getPropertyValue(variant.color).trim();

    if (!window.Swal) {
        if (window.confirm(form.dataset.confirm)) onConfirm();
        return;
    }
    Swal.fire({
        title: form.dataset.confirmTitle || "Are you sure?",
        text: form.dataset.confirm,
        icon: variant.icon,
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmButton || "Yes",
        cancelButtonText: "Cancel",
        confirmButtonColor: color ? "rgb(" + color + ")" : undefined,
        reverseButtons: true,
        focusCancel: true,
    }).then(function (result) {
        if (result.isConfirmed) onConfirm();
    });
}

document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form.dataset || form.dataset.confirmed || (!form.dataset.confirm && form.dataset.ajax === undefined)) {
        return;
    }
    e.preventDefault();
    // the browser checks (required, email, ...) before asking
    if (!form.reportValidity()) {
        return;
    }

    var send = function () {
        if (form.dataset.ajax !== undefined) {
            ajaxSubmit(form);
        } else {
            form.dataset.confirmed = "1";
            form.submit();
        }
    };
    form.dataset.confirm ? confirmForm(form, send) : send();
});

// Messages: small notification at the top (also after a redirect, kept in sessionStorage)
function toast(message, icon) {
    if (!message) return;
    if (!window.Swal) {
        alert(message);
        return;
    }
    Swal.fire({ toast: true, position: "top-end", icon: icon || "success", title: message, showConfirmButton: false, timer: 3000, timerProgressBar: true });
}

function toastAfterRedirect(message) {
    try {
        sessionStorage.setItem("toast", message || "");
    } catch (e) {}
}

window.addEventListener("load", function () {
    try {
        var message = sessionStorage.getItem("toast");
        sessionStorage.removeItem("toast");
        if (message) toast(message);
    } catch (e) {}
});

// The popup (#app-modal): <a href="..." data-modal> loads the page by ajax (it renders with layoutmodule::modal).
// Links inside the popup with data-modal replace its content (view -> edit).
// The popup keeps a back history (2026-10-21): a page opened from INSIDE the popup (a[data-modal] in it: the reservation
// view → Edit / Cash In / a cash in → its Edit ...) remembers the page it came from; a Cancel button
// ([data-tw-dismiss="modal"] in the content, not the × of the header) goes back to it instead of closing.
// nav: "push" (opened from the popup) | "back" (Cancel) | "saved" (json.modal after a save: the history is cut back to that
// page when it is in it) | none (opened from the page: a new history). The × / backdrop close it as before.
var appModal = {
    stack: [],
    current: null,
    el: function () {
        return document.getElementById("app-modal");
    },
    isOpen: function () {
        var modal = this.el();
        return !!(modal && modal.classList.contains("show"));
    },
    remember: function (url, nav) {
        url = new URL(url, window.location.href).href;
        if (nav === "push" && this.current && this.current !== url) {
            this.stack.push(this.current);
        } else if (nav === "saved") {
            var index = this.stack.indexOf(url);
            if (index !== -1) this.stack = this.stack.slice(0, index);
        } else if (nav !== "back") {
            this.stack = [];
        }
        this.current = url;
    },
    back: function () {
        if (!this.stack.length) return false;
        this.open(this.stack.pop(), "back");
        return true;
    },
    open: function (url, nav) {
        var self = this;
        var modal = this.el();
        var body = modal.querySelector(".app-modal__body");
        return fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" } })
            .then(function (response) {
                // the session ended (redirected to the login page): reload, the user logs in again
                if (response.redirected && /\/(login|admin)\/?$/.test(new URL(response.url).pathname)) {
                    window.location.reload();
                    throw null;
                }
                if (!response.ok) throw response;
                return response.text();
            })
            .then(function (html) {
                // viewers (gallery) of the previous content were moved to <body> by the template
                document.querySelectorAll("body > .gallery-modal:not(.show)").forEach(function (old) { old.remove(); });
                body.innerHTML = html;
                self.remember(url, nav);
                var content = body.firstElementChild;
                var size = content && content.dataset.modalSize ? content.dataset.modalSize : "md";
                modal.querySelector(".app-modal__dialog").dataset.size = size;
                createIcons({ icons: icons, "stroke-width": 1.5, nameAttr: "data-lucide" });
                initForms(body);
                tailwind.Modal.getOrCreateInstance(modal).show();
                setTimeout(function () {
                    var focus = body.querySelector("[autofocus]");
                    if (focus) focus.focus();
                }, 450);
            })
            .catch(function (error) {
                if (error === null) return;
                // say why (server error code / script error), the details are in the browser console
                console.error("Popup " + url, error);
                var reason = error instanceof Response
                    ? (error.status === 404 ? "it was not found, it may have been deleted" : error.status === 403 ? "you can not open it" : "server error " + error.status)
                    : "script error: " + (error && error.message ? error.message : error);
                toast("Could not open the page (" + reason + ").", "error");
            });
    },
    close: function () {
        var modal = this.el();
        if (modal && modal.classList.contains("show")) {
            tailwind.Modal.getOrCreateInstance(modal).hide();
        }
    },
};

document.addEventListener("click", function (e) {
    var link = e.target.closest && e.target.closest("a[data-modal]");
    // ctrl / cmd / middle click: open the full page in a new tab as usual
    if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
        return;
    }
    e.preventDefault();
    // from inside the open popup: its Cancel comes back here
    appModal.open(link.href, link.closest("#app-modal") && appModal.isOpen() ? "push" : null);
});

// Cancel inside the popup content (not the × of the header): back to the previous popup page when there is one
document.addEventListener("click", function (e) {
    var cancel = e.target.closest && e.target.closest('#app-modal [data-tw-dismiss="modal"]:not(.app-modal__close)');
    if (!cancel || !appModal.stack.length) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    appModal.back();
}, true);

// Ajax forms (data-ajax). The server answers JSON:
//   { message, row: {...} }  -> update the row of the list (null = remove it, e.g. deleted)
//   { message, reload: true } -> reload the lists of the page (added)
//   { message, redirect }     -> where to go when the row / list is not on this page (full page forms)
//   { counts: { name: n } }   -> new numbers for [data-counter="name"] (tabs, titles, sidebar badges with data-hide-zero)
//   { modal: url }            -> a form in the popup: the popup shows this page after saving (e.g. back to the reservation view)
//   { logout: true }          -> log out now (the employee's shift popups: closed / still working at logout)
// The X-Shift-Reminder header (PaymentModule ShiftReminder middleware) -> the "close your shift" popup after the action.
// data-row="{id}" = the list row of the record (row-{id}), its list filters are sent as list[...].
// 422 -> the errors are shown under the fields ([data-field="name"]) or at the top of the form.
function clearFormErrors(form) {
    form.querySelectorAll("[data-ajax-error]").forEach(function (el) { el.remove(); });
    form.querySelectorAll(".ts-wrapper.is-invalid").forEach(function (el) { el.classList.remove("is-invalid"); });
    form.querySelectorAll("[data-error-marked]").forEach(function (el) {
        el.classList.replace("border-danger", el.dataset.errorMarked);
        delete el.dataset.errorMarked;
    });
}

function showFormErrors(form, errors) {
    var template = document.getElementById("field-error-template");
    var summary = [];
    Object.keys(errors).forEach(function (key) {
        var name = key.split(".")[0];
        var wrapper = form.querySelector('[data-field="' + name + '"]');
        var messages = [].concat(errors[key]);
        if (!wrapper) {
            // "html_" errors are built (escaped) by the server with links, shown as HTML; the others as text
            messages.forEach(function (message) { summary.push({ text: message, html: key.indexOf("html_") === 0 }); });
            return;
        }
        wrapper.querySelectorAll("input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea, button[data-tw-toggle]").forEach(function (input) {
            ["border-slate-200", "border-slate-300/80"].forEach(function (border) {
                if (input.classList.contains(border)) {
                    input.classList.replace(border, "border-danger");
                    input.dataset.errorMarked = border;
                }
            });
        });
        wrapper.querySelectorAll(".ts-wrapper").forEach(function (box) { box.classList.add("is-invalid"); });
        messages.forEach(function (message) {
            var error = template.content.firstElementChild.cloneNode(true);
            error.textContent = message;
            wrapper.appendChild(error);
        });
    });
    if (summary.length) {
        var box = document.createElement("div");
        box.setAttribute("role", "alert");
        box.dataset.ajaxError = "";
        box.className = "alert relative border rounded-md px-5 py-4 bg-danger border-danger bg-opacity-20 border-opacity-5 text-danger mb-5";
        summary.forEach(function (item) {
            var line = document.createElement("div");
            item.html ? (line.innerHTML = item.text) : (line.textContent = item.text);
            box.appendChild(line);
        });
        form.prepend(box);
    }
    var first = form.querySelector("[data-ajax-error]");
    if (first) first.scrollIntoView({ block: "center", behavior: "smooth" });
}

function ajaxSubmit(form) {
    var data = new FormData(form);
    var rowId = form.dataset.row;
    if (rowId && window.dataTables) {
        var filters = window.dataTables.filters(rowId);
        Object.keys(filters).forEach(function (name) { data.append("list[" + name + "]", filters[name]); });
    }

    // the buttons (and the switch of a row toggle) are locked while sending, FormData is already taken
    var buttons = form.querySelectorAll("button[type=submit], [data-toggle-form] input[type=checkbox]");
    buttons.forEach(function (button) { button.disabled = true; });
    form.classList.add("is-sending");
    clearFormErrors(form);
    // a row toggle that fails goes back to its previous position
    var toggle = form.matches("[data-toggle-form]") ? form.querySelector("input[type=checkbox]") : null;
    var failed = function () {
        if (toggle) toggle.checked = !toggle.checked;
    };

    // the attribute, not form.action: a field named "action" (the shift reminder / logout popups) hides that property
    fetch(form.getAttribute("action") || window.location.href, {
        method: "POST",   // PUT / PATCH / DELETE go as _method (Laravel)
        body: data,
        headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
    })
        .then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                return { status: response.status, json: json, reminder: response.headers.get("X-Shift-Reminder") };
            });
        })
        .then(function (result) {
            var json = result.json;
            if (result.status >= 400) {
                failed();
            }
            if (result.status === 422) {
                if (json.errors) {
                    showFormErrors(form, json.errors);
                } else {
                    toast(json.message || "Please check the data.", "error");
                }
                return;
            }
            if (result.status === 404) {
                toast("This record was not found, it may have been deleted.", "error");
                return;
            }
            if (result.status >= 400) {
                toast(json.message && result.status < 500 ? json.message : "Something went wrong, please try again.", "error");
                return;
            }

            Object.keys(json.counts || {}).forEach(function (name) {
                document.querySelectorAll('[data-counter="' + name + '"]').forEach(function (el) {
                    el.textContent = json.counts[name];
                    if (el.hasAttribute("data-hide-zero")) el.hidden = !Number(json.counts[name]);
                });
            });

            if (json.logout) {
                toastAfterRedirect(json.message);
                logoutNow();
                return;
            }

            var inModal = form.closest("#app-modal");
            var tables = window.dataTables;
            var done = false;
            if ("row" in json && rowId && tables && tables.has(rowId)) {
                done = tables.update(rowId, json.row, data.get("_method") === "DELETE");
            } else if (json.reload && tables) {
                done = tables.reload();
            }

            // the Schedule page: its sections are loaded again (no list rows there, no redirect away)
            if (!done && window.todayPage && window.todayPage.active()) {
                window.todayPage.refresh();
                done = true;
            }

            // a form opened from another popup page (the back history): saved → back to that page (loaded again), not a
            // redirect / close; a delete keeps its own answer (the page it came from may be the deleted record)
            var backAfter = inModal && !json.modal && appModal.stack.length > 0 && data.get("_method") !== "DELETE";

            if (!done && json.redirect && !backAfter) {
                toastAfterRedirect(json.message);
                window.location.href = json.redirect;
                return;
            }
            toast(json.message);
            if (inModal && json.modal) {
                appModal.open(json.modal, "saved");
            } else if (result.reminder) {
                appModal.open(result.reminder);
            } else if (backAfter) {
                appModal.back();
            } else if (inModal) {
                appModal.close();
            }
        })
        .catch(function () {
            failed();
            toast("Could not connect to the server, please try again.", "error");
        })
        .finally(function () {
            buttons.forEach(function (button) { button.disabled = false; });
            form.classList.remove("is-sending");
        });
}

// Employee shift (PaymentModule, docs/payments-plan.md §2b): never a block, popups only.
// - logout form with data-logout-prompt (payments not closed): the popup "Close my shift and log out" / "Log out, I'm still working"
// - [data-shift-reminder] on the page (the shift is open too long): the "close your shift" popup when the page opens
function logoutNow() {
    var form = document.querySelector("form[data-logout-prompt]") || document.querySelector('form[action$="/logout"]');
    if (form) {
        form.submit();   // submit() skips the submit listeners (no prompt again)
    } else {
        window.location.reload();
    }
}

document.addEventListener("submit", function (e) {
    var form = e.target;
    if (form.dataset && form.dataset.logoutPrompt) {
        e.preventDefault();
        appModal.open(form.dataset.logoutPrompt);
    }
});

document.addEventListener("DOMContentLoaded", function () {
    var reminder = document.querySelector("[data-shift-reminder]");
    if (reminder) appModal.open(reminder.dataset.shiftReminder);
});

// ONE date field synced with the list tabs (layoutmodule::partials.date-range-filter + partials.filter-tabs; reservations,
// packages, payments lists). flatpickr range mode (MIT): one day clicked = that day, two = the range; × clears it.
// - a tab click: no dates sent (the tab has its own rule), the field shows the tab's days (data-date-select "Y-m-d[,Y-m-d]")
//   or its text (data-date-label, e.g. "From 14-10-2026"); All = empty
// - days picked: the tab with exactly those days is clicked (today → Today, this month → This Month, cleared → All);
//   other days = the All tab with date_from / date_to, no tab highlighted
// - data-date-default (a list without tabs: the employees' payments): starts on that day, × / Clear go back to it, never empty
// Capture phase: runs before datatables.js reads the filters and reloads the list.
function dateRangeYmd(date) {
    return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
}
function dateRangeTabs(form) {
    return Array.prototype.slice.call(form.querySelectorAll('[data-filter-tab][data-name="tab"]'));
}
// a list without tabs: back to the default day (sent as date_from / date_to)
function dateRangeShowDefault(box) {
    var day = box.dataset.dateDefault;
    box.querySelector('[name="date_from"]').value = day;
    box.querySelector('[name="date_to"]').value = day;
    box._picker.setDate(day, false, "Y-m-d");
    // × only when another day is shown
    box.querySelector("[data-date-range-clear]").hidden = true;
}
// the field shows a tab (its days / its text), sends no dates
function dateRangeShowTab(box, tab) {
    if (box.dataset.dateDefault) return dateRangeShowDefault(box);
    var input = box.querySelector("[data-date-range-input]");
    box.querySelector('[name="date_from"]').value = "";
    box.querySelector('[name="date_to"]').value = "";
    var days = tab ? tab.dataset.dateSelect : "";
    box._picker.setDate(days ? days.split(",") : [], false, "Y-m-d");
    if (!days) input.value = tab ? tab.dataset.dateLabel || "" : "";
    box.querySelector("[data-date-range-clear]").hidden = !input.value;
}
// days picked in the calendar (or cleared by ×)
function dateRangeApply(box, dates) {
    var form = box.closest("form");
    if (!dates.length && box.dataset.dateDefault) {
        box._picker.setDate(box.dataset.dateDefault, false, "Y-m-d");
        dates = box._picker.selectedDates;
    }
    var from = dates.length ? dateRangeYmd(dates[0]) : "";
    var to = dates.length > 1 ? dateRangeYmd(dates[1]) : from;
    var key = from ? from + (to !== from ? "," + to : "") : "";
    var tab = dateRangeTabs(form).find(function (tab) {
        return key ? tab.dataset.dateSelect === key : tab.dataset.tab === "all";
    });
    if (tab) {
        tab.click();
        return;
    }
    // other days: the All tab with the range, no tab highlighted
    var all = dateRangeTabs(form).find(function (tab) { return tab.dataset.tab === "all"; });
    var tabInput = form.querySelector('input[name="tab"]');
    if (tabInput) tabInput.value = all ? all.dataset.value : "all";
    dateRangeTabs(form).forEach(function (tab) { tab.classList.remove("active"); });
    box.querySelector('[name="date_from"]').value = from;
    box.querySelector('[name="date_to"]').value = to;
    box.querySelector("[data-date-range-clear]").hidden = from === box.dataset.dateDefault;
    // datatables.js reloads the list on a change in the form
    box.querySelector('[name="date_from"]').dispatchEvent(new Event("change", { bubbles: true }));
}
document.addEventListener("DOMContentLoaded", function () {
    if (!window.flatpickr) return;
    var arabic = document.documentElement.lang === "ar" && flatpickr.l10ns.ar;
    document.querySelectorAll("[data-date-range]").forEach(function (box) {
        var changed = false;
        box._picker = flatpickr(box.querySelector("[data-date-range-input]"), {
            // data-date-single: one day only (the employees' payments list)
            mode: box.hasAttribute("data-date-single") ? "single" : "range",
            dateFormat: "d-m-Y",
            locale: Object.assign({}, arabic || {}, { rangeSeparator: " → " }),
            onChange: function () { changed = true; },
            onClose: function (dates) {
                if (changed) dateRangeApply(box, dates);
                changed = false;
            },
        });
        var form = box.closest("form");
        // flatpickr's own change of the shown text: not a filter change (datatables.js reloads only on dateRangeApply())
        box.querySelector("[data-date-range-input]").addEventListener("change", function (e) { e.stopPropagation(); });
        dateRangeShowTab(box, form.querySelector('[data-filter-tab][data-name="tab"].active'));
        box.querySelector("[data-date-range-clear]").addEventListener("click", function () {
            box._picker.clear(false);
            dateRangeApply(box, []);
        });
        // the Clear button: back to the default tab / day (datatables.js empties the hidden inputs after the browser reset,
        // then reloads; the server uses the same default day for an empty one)
        form.addEventListener("reset", function () {
            setTimeout(function () {
                dateRangeShowTab(box, form.querySelector('[data-filter-tab][data-name="tab"][data-value=""]'));
                // datatables.js: recount the Clear button
                form.dispatchEvent(new Event("input"));
            });
        });
    });
});
document.addEventListener("click", function (e) {
    var tab = e.target.closest && e.target.closest('[data-filter-tab][data-name="tab"]');
    var box = tab && tab.form && tab.form.querySelector("[data-date-range]");
    if (box && box._picker) dateRangeShowTab(box, tab);
}, true);

// Row toggles (layoutmodule::partials.row-toggle): save as soon as the switch is clicked
document.addEventListener("change", function (e) {
    var form = e.target.closest && e.target.closest("form[data-toggle-form]");
    if (form && e.target.type === "checkbox") {
        form.requestSubmit();
    }
});

// Repeated rows (e.g. employee attachments): [data-repeat] > [data-repeat-list] + <template data-repeat-template> (__INDEX__)
// + [data-repeat-add] button; [data-repeat-remove] inside a row removes it.
document.addEventListener("click", function (e) {
    if (!e.target.closest) return;
    var add = e.target.closest("[data-repeat-add]");
    if (add) {
        var box = add.closest("[data-repeat]");
        var template = box.querySelector("template[data-repeat-template]");
        box.dataset.next = Number(box.dataset.next || box.querySelectorAll("[data-repeat-list] [data-repeat-row]").length) + 1;
        box.querySelector("[data-repeat-list]").insertAdjacentHTML("beforeend", template.innerHTML.replace(/__INDEX__/g, box.dataset.next));
        createIcons({ icons: icons, "stroke-width": 1.5, nameAttr: "data-lucide" });
        return;
    }
    var remove = e.target.closest("[data-repeat-remove]");
    if (remove) {
        remove.closest("[data-repeat-row]").remove();
    }
});

// ------------------------------------------------------------------
// Dynamic forms (run on page load and when the popup content is loaded: initForms(root))
//  - [data-show-if="checkbox name"]: shown only while the checkbox is checked
//    [data-show-if="field=value other"]: shown only while the field (a select) has one of the values ("field=" = empty)
//  - [data-disable-if="checkbox name"]: disabled (and emptied) while the checkbox is checked,
//    data-default-from="field": its value when enabled again and empty
//  - select[data-options-url][data-parent="field"]: its options are loaded by ajax (?field=value) when the parent changes;
//    select[data-place][data-parent="field"] (the reservation form): the same from the form's [data-place-tree], no request
//    (data-also="other_field ...": their values are sent too),
//    with a loading spinner; it is disabled while the parent is empty; the json is [{id, name, ...}], the other keys become data-*;
//    data-auto-single: a list with only one option selects it (also on page / popup load: reservationAutoSingle())
//  - Reservation form ([data-reservation-form]): package -> member (locked), plan -> amount, discount % / discount value / net, unit capacity -> people, subscription type -> repeat options
//  - Package price ([data-package-price]): discount % <-> after discount (from the amount)
//  - Payment form ([data-payment-form]): "for" (reservation / package) -> the member's list is loaded again
// ------------------------------------------------------------------
function formField(form, name) {
    return form.querySelector('[name="' + name + '"]:not([type=hidden])') || form.querySelector('[name="' + name + '"]');
}

function applyConditions(form) {
    form.querySelectorAll("[data-show-if]").forEach(function (el) {
        var rule = el.dataset.showIf.split("=");
        var field = formField(form, rule[0]);
        el.hidden = rule.length > 1
            ? !(field && rule[1].split(" ").indexOf(field.value) >= 0)
            : !(field && field.checked);
    });
    form.querySelectorAll("[data-disable-if]").forEach(function (el) {
        var checkbox = formField(form, el.dataset.disableIf);
        var off = !!(checkbox && checkbox.checked);
        el.disabled = off;
        if (off) {
            el.value = "";
        } else if (!el.value && el.dataset.defaultFrom) {
            var from = formField(form, el.dataset.defaultFrom);
            el.value = from ? from.value : "";
        }
    });
}

function loadOptions(select) {
    var form = select.form;
    var parent = formField(form, select.dataset.parent);
    var wrapper = select.closest("[data-field]") || select.parentNode;
    var placeholder = "- Select -";
    var token = (select._request = (select._request || 0) + 1);
    // a searchable select (Tom Select) is rebuilt after its options change
    var searchable = !!select.tomselect;
    if (searchable) select.tomselect.destroy();
    var done = function () {
        if (searchable) initSearchable(select.parentNode);
        // the next drop menus in the chain follow
        select.dispatchEvent(new Event("change", { bubbles: true }));
    };

    select.innerHTML = "";
    if (!parent || !parent.value) {
        select.add(new Option(placeholder, ""));
        select.disabled = true;
        done();
        return;
    }

    // data-place (the reservation form): the options are in the form already ([data-place-tree]), no request
    if (select.dataset.place) {
        placeFill(select, placeItems(select));
        done();
        return;
    }

    select.add(new Option("Loading...", ""));
    select.disabled = true;
    wrapper.classList.add("is-loading");

    var url = select.dataset.optionsUrl + (select.dataset.optionsUrl.indexOf("?") < 0 ? "?" : "&") + encodeURIComponent(select.dataset.parent) + "=" + encodeURIComponent(parent.value);
    // data-also="field another_field": their values are sent too (e.g. the units of the space AND the subscription type)
    (select.dataset.also || "").split(" ").filter(Boolean).forEach(function (name) {
        var field = formField(form, name);
        url += "&" + encodeURIComponent(name) + "=" + encodeURIComponent(field ? field.value : "");
    });
    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
        .then(function (response) {
            if (!response.ok) throw response;
            return response.json();
        })
        .then(function (items) {
            if (token !== select._request) return;
            placeFill(select, items);
        })
        .catch(function () {
            if (token !== select._request) return;
            select.innerHTML = "";
            select.add(new Option("Could not load, change the selection again", ""));
            toast("Could not load the list, please try again.", "error");
        })
        .finally(function () {
            if (token !== select._request) return;
            wrapper.classList.remove("is-loading");
            done();
        });
}

// a dependent drop menu's new options [{id, name, ...}] (the other keys become data-*); data-auto-single: the only option is
// selected (the change sent after it fills the next list of the chain)
function placeFill(select, items) {
    select.innerHTML = "";
    select.add(new Option(items.length ? "- Select -" : select.dataset.none || "Nothing to select", ""));
    items.forEach(function (item) {
        var option = new Option(item.name, item.id);
        Object.keys(item).forEach(function (key) {
            if (key !== "id" && key !== "name") option.dataset[key] = item[key];
        });
        select.add(option);
    });
    select.disabled = items.length === 0;
    if (items.length === 1 && select.hasAttribute("data-auto-single")) select.value = String(items[0].id);
}

// the reservation form's place chain from [data-place-tree] (ReservationService::placeTree()): spaces of the type, units of
// "type-space", plans of the unit
function placeItems(select) {
    var form = select.form;
    if (!form._placeTree) {
        var script = form.querySelector("script[data-place-tree]");
        try {
            form._placeTree = script ? JSON.parse(script.textContent) : {};
        } catch (e) {
            form._placeTree = {};
        }
    }
    var tree = form._placeTree;
    var value = function (name) {
        var field = formField(form, name);
        return field ? field.value : "";
    };
    if (select.dataset.place === "spaces") return (tree.spaces || {})[value("subscription_type_id")] || [];
    if (select.dataset.place === "units") return (tree.units || {})[value("subscription_type_id") + "-" + value("space_id")] || [];
    return (tree.plans || {})[value("unit_id")] || [];
}

function round2(number) {
    return Math.round((Number(number) || 0) * 100) / 100;
}

// $source: the field the user changed (amount | percentage | value | net); discount % / discount value / net calculate each other,
// discount_type keeps the one typed last (when the amount changes it stays and the others follow)
function reservationCalculate(form, source) {
    var amountEl = formField(form, "amount");
    var percentageEl = formField(form, "discount_percentage");
    var valueEl = formField(form, "discount_value");
    var typeEl = form.querySelector('[name="discount_type"]');
    var netEl = form.querySelector("[data-net]");
    if (!amountEl || !percentageEl || !valueEl || !typeEl || !netEl) return;

    if (source === "percentage" || source === "value" || source === "net") typeEl.value = source;
    var amount = Math.max(0, Number(amountEl.value) || 0);
    var value;
    if (typeEl.value === "net") {
        value = amount - Math.min(Math.max(0, Number(netEl.value) || 0), amount);
    } else if (typeEl.value === "value") {
        value = Math.min(Math.max(0, Number(valueEl.value) || 0), amount);
    } else {
        value = amount * Math.min(Math.max(0, Number(percentageEl.value) || 0), 100) / 100;
    }
    // the typed field is left as it is
    if (source !== "percentage") percentageEl.value = round2(amount > 0 ? value / amount * 100 : 0);
    if (source !== "value") valueEl.value = round2(value);
    if (source !== "net") netEl.value = round2(amount - value);
}

// the package member is used and can not be changed
function reservationLockMember(form) {
    var packageEl = formField(form, "package_id");
    var memberEl = formField(form, "member_id");
    // the Renew form: both are fixed hidden inputs
    if (!packageEl || !memberEl || !packageEl.selectedOptions) return;
    var option = packageEl.selectedOptions[0];
    var memberId = option && option.dataset.memberId;
    var searchable = memberEl.tomselect;
    // "Book For" = Package: the package's member as text under the list (2026-10-22)
    var memberText = form.querySelector("[data-package-member]");
    if (memberText) {
        memberText.hidden = !memberId;
        memberText.querySelector("[data-package-member-name]").textContent = memberId ? (option.dataset.memberText || "#" + memberId) : "";
    }
    if (memberId) {
        if (searchable) {
            // the Member field searches the server: the package's member is added as its option first (data-member-text)
            if (!searchable.options[memberId]) searchable.addOption({ value: String(memberId), text: option.dataset.memberText || ("#" + memberId) });
            searchable.setValue(String(memberId), true);
        } else {
            if (!memberEl.querySelector('option[value="' + memberId + '"]')) memberEl.add(new Option(option.dataset.memberText || "#" + memberId, memberId));
            memberEl.value = memberId;
        }
    }
    if (searchable) {
        memberId ? searchable.disable() : searchable.enable();
    } else {
        memberEl.disabled = !!memberId;
    }
    // with a package: no "+ New member" (the member is the package's); an open quick add box is closed
    var field = memberEl.closest("[data-field]");
    var linkRow = field && field.nextElementSibling;
    if (linkRow && linkRow.matches(".member-quick-link-row")) {
        linkRow.hidden = !!memberId;
        var box = memberQuickBox(memberEl);
        if (memberId && box && !box.hidden) memberQuickClose(box);
    }
}

// the end date can not be before the start date: it follows the start when needed
function reservationEndAfterStart(form) {
    var start = formField(form, "start_at");
    var end = formField(form, "end_at");
    if (start && end && !end.disabled && start.value && end.value && end.value < start.value) {
        end.value = start.value;
    }
}

// the end follows the plan's lease period from the start (one period): hour = start + 1 hour; day = the same day; week = +6 days;
// month / year = the same day of the next month / year − 1 day (31-01 → 27-02 / 28-02: the short month's last day − 1).
// Runs when the plan or the start changes; an end typed by hand is kept (data-end-touched) until the plan changes again.
function reservationEndFromPlan(form, planChanged) {
    var planEl = formField(form, "plan_id");
    var startEl = formField(form, "start_at");
    var endEl = formField(form, "end_at");
    var option = planEl && planEl.selectedOptions[0];
    var period = option && option.dataset.leasePeriod;
    if (planChanged) delete form.dataset.endTouched;
    if (!period || !startEl || !startEl.value || !endEl || form.dataset.endTouched) return;

    var parts = startEl.value.split("-").map(Number);
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    var ymd = function (d) { return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()); };
    var end = new Date(parts[0], parts[1] - 1, parts[2]);
    var months = { month: 1, year: 12 }[period];
    if (period === "hour") {
        var startTime = formField(form, "start_time");
        var endTime = formField(form, "end_time");
        if (!startTime || !startTime.value || !endTime) return;
        var hm = startTime.value.split(":").map(Number);
        end.setHours(hm[0] + 1, hm[1]);
        endTime.value = pad(end.getHours()) + ":" + pad(end.getMinutes());
    } else if (months) {
        // a month plan: the anchor day (the account's "Billing day" field, or the Renew / edit form's data-anchor; new = the start
        // day), clamped to the month's last day (Reservation::anchoredEnd(), 2026-10-23); a year plan: the same date next year
        var dayEl = formField(form, "subscription_day");
        var anchor = months === 1 ? (parseInt(dayEl && dayEl.value, 10) || parseInt(endEl.dataset.anchor, 10) || end.getDate()) : end.getDate();
        var target = new Date(end.getFullYear(), end.getMonth() + months, 1);
        target.setDate(Math.min(anchor, new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()));
        end = target;
        end.setDate(end.getDate() - 1);
    } else if (period === "week") {
        end.setDate(end.getDate() + 6);
    }
    endEl.value = ymd(end);
}

// the repeat section is shown only when the subscription type allows it
// ([data-type-allows="can-repeat"] + the option data-can-repeat); hidden = unchecked
function reservationTypeOptions(form) {
    var typeEl = formField(form, "subscription_type_id");
    if (!typeEl) return;
    // a fixed type (the Schedule page: a hidden input) carries the flags itself
    var option = typeEl.selectedOptions ? typeEl.selectedOptions[0] : typeEl;
    form.querySelectorAll("[data-type-allows]").forEach(function (box) {
        var key = box.dataset.typeAllows.replace(/-([a-z])/g, function (m, c) { return c.toUpperCase(); });
        var allowed = !!(option && option.dataset[key] === "1");
        box.hidden = !allowed;
        if (!allowed) {
            box.querySelectorAll("input[type=checkbox]").forEach(function (checkbox) { checkbox.checked = false; });
        }
    });
    applyConditions(form);
}

// the amount of the selected plan; a time based plan: the plan amount x the periods from the start to the end
// (data-period-hours: the hours of one lease period, e.g. 2.5 hours x 50 = 125; the same calculation as Plan::amountBetween)
function reservationPlanAmount(form) {
    var planEl = formField(form, "plan_id");
    var amountEl = formField(form, "amount");
    var option = planEl && planEl.selectedOptions[0];
    if (!option || option.dataset.amount === undefined || !amountEl) return;

    var amount = Number(option.dataset.amount) || 0;
    if (option.dataset.timeBased === "1") {
        var at = function (date, time) {
            var d = formField(form, date), t = formField(form, time);
            return d && d.value && t && t.value ? new Date(d.value + "T" + t.value) : null;
        };
        var start = at("start_at", "start_time");
        var end = at("end_at", "end_time");
        if (start && end) {
            var hours = Math.max(0, (end - start) / 3600000);
            amount = amount * round2(hours / (Number(option.dataset.periodHours) || 1));
        }
    }
    amountEl.value = round2(amount);
    reservationCalculate(form, "amount");
}

// a time based plan (option data-time-based="1"): the start / end time inputs are shown ([data-time-fields])
function reservationTimeBased(form) {
    var planEl = formField(form, "plan_id");
    if (!planEl) return;
    var option = planEl.selectedOptions[0];
    var timeBased = !!(option && option.dataset.timeBased === "1");
    form.querySelectorAll("[data-time-fields]").forEach(function (box) {
        box.hidden = !timeBased;
    });
    // a new reservation at night (before the business day's cutoff): a time based one is for now (data-real-date), a full day
    // one for the business date (the day before) — only the dates the user did not change
    form.querySelectorAll("input[data-business-date][data-real-date]").forEach(function (input) {
        if (input.value === input.dataset.businessDate || input.value === input.dataset.realDate) {
            input.value = timeBased ? input.dataset.realDate : input.dataset.businessDate;
        }
    });
}

// the live availability line under the dates ([data-availability], {area}.reservations.availability): asked 400 ms after the
// unit / plan / dates / times change, once the unit, plan and dates (+ the times of a time based plan) are all set; an older
// answer is ignored. Exclusive unit: "Unit busy: <member> <period>" (3 at most + "and N more"); shared (concurrent usage > 1):
// "<used> of <limit> in use at that time"; free: "Available". Saving checks it again on the server.
function reservationAvailability(form) {
    var line = form.querySelector("[data-availability]");
    if (!line) return;
    clearTimeout(line._timer);
    line._timer = setTimeout(function () {
        var value = function (name) {
            var field = formField(form, name);
            return field ? field.value : "";
        };
        var plan = formField(form, "plan_id");
        var option = plan && plan.selectedOptions ? plan.selectedOptions[0] : null;
        var timeBased = !!(option && option.dataset.timeBased === "1");
        var token = (line._token = (line._token || 0) + 1);
        if (!value("unit_id") || !value("plan_id") || !value("start_at") || !value("end_at") || (timeBased && (!value("start_time") || !value("end_time")))) {
            line.hidden = true;
            return;
        }
        var params = new URLSearchParams({ unit_id: value("unit_id"), plan_id: value("plan_id"), start_at: value("start_at"), end_at: value("end_at"),
            start_time: timeBased ? value("start_time") : "", end_time: timeBased ? value("end_time") : "", ignore: line.dataset.ignore || "" });
        fetch(line.dataset.url + "?" + params.toString(), { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
            .then(function (response) {
                if (!response.ok) throw response;
                return response.json();
            })
            .then(function (json) {
                if (token !== line._token) return;
                line.className = "availability-line";
                line.textContent = "";
                if (!json.state) {
                    line.hidden = true;
                    return;
                }
                var list = (json.items || []).map(function (item) { return item.member + " " + item.period; }).join(", ")
                    + (json.more ? " and " + json.more + " more" : "");
                if (json.state === "free") {
                    line.classList.add("availability-line--free");
                    line.textContent = "Available";
                } else if (json.limit > 1) {
                    line.classList.add(json.full ? "availability-line--busy" : "availability-line--shared");
                    line.textContent = json.used + " of " + json.limit + " in use at that time" + (json.full ? " — full: " + list : "");
                } else {
                    line.classList.add("availability-line--busy");
                    line.textContent = "Unit busy: " + list;
                }
                line.hidden = false;
            })
            .catch(function () {
                if (token === line._token) line.hidden = true;
            });
    }, 400);
}

// the first empty list of the chain (space, unit, plan) with only one option selects it on load (a new reservation: e.g. the
// Schedule page fixed the type and it has one space); its change loads the next list, which selects its only option too
function reservationAutoSingle(form) {
    var names = ["space_id", "unit_id", "plan_id"];
    for (var i = 0; i < names.length; i++) {
        var select = form.querySelector('select[name="' + names[i] + '"][data-auto-single]');
        if (!select || select.disabled) continue;
        if (select.value) continue;
        var options = Array.prototype.filter.call(select.options, function (option) { return option.value !== ""; });
        if (options.length !== 1) return;
        if (select.tomselect) {
            select.tomselect.setValue(options[0].value); // sends the change (bubbles)
        } else {
            select.value = options[0].value;
            select.dispatchEvent(new Event("change", { bubbles: true }));
        }
        return;
    }
}

// the number of people follows the unit capacity: max = capacity; a unit for 1 person (capacity 1, or 0 = not set) = 1 person,
// the field is hidden
function reservationCapacity(form) {
    var unitEl = formField(form, "unit_id");
    var peopleEl = formField(form, "number_of_peoples");
    if (!unitEl || !peopleEl) return;
    var option = unitEl.selectedOptions[0];
    var capacity = option && option.dataset.capacity ? Number(option.dataset.capacity) : 0;
    // the Renew form: a fixed hidden value
    if (peopleEl.type === "hidden") return;

    var single = !!(option && option.value) && capacity <= 1;
    peopleEl.max = capacity > 1 ? capacity : 100000;
    if (single) peopleEl.value = 1;
    peopleEl.closest("[data-field]").hidden = single;
    // the hint under the field (the div after the input, not an error)
    var hint = peopleEl.closest("[data-field]").querySelector("input ~ div:not([data-ajax-error])");
    if (hint) hint.textContent = capacity > 1 ? "Up to " + capacity + " persons." : "Not more than the unit capacity.";
}

// $source: the field the user changed (amount | discount | result); the discount % and the after discount calculate each other
function packageCalculate(box, source) {
    var discountEl = box.querySelector('[data-calc="discount"]');
    var resultEl = box.querySelector('[data-calc="result"]');
    var typeEl = box.querySelector('[data-calc="type"]');
    if (source === "discount") typeEl.value = "percentage";
    if (source === "result") typeEl.value = "after";

    var amount = Math.max(0, Number(box.querySelector('[data-calc="amount"]').value) || 0);
    if (typeEl.value === "after") {
        var after = Math.min(Math.max(0, Number(resultEl.value) || 0), amount);
        discountEl.value = round2(amount > 0 ? (amount - after) / amount * 100 : 0);
        if (source !== "result") resultEl.value = round2(after);
    } else {
        var discount = Math.min(Math.max(0, Number(discountEl.value) || 0), 100);
        resultEl.value = round2(amount - amount * discount / 100);
    }
}

// select[data-searchable]: type to search the options; select[data-member-select]: the options come from the server (memberSelect)
function initSearchable(root) {
    if (!window.TomSelect) return;
    root.querySelectorAll("select[data-searchable]").forEach(function (select) {
        if (select.tomselect) return;
        if (select.hasAttribute("data-member-select")) {
            memberSelect(select);
            return;
        }
        var searchable = new TomSelect(select, {
            plugins: { dropdown_input: {} },
            maxOptions: null,
            allowEmptyOption: true,
            placeholder: select.options[0] ? select.options[0].text : "",
            render: {
                no_results: function () {
                    return '<div class="no-results">Nothing found</div>';
                },
            },
        });
        // the search box in the open menu
        if (searchable.control_input) searchable.control_input.placeholder = "Search...";
    });
}

// ------------------------------------------------------------------
// Member select (membermodule::partials.member-select, 2026-10-18): the reservation / package / cash in / withholding forms.
//  - Tom Select over {area}.members.search?q= (2+ characters, 250 ms after the last key, 20 at most); the options are the last
//    answer only (no client filtering: a phone typed as +20… still shows "name 01…"); an older answer is ignored.
//  - "Searching…" while a request runs; '+ Add "<typed>" as a new member' only after an EMPTY answer for that text; a failed
//    request = "Search failed — retry" (click = search again), never "No member found".
//  - Quick add: the inline box under the field ([data-member-quick], opened by the + Add option or "+ New member"): Name, Mobile,
//    Company; the mobile is looked up first ({area}.members.lookup, phone_key) → "This mobile belongs to …" + Use this member;
//    Save = POST {area}.members.store?quick=1 (MemberRequest) → the new member is added and selected (its change event follows).
// ------------------------------------------------------------------
function memberSelect(select) {
    var state = { token: 0, empty: null, failed: false };
    // no empty option in the menu (it would hide "Search failed — retry"); an optional field (cash in, withholding) gets ×
    var plugins = { dropdown_input: {} };
    if (select.hasAttribute("data-member-optional")) plugins.clear_button = { title: "Clear" };
    var ts = new TomSelect(select, {
        plugins: plugins,
        maxOptions: null,
        allowEmptyOption: false,
        placeholder: select.options[0] ? select.options[0].text : "",
        loadThrottle: 250,
        // the server already matched name / phone: show every option of its answer
        score: function () { return function () { return 1; }; },
        shouldLoad: function (query) { return query.trim().length >= 2; },
        load: function (query, callback) {
            var self = this;
            var token = ++state.token;
            state.empty = null;
            state.failed = false;
            fetch(select.dataset.searchUrl + "?q=" + encodeURIComponent(query), { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
                .then(function (response) {
                    if (!response.ok) throw response;
                    return response.json();
                })
                .then(function (items) {
                    if (token !== state.token) return callback();
                    self.clearOptions();
                    if (!items.length) state.empty = query;
                    callback(items.map(function (item) { return { value: String(item.id), text: item.text }; }));
                })
                .catch(function () {
                    if (token !== state.token) return callback();
                    self.clearOptions();
                    state.failed = true;
                    callback();
                })
                .finally(function () {
                    // the same text can be searched again (Tom Select remembers the searched texts)
                    self.loadedSearches = {};
                });
        },
        // "+ Add …" only once the answer for exactly this text came back empty
        create: function (input, callback) {
            callback();
            this.close();
            memberQuickOpen(select, input);
        },
        createFilter: function (input) { return !state.failed && state.empty !== null && state.empty === input; },
        render: {
            loading: function () { return '<div class="no-results">Searching…</div>'; },
            option_create: function (data, escape) {
                return '<div class="create">+ Add <strong>"' + escape(data.input) + '"</strong> as a new member</div>';
            },
            no_results: function (data) {
                if (state.failed) return '<div class="no-results" data-member-retry>Search failed — <u>retry</u></div>';
                return data.input.trim().length < 2 ? '<div class="no-results">Type 2 letters or digits</div>' : '<div class="no-results">Searching…</div>';
            },
        },
    });
    if (ts.control_input) ts.control_input.placeholder = "Search by name or mobile...";
    // "Search failed — retry": search the same text again
    ts.dropdown.addEventListener("mousedown", function (e) {
        if (!e.target.closest("[data-member-retry]")) return;
        e.preventDefault();
        state.failed = false;
        ts.loadedSearches = {};
        ts.load(ts.control_input.value);
    });
}

function memberQuickBox(select) {
    var field = select.closest("[data-field]");
    var box = field;
    while (box && !box.matches("[data-member-quick]")) box = box.nextElementSibling;
    return box;
}

function memberQuickError(box, name, message) {
    var el = box.querySelector('[data-quick-error="' + name + '"]');
    if (!el) return;
    el.textContent = message || "";
    el.hidden = !message;
}

function memberQuickReset(box) {
    ["name", "phone", "company_id"].forEach(function (name) { memberQuickError(box, name, ""); });
    box.querySelector("[data-quick-duplicate]").hidden = true;
    box._duplicate = null;
}

// open the box under the Member field; typed digits (+, spaces allowed) → Mobile, other text → Name
function memberQuickOpen(select, text) {
    var box = memberQuickBox(select);
    if (!box) return;
    box._select = select;
    memberQuickReset(box);
    // a new member: the Member field is emptied (its change event: the form's own chains follow, e.g. the cash in items)
    if (select.tomselect) {
        select.tomselect.clear();
    } else if (select.value) {
        select.value = "";
        select.dispatchEvent(new Event("change", { bubbles: true }));
    }
    var nameEl = box.querySelector('[data-quick-field="name"]');
    var phoneEl = box.querySelector('[data-quick-field="phone"]');
    text = (text || "").trim();
    var isPhone = text !== "" && /^\+?[\d\s()-]+$/.test(text);
    nameEl.value = isPhone ? "" : text;
    phoneEl.value = isPhone ? text : "";
    box.querySelector('[data-quick-field="company_id"]').value = "";
    box.hidden = false;
    (isPhone ? nameEl : (text ? phoneEl : nameEl)).focus();
    if (isPhone) memberQuickLookup(box);
}

function memberQuickClose(box) {
    box.hidden = true;
    memberQuickReset(box);
}

// the mobile already a member's? (same account, phone_key) → the "belongs to" line; resolves to that member or null
function memberQuickLookup(box) {
    var phone = box.querySelector('[data-quick-field="phone"]').value.trim();
    var token = (box._lookup = (box._lookup || 0) + 1);
    if (phone.replace(/\D/g, "").length < 8) {
        box.querySelector("[data-quick-duplicate]").hidden = true;
        box._duplicate = null;
        return Promise.resolve(null);
    }
    return fetch(box.dataset.lookupUrl + "?phone=" + encodeURIComponent(phone), { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
        .then(function (response) {
            if (!response.ok) throw response;
            return response.json();
        })
        .then(function (json) {
            if (token !== box._lookup) return box._duplicate;
            box._duplicate = json.member;
            box.querySelector("[data-quick-duplicate-name]").textContent = json.member ? json.member.text : "";
            box.querySelector("[data-quick-duplicate]").hidden = !json.member;
            return json.member;
        })
        .catch(function () { return null; });
}

// choose a member in the Member field (its change event: the form's own chains follow) and close the box
function memberQuickChoose(box, member) {
    var ts = box._select && box._select.tomselect;
    if (ts) {
        ts.addOption({ value: String(member.id), text: member.text });
        ts.setValue(String(member.id));
    }
    memberQuickClose(box);
}

function memberQuickSave(box, button) {
    memberQuickReset(box);
    var value = function (name) { return box.querySelector('[data-quick-field="' + name + '"]').value.trim(); };
    button.disabled = true;
    memberQuickLookup(box).then(function (existing) {
        if (existing) {
            button.disabled = false;
            return;
        }
        var data = new FormData();
        data.append("_token", document.querySelector('meta[name="csrf-token"]').content);
        data.append("name", value("name"));
        data.append("phone", value("phone"));
        data.append("company_id", value("company_id"));
        return fetch(box.dataset.storeUrl, { method: "POST", body: data, headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
            .then(function (response) {
                return response.json().then(function (json) { return { ok: response.ok, status: response.status, json: json }; });
            })
            .then(function (result) {
                button.disabled = false;
                if (result.ok) {
                    toast(result.json.message || "The member has been added.", "success");
                    memberQuickChoose(box, result.json.member);
                } else if (result.status === 422) {
                    var errors = result.json.errors || {};
                    Object.keys(errors).forEach(function (name) { memberQuickError(box, name, errors[name][0]); });
                } else {
                    toast("Could not add the member, please try again.", "error");
                }
            });
    }).catch(function () {
        button.disabled = false;
        toast("Could not add the member, please try again.", "error");
    });
}

document.addEventListener("click", function (e) {
    var open = e.target.closest && e.target.closest("[data-member-quick-open]");
    if (open) {
        var row = open.closest(".member-quick-link-row");
        var field = row && row.previousElementSibling;
        var select = field && field.querySelector("select[data-member-select]");
        if (select) memberQuickOpen(select, "");
        return;
    }
    var box = e.target.closest && e.target.closest("[data-member-quick]");
    if (!box) return;
    if (e.target.closest("[data-quick-cancel]")) memberQuickClose(box);
    if (e.target.closest("[data-quick-use]") && box._duplicate) memberQuickChoose(box, box._duplicate);
    if (e.target.closest("[data-quick-save]")) memberQuickSave(box, e.target.closest("[data-quick-save]"));
});

// the mobile typed: look it up (500 ms after the last key)
document.addEventListener("input", function (e) {
    var input = e.target.closest && e.target.closest('[data-member-quick] [data-quick-field="phone"]');
    if (!input) return;
    var box = input.closest("[data-member-quick]");
    clearTimeout(box._lookupTimer);
    box._lookupTimer = setTimeout(function () { memberQuickLookup(box); }, 500);
});

// Enter in the box saves the member, not the whole form
document.addEventListener("keydown", function (e) {
    if (e.key !== "Enter" || !e.target.closest || !e.target.closest("[data-member-quick] [data-quick-field]")) return;
    e.preventDefault();
    var box = e.target.closest("[data-member-quick]");
    memberQuickSave(box, box.querySelector("[data-quick-save]"));
});

// A hidden box opened in place (the reservation view's Add Cash In: "Pay" / "+ Cash In" → [data-reveal="#cash-in-form"]):
// shown, scrolled to, its first field focused; [data-reveal-close] inside hides it again
document.addEventListener("click", function (e) {
    var opener = e.target.closest && e.target.closest("[data-reveal]");
    var closer = !opener && e.target.closest && e.target.closest("[data-reveal-close]");
    if (closer) {
        var open = closer.closest("form, [data-reveal-box]");
        if (open) open.hidden = true;
        return;
    }
    if (!opener) return;
    var box = (opener.closest("#app-modal") || document).querySelector(opener.dataset.reveal);
    if (!box) return;
    e.preventDefault();
    box.hidden = false;
    box.scrollIntoView({ behavior: "smooth", block: "center" });
    var field = box.querySelector("input:not([type=hidden]), select, textarea");
    if (field) field.focus({ preventScroll: true });
});

// the reservations list "Renewal" filter: its dates are renewal dates (this month when none) — choosing it moves to the All
// tab (no Today / Upcoming rule; the date field emptied); capture phase: before datatables.js reloads the list once
document.addEventListener("change", function (e) {
    var form = e.target.form;
    if (!form || e.target.name !== "renewal" || !e.target.value) return;
    var all = dateRangeTabs(form).find(function (tab) { return tab.dataset.tab === "all"; });
    var tabInput = form.querySelector('input[name="tab"]');
    if (!all || !tabInput || tabInput.value === all.dataset.value || form.querySelector('[name="date_from"]')?.value) return;
    tabInput.value = all.dataset.value;
    dateRangeTabs(form).forEach(function (tab) { tab.classList.toggle("active", tab === all); });
    var box = form.querySelector("[data-date-range]");
    if (box && box._picker) dateRangeShowTab(box, all);
}, true);

// ------------------------------------------------------------------
// Chained list filters (the reservations list: type -> space -> unit, 2026-10-22)
//  - select[data-filter-parents="a b"]: only its options whose data-match ("a:1 b:5" tokens) has every chosen parent value;
//    a value no longer offered goes back to "" (All)
//  - [data-filter-requires="a"]: hidden until a has a value
// Runs in the capture phase, before datatables.js reads the filters (one reload per change), after a Clear and on page load.
// ------------------------------------------------------------------
function filterChain(form) {
    form.querySelectorAll("select[data-filter-parents]").forEach(function (select) {
        if (!select._allOptions) select._allOptions = Array.prototype.slice.call(select.options);
        var parents = select.dataset.filterParents.split(" ").map(function (name) {
            var parent = form.querySelector('[name="' + name + '"]');
            return parent && parent.value ? name + ":" + parent.value : null;
        }).filter(Boolean);
        var value = select.value;
        select.replaceChildren.apply(select, select._allOptions.filter(function (option) {
            if (!option.value) return true;
            var match = (option.dataset.match || "").split(" ");
            return parents.every(function (token) { return match.indexOf(token) >= 0; });
        }));
        select.value = Array.prototype.some.call(select.options, function (option) { return option.value === value; }) ? value : "";
        var requires = select.dataset.filterRequires && form.querySelector('[name="' + select.dataset.filterRequires + '"]');
        if (requires) {
            select.hidden = !requires.value;
            if (!requires.value) select.value = "";
        }
    });
}

document.addEventListener("change", function (e) {
    var form = e.target.form;
    if (form && e.target.name && form.querySelector('select[data-filter-parents~="' + e.target.name + '"]')) filterChain(form);
}, true);

document.addEventListener("reset", function (e) {
    var form = e.target;
    if (form.querySelector && form.querySelector("select[data-filter-parents]")) setTimeout(function () { filterChain(form); });
}, true);

// after datatables.js set the [data-initial] values (e.g. ?subscription_type_id= from the Schedule page)
window.addEventListener("load", function () {
    document.querySelectorAll("form").forEach(function (form) {
        if (form.querySelector("select[data-filter-parents]")) filterChain(form);
    });
});

function initForms(root) {
    initSearchable(root);
    (root.tagName === "FORM" ? [root] : root.querySelectorAll("form")).forEach(function (form) {
        applyConditions(form);
        if (form.matches("[data-reservation-form]")) {
            reservationLockMember(form);
            reservationCapacity(form);
            reservationTypeOptions(form);
            reservationTimeBased(form);
            reservationAutoSingle(form);
            reservationAvailability(form);
        }
    });
}

document.addEventListener("DOMContentLoaded", function () {
    initForms(document);
});

document.addEventListener("change", function (e) {
    var form = e.target.form;
    if (!form || !e.target.name) return;
    var name = e.target.name;

    if (form.querySelector('[data-show-if="' + name + '"], [data-show-if^="' + name + '="], [data-disable-if="' + name + '"]')) {
        applyConditions(form);
    }
    form.querySelectorAll('select[data-options-url][data-parent="' + name + '"], select[data-place][data-parent="' + name + '"]').forEach(loadOptions);

    // the member's reservations / packages follow "for" too (the member is the data-parent)
    if (form.matches("[data-payment-form]") && name === "payable") {
        form.querySelectorAll('select[data-options-url][data-also~="payable"]').forEach(loadOptions);
    }

    if (form.matches("[data-reservation-form]")) {
        if (name === "package_id") reservationLockMember(form);
        // "Book For" = Member: no package (the Member field is free again)
        if (name === "book_for" && e.target.value === "member") {
            var packageEl = formField(form, "package_id");
            if (packageEl && packageEl.value) {
                packageEl.tomselect ? packageEl.tomselect.clear(true) : (packageEl.value = "");
                reservationLockMember(form);
            }
        }
        if (name === "unit_id") reservationCapacity(form);
        if (name === "subscription_type_id") reservationTypeOptions(form);
        if (name === "start_at") reservationEndAfterStart(form);
        if (name === "plan_id") reservationTimeBased(form);
        // the end follows the plan's lease period (an end typed by hand is kept until the plan changes)
        if (name === "end_at" || name === "end_time") form.dataset.endTouched = "1";
        if (["plan_id", "start_at", "start_time"].indexOf(name) >= 0) reservationEndFromPlan(form, name === "plan_id");
        // the amount follows the plan (and the start / end of a time based plan)
        if (["plan_id", "start_at", "start_time", "end_at", "end_time"].indexOf(name) >= 0) reservationPlanAmount(form);
        // the live availability line (after the end was set from the plan above)
        if (["unit_id", "plan_id", "start_at", "start_time", "end_at", "end_time"].indexOf(name) >= 0) reservationAvailability(form);
    }
});

// Add Package (2026-10-22): the chosen member fills the name with the server's suggestion "<member> - <m-Y>[ - N]",
// unless the name was typed by hand ([data-name-suggest] gets data-touched); newest answer only
function packageNameSuggest(form) {
    var input = form.querySelector("[data-name-suggest]");
    var member = formField(form, "member_id");
    if (!input || input.dataset.touched || !member || !member.value) return;
    var token = (input._suggest = (input._suggest || 0) + 1);
    fetch(input.dataset.nameSuggest + "?member_id=" + encodeURIComponent(member.value), { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
            if (json && json.name && token === input._suggest && !input.dataset.touched) input.value = json.name;
        })
        .catch(function () {});
}

document.addEventListener("change", function (e) {
    if (e.target.name === "member_id" && e.target.form && e.target.form.querySelector("[data-name-suggest]")) packageNameSuggest(e.target.form);
});

document.addEventListener("input", function (e) {
    var form = e.target.form;
    if (!form) return;
    // a package name typed by hand: the member no longer changes it
    if (e.target.matches("[data-name-suggest]")) e.target.dataset.touched = e.target.value ? "1" : "";
    if (form.matches("[data-reservation-form]")) {
        var sources = { amount: "amount", discount_percentage: "percentage", discount_value: "value", net_amount: "net" };
        if (sources[e.target.name]) reservationCalculate(form, sources[e.target.name]);
        if (e.target.name === "start_at") reservationEndAfterStart(form);
        if (["start_at", "start_time", "end_at", "end_time"].indexOf(e.target.name) >= 0) reservationAvailability(form);
    }
    var box = e.target.closest("[data-package-price]");
    if (box && e.target.dataset.calc) packageCalculate(box, e.target.dataset.calc);
});

// Reservation status menu (reservationmodule::Reservation.partials.status-menu): the chosen status is saved by ajax,
// the badge is locked with a spinner until the answer, then it gets the new name + color (and the list row is updated)
document.addEventListener("click", function (e) {
    var option = e.target.closest && e.target.closest("[data-status-option]");
    if (!option) return;
    var toggle = document.getElementById(option.dataset.toggle);
    if (!toggle || toggle.classList.contains("is-sending")) return;

    var data = new FormData();
    data.append("_method", "PATCH");
    data.append("_token", document.querySelector('meta[name="csrf-token"]').content);
    data.append("reservation_status_id", option.dataset.statusId);
    // the list filters: the row leaves the list when it does not match them any more
    if (window.dataTables) {
        var filters = window.dataTables.filters(option.dataset.row);
        Object.keys(filters).forEach(function (name) { data.append("list[" + name + "]", filters[name]); });
    }

    toggle.classList.add("is-sending");
    toggle.disabled = true;
    fetch(option.dataset.url, { method: "POST", body: data, headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
        .then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) { return { ok: response.ok, json: json }; });
        })
        .then(function (result) {
            var json = result.json;
            if (!result.ok || !json.status) {
                // "html_" errors (built and escaped by the server, with links: e.g. the slot is taken) stay open until closed
                if (json.errors && Object.keys(json.errors)[0].indexOf("html_") === 0 && window.Swal) {
                    Swal.fire({ icon: "error", title: "Can't change the status", html: [].concat(Object.values(json.errors)[0])[0] });
                    return;
                }
                var errors = json.errors ? Object.values(json.errors)[0] : null;
                toast((errors && errors[0]) || json.message || "Could not change the status, please try again.", "error");
                return;
            }
            // the new badge
            toggle.className = json.status.class + " status-menu__toggle";
            toggle.querySelector("[data-status-name]").textContent = json.status.name;
            // the check mark on the chosen status
            document.querySelectorAll('[data-status-option][data-toggle="' + option.dataset.toggle + '"]').forEach(function (item) {
                var check = item.querySelector("[data-lucide='check'], svg.lucide-check");
                if (check) check.remove();
                if (item.dataset.statusId === String(json.status.id)) {
                    item.insertAdjacentHTML("beforeend", '<i data-lucide="check" class="ml-auto h-4 w-4 text-slate-500"></i>');
                }
            });
            createIcons({ icons: icons, "stroke-width": 1.5, nameAttr: "data-lucide" });
            if (window.dataTables && "row" in json) window.dataTables.update(option.dataset.row, json.row);
            if (window.todayPage && window.todayPage.active()) window.todayPage.refresh();
            toast(json.message);
        })
        .catch(function () {
            toast("Could not connect to the server, please try again.", "error");
        })
        .finally(function () {
            toggle.classList.remove("is-sending");
            toggle.disabled = false;
        });
});

// Colors drop menu (unitmodule::partials.color-select): pick the clicked color and show it + its name on the button.
function pickColorOption(option) {
    // the open menu is moved to <body> by the template, so find the button by id, not by parents
    var radio = option.querySelector(".color-select__radio");
    radio.checked = true;
    var current = document.getElementById(option.dataset.current);
    current.innerHTML = option.querySelector(".color-swatch").outerHTML + " " + option.querySelector("span").textContent;
}

document.addEventListener("click", function (e) {
    var option = e.target.closest && e.target.closest(".color-select__option");
    if (option) {
        pickColorOption(option);
    }
});

document.addEventListener("change", function (e) {
    if (e.target.matches(".color-select__radio")) {
        pickColorOption(e.target.closest(".color-select__option"));
    }
});

// Images gallery (layoutmodule::partials.gallery): open the viewer on a thumbnail and navigate between the images.
function galleryShow(modal, index) {
    var items = modal.querySelectorAll(".gallery-modal__strip-item");
    index = (index + items.length) % items.length;
    modal.dataset.index = index;
    modal.querySelector(".gallery-modal__image").src = items[index].dataset.src;
    modal.querySelector(".gallery-modal__counter").textContent = (index + 1) + " / " + items.length;
    items.forEach(function (item, i) {
        item.classList.toggle("active", i === index);
    });
    items[index].scrollIntoView({ block: "nearest", inline: "center" });
    modal.querySelectorAll(".gallery-modal__nav").forEach(function (nav) {
        nav.hidden = items.length < 2;
    });
}

document.addEventListener("click", function (e) {
    if (!e.target.closest) {
        return;
    }
    var thumb = e.target.closest("[data-gallery-open]");
    if (thumb) {
        var modal = document.getElementById(thumb.dataset.galleryOpen);
        galleryShow(modal, Number(thumb.dataset.index));
        tailwind.Modal.getOrCreateInstance(modal).show();
        return;
    }
    var step = e.target.closest("[data-gallery-step]");
    if (step) {
        var stepModal = step.closest(".gallery-modal");
        galleryShow(stepModal, Number(stepModal.dataset.index) + Number(step.dataset.galleryStep));
        return;
    }
    var go = e.target.closest("[data-gallery-go]");
    if (go) {
        galleryShow(go.closest(".gallery-modal"), Number(go.dataset.galleryGo));
    }
});

document.addEventListener("keydown", function (e) {
    var modal = document.querySelector(".gallery-modal.show");
    if (!modal || (e.key !== "ArrowLeft" && e.key !== "ArrowRight")) {
        return;
    }
    galleryShow(modal, Number(modal.dataset.index) + (e.key === "ArrowRight" ? 1 : -1));
});


// Schedule page ([data-today], ReservationModule Schedule/*): todayPage.refresh() loads its sections again (data-url) after every save
// in a popup (ajaxSubmit, status menu); Timeline | List per type ([data-today-toggle], remembered in localStorage, optional);
// the now line of today's timelines (.tl[data-tl-start] = the first hour, unix seconds; 15 minute slots, custom.css .tl-from-N)
window.todayPage = (function () {
    function root() {
        return document.querySelector("[data-today]");
    }
    function remembered(type) {
        try {
            return localStorage.getItem("todayView." + type);
        } catch (e) {
            return null;
        }
    }
    function remember(type, view) {
        try {
            localStorage.setItem("todayView." + type, view);
        } catch (e) { /* private mode: not remembered */ }
    }
    function showView(toggle, view) {
        var section = toggle.closest(".today-section");
        toggle.querySelectorAll("[data-view]").forEach(function (button) {
            button.classList.toggle("active", button.dataset.view === view);
        });
        section.querySelectorAll("[data-today-view]").forEach(function (box) {
            box.hidden = box.dataset.todayView !== view;
        });
    }
    function applyViews(box) {
        box.querySelectorAll("[data-today-toggle]").forEach(function (toggle) {
            var view = remembered(toggle.dataset.todayToggle);
            showView(toggle, view === "list" ? "list" : "timeline");
        });
    }
    function nowLines(box) {
        box.querySelectorAll(".tl[data-tl-start]").forEach(function (timeline) {
            var slots = Number(timeline.dataset.tlSlots);
            var slot = Math.floor((Date.now() / 1000 - Number(timeline.dataset.tlStart)) / 900) + 1;
            timeline.querySelectorAll("[data-tl-now]").forEach(function (line) {
                line.className = line.className.replace(/\btl-from-\d+\b/g, "").trim();
                line.hidden = slot < 1 || slot > slots;
                if (!line.hidden) line.classList.add("tl-from-" + slot);
            });
        });
    }
    function init(box) {
        applyViews(box);
        nowLines(box);
    }

    document.addEventListener("click", function (e) {
        var button = e.target.closest && e.target.closest("[data-today-toggle] [data-view]");
        if (!button) return;
        var toggle = button.closest("[data-today-toggle]");
        remember(toggle.dataset.todayToggle, button.dataset.view);
        showView(toggle, button.dataset.view);
    });

    // the date field next to ‹ › ([data-today-date], flatpickr): opens the page of the picked day
    function datePicker() {
        var input = document.querySelector("[data-today-date]");
        if (!input || !window.flatpickr) return;
        var arabic = document.documentElement.lang === "ar" && flatpickr.l10ns.ar;
        flatpickr(input, {
            dateFormat: "D d-m-Y",
            defaultDate: flatpickr.parseDate(input.dataset.date, "Y-m-d"),
            locale: arabic || "default",
            onChange: function (dates) {
                if (!dates.length) return;
                var day = flatpickr.formatDate(dates[0], "Y-m-d");
                if (day !== input.dataset.date) window.location.href = input.dataset.url + "?date=" + day;
            },
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        datePicker();
        var box = root();
        if (!box) return;
        init(box);
        setInterval(function () { nowLines(box); }, 60000);
    });

    return {
        active: function () {
            return !!root();
        },
        refresh: function () {
            var box = root();
            if (!box) return;
            fetch(box.dataset.url, { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" } })
                .then(function (response) {
                    if (!response.ok) throw response;
                    return response.text();
                })
                .then(function (html) {
                    box.innerHTML = html;
                    createIcons({ icons: icons, "stroke-width": 1.5, nameAttr: "data-lucide" });
                    init(box);
                })
                .catch(function () {
                    toast("Could not refresh the page, please reload it.", "error");
                });
        },
    };
})();


// Drag and drop rows (a DataTables table with data-reorder-url, e.g. the units of a space): drag a row by its [data-drag-handle]
// and drop it on another row (upper half = before it, lower half = after it); sends {id, target_id, position} (ids from the
// rows' "row-{id}") as PATCH, then the list is drawn again. Native HTML drag and drop, no library.
(function () {
    var dragged = null;
    function rowId(tr) {
        return tr && tr.id ? tr.id.replace(/^row-/, "") : null;
    }
    function clearMarks(table) {
        table.querySelectorAll("tr.drop-before, tr.drop-after").forEach(function (tr) { tr.classList.remove("drop-before", "drop-after"); });
    }
    function reorderTable(el) {
        var table = el.closest && el.closest("table[data-reorder-url]");
        return table && el.closest("tbody") ? table : null;
    }

    // only the handle starts a drag (text in the other cells stays selectable)
    document.addEventListener("mousedown", function (e) {
        var handle = e.target.closest && e.target.closest("[data-drag-handle]");
        var tr = handle && handle.closest("tr");
        if (tr && reorderTable(tr)) tr.draggable = true;
    });
    document.addEventListener("dragstart", function (e) {
        var tr = e.target.closest && e.target.closest("tr[draggable=true]");
        if (!tr || !reorderTable(tr)) return;
        dragged = tr;
        tr.classList.add("is-dragging");
        e.dataTransfer.effectAllowed = "move";
        e.dataTransfer.setData("text/plain", rowId(tr));
    });
    document.addEventListener("dragover", function (e) {
        var tr = dragged && e.target.closest && e.target.closest("tr");
        var table = tr && reorderTable(tr);
        if (!table || tr === dragged || !rowId(tr)) return;
        e.preventDefault();
        var box = tr.getBoundingClientRect();
        var after = e.clientY > box.top + box.height / 2;
        clearMarks(table);
        tr.classList.add(after ? "drop-after" : "drop-before");
    });
    document.addEventListener("drop", function (e) {
        var tr = dragged && e.target.closest && e.target.closest("tr");
        var table = tr && reorderTable(tr);
        if (!table || tr === dragged || !rowId(tr)) return;
        e.preventDefault();
        var position = tr.classList.contains("drop-after") ? "after" : "before";
        clearMarks(table);
        // moved at once on the page, saved, then the list is drawn again from the server
        tr.parentNode.insertBefore(dragged, position === "after" ? tr.nextSibling : tr);
        var data = new FormData();
        data.append("_method", "PATCH");
        data.append("id", rowId(dragged));
        data.append("target_id", rowId(tr));
        data.append("position", position);
        fetch(table.dataset.reorderUrl, {
            method: "POST",
            body: data,
            headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json", "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content },
        })
            .then(function (response) {
                if (!response.ok) throw response;
            })
            .catch(function () {
                toast("The order could not be saved, please try again.", "error");
            })
            .finally(function () {
                if (window.dataTables) window.dataTables.reload();
            });
    });
    document.addEventListener("dragend", function () {
        if (!dragged) return;
        dragged.classList.remove("is-dragging");
        dragged.draggable = false;
        var table = reorderTable(dragged);
        if (table) clearMarks(table);
        dragged = null;
    });
})();
