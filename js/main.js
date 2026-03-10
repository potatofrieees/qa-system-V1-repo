/**
 * S.I.T.A QA System — Global JS
 */

// ── Inject SweetAlert styles ──────────────────────────────────
(function () {
    var s = document.createElement('style');
    s.textContent =
        '@keyframes qa-spin{to{transform:rotate(360deg)}}' +

        '.swal2-container{' +
        'position:fixed!important;top:0!important;left:0!important;' +
        'width:100%!important;height:100%!important;' +
        'display:flex!important;align-items:center!important;' +
        'justify-content:center!important;z-index:99999!important;}' +

        '.swal2-popup.qa-popup{' +
        'font-family:"DM Sans",sans-serif!important;' +
        'border-radius:18px!important;padding:32px 28px 24px!important;' +
        'box-shadow:0 20px 60px rgba(0,0,0,.25)!important;' +
        'max-width:420px!important;width:90vw!important;margin:auto!important;}' +

        '.swal2-popup.qa-popup .swal2-title{' +
        'font-size:1.15rem!important;font-weight:700!important;' +
        'color:#1e1b4b!important;padding:0 0 6px!important;}' +

        '.swal2-popup.qa-popup .swal2-html-container{' +
        'font-size:.88rem!important;color:#4b5563!important;' +
        'margin:6px 0 0!important;line-height:1.6!important;}' +

        '.swal2-popup.qa-popup .swal2-actions{' +
        'gap:10px!important;margin-top:22px!important;' +
        'justify-content:center!important;flex-wrap:wrap!important;}' +

        '.qa-btn{border:none!important;border-radius:9px!important;' +
        'padding:10px 26px!important;font-size:.875rem!important;' +
        'font-weight:600!important;cursor:pointer!important;' +
        'font-family:"DM Sans",sans-serif!important;line-height:1.4!important;}' +

        '.qa-btn-purple{background:#7c3aed!important;color:#fff!important;}' +
        '.qa-btn-purple:hover{background:#6d28d9!important;}' +
        '.qa-btn-red{background:#dc2626!important;color:#fff!important;}' +
        '.qa-btn-red:hover{background:#b91c1c!important;}' +
        '.qa-btn-green{background:#059669!important;color:#fff!important;}' +
        '.qa-btn-green:hover{background:#047857!important;}' +
        '.qa-btn-gray{background:#f3f4f6!important;color:#374151!important;' +
        'border:1px solid #d1d5db!important;}' +
        '.qa-btn-gray:hover{background:#e5e7eb!important;}' +

        '.swal2-toast.qa-popup{padding:12px 18px!important;' +
        'border-radius:12px!important;min-width:260px!important;max-width:380px!important;}';
    document.head.appendChild(s);
})();

// ── Toast notifications ───────────────────────────────────────
function swalToast(type, html) {
    if (typeof Swal === 'undefined') return;
    Swal.fire({
        toast: true, position: 'top-end',
        icon: type, title: html,
        showConfirmButton: false,
        timer: 5000, timerProgressBar: true,
        customClass: { popup: 'qa-popup' },
        buttonsStyling: false,
    });
}

// ── Centered confirmation dialog ──────────────────────────────
function qaConfirm(title, html, icon, confirmText, confirmBtnClass, cancelText) {
    return Swal.fire({
        title: title,
        html:  html || '',
        icon:  icon || 'warning',
        showCancelButton:  true,
        confirmButtonText: confirmText || 'Yes, Continue',
        cancelButtonText:  cancelText  || 'Cancel',
        reverseButtons:    true,
        focusCancel:       true,
        customClass: {
            popup:         'qa-popup',
            confirmButton: 'qa-btn ' + (confirmBtnClass || 'qa-btn-purple'),
            cancelButton:  'qa-btn qa-btn-gray',
        },
        buttonsStyling: false,
    });
}

