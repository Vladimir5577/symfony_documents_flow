/**
 * Kanban Board Application
 */
var KanbanApp = (function () {
    "use strict";

    var config = {};
    var currentCardId = null;
    var currentCardData = null;
    var commentPollTimer = null;
    var knownCommentIds = new Set();

    // ── Helpers ──

    function escapeHtml(str) {
        var div = document.createElement("div");
        div.textContent = str || "";
        return div.innerHTML;
    }

    function showToast(message, type) {
        type = type || "danger";
        var container = document.getElementById("toast-container");
        if (!container) return;
        var toast = document.createElement("div");
        toast.className = "alert alert-" + type + " alert-dismissible fade show mb-2";
        toast.setAttribute("role", "alert");
        toast.innerHTML = escapeHtml(message) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        container.appendChild(toast);
        setTimeout(function () {
            if (toast.parentNode) toast.remove();
        }, 5000);
    }

    function apiFetch(url, opts) {
        opts = opts || {};
        var headers = opts.headers || {};
        if (!(opts.body instanceof FormData)) {
            headers["Content-Type"] = "application/json";
        }
        headers["X-Requested-With"] = "XMLHttpRequest";
        opts.headers = headers;
        return fetch(url, opts).then(function (res) {
            if (res.status === 204) return null;
            if (!res.ok) {
                return res.json().then(function (data) {
                    throw new Error(data.error || "Ошибка сервера");
                });
            }
            return res.json();
        });
    }

    function formatDate(isoStr) {
        if (!isoStr) return "—";
        var d = new Date(isoStr);
        return d.toLocaleDateString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
    }

    function formatShortDate(isoStr) {
        if (!isoStr) return "";
        var d = new Date(isoStr);
        return d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" }) +
            ", " + d.toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" });
    }

    // ── Dragula Setup ──

    function initDragula() {
        var columns = document.querySelectorAll(".kanban-column-body");
        if (!columns.length) return;

        var containers = Array.from(columns);
        var drake = dragula(containers, {
            moves: function (el) {
                return el.classList.contains("kanban-card") && config.canEdit;
            }
        });

        drake.on("drop", function (el, target, source) {
            if (!target) return;
            var cardId = el.getAttribute("data-card-id");
            var targetColumnId = target.getAttribute("data-column-id");

            // Calculate new position
            var cards = Array.from(target.querySelectorAll(".kanban-card"));
            var idx = cards.indexOf(el);
            var position;

            if (cards.length <= 1) {
                position = 1.0;
            } else if (idx === 0) {
                var nextPos = parseFloat(cards[1].getAttribute("data-position") || "1");
                position = nextPos / 2;
            } else if (idx === cards.length - 1) {
                var prevPos = parseFloat(cards[idx - 1].getAttribute("data-position") || "0");
                position = prevPos + 1.0;
            } else {
                var prevP = parseFloat(cards[idx - 1].getAttribute("data-position") || "0");
                var nextP = parseFloat(cards[idx + 1].getAttribute("data-position") || "0");
                position = (prevP + nextP) / 2;
            }

            el.setAttribute("data-position", position);

            apiFetch("/api/kanban/cards/" + cardId + "/move", {
                method: "POST",
                body: JSON.stringify({
                    column_id: targetColumnId,
                    position: position,
                    prev_updated_at: el.getAttribute("data-updated-at") || null
                })
            }).then(function (data) {
                if (data) {
                    el.setAttribute("data-updated-at", data.updatedAt || "");
                    el.setAttribute("data-position", data.position);
                }
                updateColumnCounts();
            }).catch(function (err) {
                showToast(err.message);
                // Revert: move card back
                source.appendChild(el);
                updateColumnCounts();
            });
        });
    }

    function updateColumnCounts() {
        document.querySelectorAll(".kanban-column").forEach(function (col) {
            var count = col.querySelectorAll(".kanban-card").length;
            var badge = col.querySelector(".column-count");
            if (badge) badge.textContent = count;
        });
    }

    // ── Card CRUD ──

    function initCardCreation() {
        document.querySelectorAll(".kanban-add-btn").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                var column = btn.closest(".kanban-column");
                var body = column.querySelector(".kanban-column-body");
                var columnId = body.getAttribute("data-column-id");

                // Check if input already exists
                var existing = body.querySelector(".kanban-new-card-input");
                if (existing) {
                    existing.focus();
                    return;
                }

                var wrap = document.createElement("div");
                wrap.className = "kanban-new-task-wrap";
                wrap.innerHTML = '<input type="text" class="form-control form-control-sm kanban-new-card-input" placeholder="Название карточки..." autofocus>';
                body.insertBefore(wrap, body.firstChild);

                var input = wrap.querySelector("input");
                input.focus();

                function commit() {
                    var title = (input.value || "").trim();
                    wrap.remove();
                    if (!title) return;

                    apiFetch("/api/kanban/cards", {
                        method: "POST",
                        body: JSON.stringify({ column_id: columnId, title: title })
                    }).then(function (data) {
                        var card = createCardElement(data);
                        body.appendChild(card);
                        updateColumnCounts();
                    }).catch(function (err) {
                        showToast(err.message);
                    });
                }

                input.addEventListener("keydown", function (e) {
                    if (e.key === "Enter") { e.preventDefault(); commit(); }
                    if (e.key === "Escape") { wrap.remove(); }
                });
                input.addEventListener("blur", commit);
            });
        });
    }

    function createCardElement(data) {
        var card = document.createElement("div");
        card.className = "kanban-card";
        card.setAttribute("data-card-id", data.id);
        card.setAttribute("data-position", data.position || "1");
        card.setAttribute("data-updated-at", data.updatedAt || "");

        var html = '<div class="kanban-card-title">' + escapeHtml(data.title) + '</div>';
        if (data.description) {
            var desc = data.description.length > 80 ? data.description.substring(0, 80) + '...' : data.description;
            html += '<div class="kanban-card-description">' + escapeHtml(desc) + '</div>';
        }
        html += '<div class="kanban-card-meta">';
        if (data.priority) {
            html += '<span class="badge bg-light-' + escapeHtml(data.priorityColor || 'secondary') + ' text-' + escapeHtml(data.priorityColor || 'secondary') + '">' + escapeHtml(data.priorityLabel || '') + '</span>';
        }
        html += '</div>';
        card.innerHTML = html;

        return card;
    }

    // ── Column Creation ──

    function initColumnCreation() {
        var btn = document.getElementById("add-column-btn");
        if (!btn) return;

        btn.addEventListener("click", function () {
            var title = prompt("Название колонки:");
            if (!title || !title.trim()) return;

            apiFetch("/api/kanban/boards/" + config.boardId + "/columns", {
                method: "POST",
                body: JSON.stringify({ title: title.trim(), headerColor: "bg-primary" })
            }).then(function () {
                window.location.reload();
            }).catch(function (err) {
                showToast(err.message);
            });
        });
    }

    // ── Card Sidebar ──

    function initCardSidebar() {
        var sidebar = document.getElementById("task-sidebar");
        if (!sidebar) return;

        // Open sidebar on card click
        var board = document.getElementById("kanban-board");
        if (board) {
            board.addEventListener("click", function (e) {
                var card = e.target.closest(".kanban-card");
                if (!card || e.target.closest(".kanban-add-btn") || e.target.closest(".kanban-new-card-input")) return;
                var cardId = card.getAttribute("data-card-id");
                if (cardId) openSidebar(cardId);
            });
        }

        // Close sidebar
        document.getElementById("task-sidebar-close").addEventListener("click", closeSidebar);
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && sidebar.getAttribute("aria-hidden") === "false") closeSidebar();
        });
        document.addEventListener("click", function (e) {
            if (sidebar.getAttribute("aria-hidden") !== "false") return;
            if (e.target.closest(".task-sidebar-panel")) return;
            if (e.target.closest(".kanban-card")) return;
            if (e.target.closest(".kanban-add-btn")) return;
            closeSidebar();
        });

        // Tab switching
        document.querySelectorAll(".task-sidebar-tab").forEach(function (tab) {
            tab.addEventListener("click", function () {
                var targetId = "pane-" + this.getAttribute("data-tab");
                document.querySelectorAll(".task-sidebar-tab").forEach(function (t) {
                    t.classList.remove("active");
                    t.setAttribute("aria-selected", "false");
                });
                document.querySelectorAll(".task-sidebar-tab-pane").forEach(function (pane) {
                    pane.classList.remove("active");
                    pane.hidden = true;
                });
                this.classList.add("active");
                this.setAttribute("aria-selected", "true");
                var pane = document.getElementById(targetId);
                if (pane) {
                    pane.classList.add("active");
                    pane.hidden = false;
                    if (targetId === "pane-chat") scrollChatToBottom();
                }
            });
        });

        // Delete button
        var deleteBtn = document.getElementById("task-sidebar-delete");
        if (deleteBtn) {
            deleteBtn.addEventListener("click", function () {
                if (!currentCardId) return;
                if (!confirm("Удалить карточку?")) return;

                apiFetch("/api/kanban/cards/" + currentCardId, { method: "DELETE" })
                    .then(function () {
                        var cardEl = document.querySelector('[data-card-id="' + currentCardId + '"]');
                        if (cardEl) cardEl.remove();
                        closeSidebar();
                        updateColumnCounts();
                    })
                    .catch(function (err) { showToast(err.message); });
            });
        }

        initChat();
        initDescription();
        initChecklist();
        initAttachments();
        initDueAt();
    }

    function openSidebar(cardId) {
        currentCardId = cardId;
        var sidebar = document.getElementById("task-sidebar");
        sidebar.setAttribute("aria-hidden", "false");

        // Load card data
        apiFetch("/api/kanban/cards/" + cardId).then(function (data) {
            currentCardData = data;
            document.getElementById("task-sidebar-title").textContent = data.title;
            document.getElementById("task-sidebar-id").textContent = data.columnTitle || "";
            document.getElementById("task-sidebar-description").textContent = data.description || "";

            // Info tab
            document.getElementById("task-info-priority").textContent = data.priorityLabel || "—";
            document.getElementById("task-created-at").textContent = formatDate(data.createdAt);
            document.getElementById("task-updated-at").textContent = formatDate(data.updatedAt);

            var dueInput = document.getElementById("task-due-at");
            if (dueInput && data.dueAt) {
                dueInput.value = data.dueAt.substring(0, 16);
            } else if (dueInput) {
                dueInput.value = "";
            }

            // Column select
            var colSelect = document.getElementById("task-column-select");
            if (colSelect) colSelect.value = data.columnId;

            // Priority select
            var priSelect = document.getElementById("task-priority-select");
            if (priSelect) priSelect.value = data.priority || "";

            // Comments
            renderComments(data.comments || []);
            startCommentPolling(cardId);

            // Checklist
            renderChecklist(data.checklist || []);

            // Attachments
            renderAttachments(data.attachments || []);

            // Reset to chat tab
            scrollChatToBottom();
        }).catch(function (err) {
            showToast(err.message);
        });
    }

    function closeSidebar() {
        var sidebar = document.getElementById("task-sidebar");
        sidebar.setAttribute("aria-hidden", "true");
        currentCardId = null;
        currentCardData = null;
        stopCommentPolling();

        // Reset description edit
        var viewWrap = document.getElementById("task-description-view");
        var editWrap = document.getElementById("task-description-edit");
        if (viewWrap) viewWrap.hidden = false;
        if (editWrap) editWrap.hidden = true;
    }

    // ── Chat ──

    function scrollChatToBottom() {
        var msgs = document.getElementById("chat-messages");
        if (msgs) msgs.scrollTop = msgs.scrollHeight;
    }

    function renderComments(comments) {
        var container = document.getElementById("chat-messages");
        if (!container) return;
        container.innerHTML = "";
        knownCommentIds.clear();

        if (!comments.length) {
            container.innerHTML = '<div class="task-chat-msg task-chat-msg-placeholder"><div class="task-chat-msg-bubble"><div class="task-chat-msg-text">Сообщений пока нет.</div></div></div>';
            return;
        }

        comments.forEach(function (c) {
            knownCommentIds.add(c.id);
            appendCommentBubble(c);
        });
        scrollChatToBottom();
    }

    function appendCommentBubble(c) {
        var container = document.getElementById("chat-messages");
        if (!container) return;

        // Remove placeholder
        var ph = container.querySelector(".task-chat-msg-placeholder");
        if (ph) ph.remove();

        var msg = document.createElement("div");
        msg.className = "task-chat-msg";
        msg.setAttribute("data-comment-id", c.id);
        msg.innerHTML =
            '<div class="task-chat-msg-bubble">' +
            '<div class="task-chat-msg-text">' + escapeHtml(c.body) + '</div>' +
            '<div class="task-chat-msg-meta"><span class="task-chat-msg-time">' +
            escapeHtml(c.authorName) + ' · ' + formatShortDate(c.createdAt) +
            '</span></div></div>';
        container.appendChild(msg);
    }

    function initChat() {
        var sendBtn = document.getElementById("task-chat-send");
        var input = document.getElementById("task-chat-input");
        if (!sendBtn || !input) return;

        function sendMessage() {
            var body = (input.value || "").trim();
            if (!body || !currentCardId) return;
            input.value = "";

            // Optimistic UI
            var tempId = "temp-" + Date.now();
            var tempComment = {
                id: tempId,
                body: body,
                authorName: config.currentUserName,
                createdAt: new Date().toISOString()
            };
            knownCommentIds.add(tempId);
            appendCommentBubble(tempComment);
            scrollChatToBottom();

            apiFetch("/api/kanban/cards/" + currentCardId + "/comments", {
                method: "POST",
                body: JSON.stringify({ body: body })
            }).then(function (data) {
                // Replace temp with real
                knownCommentIds.delete(tempId);
                knownCommentIds.add(data.id);
                var tempEl = document.querySelector('[data-comment-id="' + tempId + '"]');
                if (tempEl) tempEl.setAttribute("data-comment-id", data.id);
            }).catch(function (err) {
                // Rollback
                knownCommentIds.delete(tempId);
                var tempEl = document.querySelector('[data-comment-id="' + tempId + '"]');
                if (tempEl) tempEl.remove();
                showToast(err.message);
            });
        }

        sendBtn.addEventListener("click", sendMessage);
        input.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }

    function startCommentPolling(cardId) {
        stopCommentPolling();
        commentPollTimer = setInterval(function () {
            if (currentCardId !== cardId) { stopCommentPolling(); return; }

            apiFetch("/api/kanban/cards/" + cardId + "/comments")
                .then(function (comments) {
                    if (!comments) return;
                    comments.forEach(function (c) {
                        if (!knownCommentIds.has(c.id)) {
                            knownCommentIds.add(c.id);
                            appendCommentBubble(c);
                            scrollChatToBottom();
                        }
                    });
                })
                .catch(function () { /* silent */ });
        }, 5000);
    }

    function stopCommentPolling() {
        if (commentPollTimer) {
            clearInterval(commentPollTimer);
            commentPollTimer = null;
        }
    }

    // ── Description ──

    function initDescription() {
        var editBtn = document.getElementById("task-desc-edit-btn");
        var saveBtn = document.getElementById("task-desc-save-btn");
        var cancelBtn = document.getElementById("task-desc-cancel-btn");
        var viewWrap = document.getElementById("task-description-view");
        var editWrap = document.getElementById("task-description-edit");
        var textarea = document.getElementById("task-description-textarea");
        var descEl = document.getElementById("task-sidebar-description");

        if (editBtn) {
            editBtn.addEventListener("click", function () {
                textarea.value = (descEl.textContent || "").trim();
                viewWrap.hidden = true;
                editWrap.hidden = false;
                textarea.focus();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener("click", function () {
                editWrap.hidden = true;
                viewWrap.hidden = false;
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener("click", function () {
                if (!currentCardId) return;

                var payload = {
                    description: textarea.value
                };

                var colSelect = document.getElementById("task-column-select");
                var priSelect = document.getElementById("task-priority-select");

                if (priSelect) {
                    payload.priority = priSelect.value ? parseInt(priSelect.value) : null;
                }

                apiFetch("/api/kanban/cards/" + currentCardId, {
                    method: "PATCH",
                    body: JSON.stringify(payload)
                }).then(function (data) {
                    descEl.textContent = data.description || "";
                    editWrap.hidden = true;
                    viewWrap.hidden = false;

                    // Move card if column changed
                    if (colSelect && currentCardData && colSelect.value !== currentCardData.columnId) {
                        return apiFetch("/api/kanban/cards/" + currentCardId + "/move", {
                            method: "POST",
                            body: JSON.stringify({
                                column_id: colSelect.value,
                                position: 9999
                            })
                        }).then(function () {
                            window.location.reload();
                        });
                    }

                    // Update card on board
                    var cardEl = document.querySelector('[data-card-id="' + currentCardId + '"]');
                    if (cardEl) {
                        var titleEl = cardEl.querySelector(".kanban-card-title");
                        if (titleEl) titleEl.textContent = data.title;
                    }

                    // Update info tab
                    document.getElementById("task-info-priority").textContent = data.priorityLabel || "—";
                }).catch(function (err) { showToast(err.message); });
            });
        }
    }

    // ── Checklist ──

    function renderChecklist(items) {
        var container = document.getElementById("checklist-items");
        if (!container) return;
        container.innerHTML = "";

        if (!items.length) {
            container.innerHTML = '<div class="task-subtasks-empty small">Подзадач пока нет.</div>';
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement("div");
            row.className = "form-check d-flex align-items-center gap-2 mb-2";
            row.setAttribute("data-checklist-id", item.id);
            row.innerHTML =
                '<input class="form-check-input" type="checkbox"' + (item.isCompleted ? ' checked' : '') + '>' +
                '<label class="form-check-label flex-grow-1' + (item.isCompleted ? ' text-decoration-line-through text-muted' : '') + '">' + escapeHtml(item.title) + '</label>' +
                (config.canEdit ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 checklist-delete-btn"><i class="bi bi-x-lg"></i></button>' : '');
            container.appendChild(row);

            // Toggle
            var checkbox = row.querySelector("input[type=checkbox]");
            if (checkbox && config.canEdit) {
                checkbox.addEventListener("change", function () {
                    apiFetch("/api/kanban/cards/" + currentCardId + "/checklist/" + item.id, {
                        method: "PATCH",
                        body: JSON.stringify({ isCompleted: checkbox.checked })
                    }).catch(function (err) {
                        checkbox.checked = !checkbox.checked;
                        showToast(err.message);
                    });
                    var label = row.querySelector("label");
                    if (label) {
                        label.classList.toggle("text-decoration-line-through", checkbox.checked);
                        label.classList.toggle("text-muted", checkbox.checked);
                    }
                });
            }

            // Delete
            var delBtn = row.querySelector(".checklist-delete-btn");
            if (delBtn) {
                delBtn.addEventListener("click", function () {
                    apiFetch("/api/kanban/cards/" + currentCardId + "/checklist/" + item.id, {
                        method: "DELETE"
                    }).then(function () {
                        row.remove();
                    }).catch(function (err) { showToast(err.message); });
                });
            }
        });
    }

    function initChecklist() {
        var addBtn = document.getElementById("add-checklist-btn");
        if (!addBtn) return;

        addBtn.addEventListener("click", function () {
            var container = document.getElementById("checklist-items");
            if (!container || !currentCardId) return;

            var existing = container.querySelector(".checklist-new-input");
            if (existing) { existing.focus(); return; }

            var wrap = document.createElement("div");
            wrap.className = "mb-2";
            wrap.innerHTML = '<input type="text" class="form-control form-control-sm checklist-new-input" placeholder="Название подзадачи...">';
            container.insertBefore(wrap, container.firstChild);

            var input = wrap.querySelector("input");
            input.focus();

            function commit() {
                var title = (input.value || "").trim();
                wrap.remove();
                if (!title) return;

                apiFetch("/api/kanban/cards/" + currentCardId + "/checklist", {
                    method: "POST",
                    body: JSON.stringify({ title: title })
                }).then(function () {
                    // Reload card
                    openSidebar(currentCardId);
                }).catch(function (err) { showToast(err.message); });
            }

            input.addEventListener("keydown", function (e) {
                if (e.key === "Enter") { e.preventDefault(); commit(); }
                if (e.key === "Escape") { wrap.remove(); }
            });
            input.addEventListener("blur", commit);
        });
    }

    // ── Attachments ──

    function renderAttachments(attachments) {
        var container = document.getElementById("attachments-list");
        if (!container) return;
        container.innerHTML = "";

        if (!attachments.length) {
            container.innerHTML = '<div class="small text-muted">Нет вложений.</div>';
            return;
        }

        attachments.forEach(function (att) {
            var item = document.createElement("div");
            item.className = "attachment-item";
            var sizeKb = Math.round(att.sizeBytes / 1024);
            item.innerHTML =
                '<div>' +
                '<a href="/api/kanban/cards/' + currentCardId + '/attachments/' + att.id + '/download" class="text-decoration-none">' +
                '<i class="bi bi-paperclip me-1"></i>' + escapeHtml(att.filename) +
                '</a>' +
                '<small class="text-muted ms-2">' + sizeKb + ' KB</small>' +
                '</div>' +
                (config.canEdit ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 att-delete-btn" data-att-id="' + att.id + '"><i class="bi bi-trash"></i></button>' : '');
            container.appendChild(item);

            var delBtn = item.querySelector(".att-delete-btn");
            if (delBtn) {
                delBtn.addEventListener("click", function () {
                    if (!confirm("Удалить вложение?")) return;
                    apiFetch("/api/kanban/cards/" + currentCardId + "/attachments/" + att.id, {
                        method: "DELETE"
                    }).then(function () {
                        item.remove();
                    }).catch(function (err) { showToast(err.message); });
                });
            }
        });
    }

    function initAttachments() {
        var input = document.getElementById("attachment-input");
        if (!input) return;

        input.addEventListener("change", function () {
            if (!input.files.length || !currentCardId) return;

            var file = input.files[0];
            var ext = file.name.split('.').pop().toLowerCase();
            var allowed = ["pdf", "png", "jpg", "jpeg", "webp", "docx", "xlsx"];
            if (allowed.indexOf(ext) === -1) {
                showToast("Тип файла ." + ext + " не поддерживается.");
                input.value = "";
                return;
            }

            var formData = new FormData();
            formData.append("file", file);

            apiFetch("/api/kanban/cards/" + currentCardId + "/attachments", {
                method: "POST",
                body: formData
            }).then(function () {
                input.value = "";
                openSidebar(currentCardId);
            }).catch(function (err) {
                showToast(err.message);
                input.value = "";
            });
        });
    }

    // ── Due Date ──

    function initDueAt() {
        var input = document.getElementById("task-due-at");
        if (!input || !config.canEdit) return;

        input.addEventListener("change", function () {
            if (!currentCardId) return;

            apiFetch("/api/kanban/cards/" + currentCardId, {
                method: "PATCH",
                body: JSON.stringify({
                    dueAt: input.value ? new Date(input.value).toISOString() : null
                })
            }).catch(function (err) { showToast(err.message); });
        });
    }

    // ── Init ──

    function init(cfg) {
        config = cfg;
        initDragula();
        initCardCreation();
        initColumnCreation();
        initCardSidebar();
    }

    return { init: init };
})();
