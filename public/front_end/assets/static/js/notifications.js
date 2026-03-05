(function () {
    'use strict';

    const POLL_INTERVAL = 5 * 60 * 1000; // 5 minutes
    const STORAGE_KEY = 'notifications_last_check';

    const badge = document.getElementById('notification-badge');
    const list = document.getElementById('notification-list');
    const markAllBtn = document.getElementById('mark-all-read-btn');

    if (!badge || !list) return;

    function getLastCheck() {
        return localStorage.getItem(STORAGE_KEY) || null;
    }

    function setLastCheck() {
        localStorage.setItem(STORAGE_KEY, new Date().toISOString());
    }

    function formatTime(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);

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
            const readClass = n.isRead ? 'opacity-50' : '';
            const linkHref = n.link ? n.link : '#';
            const dot = n.isRead ? '' : '<span class="bg-primary rounded-circle d-inline-block" style="width: 8px; height: 8px; flex-shrink: 0;"></span>';

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

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(notification) {
        if (typeof Swal === 'undefined') return;

        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: notification.title,
            showConfirmButton: false,
            timer: 5000,
            timerProgressBar: true,
        });
    }

    function fetchNotifications(showNewToasts) {
        fetch('/api/notifications/latest')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                updateBadge(data.unreadCount);
                renderNotifications(data.notifications);

                if (showNewToasts) {
                    var lastCheck = getLastCheck();
                    if (lastCheck) {
                        var newOnes = data.notifications.filter(function (n) {
                            return !n.isRead && n.createdAt > lastCheck;
                        });
                        newOnes.forEach(function (n) { showToast(n); });
                    }
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