// ── Wire up swal-confirm-form forms ──────────────────────────
// ONLY targets forms with class="swal-confirm-form"
// Does NOT touch any other form
function wireConfirmForms() {
    document.querySelectorAll('form.swal-confirm-form').forEach(function (form) {
        if (form._swalWired) return;
        form._swalWired = true;

        form.addEventListener('submit', function (e) {
            // Only intercept if Swal is available
            if (typeof Swal === 'undefined') return;

            e.preventDefault();

            var title       = form.dataset.title   || 'Are you sure?';
            var text        = form.dataset.text    || '';
            var icon        = form.dataset.icon    || 'warning';
            var confirmText = form.dataset.confirm || 'Yes, Continue';
            var btnClass    = form.dataset.cls     || 'qa-btn-purple';

            qaConfirm(title, text, icon, confirmText, btnClass).then(function (result) {
                if (!result.isConfirmed) return;

                // Show spinner on the submit button
                var btn = form.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    var orig = btn.innerHTML;
                    btn.innerHTML =
                        '<span style="display:inline-flex;align-items:center;gap:5px;">' +
                        '<svg style="width:14px;height:14px;animation:qa-spin 1s linear infinite" ' +
                        'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
                        '<circle cx="12" cy="12" r="10" opacity=".2"/>' +
                        '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>' +
                        'Processing\u2026</span>';
                    // Safety re-enable after 8s
                    setTimeout(function () {
                        btn.disabled = false;
                        btn.innerHTML = orig;
                    }, 8000);
                }

                // Submit the form bypassing all event listeners
                HTMLFormElement.prototype.submit.call(form);
            });
        });
    });
}

// ── Convert PHP .alert flash messages → toasts ───────────────
function convertFlashAlerts() {
    document.querySelectorAll('.alert').forEach(function (a) {
        var isErr = a.classList.contains('alert-error');
        var text  = a.innerHTML
            .replace(/<svg[\s\S]*?<\/svg>/gi, '')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ').trim();
        if (text) swalToast(isErr ? 'error' : 'success', text);
        a.style.display = 'none';
    });
}

// ── Modal helpers ─────────────────────────────────────────────
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('open');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

// ── Live notification badge ───────────────────────────────────
function refreshNotifBadge() {
    fetch('get_unread_count.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data) return;
            document.querySelectorAll('.notif-badge').forEach(function (el) {
                el.textContent   = data.count > 0 ? data.count : '';
                el.style.display = data.count > 0 ? 'flex' : 'none';
            });
        })
        .catch(function () {});
}
setInterval(refreshNotifBadge, 60000);

// ── DOM Ready ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    if (typeof Swal !== 'undefined') {
        convertFlashAlerts();
        wireConfirmForms();   // ONLY wires forms with class="swal-confirm-form"
    } else {
        // Fallback: fade out alerts after 6s
        document.querySelectorAll('.alert').forEach(function (a) {
            setTimeout(function () {
                a.style.transition = 'opacity .5s';
                a.style.opacity = '0';
                setTimeout(function () { a.remove(); }, 500);
            }, 6000);
        });
    }

    // ESC key closes modals
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(function (m) {
                m.classList.remove('open');
            });
        }
    });

    // Click overlay background to close
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('open');
        });
    });

    // Mobile sidebar
    var sidebar   = document.getElementById('sidebar');
    var toggleBtn = document.getElementById('sidebar-toggle');
    if (sidebar && toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('open');
        });
    }
    if (sidebar) {
        document.addEventListener('click', function (e) {
            if (window.innerWidth < 900 &&
                !sidebar.contains(e.target) &&
                e.target !== toggleBtn) {
                sidebar.classList.remove('open');
            }
        });
    }
});

