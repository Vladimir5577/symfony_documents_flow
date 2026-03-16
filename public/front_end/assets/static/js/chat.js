/**
 * Corporate Chat (Telegram-style) — chat.js
 */
(function () {
    'use strict';

    var MERCURE_URL = window.__chatConfig ? window.__chatConfig.mercureUrl : '';
    var CURRENT_USER_ID = window.__chatConfig ? window.__chatConfig.currentUserId : 0;

    var state = {
        rooms: [],
        currentRoomId: null,
        currentRoomDetail: null,
        messages: [],
        loading: false,
        hasMore: true,
        eventSourceRoom: null,
        eventSourceUser: null,
    };

    // DOM refs
    var els = {};

    function init() {
        els.roomList = document.getElementById('chat-room-list');
        els.roomSearch = document.getElementById('chat-room-search');
        els.messagesArea = document.getElementById('chat-messages-area');
        els.messageInput = document.getElementById('chat-message-input');
        els.sendBtn = document.getElementById('chat-send-btn');
        els.fileInput = document.getElementById('chat-file-input');
        els.fileBtn = document.getElementById('chat-file-btn');
        els.chatHeader = document.getElementById('chat-header-title');
        els.chatPlaceholder = document.getElementById('chat-placeholder');
        els.chatMain = document.getElementById('chat-main-panel');
        els.backBtn = document.getElementById('chat-back-btn');
        els.participantsBtn = document.getElementById('chat-participants-btn');
        els.participantsPanel = document.getElementById('chat-participants-panel');
        els.participantsList = document.getElementById('chat-participants-list');
        els.participantsActions = document.getElementById('chat-participants-actions');
        els.addParticipantBtn = document.getElementById('chat-add-participant-btn');
        els.participantsClose = document.getElementById('chat-participants-close');
        els.participantsCount = document.getElementById('chat-participants-count');

        if (!els.roomList) return;

        loadRooms();
        subscribeUserTopic();

        els.sendBtn.addEventListener('click', sendMessage);
        els.messageInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        els.fileBtn.addEventListener('click', function () { els.fileInput.click(); });
        els.fileInput.addEventListener('change', sendMessage);

        if (els.roomSearch) {
            els.roomSearch.addEventListener('input', filterRooms);
        }

        if (els.backBtn) {
            els.backBtn.addEventListener('click', function () {
                document.getElementById('chat-container').classList.remove('chat-room-open');
            });
        }

        // Scroll to load more
        els.messagesArea.addEventListener('scroll', function () {
            if (els.messagesArea.scrollTop < 60 && !state.loading && state.hasMore && state.currentRoomId) {
                loadMoreMessages();
            }
        });

        // Drag & drop
        els.messagesArea.addEventListener('dragover', function (e) { e.preventDefault(); e.stopPropagation(); });
        els.messagesArea.addEventListener('drop', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (e.dataTransfer.files.length > 0) {
                els.fileInput.files = e.dataTransfer.files;
                sendMessage();
            }
        });

        initPrivateModal();
        initGroupModal();

        // Participants panel
        if (els.participantsBtn) {
            els.participantsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                els.participantsPanel.classList.toggle('d-none');
            });
        }
        if (els.participantsClose) {
            els.participantsClose.addEventListener('click', function () {
                els.participantsPanel.classList.add('d-none');
            });
        }
        // Close participants panel on click outside
        if (els.participantsPanel) {
            els.participantsPanel.addEventListener('click', function (e) {
                e.stopPropagation();
            });
            document.addEventListener('click', function () {
                if (els.participantsPanel && !els.participantsPanel.classList.contains('d-none')) {
                    els.participantsPanel.classList.add('d-none');
                }
            });
        }
        if (els.addParticipantBtn) {
            els.addParticipantBtn.addEventListener('click', function () {
                openAddParticipantModal();
            });
        }
    }

    // -------- Rooms --------

    var _loadRoomsTimer = null;

    function loadRooms() {
        fetch('/api/chat/rooms', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (rooms) {
                state.rooms = rooms;
                renderRooms(rooms);
            });
    }

    function loadRoomsDebounced() {
        clearTimeout(_loadRoomsTimer);
        _loadRoomsTimer = setTimeout(loadRooms, 300);
    }

    function renderRooms(rooms) {
        els.roomList.innerHTML = '';
        if (rooms.length === 0) {
            els.roomList.innerHTML = '<div class="text-muted small p-3 text-center">Нет чатов</div>';
            return;
        }
        rooms.forEach(function (room) {
            var div = document.createElement('div');
            div.className = 'chat-room-item' + (room.id === state.currentRoomId ? ' active' : '');
            div.dataset.roomId = room.id;

            var initials = getInitials(room.name || '');
            var typeIcon = room.type === 'group' ? 'bi-people-fill' : (room.type === 'department' ? 'bi-building' : '');
            var avatarDataAttr = room.other_user_id ? ' data-user-id="' + room.other_user_id + '"' : '';

            var avatarContent;
            if (typeIcon) {
                avatarContent = '<i class="bi ' + typeIcon + '"></i>';
            } else if (room.other_user_avatar) {
                avatarContent = '<img src="' + escapeHtml(room.other_user_avatar) + '" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" alt="">';
            } else {
                avatarContent = initials;
            }

            var preview = room.last_message || '';
            if (preview.length > 40) preview = preview.substring(0, 40) + '...';

            var senderPrefix = '';
            if (room.type !== 'private' && room.last_message_sender) {
                var parts = room.last_message_sender.split(' ');
                senderPrefix = (parts[0] || '') + ': ';
            }

            div.innerHTML =
                '<div class="chat-room-avatar"' + avatarDataAttr + '>' + avatarContent + '</div>' +
                '<div class="chat-room-info">' +
                    '<div class="d-flex justify-content-between">' +
                        '<span class="chat-room-name">' + escapeHtml(room.name || 'Чат') + '</span>' +
                        '<span class="chat-room-time">' + formatTime(room.last_message_at) + '</span>' +
                    '</div>' +
                    '<div class="d-flex justify-content-between align-items-center">' +
                        '<span class="chat-room-preview">' + escapeHtml(senderPrefix + preview) + '</span>' +
                        (room.unread_count > 0 ? '<span class="chat-room-badge">' + room.unread_count + '</span>' : '') +
                    '</div>' +
                '</div>';

            // Avatar click → user popover (private chats only)
            var avatarEl = div.querySelector('.chat-room-avatar[data-user-id]');
            if (avatarEl) {
                avatarEl.addEventListener('click', function (e) {
                    e.stopPropagation();
                    showUserPopover(parseInt(avatarEl.dataset.userId, 10), avatarEl);
                });
            }

            div.addEventListener('click', function () { openRoom(room.id); });
            els.roomList.appendChild(div);
        });
    }

    function filterRooms() {
        var q = (els.roomSearch.value || '').toLowerCase().trim();
        var filtered = state.rooms.filter(function (r) {
            return !q || (r.name && r.name.toLowerCase().indexOf(q) >= 0);
        });
        renderRooms(filtered);
    }

    function openRoom(roomId) {
        state.currentRoomId = roomId;
        state.messages = [];
        state.hasMore = true;
        state.loading = false;

        document.getElementById('chat-container').classList.add('chat-room-open');

        var room = state.rooms.find(function (r) { return r.id === roomId; });
        els.chatHeader.textContent = room ? (room.name || 'Чат') : 'Чат';

        els.chatPlaceholder.classList.add('d-none');
        els.chatMain.classList.remove('d-none');
        els.messagesArea.innerHTML = '';
        els.messageInput.value = '';

        // Hide participants panel on room switch
        if (els.participantsPanel) els.participantsPanel.classList.add('d-none');

        // Mark active
        els.roomList.querySelectorAll('.chat-room-item').forEach(function (el) {
            el.classList.toggle('active', parseInt(el.dataset.roomId) === roomId);
        });

        loadMessages(roomId, null);
        markAsRead(roomId);
        subscribeRoomTopic(roomId);
        loadRoomDetails(roomId);
    }

    // -------- Messages --------

    function loadMessages(roomId, beforeId) {
        state.loading = true;
        var url = '/api/chat/rooms/' + roomId + '/messages';
        if (beforeId) url += '?before=' + beforeId;

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (messages) {
                // Ignore response if user switched to another room
                if (roomId !== state.currentRoomId) return;

                state.loading = false;
                if (messages.length < 30) state.hasMore = false;

                messages.reverse();

                if (!beforeId) {
                    state.messages = messages;
                    els.messagesArea.style.opacity = '0';
                    renderAllMessages();
                    els.messagesArea.scrollTop = els.messagesArea.scrollHeight;
                    requestAnimationFrame(function () {
                        els.messagesArea.style.opacity = '';
                    });
                } else {
                    var oldScrollHeight = els.messagesArea.scrollHeight;
                    state.messages = messages.concat(state.messages);
                    renderAllMessages();
                    els.messagesArea.scrollTop = els.messagesArea.scrollHeight - oldScrollHeight;
                }
            })
            .catch(function () {
                if (roomId === state.currentRoomId) state.loading = false;
            });
    }

    function loadMoreMessages() {
        if (state.messages.length === 0) return;
        var oldestId = state.messages[0].id;
        loadMessages(state.currentRoomId, oldestId);
    }

    function renderAllMessages() {
        var room = state.rooms.find(function (r) { return r.id === state.currentRoomId; });
        var isGroup = room && room.type !== 'private';

        var fragment = document.createDocumentFragment();
        state.messages.forEach(function (msg) {
            appendMessageToFragment(msg, isGroup, fragment);
        });

        els.messagesArea.innerHTML = '';
        els.messagesArea.appendChild(fragment);
    }

    function buildMessageEl(msg, isGroup) {
        var div = document.createElement('div');
        var isMine = msg.sender && msg.sender.id === CURRENT_USER_ID;
        div.className = 'chat-bubble ' + (isMine ? 'chat-bubble-mine' : 'chat-bubble-other');
        div.dataset.messageId = msg.id;

        var html = '';

        if (msg.is_deleted) {
            html = '<div class="chat-deleted"><em>Сообщение удалено</em></div>';
        } else {
            if (isGroup && !isMine && msg.sender) {
                html += '<div class="chat-bubble-sender" data-user-id="' + msg.sender.id + '">' + escapeHtml(msg.sender.lastname + ' ' + msg.sender.firstname) + '</div>';
            }
            if (msg.content) {
                html += '<div class="chat-bubble-text">' + escapeHtml(msg.content) + '</div>';
            }
            if (msg.files && msg.files.length > 0) {
                html += '<div class="chat-bubble-files">';
                msg.files.forEach(function (f) {
                    html += '<a href="' + escapeHtml(f.path) + '" target="_blank" class="chat-file-link"><i class="bi bi-file-earmark"></i> ' + escapeHtml(f.title) + '</a>';
                });
                html += '</div>';
            }
            if (isMine) {
                html += '<div class="chat-msg-actions">' +
                    '<button class="chat-actions-btn" title="Действия"><i class="bi bi-three-dots-vertical"></i></button>' +
                    '<div class="chat-actions-menu">' +
                        '<button class="chat-action-item chat-action-edit" data-msg-id="' + msg.id + '"><i class="bi bi-pencil"></i> Редактировать</button>' +
                        '<button class="chat-action-item chat-action-delete" data-msg-id="' + msg.id + '"><i class="bi bi-trash3"></i> Удалить</button>' +
                    '</div>' +
                '</div>';
            }
        }

        var timeLabel = formatMessageTime(msg.created_at);
        if (msg.updated_at && !msg.is_deleted && msg.updated_at !== msg.created_at) {
            timeLabel += ' <span class="chat-edited-label">изменено ' + formatMessageTime(msg.updated_at) + '</span>';
        }
        if (isMine && !msg.is_deleted) {
            timeLabel += ' <span class="chat-read-status" data-msg-id="' + msg.id + '">' +
                (msg.is_read ? '<i class="bi bi-check2-all text-primary"></i>' : '<i class="bi bi-check2"></i>') +
                '</span>';
        }
        html += '<div class="chat-bubble-time">' + timeLabel + '</div>';

        div.innerHTML = html;

        // Bind 3-dot menu toggle
        var actionsBtn = div.querySelector('.chat-actions-btn');
        var actionsMenu = div.querySelector('.chat-actions-menu');
        if (actionsBtn && actionsMenu) {
            actionsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                document.querySelectorAll('.chat-actions-menu.open').forEach(function (m) {
                    if (m !== actionsMenu) m.classList.remove('open');
                });
                actionsMenu.classList.toggle('open');
            });
        }

        var editBtn = div.querySelector('.chat-action-edit');
        if (editBtn) {
            editBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                actionsMenu.classList.remove('open');
                startEditMessage(msg, div);
            });
        }

        var deleteBtn = div.querySelector('.chat-action-delete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                actionsMenu.classList.remove('open');
                confirmDeleteMessage(msg.id);
            });
        }

        // Bind sender name click for popover
        var senderEl = div.querySelector('.chat-bubble-sender[data-user-id]');
        if (senderEl) {
            senderEl.addEventListener('click', function (e) {
                e.stopPropagation();
                showUserPopover(parseInt(senderEl.dataset.userId, 10), senderEl);
            });
        }

        return div;
    }

    function appendMessageToFragment(msg, isGroup, fragment) {
        fragment.appendChild(buildMessageEl(msg, isGroup));
    }

    function appendMessageDOM(msg, isGroup) {
        els.messagesArea.appendChild(buildMessageEl(msg, isGroup));
    }

    // Close action menus on click outside
    document.addEventListener('click', function () {
        document.querySelectorAll('.chat-actions-menu.open').forEach(function (m) {
            m.classList.remove('open');
        });
    });

    function sendMessage() {
        if (!state.currentRoomId) return;

        var content = (els.messageInput.value || '').trim();
        var files = els.fileInput.files;

        if (!content && (!files || files.length === 0)) return;

        var url = '/api/chat/rooms/' + state.currentRoomId + '/messages';

        if (files && files.length > 0) {
            var formData = new FormData();
            formData.append('content', content);
            for (var i = 0; i < files.length; i++) {
                formData.append('files[]', files[i]);
            }
            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            }).then(function (r) { return r.json(); }).then(function () {
                els.messageInput.value = '';
                els.fileInput.value = '';
            });
        } else {
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ content: content }),
            }).then(function (r) { return r.json(); }).then(function () {
                els.messageInput.value = '';
            });
        }
    }

    function confirmDeleteMessage(msgId) {
        Swal.fire({
            title: 'Удалить сообщение?',
            text: 'Сообщение будет удалено для всех участников.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Удалить',
            cancelButtonText: 'Отмена',
        }).then(function (result) {
            if (result.isConfirmed) {
                fetch('/api/chat/messages/' + msgId, {
                    method: 'DELETE',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
            }
        });
    }

    function startEditMessage(msg, bubbleEl) {
        var textEl = bubbleEl.querySelector('.chat-bubble-text');
        if (!textEl) return;

        var originalText = msg.content || '';
        var input = document.createElement('textarea');
        input.className = 'chat-edit-input form-control';
        input.value = originalText;
        input.rows = 2;

        var btnWrap = document.createElement('div');
        btnWrap.className = 'chat-edit-buttons mt-1';
        btnWrap.innerHTML = '<button class="btn btn-sm btn-primary chat-edit-save me-1">Сохранить</button>' +
            '<button class="btn btn-sm btn-secondary chat-edit-cancel">Отмена</button>';

        textEl.innerHTML = '';
        textEl.appendChild(input);
        textEl.appendChild(btnWrap);
        input.focus();

        // Hide actions menu during editing
        var actionsWrap = bubbleEl.querySelector('.chat-msg-actions');
        if (actionsWrap) actionsWrap.style.display = 'none';

        function cancelEdit() {
            textEl.innerHTML = escapeHtml(originalText);
            if (actionsWrap) actionsWrap.style.display = '';
        }

        btnWrap.querySelector('.chat-edit-cancel').addEventListener('click', cancelEdit);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                cancelEdit();
            }
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                saveEdit();
            }
        });

        function saveEdit() {
            var newText = input.value.trim();
            if (!newText || newText === originalText) {
                cancelEdit();
                return;
            }
            fetch('/api/chat/messages/' + msg.id, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ content: newText }),
            })
                .then(function (r) { return r.json(); })
                .then(function (updated) {
                    // Update local state
                    msg.content = updated.content;
                    msg.updated_at = updated.updated_at;
                    textEl.innerHTML = escapeHtml(updated.content);
                    if (actionsWrap) actionsWrap.style.display = '';
                    // Update time label
                    var timeEl = bubbleEl.querySelector('.chat-bubble-time');
                    if (timeEl) {
                        timeEl.innerHTML = formatMessageTime(msg.created_at) +
                            ' <span class="chat-edited-label">изменено ' + formatMessageTime(updated.updated_at) + '</span>';
                    }
                })
                .catch(function () { cancelEdit(); });
        }

        btnWrap.querySelector('.chat-edit-save').addEventListener('click', saveEdit);
    }

    function markAsRead(roomId) {
        fetch('/api/chat/rooms/' + roomId + '/read', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function () {
            var room = state.rooms.find(function (r) { return r.id === roomId; });
            if (room) {
                room.unread_count = 0;
                // Update badge in-place without full re-render
                var roomEl = els.roomList.querySelector('.chat-room-item[data-room-id="' + roomId + '"]');
                if (roomEl) {
                    var badge = roomEl.querySelector('.chat-room-badge');
                    if (badge) badge.remove();
                }
            }
        });
    }

    // -------- User Popover --------

    var activePopover = null;

    function closeUserPopover() {
        if (activePopover) {
            activePopover.remove();
            activePopover = null;
        }
    }

    function showUserPopover(userId, anchorEl) {
        closeUserPopover();

        fetch('/api/chat/users/' + userId + '/profile', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (user) {
                closeUserPopover();

                var popover = document.createElement('div');
                popover.className = 'chat-user-popover';

                var avatarHtml;
                if (user.avatar) {
                    avatarHtml = '<img src="' + escapeHtml(user.avatar) + '" class="chat-popover-avatar" alt="">';
                } else {
                    var initials = getInitials(trim(user.lastname + ' ' + user.firstname));
                    avatarHtml = '<div class="chat-popover-avatar-initials">' + initials + '</div>';
                }

                var fullName = trim(user.lastname + ' ' + user.firstname + (user.patronymic ? ' ' + user.patronymic : ''));
                var lastSeenHtml = formatLastSeen(user.last_seen_at);

                var html = '<div class="chat-popover-header">' +
                    avatarHtml +
                    '<div>' +
                        '<div class="chat-popover-name">' + escapeHtml(fullName) + '</div>' +
                        (user.profession ? '<div class="chat-popover-profession">' + escapeHtml(user.profession) + '</div>' : '') +
                        '<div class="chat-popover-lastseen ' + (lastSeenHtml.online ? '' : 'offline') + '">' + lastSeenHtml.text + '</div>' +
                    '</div>' +
                '</div>';

                var hasDetails = user.phone || user.email || user.organization || user.department;
                if (hasDetails) {
                    html += '<div class="chat-popover-body">';
                    if (user.organization) {
                        html += '<div class="chat-popover-row"><i class="bi bi-building"></i> ' + escapeHtml(user.organization) + '</div>';
                    }
                    if (user.department) {
                        html += '<div class="chat-popover-row"><i class="bi bi-diagram-3"></i> ' + escapeHtml(user.department) + '</div>';
                    }
                    if (user.phone) {
                        html += '<div class="chat-popover-row"><i class="bi bi-telephone"></i> ' + escapeHtml(user.phone) + '</div>';
                    }
                    if (user.email) {
                        html += '<div class="chat-popover-row"><i class="bi bi-envelope"></i> ' + escapeHtml(user.email) + '</div>';
                    }
                    html += '</div>';
                }

                html += '<div class="chat-popover-footer">' +
                    '<button class="btn btn-sm btn-outline-primary w-100 chat-popover-write-btn" data-user-id="' + user.id + '">' +
                        '<i class="bi bi-chat-dots"></i> Написать' +
                    '</button>' +
                '</div>';

                popover.innerHTML = html;

                // Position popover using fixed positioning relative to viewport
                document.body.appendChild(popover);

                var anchorRect = anchorEl.getBoundingClientRect();
                var popoverRect = popover.getBoundingClientRect();

                var top, left = anchorRect.left;

                // Vertical: prefer below, fall back to above, clamp to viewport
                if (anchorRect.bottom + 4 + popoverRect.height <= window.innerHeight) {
                    top = anchorRect.bottom + 4;
                } else if (anchorRect.top - popoverRect.height - 4 >= 0) {
                    top = anchorRect.top - popoverRect.height - 4;
                } else {
                    top = Math.max(8, window.innerHeight - popoverRect.height - 8);
                }

                // Horizontal: clamp to viewport
                if (left + popoverRect.width > window.innerWidth) {
                    left = window.innerWidth - popoverRect.width - 8;
                }
                if (left < 8) left = 8;

                popover.style.position = 'fixed';
                popover.style.top = top + 'px';
                popover.style.left = left + 'px';

                activePopover = popover;

                // Write button
                var writeBtn = popover.querySelector('.chat-popover-write-btn');
                if (writeBtn) {
                    writeBtn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        closeUserPopover();
                        fetch('/api/chat/rooms/private', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ user_id: user.id }),
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                loadRooms();
                                setTimeout(function () { openRoom(data.id); }, 300);
                            });
                    });
                }

                // Stop clicks inside popover from closing it
                popover.addEventListener('click', function (e) { e.stopPropagation(); });
            });
    }

    function formatLastSeen(dateStr) {
        if (!dateStr) return { text: 'не в сети', online: false };

        var d = new Date(dateStr);
        var now = new Date();
        var diffMs = now - d;
        var diffMin = Math.floor(diffMs / 60000);

        if (diffMin < 5) return { text: 'в сети', online: true };
        if (diffMin < 60) return { text: 'был(а) ' + diffMin + ' мин. назад', online: false };

        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var dayDiff = Math.floor((today - msgDay) / 86400000);

        var time = padZero(d.getHours()) + ':' + padZero(d.getMinutes());

        if (dayDiff === 0) return { text: 'был(а) сегодня в ' + time, online: false };
        if (dayDiff === 1) return { text: 'был(а) вчера в ' + time, online: false };

        return { text: 'был(а) ' + padZero(d.getDate()) + '.' + padZero(d.getMonth() + 1) + '.' + d.getFullYear(), online: false };
    }

    // Close popover on click outside
    document.addEventListener('click', function () { closeUserPopover(); });

    // -------- Participants --------

    function loadRoomDetails(roomId) {
        var room = state.rooms.find(function (r) { return r.id === roomId; });
        var isGroupOrDept = room && room.type !== 'private';

        // Show/hide participants button
        if (els.participantsBtn) {
            if (isGroupOrDept) {
                els.participantsBtn.classList.remove('d-none');
            } else {
                els.participantsBtn.classList.add('d-none');
            }
        }

        if (!isGroupOrDept) {
            state.currentRoomDetail = null;
            return;
        }

        fetch('/api/chat/rooms/' + roomId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (detail) {
                if (roomId !== state.currentRoomId) return;
                state.currentRoomDetail = detail;
                if (els.participantsCount) {
                    els.participantsCount.textContent = detail.participants ? detail.participants.length : '';
                }
                renderParticipants(detail.participants || [], detail.created_by);
            });
    }

    function renderParticipants(participants, createdBy) {
        if (!els.participantsList) return;
        els.participantsList.innerHTML = '';

        var isCreator = createdBy && createdBy.id === CURRENT_USER_ID;

        participants.forEach(function (p) {
            var div = document.createElement('div');
            div.className = 'chat-participant-item d-flex align-items-center px-3 py-2';

            var avatarHtml;
            if (p.avatar) {
                avatarHtml = '<img src="' + escapeHtml(p.avatar) + '" class="chat-participant-avatar rounded-circle me-2" width="32" height="32" alt="">';
            } else {
                var initials = getInitials(trim(p.lastname + ' ' + p.firstname));
                avatarHtml = '<div class="chat-participant-avatar chat-participant-avatar-initials rounded-circle me-2 d-flex align-items-center justify-content-center" style="width:32px;height:32px;background:#e0e0e0;font-size:0.8rem;font-weight:600;">' + initials + '</div>';
            }

            var nameText = trim(p.lastname + ' ' + p.firstname);
            var isOwner = createdBy && createdBy.id === p.id;

            var html = avatarHtml +
                '<div class="flex-grow-1">' +
                    '<span class="chat-participant-name">' + escapeHtml(nameText) + '</span>' +
                    (isOwner ? ' <span class="chat-participant-creator badge bg-secondary ms-1" style="font-size:0.65rem;">создатель</span>' : '') +
                '</div>';

            // Show remove button if current user is creator and participant is not themselves
            if (isCreator && p.id !== CURRENT_USER_ID) {
                html += '<button class="chat-participant-remove btn btn-sm btn-outline-danger ms-2" data-user-id="' + p.id + '" title="Удалить"><i class="bi bi-x"></i></button>';
            }

            div.innerHTML = html;

            var removeBtn = div.querySelector('.chat-participant-remove');
            if (removeBtn) {
                removeBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    confirmRemoveParticipant(p.id, nameText);
                });
            }

            // Click on participant → user popover
            div.style.cursor = 'pointer';
            div.addEventListener('click', function () {
                showUserPopover(p.id, div);
            });

            els.participantsList.appendChild(div);
        });

        // Show/hide add participant button (only for room creator)
        if (els.participantsActions) {
            if (isCreator) {
                els.participantsActions.classList.remove('d-none');
            } else {
                els.participantsActions.classList.add('d-none');
            }
        }
    }

    function confirmRemoveParticipant(userId, userName) {
        Swal.fire({
            title: 'Удалить участника?',
            text: 'Удалить ' + userName + ' из чата?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Удалить',
            cancelButtonText: 'Отмена',
        }).then(function (result) {
            if (result.isConfirmed) {
                fetch('/api/chat/rooms/' + state.currentRoomId + '/participants/' + userId, {
                    method: 'DELETE',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (r) { return r.json(); })
                    .then(function () {
                        loadRoomDetails(state.currentRoomId);
                    });
            }
        });
    }

    function openAddParticipantModal() {
        // Reuse the private chat modal's org tree and user search, but with a custom confirm action
        var modalEl = document.getElementById('chatNewPrivateModal');
        if (!modalEl) return;

        // Set a flag so the confirm button knows to add participant instead of creating a room
        window.__chatAddParticipantMode = true;
        window.__chatAddParticipantRoomId = state.currentRoomId;

        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    function trim(str) {
        return (str || '').replace(/^\s+|\s+$/g, '');
    }

    // -------- Mercure --------

    function subscribeUserTopic() {
        if (!MERCURE_URL || !CURRENT_USER_ID) return;
        var url = new URL(MERCURE_URL);
        url.searchParams.append('topic', '/chat/user/' + CURRENT_USER_ID);

        state.eventSourceUser = new EventSource(url);
        state.eventSourceUser.onmessage = function (e) {
            try {
                var data = JSON.parse(e.data);
                if (data.type === 'room_updated') {
                    loadRoomsDebounced();
                }
            } catch (err) {}
        };
    }

    function subscribeRoomTopic(roomId) {
        if (state.eventSourceRoom) {
            state.eventSourceRoom.close();
            state.eventSourceRoom = null;
        }
        if (!MERCURE_URL) return;

        var url = new URL(MERCURE_URL);
        url.searchParams.append('topic', '/chat/room/' + roomId);

        state.eventSourceRoom = new EventSource(url);
        state.eventSourceRoom.onmessage = function (e) {
            try {
                var data = JSON.parse(e.data);
                if (data.type === 'new_message' && data.message) {
                    if (state.currentRoomId === roomId) {
                        var exists = state.messages.some(function (m) { return m.id === data.message.id; });
                        if (!exists) {
                            state.messages.push(data.message);
                            var room = state.rooms.find(function (r) { return r.id === roomId; });
                            var isGroup = room && room.type !== 'private';
                            appendMessageDOM(data.message, isGroup);
                            scrollToBottom();
                            if (data.message.sender && data.message.sender.id !== CURRENT_USER_ID) {
                                markAsRead(roomId);
                            }
                        }
                    }
                } else if (data.type === 'message_edited' && data.message) {
                    var editedMsg = state.messages.find(function (m) { return m.id === data.message.id; });
                    if (editedMsg) {
                        editedMsg.content = data.message.content;
                        editedMsg.updated_at = data.message.updated_at;
                    }
                    var editedBubble = els.messagesArea.querySelector('[data-message-id="' + data.message.id + '"]');
                    if (editedBubble) {
                        var textEl = editedBubble.querySelector('.chat-bubble-text');
                        if (textEl) textEl.innerHTML = escapeHtml(data.message.content);
                        var timeEl = editedBubble.querySelector('.chat-bubble-time');
                        if (timeEl) {
                            timeEl.innerHTML = formatMessageTime(data.message.created_at) +
                                ' <span class="chat-edited-label">изменено ' + formatMessageTime(data.message.updated_at) + '</span>';
                        }
                    }
                } else if (data.type === 'message_deleted' && data.message_id) {
                    var msg = state.messages.find(function (m) { return m.id === data.message_id; });
                    if (msg) {
                        msg.is_deleted = true;
                        msg.content = null;
                        msg.files = [];
                    }
                    var bubble = els.messagesArea.querySelector('[data-message-id="' + data.message_id + '"]');
                    if (bubble) {
                        var timeEl = bubble.querySelector('.chat-bubble-time');
                        var timeHtml = timeEl ? timeEl.outerHTML : '';
                        bubble.innerHTML = '<div class="chat-deleted"><em>Сообщение удалено</em></div>' + timeHtml;
                    }
                } else if (data.type === 'read') {
                    // Update read status checkmarks
                    if (data.user_id && data.user_id !== CURRENT_USER_ID) {
                        els.messagesArea.querySelectorAll('.chat-read-status').forEach(function (el) {
                            el.innerHTML = '<i class="bi bi-check2-all text-primary"></i>';
                        });
                        state.messages.forEach(function (m) { m.is_read = true; });
                    }
                }
            } catch (err) {}
        };
    }

    // -------- Private Chat Modal --------

    function initPrivateModal() {
        var orgDataContainer = document.getElementById('chat-org-tree-data');
        if (!orgDataContainer) return;

        var nodes = Array.from(orgDataContainer.querySelectorAll('.org-node')).map(function (el) {
            return { id: el.getAttribute('data-org-id'), parentId: el.getAttribute('data-parent-id') || null, name: el.getAttribute('data-name') };
        });

        var treeEl = document.getElementById('chat-private-org-tree');
        var usersPlaceholder = document.getElementById('chat-private-users-placeholder');
        var usersList = document.getElementById('chat-private-users-list');
        var selectedPlaceholder = document.getElementById('chat-private-selected-placeholder');
        var selectedOne = document.getElementById('chat-private-selected-one');
        var confirmBtn = document.getElementById('chat-private-confirm-btn');
        var searchInput = document.getElementById('chat-private-user-search');
        var searchClearBtn = document.getElementById('chat-private-search-clear');
        var usersUrlTemplate = orgDataContainer.getAttribute('data-users-url-template') || '';
        var usersSearchUrl = orgDataContainer.getAttribute('data-users-search-url') || '';

        var selectedUser = null;
        var searchDebounceTimer = null;

        function getChildren(parentId) { return nodes.filter(function (n) { return n.parentId === parentId; }); }
        function hasChildren(id) { return nodes.some(function (n) { return n.parentId === id; }); }

        function buildTree(parentId, level) {
            var children = getChildren(parentId);
            if (children.length === 0) return null;
            var container = document.createElement('div');
            if (level > 0) container.className = 'org-tree-children';
            children.forEach(function (node) {
                var row = document.createElement('div');
                row.className = 'org-tree-item';
                row.style.paddingLeft = (level * 20 + 4) + 'px';
                row.dataset.orgId = node.id;
                var hasKids = hasChildren(node.id);
                var toggle = document.createElement('span');
                toggle.className = 'org-tree-toggle';
                if (hasKids) toggle.textContent = '\u25B6';
                row.appendChild(toggle);
                var icon = document.createElement('i');
                icon.className = 'org-tree-icon bi ' + (hasKids ? 'bi-diagram-3' : 'bi-building');
                row.appendChild(icon);
                var nameSpan = document.createElement('span');
                nameSpan.textContent = node.name;
                row.appendChild(nameSpan);
                container.appendChild(row);
                var childContainer = null;
                if (hasKids) { childContainer = buildTree(node.id, level + 1); if (childContainer) container.appendChild(childContainer); }
                row.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var prev = treeEl.querySelector('.org-tree-item.selected');
                    if (prev) prev.classList.remove('selected');
                    row.classList.add('selected');
                    if (searchInput) { searchInput.value = ''; searchClearBtn.classList.add('d-none'); }
                    if (hasKids && childContainer) {
                        childContainer.classList.toggle('open');
                        toggle.classList.toggle('open');
                    }
                    loadUsers(node.id);
                });
            });
            return container;
        }

        function loadUsers(orgId) {
            usersPlaceholder.classList.remove('d-none');
            usersPlaceholder.textContent = 'Загрузка...';
            usersList.classList.add('d-none');
            usersList.innerHTML = '';
            if (!usersUrlTemplate) return;
            var url = usersUrlTemplate.replace('__ORG__', orgId);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { renderUsersList(data); })
                .catch(function () { usersPlaceholder.textContent = 'Ошибка загрузки'; });
        }

        function renderUsersList(data) {
            usersPlaceholder.classList.add('d-none');
            usersList.classList.remove('d-none');
            usersList.innerHTML = '';
            if (!data || data.length === 0) { usersList.innerHTML = '<div class="text-muted small">Пользователи не найдены</div>'; return; }
            data.forEach(function (u) {
                var label = u.profession ? (u.name + ' \u2014 ' + u.profession) : u.name;
                var item = document.createElement('div');
                item.className = 'chat-private-user-item';
                item.dataset.id = u.id;
                item.textContent = label;
                if (selectedUser && String(selectedUser.id) === String(u.id)) item.classList.add('selected');
                item.addEventListener('click', function () {
                    selectedUser = { id: u.id, name: u.name, label: label };
                    usersList.querySelectorAll('.chat-private-user-item').forEach(function (el) { el.classList.remove('selected'); });
                    item.classList.add('selected');
                    renderSelected();
                    confirmBtn.disabled = false;
                });
                usersList.appendChild(item);
            });
        }

        function renderSelected() {
            if (!selectedUser) {
                selectedPlaceholder.classList.remove('d-none');
                selectedOne.classList.add('d-none');
                selectedOne.innerHTML = '';
            } else {
                selectedPlaceholder.classList.add('d-none');
                selectedOne.classList.remove('d-none');
                selectedOne.innerHTML = '';
                var span = document.createElement('span');
                span.textContent = selectedUser.label || selectedUser.name;
                selectedOne.appendChild(span);
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'btn btn-sm btn-outline-danger';
                rm.textContent = '\u00D7';
                rm.addEventListener('click', function () {
                    selectedUser = null;
                    renderSelected();
                    confirmBtn.disabled = true;
                    usersList.querySelectorAll('.chat-private-user-item.selected').forEach(function (el) { el.classList.remove('selected'); });
                });
                selectedOne.appendChild(rm);
            }
        }

        if (treeEl) {
            var tree = buildTree(null, 0);
            if (tree) treeEl.appendChild(tree);
            else treeEl.innerHTML = '<div class="text-muted small">Нет организаций</div>';
        }

        confirmBtn.addEventListener('click', function () {
            if (!selectedUser) return;
            confirmBtn.disabled = true;

            if (window.__chatAddParticipantMode && window.__chatAddParticipantRoomId) {
                // Add participant to existing room
                var roomId = window.__chatAddParticipantRoomId;
                fetch('/api/chat/rooms/' + roomId + '/participants', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ user_id: parseInt(selectedUser.id, 10) }),
                })
                    .then(function (r) { return r.json(); })
                    .then(function () {
                        var modal = bootstrap.Modal.getInstance(document.getElementById('chatNewPrivateModal'));
                        if (modal) modal.hide();
                        loadRoomDetails(roomId);
                    })
                    .catch(function () { confirmBtn.disabled = false; });
            } else {
                // Create new private room
                fetch('/api/chat/rooms/private', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ user_id: parseInt(selectedUser.id, 10) }),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var modal = bootstrap.Modal.getInstance(document.getElementById('chatNewPrivateModal'));
                        if (modal) modal.hide();
                        loadRooms();
                        setTimeout(function () { openRoom(data.id); }, 300);
                    })
                    .catch(function () { confirmBtn.disabled = false; });
            }
        });

        var modalEl = document.getElementById('chatNewPrivateModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', function () {
                selectedUser = null;
                renderSelected();
                confirmBtn.disabled = true;
                usersPlaceholder.classList.remove('d-none');
                usersPlaceholder.textContent = 'Выберите организацию';
                usersList.classList.add('d-none');
                usersList.innerHTML = '';
                if (searchInput) { searchInput.value = ''; searchClearBtn.classList.add('d-none'); }

                // Update modal title and button text based on mode
                var modalTitle = modalEl.querySelector('.modal-title');
                if (window.__chatAddParticipantMode) {
                    if (modalTitle) modalTitle.textContent = 'Добавить участника';
                    confirmBtn.textContent = 'Добавить';
                } else {
                    if (modalTitle) modalTitle.textContent = 'Новый чат';
                    confirmBtn.textContent = 'Начать чат';
                }
            });
            modalEl.addEventListener('hidden.bs.modal', function () {
                window.__chatAddParticipantMode = false;
                window.__chatAddParticipantRoomId = null;
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var query = searchInput.value.trim();
                searchClearBtn.classList.toggle('d-none', query.length === 0);
                clearTimeout(searchDebounceTimer);
                if (query.length < 2) {
                    if (query.length === 0) { usersPlaceholder.classList.remove('d-none'); usersPlaceholder.textContent = 'Выберите организацию'; usersList.classList.add('d-none'); }
                    return;
                }
                searchDebounceTimer = setTimeout(function () {
                    usersPlaceholder.classList.remove('d-none');
                    usersPlaceholder.textContent = 'Поиск...';
                    usersList.classList.add('d-none');
                    fetch(usersSearchUrl + '?query=' + encodeURIComponent(query), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) { renderUsersList(data); })
                        .catch(function () { usersPlaceholder.textContent = 'Ошибка поиска'; });
                }, 300);
            });
            searchClearBtn.addEventListener('click', function () {
                searchInput.value = '';
                searchClearBtn.classList.add('d-none');
                usersPlaceholder.classList.remove('d-none');
                usersPlaceholder.textContent = 'Выберите организацию';
                usersList.classList.add('d-none');
            });
        }
    }

    // -------- Group Chat Modal --------

    function initGroupModal() {
        var orgDataContainer = document.getElementById('chat-org-tree-data');
        if (!orgDataContainer) return;

        var confirmBtn = document.getElementById('chat-group-confirm-btn');
        if (!confirmBtn) return;

        var nodes = Array.from(orgDataContainer.querySelectorAll('.org-node')).map(function (el) {
            return { id: el.getAttribute('data-org-id'), parentId: el.getAttribute('data-parent-id') || null, name: el.getAttribute('data-name') };
        });

        var treeEl = document.getElementById('chat-group-org-tree');
        var usersPlaceholder = document.getElementById('chat-group-users-placeholder');
        var usersList = document.getElementById('chat-group-users-list');
        var selectedPlaceholder = document.getElementById('chat-group-selected-placeholder');
        var selectedList = document.getElementById('chat-group-selected-list');
        var groupNameInput = document.getElementById('chat-group-name');
        var searchInput = document.getElementById('chat-group-user-search');
        var searchClearBtn = document.getElementById('chat-group-search-clear');
        var usersUrlTemplate = orgDataContainer.getAttribute('data-users-url-template') || '';
        var usersSearchUrl = orgDataContainer.getAttribute('data-users-search-url') || '';

        var selectedUsers = [];
        var searchDebounceTimer = null;

        function getChildren(parentId) { return nodes.filter(function (n) { return n.parentId === parentId; }); }
        function hasChildren(id) { return nodes.some(function (n) { return n.parentId === id; }); }

        function buildTree(parentId, level) {
            var children = getChildren(parentId);
            if (children.length === 0) return null;
            var container = document.createElement('div');
            if (level > 0) container.className = 'org-tree-children';
            children.forEach(function (node) {
                var row = document.createElement('div');
                row.className = 'org-tree-item';
                row.style.paddingLeft = (level * 20 + 4) + 'px';
                var hasKids = hasChildren(node.id);
                var toggle = document.createElement('span');
                toggle.className = 'org-tree-toggle';
                if (hasKids) toggle.textContent = '\u25B6';
                row.appendChild(toggle);
                var icon = document.createElement('i');
                icon.className = 'org-tree-icon bi ' + (hasKids ? 'bi-diagram-3' : 'bi-building');
                row.appendChild(icon);
                var nameSpan = document.createElement('span');
                nameSpan.textContent = node.name;
                row.appendChild(nameSpan);
                container.appendChild(row);
                var childContainer = null;
                if (hasKids) { childContainer = buildTree(node.id, level + 1); if (childContainer) container.appendChild(childContainer); }
                row.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var prev = treeEl.querySelector('.org-tree-item.selected');
                    if (prev) prev.classList.remove('selected');
                    row.classList.add('selected');
                    if (searchInput) { searchInput.value = ''; searchClearBtn.classList.add('d-none'); }
                    if (hasKids && childContainer) {
                        childContainer.classList.toggle('open');
                        toggle.classList.toggle('open');
                    }
                    loadGroupUsers(node.id);
                });
            });
            return container;
        }

        function loadGroupUsers(orgId) {
            usersPlaceholder.classList.remove('d-none');
            usersPlaceholder.textContent = 'Загрузка...';
            usersList.classList.add('d-none');
            if (!usersUrlTemplate) return;
            var url = usersUrlTemplate.replace('__ORG__', orgId);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { renderGroupUsersList(data); })
                .catch(function () { usersPlaceholder.textContent = 'Ошибка загрузки'; });
        }

        function renderGroupUsersList(data) {
            usersPlaceholder.classList.add('d-none');
            usersList.classList.remove('d-none');
            usersList.innerHTML = '';
            if (!data || data.length === 0) { usersList.innerHTML = '<div class="text-muted small">Пользователи не найдены</div>'; return; }
            data.forEach(function (u) {
                var label = u.profession ? (u.name + ' \u2014 ' + u.profession) : u.name;
                var item = document.createElement('div');
                item.className = 'chat-group-user-item';
                item.dataset.id = u.id;

                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'form-check-input me-2';
                cb.checked = selectedUsers.some(function (su) { return String(su.id) === String(u.id); });

                var span = document.createElement('span');
                span.textContent = label;

                item.appendChild(cb);
                item.appendChild(span);

                item.addEventListener('click', function (e) {
                    if (e.target === cb) return;
                    cb.checked = !cb.checked;
                    toggleGroupUser(u, cb.checked);
                });
                cb.addEventListener('change', function () { toggleGroupUser(u, cb.checked); });

                usersList.appendChild(item);
            });
        }

        function toggleGroupUser(u, checked) {
            if (checked) {
                if (!selectedUsers.some(function (su) { return String(su.id) === String(u.id); })) {
                    selectedUsers.push({ id: u.id, name: u.name });
                }
            } else {
                selectedUsers = selectedUsers.filter(function (su) { return String(su.id) !== String(u.id); });
            }
            renderGroupSelected();
            updateGroupConfirmBtn();
        }

        function renderGroupSelected() {
            if (selectedUsers.length === 0) {
                selectedPlaceholder.classList.remove('d-none');
                selectedList.classList.add('d-none');
                selectedList.innerHTML = '';
                return;
            }
            selectedPlaceholder.classList.add('d-none');
            selectedList.classList.remove('d-none');
            selectedList.innerHTML = '';
            selectedUsers.forEach(function (u) {
                var tag = document.createElement('span');
                tag.className = 'chat-group-selected-tag';
                tag.innerHTML = escapeHtml(u.name) + ' <button class="btn-remove" type="button">&times;</button>';
                tag.querySelector('.btn-remove').addEventListener('click', function () {
                    selectedUsers = selectedUsers.filter(function (su) { return String(su.id) !== String(u.id); });
                    renderGroupSelected();
                    updateGroupConfirmBtn();
                    // Uncheck in list
                    usersList.querySelectorAll('.chat-group-user-item').forEach(function (el) {
                        if (el.dataset.id === String(u.id)) {
                            var cb = el.querySelector('input[type=checkbox]');
                            if (cb) cb.checked = false;
                        }
                    });
                });
                selectedList.appendChild(tag);
            });
        }

        function updateGroupConfirmBtn() {
            var name = (groupNameInput.value || '').trim();
            confirmBtn.disabled = !name || selectedUsers.length === 0;
        }

        if (treeEl) {
            var tree = buildTree(null, 0);
            if (tree) treeEl.appendChild(tree);
        }

        if (groupNameInput) groupNameInput.addEventListener('input', updateGroupConfirmBtn);

        confirmBtn.addEventListener('click', function () {
            var name = (groupNameInput.value || '').trim();
            if (!name || selectedUsers.length === 0) return;
            confirmBtn.disabled = true;
            var userIds = selectedUsers.map(function (u) { return parseInt(u.id, 10); });
            fetch('/api/chat/rooms/group', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ name: name, user_ids: userIds }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var modal = bootstrap.Modal.getInstance(document.getElementById('chatNewGroupModal'));
                    if (modal) modal.hide();
                    loadRooms();
                    setTimeout(function () { openRoom(data.id); }, 300);
                })
                .catch(function () { confirmBtn.disabled = false; });
        });

        var modalEl = document.getElementById('chatNewGroupModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', function () {
                selectedUsers = [];
                renderGroupSelected();
                groupNameInput.value = '';
                updateGroupConfirmBtn();
                usersPlaceholder.classList.remove('d-none');
                usersPlaceholder.textContent = 'Выберите организацию';
                usersList.classList.add('d-none');
                usersList.innerHTML = '';
                if (searchInput) { searchInput.value = ''; searchClearBtn.classList.add('d-none'); }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var query = searchInput.value.trim();
                searchClearBtn.classList.toggle('d-none', query.length === 0);
                clearTimeout(searchDebounceTimer);
                if (query.length < 2) {
                    if (query.length === 0) { usersPlaceholder.classList.remove('d-none'); usersPlaceholder.textContent = 'Выберите организацию'; usersList.classList.add('d-none'); }
                    return;
                }
                searchDebounceTimer = setTimeout(function () {
                    usersPlaceholder.classList.remove('d-none');
                    usersPlaceholder.textContent = 'Поиск...';
                    usersList.classList.add('d-none');
                    fetch(usersSearchUrl + '?query=' + encodeURIComponent(query), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) { renderGroupUsersList(data); })
                        .catch(function () { usersPlaceholder.textContent = 'Ошибка поиска'; });
                }, 300);
            });
            searchClearBtn.addEventListener('click', function () {
                searchInput.value = '';
                searchClearBtn.classList.add('d-none');
                usersPlaceholder.classList.remove('d-none');
                usersPlaceholder.textContent = 'Выберите организацию';
                usersList.classList.add('d-none');
            });
        }
    }

    // -------- Helpers --------

    function getInitials(name) {
        if (!name) return '?';
        var parts = name.split(' ');
        var init = '';
        for (var i = 0; i < Math.min(parts.length, 2); i++) {
            if (parts[i]) init += parts[i][0].toUpperCase();
        }
        return init || '?';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        var now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return padZero(d.getHours()) + ':' + padZero(d.getMinutes());
        }
        var yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        if (d.toDateString() === yesterday.toDateString()) return 'Вчера';
        return padZero(d.getDate()) + '.' + padZero(d.getMonth() + 1);
    }

    function formatMessageTime(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        return padZero(d.getHours()) + ':' + padZero(d.getMinutes());
    }

    function padZero(n) { return n < 10 ? '0' + n : '' + n; }

    function scrollToBottom() {
        setTimeout(function () {
            els.messagesArea.scrollTop = els.messagesArea.scrollHeight;
        }, 50);
    }

    // Init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
