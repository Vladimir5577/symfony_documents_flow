(function () {
    'use strict';

    // var POLL_INTERVAL = 10 * 1000;  // 10 seconds
    var POLL_INTERVAL = 5 * 60 * 1000; // 5 minutes
    var STORAGE_KEY = 'notifications_last_check';
    var DISMISSED_KEY = 'notifications_dismissed_toast_ids';
    var DISMISSED_LIMIT = 100;

    var badge = document.getElementById('notification-badge');
    var list = document.getElementById('notification-list');
    var markAllBtn = document.getElementById('mark-all-read-btn');
    var toastStack = document.getElementById('toastStack');

    if (!badge || !list) return;

    // ===== Toast icons (SVG) =====
    var toastIcons = {
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 17h.01"/><path d="M9.1 9a3 3 0 1 1 5.8 1c0 2-3 3-3 3"/><circle cx="12" cy="12" r="9"/></svg>',
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>'
    };

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ===== Dismissed toasts (localStorage) =====
    function getDismissedIds() {
        try {
            var raw = localStorage.getItem(DISMISSED_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function addDismissedId(id) {
        var ids = getDismissedIds();
        if (ids.indexOf(id) !== -1) return;
        ids.push(id);
        if (ids.length > DISMISSED_LIMIT) {
            ids = ids.slice(-DISMISSED_LIMIT);
        }
        try {
            localStorage.setItem(DISMISSED_KEY, JSON.stringify(ids));
        } catch (e) {}
    }

    function isDismissed(id) {
        return getDismissedIds().indexOf(id) !== -1;
    }

    // ===== Toast notification =====
    // notificationId - optional; if set, closing the toast saves id to localStorage and it won't show again
    function showToast(title, text, type, duration, notificationId) {
        if (!toastStack) return;
        type = type || 'info';
        duration = duration || 5000;

        var toast = document.createElement('div');
        toast.className = 'toast-notify';
        toast.setAttribute('data-type', type);
        if (notificationId != null) {
            toast.setAttribute('data-notification-id', String(notificationId));
        }

        toast.innerHTML =
            '<div class="toast-notify__icon">' + (toastIcons[type] || toastIcons.info) + '</div>' +
            '<div class="toast-notify__content">' +
                '<h4 class="toast-notify__title">' + escapeHtml(title) + '</h4>' +
                (text ? '<p class="toast-notify__text">' + escapeHtml(text) + '</p>' : '') +
            '</div>' +
            '<button class="toast-notify__close" aria-label="Закрыть">' +
                '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">' +
                    '<path d="M18 6 6 18"/><path d="M6 6l12 12"/>' +
                '</svg>' +
            '</button>' +
            '<div class="toast-notify__progress">' +
                '<div class="toast-notify__progress-bar" style="animation-duration:' + duration + 'ms"></div>' +
            '</div>';

        toastStack.appendChild(toast);

        var timeout = setTimeout(function () { closeToast(toast); }, duration);
        var startedAt = performance.now();
        var remaining = duration;
        var paused = false;
        var bar = toast.querySelector('.toast-notify__progress-bar');
        var closeBtn = toast.querySelector('.toast-notify__close');

        closeBtn.addEventListener('click', function () {
            clearTimeout(timeout);
            var nid = toast.getAttribute('data-notification-id');
            if (nid) addDismissedId(nid);
            closeToast(toast);
        });

        toast.addEventListener('mouseenter', function () {
            if (paused) return;
            paused = true;
            clearTimeout(timeout);
            remaining -= performance.now() - startedAt;
            bar.style.animationPlayState = 'paused';
        });

        toast.addEventListener('mouseleave', function () {
            if (!paused) return;
            paused = false;
            startedAt = performance.now();
            bar.style.animationPlayState = 'running';
            timeout = setTimeout(function () { closeToast(toast); }, remaining);
        });
    }

    function closeToast(toast) {
        if (!toast || toast.classList.contains('is-closing')) return;
        toast.classList.add('is-closing');
        toast.addEventListener('animationend', function () { toast.remove(); }, { once: true });
    }

    // Make globally available for other scripts
    window.showToast = showToast;

    // ===== Notification system =====
    function getLastCheck() {
        return localStorage.getItem(STORAGE_KEY) || null;
    }

    function setLastCheck() {
        localStorage.setItem(STORAGE_KEY, new Date().toISOString());
    }

    function formatTime(dateStr) {
        var date = new Date(dateStr);
        var now = new Date();
        var diff = Math.floor((now - date) / 1000);

        if (diff < 60) return 'только что';
        if (diff < 3600) return Math.floor(diff / 60) + ' мин. назад';
        if (diff < 86400) return Math.floor(diff / 3600) + ' ч. назад';

        return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
    }

    function updateBadge(count) {
        if (count > 0) {
            badge.textContent = count >= 100 ? '99+' : count;
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }

    function renderNotifications(notifications) {
        if (notifications.length === 0) {
            list.innerHTML = '<div class="text-center text-muted py-4" style="font-size: 0.85rem;">Нет уведомлений</div>';
            return;
        }

        list.innerHTML = notifications.map(function (n) {
            var readClass = n.isRead ? 'opacity-50' : '';
            var linkHref = n.link ? n.link : '#';
            var dot = n.isRead ? '' : '<span class="bg-primary rounded-circle d-inline-block" style="width: 8px; height: 8px; flex-shrink: 0;"></span>';

            return '<a href="' + linkHref + '" class="dropdown-item d-flex align-items-start gap-2 px-3 py-2 border-bottom text-wrap ' + readClass + '" data-notification-id="' + n.id + '" style="white-space: normal;">'
                + dot
                + '<div style="min-width: 0;">'
                + '<div style="font-size: 0.85rem; font-weight: 500;">' + escapeHtml(n.title) + '</div>'
                + '<div class="text-muted" style="font-size: 0.75rem;">' + formatTime(n.createdAt) + '</div>'
                + '</div>'
                + '</a>';
        }).join('');

        list.querySelectorAll('[data-notification-id]').forEach(function (el) {
            el.addEventListener('click', function () {
                var id = el.getAttribute('data-notification-id');
                markAsRead(id);
            });
        });
    }

    function fetchNotifications(showNewToasts) {
        fetch('/api/notifications/latest')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                updateBadge(data.unreadCount);
                renderNotifications(data.notifications);

                if (showNewToasts && data.notifications) {
                    var unread = data.notifications.filter(function (n) {
                        return !n.isRead && !isDismissed(String(n.id));
                    });
                    unread.slice(0, 10).forEach(function (n, i) {
                        setTimeout(function () {
                            showToast(n.title, n.text || n.message || '', 'info', 5000, n.id);
                        }, i * 300);
                    });
                }

                setLastCheck();
            })
            .catch(function (err) {
                console.error('Notification fetch error:', err);
            });
    }

    function markAsRead(id) {
        fetch('/api/notifications/' + id + '/read', { method: 'POST' })
            .then(function () { fetchNotifications(false); });
    }

    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            fetch('/api/notifications/read-all', { method: 'POST' })
                .then(function () { fetchNotifications(false); });
        });
    }

    // Initial fetch
    fetchNotifications(true);

    // Poll every 5 minutes
    setInterval(function () { fetchNotifications(true); }, POLL_INTERVAL);
})();