// ── Notification Slide Panel ───────────────────────────────────
(function () {
    // Inject panel HTML once DOM is ready
    function buildPanel() {
        if (document.getElementById('qa-notif-panel-overlay')) return;
        var notifPageUrl = 'notifications.php';
        var html = '<div class="notif-panel-overlay" id="qa-notif-panel-overlay">' +
            '<div class="notif-panel" id="qa-notif-panel">' +
            '<div class="notif-panel-head">' +
            '<h3>Notifications</h3>' +
            '<a href="' + notifPageUrl + '" class="btn btn-ghost btn-sm" id="qa-notif-view-all" style="font-size:.78rem;">View all</a>' +
            '<button type="button" onclick="closeNotifPanel()" style="background:none;border:none;cursor:pointer;color:var(--muted);padding:4px;border-radius:6px;font-size:1.2rem;line-height:1;" title="Close">✕</button>' +
            '</div>' +
            '<div class="notif-panel-body" id="qa-notif-panel-body">' +
            '<div class="notif-panel-loading">Loading…</div>' +
            '</div>' +
            '<div class="notif-panel-footer">' +
            '<form method="POST" action="notifications.php" id="qa-notif-mark-all-form" style="display:inline;">' +
            '<input type="hidden" name="mark_all_read" value="1">' +
            '<button type="button" class="btn btn-outline btn-sm" style="width:100%;" onclick="markAllRead()">Mark all as read</button>' +
            '</form>' +
            '</div>' +
            '</div>' +
            '</div>';
        var div = document.createElement('div');
        div.innerHTML = html;
        document.body.appendChild(div.firstChild);

        // Close on overlay click
        document.getElementById('qa-notif-panel-overlay').addEventListener('click', function (e) {
            if (e.target === this) closeNotifPanel();
        });
    }

    window.openNotifPanel = function () {
        buildPanel();
        document.getElementById('qa-notif-panel-overlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        loadNotifPanel();
    };

    window.closeNotifPanel = function () {
        var overlay = document.getElementById('qa-notif-panel-overlay');
        if (overlay) overlay.classList.remove('open');
        document.body.style.overflow = '';
    };

    window.markAllRead = function () {
        fetch('notifications.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mark_all_read=1'
        }).then(function () {
            loadNotifPanel();
            refreshNotifBadge();
        });
    };

    window.loadNotifPanel = function () {
        var body = document.getElementById('qa-notif-panel-body');
        if (!body) return;
        body.innerHTML = '<div class="notif-panel-loading">Loading…</div>';

        fetch('get_notif_panel.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { body.innerHTML = '<div class="notif-panel-empty"><p>Could not load notifications.</p></div>'; return; }
                if (!data.items || data.items.length === 0) {
                    body.innerHTML = '<div class="notif-panel-empty">' +
                        '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>' +
                        '<p>No notifications</p></div>';
                    return;
                }
                var html = '';
                data.items.forEach(function (n) {
                    var cls = n.is_read ? '' : ' unread';
                    html += '<div class="notif-panel-item' + cls + '" onclick="notifPanelClick(' + n.id + ',' + JSON.stringify(n.link || '') + ',' + JSON.stringify(n.mark_url) + ')">' +
                        '<div class="notif-panel-icon">' + (n.icon || '📢') + '</div>' +
                        '<div class="notif-panel-text">' +
                        '<div class="notif-panel-title">' + n.title + '</div>' +
                        '<div class="notif-panel-time">' + n.time_ago + '</div>' +
                        '</div>' +
                        '</div>';
                });
                body.innerHTML = html;
                refreshNotifBadge();
            })
            .catch(function () {
                body.innerHTML = '<div class="notif-panel-empty"><p>Could not load notifications.</p></div>';
            });
    };

    window.notifPanelClick = function (id, link, markUrl) {
        // Mark as read via fetch then navigate
        fetch(markUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'mark_id=' + id })
            .then(function () {
                refreshNotifBadge();
                if (link) { closeNotifPanel(); window.location.href = link; }
                else loadNotifPanel();
            });
    };

    // Intercept notif-btn clicks to open panel instead of navigate
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.notif-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openNotifPanel();
            });
        });
    });
})();

// ── Scroll Position Preservation for AJAX Actions ─────────────
(function () {
    var SCROLL_KEY = 'qa_scroll_y';
    // Save scroll position before form submissions that use swal-confirm
    document.addEventListener('DOMContentLoaded', function () {
        // Restore scroll if flag set
        var saved = sessionStorage.getItem(SCROLL_KEY);
        if (saved !== null) {
            window.scrollTo(0, parseInt(saved, 10));
            sessionStorage.removeItem(SCROLL_KEY);
        }
        // Save scroll before any swal-confirm-form submit
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form.classList.contains('swal-confirm-form')) {
                sessionStorage.setItem(SCROLL_KEY, window.scrollY);
            }
        }, true);
    });
})();
