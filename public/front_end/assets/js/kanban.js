/**
 * Kanban Board Application — v28.2 (auto-save, assignees, labels, title, save-status)
 */
var KanbanApp = (function () {
    "use strict";

    var config = {};
    var currentCardId = null;
    var currentCardData = null;
    var commentPollTimer = null;
    var knownCommentIds = new Set();
    var currentAssigneeIds = [];
    var saveStatusTimer = null;
    var currentSubtaskIdForAssign = null;

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

    // ── Debounce ──

    function makeDebounce(fn, ms) {
        var timer;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, ms);
        };
    }

    // ── Save Status ──

    function setSaveStatus(status) {
        var badge = document.getElementById("save-status");
        if (!badge) return;
        clearTimeout(saveStatusTimer);
        badge.style.display = "";
        if (status === "saving") {
            badge.textContent = "Сохраняю…";
            badge.className = "badge bg-secondary";
        } else if (status === "saved") {
            badge.textContent = "Сохранено";
            badge.className = "badge bg-success";
            saveStatusTimer = setTimeout(function () { badge.style.display = "none"; }, 3000);
        } else if (status === "error") {
            badge.textContent = "Ошибка";
            badge.className = "badge bg-danger";
        }
    }

    // ── Auto-save field ──

    function autoSaveField(fieldName, getValue) {
        if (!currentCardId) return;
        setSaveStatus("saving");
        var payload = {};
        payload[fieldName] = getValue();
        apiFetch("/api/kanban/cards/" + currentCardId, {
            method: "PATCH",
            body: JSON.stringify(payload)
        }).then(function (data) {
            setSaveStatus("saved");
            if (currentCardData && data) {
                if (data.title !== undefined) currentCardData.title = data.title;
                if (data.description !== undefined) currentCardData.description = data.description;
                if (data.priority !== undefined) {
                    currentCardData.priority = data.priority;
                    currentCardData.priorityLabel = data.priorityLabel;
                    currentCardData.priorityColor = data.priorityColor;
                }
                if (data.dueDate !== undefined) currentCardData.dueDate = data.dueDate;
                if (data.borderColor !== undefined) currentCardData.borderColor = data.borderColor;
                if (data.updatedAt) currentCardData.updatedAt = data.updatedAt;
            }
            updateCardOnBoard(currentCardData || data);
        }).catch(function (err) {
            setSaveStatus("error");
            showToast(err.message);
        });
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

    // ── Card face builder ──

    function buildCardMeta(data) {
        var html = '<div class="kanban-card-meta">';
        if (data.priority && data.priorityLabel) {
            var color = data.priorityColor || "secondary";
            html += '<span class="badge bg-light-' + escapeHtml(color) + ' text-' + escapeHtml(color) + '">' + escapeHtml(data.priorityLabel) + '</span>';
        }
        if (data.labels && data.labels.length) {
            data.labels.forEach(function (lbl) {
                html += '<span class="badge ' + escapeHtml(lbl.color) + '">' + escapeHtml(lbl.name) + '</span>';
            });
        }
        if (data.dueDate) {
            var d = new Date(data.dueDate);
            var day = String(d.getDate()).padStart(2, "0");
            var month = String(d.getMonth() + 1).padStart(2, "0");
            html += '<span class="text-muted"><i class="bi bi-calendar3"></i> ' + day + "." + month + "</span>";
        }
        if (data.subtasks && data.subtasks.length) {
            var total = data.subtasks.length;
            var done = data.subtasks.filter(function (ci) { return ci.isCompleted; }).length;
            html += '<span class="text-muted"><i class="bi bi-check2-square"></i> ' + done + "/" + total + "</span>";
        }
        if (data.comments && data.comments.length) {
            html += '<span class="text-muted"><i class="bi bi-chat-dots"></i> ' + data.comments.length + "</span>";
        }
        if (data.assignees && data.assignees.length) {
            html += '<span class="kanban-card-assignees">';
            var show = data.assignees.slice(0, 3);
            show.forEach(function (a) {
                var displayName = [a.lastname, a.firstname].filter(Boolean).join(" ") || a.name || "—";
                var initials = (a.firstname && a.lastname)
                    ? (a.firstname.charAt(0) + a.lastname.charAt(0)).toUpperCase()
                    : ((a.name || "").trim().split(/\s+/).map(function (p) { return p.charAt(0); }).join("").slice(0, 2).toUpperCase() || "?");
                html += '<span class="kanban-card-assignee-item">';
                html += '<span class="kanban-card-assignee-avatar" title="' + escapeHtml(displayName) + '">' + escapeHtml(initials) + "</span>";
                html += '<span class="kanban-card-assignee-name">' + escapeHtml(displayName) + "</span>";
                html += "</span>";
            });
            if (data.assignees.length > 3) {
                html += '<span class="kanban-card-assignee-more">+' + (data.assignees.length - 3) + "</span>";
            }
            html += "</span>";
        }
        html += "</div>";
        return html;
    }

    // ── Card CRUD ──

    function initCardCreation() {
        document.querySelectorAll(".kanban-add-btn").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                var column = btn.closest(".kanban-column");
                var body = column.querySelector(".kanban-column-body");
                var columnId = body.getAttribute("data-column-id");

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
                        initCardSubtasksWidgets();
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
        card.setAttribute("data-border-color", data.borderColor || "");

        var html = '<div class="kanban-card-header">' +
            '<div class="kanban-card-title">' + escapeHtml(data.title) + "</div>";

        if (config.canEdit) {
            html += '<button type="button" class="kanban-card-menu-btn" data-card-id="' + data.id + '" title="Меню карточки">' +
                '<i class="bi bi-three-dots-vertical"></i>' +
                '</button>' +
                '<div class="kanban-card-dropdown" data-card-id="' + data.id + '" style="display:none;">' +
                '<button type="button" class="kanban-card-dropdown-item rename-card-btn" data-card-id="' + data.id + '">' +
                '<i class="bi bi-pencil"></i> Переименовать' +
                '</button>' +
                '<div class="kanban-card-color-section">' +
                '<div class="kanban-card-color-label">Цвет карточки:</div>' +
                '<button type="button" class="card-color-option" data-color="" title="Без цвета">' +
                '<span class="color-preview swatch-none">✕</span>' +
                '</button>' +
                '<button type="button" class="card-color-option" data-color="primary" title="Синий">' +
                '<span class="color-preview bg-primary"></span>' +
                '</button>' +
                '<button type="button" class="card-color-option" data-color="success" title="Зелёный">' +
                '<span class="color-preview bg-success"></span>' +
                '</button>' +
                '<button type="button" class="card-color-option" data-color="warning" title="Жёлтый">' +
                '<span class="color-preview bg-warning"></span>' +
                '</button>' +
                '<button type="button" class="card-color-option" data-color="danger" title="Красный">' +
                '<span class="color-preview bg-danger"></span>' +
                '</button>' +
                '<button type="button" class="card-color-option" data-color="info" title="Голубой">' +
                '<span class="color-preview bg-info"></span>' +
                '</button>' +
                '<button type="button" class="card-color-option" data-color="dark" title="Тёмный">' +
                '<span class="color-preview bg-dark"></span>' +
                '</button>' +
                '</div>' +
                '<button type="button" class="kanban-card-dropdown-item delete-card-btn" data-card-id="' + data.id + '">' +
                '<i class="bi bi-trash"></i> Удалить' +
                '</button>' +
                '</div>';
        }

        html += '</div>';

        if (data.description) {
            var desc = data.description.length > 80 ? data.description.substring(0, 80) + "..." : data.description;
            html += '<div class="kanban-card-description">' + escapeHtml(desc) + "</div>";
        }
        html += buildCardMeta(data);
        html += '<div class="card-subtasks card-subtasks-collapsed" data-card-id="' + data.id + '" data-subtasks-host">' +
            '<div class="card-subtasks-header">' +
            '<div class="card-subtasks-header-main">' +
            '<button type="button" class="card-subtasks-toggle" aria-label="Показать/скрыть подзадачи"><i class="bi bi-chevron-right"></i></button>' +
            '<span class="card-subtasks-label">Подзадачи</span>' +
            '<span class="card-subtasks-counter text-muted">(0/0)</span>' +
            '</div>' +
            (config.canEdit ? '<button type="button" class="btn btn-sm btn-outline-secondary card-subtasks-header-add" title="Добавить подзадачу"><i class="bi bi-plus-lg"></i></button>' : '') +
            '</div>' +
            '<div class="card-subtasks-body">' +
            '<div class="card-subtasks-input-wrap mt-1" style="display:none;">' +
            '<div class="input-group input-group-sm"><input type="text" class="form-control card-subtasks-input" placeholder="Новая подзадача..."></div>' +
            '</div>' +
            '<div class="card-subtasks-items"></div>' +
            '</div></div>';
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

    // ── Column Colors ──

    function initColumnColors() {
        var colors = [
            { value: "bg-primary", cls: "bg-primary" },
            { value: "bg-success", cls: "bg-success" },
            { value: "bg-warning", cls: "bg-warning" },
            { value: "bg-danger",  cls: "bg-danger"  },
            { value: "bg-info",    cls: "bg-info"     },
            { value: "bg-dark",    cls: "bg-dark"     }
        ];

        var activePopover = null;

        function closePopover() {
            if (activePopover) { activePopover.remove(); activePopover = null; }
        }

        document.addEventListener("click", function (e) {
            if (activePopover && !activePopover.contains(e.target) && !e.target.closest(".column-color-btn")) {
                closePopover();
            }
        });

        document.querySelectorAll(".column-color-btn").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                if (activePopover) { closePopover(); return; }

                var columnId = btn.getAttribute("data-column-id");
                var colEl = document.querySelector('.kanban-column[data-column-id="' + columnId + '"]');
                var currentColor = colEl ? colEl.getAttribute("data-header-color") : "";

                var popover = document.createElement("div");
                popover.className = "column-color-popover";

                colors.forEach(function (c) {
                    var sw = document.createElement("button");
                    sw.type = "button";
                    sw.className = "column-color-swatch " + c.cls + (currentColor === c.value ? " active" : "");
                    sw.addEventListener("click", function (ev) {
                        ev.stopPropagation();
                        closePopover();
                        apiFetch("/api/kanban/boards/" + config.boardId + "/columns/" + columnId, {
                            method: "PATCH",
                            body: JSON.stringify({ headerColor: c.value })
                        }).then(function () {
                            if (colEl) colEl.setAttribute("data-header-color", c.value);
                        }).catch(function (err) { showToast(err.message); });
                    });
                    popover.appendChild(sw);
                });

                btn.closest(".kanban-column-header").appendChild(popover);
                activePopover = popover;
            });
        });
    }

    // ── Update card on board ──

    function updateCardOnBoard(data) {
        if (!data || !data.id) return;
        var cardEl = document.querySelector('[data-card-id="' + data.id + '"]');
        if (!cardEl) return;

        var titleEl = cardEl.querySelector(".kanban-card-title");
        if (titleEl && data.title !== undefined) {
            titleEl.textContent = data.title;
        }

        var descEl = cardEl.querySelector(".kanban-card-description");
        if (data.description) {
            var preview = data.description.length > 80 ? data.description.substring(0, 80) + "..." : data.description;
            if (descEl) {
                descEl.textContent = preview;
            } else if (titleEl) {
                var newDesc = document.createElement("div");
                newDesc.className = "kanban-card-description";
                newDesc.textContent = preview;
                titleEl.after(newDesc);
            }
        } else if (data.description === "" || data.description === null) {
            if (descEl) descEl.remove();
        }

        var metaEl = cardEl.querySelector(".kanban-card-meta");
        if (metaEl) {
            var newMeta = document.createElement("div");
            newMeta.innerHTML = buildCardMeta(data);
            metaEl.replaceWith(newMeta.firstChild);
        }

        if (data.updatedAt) {
            cardEl.setAttribute("data-updated-at", data.updatedAt);
        }
        if (data.borderColor !== undefined) {
            cardEl.setAttribute("data-border-color", data.borderColor || "");
        }
    }

    // ── Card Sidebar ──

    function initCardSidebar() {
        var sidebar = document.getElementById("task-sidebar");
        if (!sidebar) return;

        var board = document.getElementById("kanban-board");
        if (board) {
            board.addEventListener("click", function (e) {
                var card = e.target.closest(".kanban-card");
                if (!card || e.target.closest(".kanban-add-btn") || e.target.closest(".kanban-new-card-input")) return;
                // Ignore clicks on card menu button and dropdown
                if (e.target.closest(".kanban-card-menu-btn") || e.target.closest(".kanban-card-dropdown")) return;
                // Ignore clicks on card subtasks (toggle, add, checkbox) so they don't open sidebar
                if (e.target.closest(".card-subtasks")) return;
                var cardId = card.getAttribute("data-card-id");
                if (cardId) openSidebar(cardId);
            });
        }

        document.getElementById("task-sidebar-close").addEventListener("click", closeSidebar);
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && sidebar.getAttribute("aria-hidden") === "false") closeSidebar();
        });
        document.addEventListener("click", function (e) {
            if (sidebar.getAttribute("aria-hidden") !== "false") return;
            if (e.target.closest(".task-sidebar-panel")) return;
            if (e.target.closest(".kanban-card")) return;
            if (e.target.closest(".kanban-add-btn")) return;
            if (e.target.closest(".modal") || e.target.closest(".modal-backdrop")) return;
            closeSidebar();
        });

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
        initTitle();
        initDescription();
        initPriority();
        initSubtasks();
        initSubtaskAssigneeArea();
        initCardSubtasksWidgets();
        initAttachments();
        initDueAt();
        initAssignees();
        initAssignNewUserModal();
        initLabels();
        initBorderColor();
    }

    function openSidebar(cardId) {
        // Remove highlight from any previously-open card
        document.querySelectorAll(".kanban-card--open").forEach(function (c) {
            c.classList.remove("kanban-card--open");
        });
        currentCardId = cardId;
        var cardEl = document.querySelector('[data-card-id="' + cardId + '"]');
        if (cardEl) cardEl.classList.add("kanban-card--open");
        var sidebar = document.getElementById("task-sidebar");
        sidebar.setAttribute("aria-hidden", "false");

        // Reset to chat tab
        document.querySelectorAll(".task-sidebar-tab").forEach(function (t) {
            t.classList.remove("active");
            t.setAttribute("aria-selected", "false");
        });
        document.querySelectorAll(".task-sidebar-tab-pane").forEach(function (pane) {
            pane.classList.remove("active");
            pane.hidden = true;
        });
        var chatTab = document.querySelector('[data-tab="chat"]');
        if (chatTab) { chatTab.classList.add("active"); chatTab.setAttribute("aria-selected", "true"); }
        var chatPane = document.getElementById("pane-chat");
        if (chatPane) { chatPane.classList.add("active"); chatPane.hidden = false; }

        apiFetch("/api/kanban/cards/" + cardId).then(function (data) {
            currentCardData = data;

            // Title
            var titleInput = document.getElementById("task-sidebar-title-input");
            if (titleInput) {
                if (titleInput.tagName === "INPUT") {
                    titleInput.value = data.title;
                } else {
                    titleInput.textContent = data.title;
                }
            }
            document.getElementById("task-sidebar-id").textContent = data.columnTitle || "";

            // Description
            var textarea = document.getElementById("task-description-textarea");
            if (textarea) textarea.value = data.description || "";

            // Priority
            var priSelect = document.getElementById("task-priority-select");
            if (priSelect) priSelect.value = data.priority || "";

            // Due date
            var dueInput = document.getElementById("task-due-at");
            if (dueInput) dueInput.value = data.dueDate ? data.dueDate.substring(0, 16) : "";

            // Timestamps
            var createdEl = document.getElementById("task-created-at");
            if (createdEl) createdEl.textContent = formatDate(data.createdAt);
            var updatedEl = document.getElementById("task-updated-at");
            if (updatedEl) updatedEl.textContent = formatDate(data.updatedAt);

            // Assignees
            currentAssigneeIds = (data.assignees || []).map(function (a) { return a.id; });
            renderAssignees(data.assignees || []);

            // Labels
            renderLabels(data.labels || []);

            // Comments
            renderComments(data.comments || []);
            startCommentPolling(cardId);

            // Подзадачи: синхронизация сайдбар + блок на карточке
            refreshSubtaskViews(currentCardId, data.subtasks || []);

            // Attachments
            renderAttachments(data.attachments || []);

            // Border colour picker
            var picker = document.getElementById("card-border-color-picker");
            if (picker) {
                picker.querySelectorAll(".card-color-swatch").forEach(function (s) {
                    s.classList.toggle("active", s.getAttribute("data-color") === (data.borderColor || ""));
                });
            }

            scrollChatToBottom();

            // Hide save status on open
            var badge = document.getElementById("save-status");
            if (badge) badge.style.display = "none";
        }).catch(function (err) {
            showToast(err.message);
        });
    }

    function closeSidebar() {
        document.querySelectorAll(".kanban-card--open").forEach(function (c) {
            c.classList.remove("kanban-card--open");
        });
        var sidebar = document.getElementById("task-sidebar");
        sidebar.setAttribute("aria-hidden", "true");
        currentCardId = null;
        currentCardData = null;
        currentAssigneeIds = [];
        stopCommentPolling();

        var adrop = document.getElementById("assignee-dropdown");
        var ldrop = document.getElementById("label-dropdown");
        if (adrop) adrop.style.display = "none";
        if (ldrop) ldrop.style.display = "none";
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

        var ph = container.querySelector(".task-chat-msg-placeholder");
        if (ph) ph.remove();

        var msg = document.createElement("div");
        msg.className = "task-chat-msg";
        msg.setAttribute("data-comment-id", c.id);
        msg.innerHTML =
            '<div class="task-chat-msg-bubble">' +
            '<div class="task-chat-msg-text">' + escapeHtml(c.body) + '</div>' +
            '<div class="task-chat-msg-meta"><span class="task-chat-msg-time">' +
            escapeHtml(c.authorName) + " · " + formatShortDate(c.createdAt) +
            "</span></div></div>";
        container.appendChild(msg);
    }

    function appendAttachmentBubble(att) {
        var container = document.getElementById("chat-messages");
        if (!container) return;

        var ph = container.querySelector(".task-chat-msg-placeholder");
        if (ph) ph.remove();

        var sizeKb = Math.round((att.sizeBytes || 0) / 1024);
        var downloadUrl = "/api/kanban/cards/" + currentCardId + "/attachments/" + att.id + "/download";
        var isImage = att.contentType && att.contentType.indexOf("image/") === 0;

        var bodyHtml;
        if (isImage) {
            bodyHtml =
                '<a href="' + downloadUrl + '" target="_blank" class="d-block">' +
                '<img src="' + downloadUrl + '" class="chat-img-preview" alt="' + escapeHtml(att.filename) + '">' +
                '</a>' +
                '<small class="text-muted d-block mt-1">' +
                '<i class="bi bi-paperclip me-1"></i>' + escapeHtml(att.filename) + ' · ' + sizeKb + ' KB' +
                '</small>';
        } else {
            bodyHtml =
                '<a href="' + downloadUrl + '" class="text-decoration-none">' +
                '<i class="bi bi-paperclip me-1"></i>' + escapeHtml(att.filename) +
                '</a>' +
                '<small class="text-muted ms-2">' + sizeKb + ' KB</small>';
        }

        var msg = document.createElement("div");
        msg.className = "task-chat-msg";
        msg.innerHTML =
            '<div class="task-chat-msg-bubble">' +
            '<div class="task-chat-msg-text">' + bodyHtml + '</div>' +
            '<div class="task-chat-msg-meta"><span class="task-chat-msg-time">' +
            escapeHtml(config.currentUserName) + " · " + formatShortDate(att.createdAt || new Date().toISOString()) +
            "</span></div></div>";
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
                knownCommentIds.delete(tempId);
                knownCommentIds.add(data.id);
                var tempEl = document.querySelector('[data-comment-id="' + tempId + '"]');
                if (tempEl) tempEl.setAttribute("data-comment-id", data.id);
                // Update comment count on card face
                if (currentCardData) {
                    currentCardData.comments = currentCardData.comments || [];
                    currentCardData.comments.push(data);
                    updateCardOnBoard(currentCardData);
                }
            }).catch(function (err) {
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
        input.addEventListener("blur", sendMessage);

        var attachBtn = document.getElementById("task-chat-attach-btn");
        var fileInput = document.getElementById("task-chat-file-input");
        if (attachBtn && fileInput) {
            attachBtn.addEventListener("click", function () {
                fileInput.click();
            });

            fileInput.addEventListener("change", function () {
                if (!fileInput.files.length || !currentCardId) return;

                var file = fileInput.files[0];
                var ext = file.name.split(".").pop().toLowerCase();
                var allowed = ["pdf", "png", "jpg", "jpeg", "webp", "docx", "xlsx"];
                if (allowed.indexOf(ext) === -1) {
                    showToast("Тип файла ." + ext + " не поддерживается.");
                    fileInput.value = "";
                    return;
                }

                var formData = new FormData();
                formData.append("file", file);

                apiFetch("/api/kanban/cards/" + currentCardId + "/attachments", {
                    method: "POST",
                    body: formData
                }).then(function (att) {
                    fileInput.value = "";
                    appendAttachmentBubble(att);
                    scrollChatToBottom();
                    if (currentCardData && att) {
                        currentCardData.attachments = currentCardData.attachments || [];
                        currentCardData.attachments.push(att);
                        renderAttachments(currentCardData.attachments);
                    }
                }).catch(function (err) {
                    showToast(err.message);
                    fileInput.value = "";
                });
            });
        }
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
        }, 30000);
    }

    function stopCommentPolling() {
        if (commentPollTimer) {
            clearInterval(commentPollTimer);
            commentPollTimer = null;
        }
    }

    // ── Title auto-save ──

    function initTitle() {
        var input = document.getElementById("task-sidebar-title-input");
        if (!input || input.tagName !== "INPUT" || !config.canEdit) return;

        function save() {
            if (currentCardId && input.value.trim()) {
                autoSaveField("title", function () { return input.value.trim(); });
            }
        }

        input.addEventListener("keydown", function (e) {
            if (e.key === "Enter") { e.preventDefault(); save(); }
        });
        input.addEventListener("blur", save);
    }

    // ── Description auto-save ──

    function initDescription() {
        var textarea = document.getElementById("task-description-textarea");
        if (!textarea || !config.canEdit) return;

        textarea.addEventListener("blur", function () {
            if (currentCardId) {
                autoSaveField("description", function () { return textarea.value; });
            }
        });
    }

    // ── Priority auto-save ──

    function initPriority() {
        var select = document.getElementById("task-priority-select");
        if (!select || !config.canEdit) return;

select.addEventListener("change", function () {
                autoSaveField("priority", function () {
                    return select.value || null;
                });
            });
    }

    // ── Due Date auto-save ──

    function initDueAt() {
        var input = document.getElementById("task-due-at");
        if (!input || !config.canEdit) return;

        input.addEventListener("change", function () {
            autoSaveField("dueDate", function () {
                return input.value ? new Date(input.value).toISOString() : null;
            });
        });
    }

    // ── Подзадачи (subtasks): один источник — currentCardData.subtasks / API; синхронизация карточка + сайдбар ──

    function renderCardSubtasks(cardId, items) {
        var block = document.querySelector('.card-subtasks[data-card-id="' + cardId + '"]');
        if (!block) return;
        var counterEl = block.querySelector(".card-subtasks-counter");
        var itemsEl = block.querySelector(".card-subtasks-items");
        var progressWrap = block.querySelector(".card-subtasks-progress");
        var barEl = block.querySelector(".card-subtasks-progress-fill");
        if (!itemsEl) return;

        items = items || [];
        var done = items.filter(function (c) { return c.isCompleted; }).length;
        var total = items.length;
        if (counterEl) counterEl.textContent = total ? "(" + done + "/" + total + ")" : "(0/0)";
        if (progressWrap) progressWrap.style.display = total > 0 ? "" : "none";
        if (barEl) {
            var percent = total ? (done / total) * 100 : 0;
            barEl.style.width = percent + "%";
        }

        itemsEl.innerHTML = "";
        items.forEach(function (item) {
            var row = document.createElement("div");
            row.className = "form-check card-subtasks-item" + (item.isCompleted ? " card-subtasks-item-completed" : "");
            row.setAttribute("data-subtask-id", item.id);
            row.innerHTML =
                '<input class="form-check-input card-subtasks-checkbox" type="checkbox"' + (item.isCompleted ? " checked" : "") + ">" +
                '<label class="form-check-label ms-1 card-subtasks-text">' + escapeHtml(item.title) + "</label>";
            itemsEl.appendChild(row);
        });
    }

    function refreshSubtaskViews(cardId, items) {
        items = items || [];
        if (String(cardId) === String(currentCardId)) {
            if (currentCardData) currentCardData.subtasks = items;
            renderSubtasks(items);
        }
        renderCardSubtasks(cardId, items);
        if (currentCardData && currentCardData.id === parseInt(cardId, 10)) {
            updateCardOnBoard(currentCardData);
        }
    }

    function renderSubtasks(items) {
        var container = document.getElementById("subtask-items");
        if (!container) return;
        container.innerHTML = "";

        if (!items.length) {
            container.innerHTML = '<div class="task-subtasks-empty small">Подзадач пока нет.</div>';
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement("div");
            row.className = "form-check d-flex align-items-center gap-2 mb-2";
            row.setAttribute("data-subtask-id", item.id);

            var assigneeHtml = "";
            if (config.canEdit) {
                if (item.userName) {
                    assigneeHtml =
                        '<span class="badge bg-secondary me-1 subtask-assignee-chip" data-subtask-id="' + item.id + '" style="cursor:pointer;font-size:0.78rem;">' +
                        escapeHtml(item.userName) + ' <span style="opacity:0.7">&times;</span>' +
                        '</span>';
                } else {
                    assigneeHtml =
                        '<button type="button" class="btn btn-sm btn-outline-secondary subtask-assignee-add-btn" data-subtask-id="' + item.id + '" style="padding:0.1rem 0.4rem;font-size:0.8rem;white-space:nowrap;">' +
                        'Назначить' +
                        '</button>';
                }
            } else if (item.userName) {
                assigneeHtml = '<span class="text-muted small" style="white-space:nowrap;flex-shrink:0;">' + escapeHtml(item.userName) + '</span>';
            }

            row.innerHTML =
                '<input class="form-check-input" type="checkbox"' + (item.isCompleted ? " checked" : "") + ">" +
                '<label class="form-check-label flex-grow-1' + (item.isCompleted ? " text-decoration-line-through text-muted" : "") + '">' + escapeHtml(item.title) + "</label>" +
                assigneeHtml +
                (config.canEdit ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 subtask-delete-btn"><i class="bi bi-x-lg"></i></button>' : "");
            container.appendChild(row);

            var chipEl = row.querySelector(".subtask-assignee-chip");
            var addBtn = row.querySelector(".subtask-assignee-add-btn");
            if (chipEl) {
                chipEl.addEventListener("click", function (e) {
                    e.stopPropagation();
                    assignSubtaskUser(item.id, null);
                });
            }
            if (addBtn) {
                addBtn.addEventListener("click", function (e) {
                    e.stopPropagation();
                    showSubtaskAssigneeDropdown(addBtn, item.id);
                });
            }

            var checkbox = row.querySelector("input[type=checkbox]");
            if (checkbox && config.canEdit) {
                checkbox.addEventListener("change", function () {
                    var label = row.querySelector("label");
                    if (label) {
                        label.classList.toggle("text-decoration-line-through", checkbox.checked);
                        label.classList.toggle("text-muted", checkbox.checked);
                    }
                    apiFetch("/api/kanban/cards/" + currentCardId + "/checklist/" + item.id, {
                        method: "PATCH",
                        body: JSON.stringify({ isCompleted: checkbox.checked })
                    }).then(function () {
                        if (currentCardData && currentCardData.subtasks) {
                            var ci = currentCardData.subtasks.find(function (c) { return c.id === item.id; });
                            if (ci) ci.isCompleted = checkbox.checked;
                        }
                        refreshSubtaskViews(currentCardId, currentCardData ? currentCardData.subtasks : []);
                    }).catch(function (err) {
                        checkbox.checked = !checkbox.checked;
                        if (label) {
                            label.classList.toggle("text-decoration-line-through", checkbox.checked);
                            label.classList.toggle("text-muted", checkbox.checked);
                        }
                        showToast(err.message);
                    });
                });
            }

            var delBtn = row.querySelector(".subtask-delete-btn");
            if (delBtn) {
                delBtn.addEventListener("click", function () {
                    apiFetch("/api/kanban/cards/" + currentCardId + "/checklist/" + item.id, {
                        method: "DELETE"
                    }).then(function () {
                        var next = (currentCardData && currentCardData.subtasks) ? currentCardData.subtasks.filter(function (c) { return c.id !== item.id; }) : [];
                        if (currentCardData) currentCardData.subtasks = next;
                        refreshSubtaskViews(currentCardId, next);
                    }).catch(function (err) { showToast(err.message); });
                });
            }
        });
    }

    // ── Subtask assignee dropdown (mirrors card assignee in Info tab) ──

    function initSubtaskAssigneeArea() {
        // Build one floating dropdown mirroring #assignee-dropdown structure
        var dd = document.createElement("div");
        dd.id = "subtask-assignee-dropdown";
        dd.className = "dropdown-menu p-2";
        dd.style.cssText = "display:none;position:fixed;z-index:1200;min-width:220px;";
        dd.innerHTML =
            '<div class="d-flex gap-1 mb-2 align-items-center">' +
                '<input type="text" id="subtask-assignee-search" class="form-control form-control-sm flex-grow-1" placeholder="Поиск...">' +
                '<button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0" id="subtask-assignee-add-new-btn" title="Добавить пользователя в проект и назначить">' +
                    '<i class="bi bi-person-plus"></i>' +
                '</button>' +
            '</div>' +
            '<div id="subtask-assignee-results" style="max-height:160px;overflow-y:auto;"></div>';
        document.body.appendChild(dd);

        var searchInput = document.getElementById("subtask-assignee-search");
        if (searchInput) {
            searchInput.addEventListener("input", makeDebounce(function () {
                searchSubtaskAssigneeUsers(searchInput.value);
            }, 300));
        }

        var addNewBtn = document.getElementById("subtask-assignee-add-new-btn");
        if (addNewBtn) {
            addNewBtn.addEventListener("click", function (e) {
                e.stopPropagation();
                var subtaskId = currentSubtaskIdForAssign;
                if (!subtaskId) return;
                closeSubtaskAssigneeDropdown();
                var modalEl = document.getElementById("assignNewUserOrgModal");
                if (!modalEl) return;
                window.__kanbanOrgModalConfirm = function (user) {
                    // user added to project via org modal — now assign them to this subtask
                    assignSubtaskUser(subtaskId, user.id);
                };
                window.__kanbanShowToast = showToast;
                var modal = window.bootstrap && bootstrap.Modal.getOrCreateInstance(modalEl);
                if (modal) modal.show();
            });
        }

        document.addEventListener("click", function (e) {
            if (currentSubtaskIdForAssign !== null &&
                !e.target.closest("#subtask-assignee-dropdown") &&
                !e.target.closest(".subtask-assignee-add-btn")) {
                closeSubtaskAssigneeDropdown();
            }
        });
    }

    function showSubtaskAssigneeDropdown(anchorEl, subtaskId) {
        var dd = document.getElementById("subtask-assignee-dropdown");
        if (!dd) return;

        currentSubtaskIdForAssign = subtaskId;

        var rect = anchorEl.getBoundingClientRect();
        var ddW = 230;
        var left = rect.left;
        if (left + ddW > window.innerWidth - 8) left = window.innerWidth - ddW - 8;
        dd.style.top  = (rect.bottom + 4) + "px";
        dd.style.left = left + "px";
        dd.style.width = ddW + "px";
        dd.style.display = "block";

        var searchInput = document.getElementById("subtask-assignee-search");
        if (searchInput) { searchInput.value = ""; searchInput.focus(); }
        searchSubtaskAssigneeUsers("");
    }

    function closeSubtaskAssigneeDropdown() {
        var dd = document.getElementById("subtask-assignee-dropdown");
        if (dd) dd.style.display = "none";
        currentSubtaskIdForAssign = null;
    }

    function searchSubtaskAssigneeUsers(query) {
        var results = document.getElementById("subtask-assignee-results");
        if (!results || currentSubtaskIdForAssign === null) return;

        var subtaskId = currentSubtaskIdForAssign;

        // Find the current assignee id for this subtask from currentCardData
        var currentUserId = null;
        if (currentCardData && currentCardData.subtasks) {
            var ci = currentCardData.subtasks.find(function (c) { return c.id === subtaskId; });
            if (ci) currentUserId = ci.userId;
        }

        var url = "/api/kanban/users/search?query=" + encodeURIComponent(query || "");
        if (config.projectId) url += "&project_id=" + config.projectId;

        apiFetch(url).then(function (users) {
            results.innerHTML = "";
            if (!(users || []).length) {
                results.innerHTML = '<div class="text-muted small px-2 py-1">Не найдено</div>';
                return;
            }
            (users || []).forEach(function (u) {
                var item = document.createElement("div");
                item.className = "dropdown-item d-flex align-items-center justify-content-between";
                item.style.cursor = "pointer";
                var isSelected = String(u.id) === String(currentUserId);
                item.innerHTML =
                    "<span>" + escapeHtml(u.name) + "</span>" +
                    (isSelected ? '<i class="bi bi-check2 text-primary"></i>' : "");
                item.addEventListener("click", function (e) {
                    e.stopPropagation();
                    assignSubtaskUser(subtaskId, u.id);
                    closeSubtaskAssigneeDropdown();
                });
                results.appendChild(item);
            });
        }).catch(function () {});
    }

    function assignSubtaskUser(subtaskId, userId) {
        if (!currentCardId) return;
        apiFetch("/api/kanban/cards/" + currentCardId + "/checklist/" + subtaskId, {
            method: "PATCH",
            body: JSON.stringify({ user_id: userId !== undefined ? userId : null })
        }).then(function (data) {
            if (currentCardData && currentCardData.subtasks) {
                var ci = currentCardData.subtasks.find(function (c) { return c.id === subtaskId; });
                if (ci) {
                    ci.userId = data.userId;
                    ci.userName = data.userName;
                }
            }
            renderSubtasks(currentCardData ? currentCardData.subtasks : []);
        }).catch(function (err) { showToast(err.message); });
    }

    function initSubtasks() {
        var addBtn = document.getElementById("add-subtask-btn");
        if (!addBtn) return;

        addBtn.addEventListener("click", function () {
            var container = document.getElementById("subtask-items");
            if (!container || !currentCardId) return;

            var existing = container.querySelector(".subtask-new-input");
            if (existing) { existing.focus(); return; }

            var wrap = document.createElement("div");
            wrap.className = "mb-2";
            wrap.innerHTML = '<input type="text" class="form-control form-control-sm subtask-new-input" placeholder="Название подзадачи...">';
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
                }).then(function (newItem) {
                    if (currentCardData) {
                        currentCardData.subtasks = currentCardData.subtasks || [];
                        currentCardData.subtasks.push(newItem);
                    }
                    refreshSubtaskViews(currentCardId, currentCardData ? currentCardData.subtasks : []);
                }).catch(function (err) { showToast(err.message); });
            }

            input.addEventListener("keydown", function (e) {
                if (e.key === "Enter") { e.preventDefault(); commit(); }
                if (e.key === "Escape") { wrap.remove(); }
            });
            input.addEventListener("blur", commit);
        });
    }

    function refreshSubtaskProgress() {
        if (!currentCardId || !currentCardData) return;
        refreshSubtaskViews(currentCardId, currentCardData.subtasks || []);
    }

    function fetchCardSubtasks(cardId) {
        return apiFetch("/api/kanban/cards/" + cardId).then(function (data) {
            return data.subtasks || [];
        });
    }

    function initCardSubtasksWidgets() {
        document.querySelectorAll(".card-subtasks[data-card-id]").forEach(function (block) {
            if (block.__cardSubtasksBound) return;
            block.__cardSubtasksBound = true;
            var cardId = block.getAttribute("data-card-id");
            var header = block.querySelector(".card-subtasks-header");
            var toggleBtn = block.querySelector(".card-subtasks-toggle");
            var addBtn = block.querySelector(".card-subtasks-header-add");
            var inputWrap = block.querySelector(".card-subtasks-input-wrap");
            var input = block.querySelector(".card-subtasks-input");
            var itemsEl = block.querySelector(".card-subtasks-items");

            function toggleCollapsed() {
                block.classList.toggle("card-subtasks-collapsed");
                var icon = toggleBtn && toggleBtn.querySelector("i");
                if (icon) {
                    icon.classList.toggle("bi-chevron-right", block.classList.contains("card-subtasks-collapsed"));
                    icon.classList.toggle("bi-chevron-down", !block.classList.contains("card-subtasks-collapsed"));
                }
            }

            if (header) {
                header.addEventListener("click", function (ev) {
                    if (ev.target.closest(".card-subtasks-header-add") || ev.target.closest(".card-subtasks-input")) return;
                    ev.preventDefault();
                    ev.stopPropagation();
                    toggleCollapsed();
                });
            }

            if (addBtn && config.canEdit) {
                addBtn.addEventListener("click", function (ev) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    if (!inputWrap || !input) return;
                    if (block.classList.contains("card-subtasks-collapsed")) toggleCollapsed();
                    inputWrap.style.display = "";
                    input.value = "";
                    input.focus();
                });
            }

            if (input && inputWrap && config.canEdit) {
                function commitSubtaskFromInput() {
                    var title = (input.value || "").trim();
                    if (!title) {
                        input.value = "";
                        inputWrap.style.display = "none";
                        return;
                    }
                    input.value = "";
                    inputWrap.style.display = "none";
                    apiFetch("/api/kanban/cards/" + cardId + "/checklist", {
                        method: "POST",
                        body: JSON.stringify({ title: title })
                    }).then(function () {
                        return fetchCardSubtasks(cardId);
                    }).then(function (list) {
                        refreshSubtaskViews(cardId, list);
                        if (String(cardId) === String(currentCardId) && currentCardData) currentCardData.subtasks = list;
                    }).catch(function (err) { showToast(err.message); });
                }

                input.addEventListener("keydown", function (ev) {
                    if (ev.key === "Enter") {
                        ev.preventDefault();
                        commitSubtaskFromInput();
                    } else if (ev.key === "Escape") {
                        input.value = "";
                        inputWrap.style.display = "none";
                    }
                });

                input.addEventListener("blur", function () {
                    // При потере фокуса (клик в любое место) создаём подзадачу, если что-то введено
                    if (inputWrap.style.display === "none") return;
                    commitSubtaskFromInput();
                });
            }

            if (itemsEl && config.canEdit) {
                itemsEl.addEventListener("change", function (ev) {
                    var checkbox = ev.target;
                    if (!checkbox || !checkbox.classList.contains("card-subtasks-checkbox")) return;
                    var row = checkbox.closest("[data-subtask-id]");
                    if (!row) return;
                    var itemId = row.getAttribute("data-subtask-id");
                    apiFetch("/api/kanban/cards/" + cardId + "/checklist/" + itemId, {
                        method: "PATCH",
                        body: JSON.stringify({ isCompleted: checkbox.checked })
                    }).then(function () {
                        return fetchCardSubtasks(cardId);
                    }).then(function (list) {
                        refreshSubtaskViews(cardId, list);
                        if (String(cardId) === String(currentCardId) && currentCardData) currentCardData.subtasks = list;
                    }).catch(function (err) {
                        checkbox.checked = !checkbox.checked;
                        showToast(err.message);
                    });
                });
            }
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
                "<div>" +
                '<a href="/api/kanban/cards/' + currentCardId + "/attachments/" + att.id + '/download" class="text-decoration-none">' +
                '<i class="bi bi-paperclip me-1"></i>' + escapeHtml(att.filename) +
                "</a>" +
                '<small class="text-muted ms-2">' + sizeKb + " KB</small>" +
                "</div>" +
                (config.canEdit ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 att-delete-btn" data-att-id="' + att.id + '"><i class="bi bi-trash"></i></button>' : "");
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
            var ext = file.name.split(".").pop().toLowerCase();
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
            }).then(function (newAtt) {
                input.value = "";
                if (currentCardData && newAtt) {
                    currentCardData.attachments = currentCardData.attachments || [];
                    currentCardData.attachments.push(newAtt);
                    renderAttachments(currentCardData.attachments);
                }
            }).catch(function (err) {
                showToast(err.message);
                input.value = "";
            });
        });
    }

    // ── Assignees ──

    function renderAssignees(list) {
        var chips = document.getElementById("assignees-chips");
        if (!chips) return;
        chips.innerHTML = "";
        var canEdit = !!document.getElementById("assignee-add-btn");
        (list || []).forEach(function (a) {
            var chip = document.createElement("span");
            chip.className = "badge bg-secondary me-1 mb-1";
            chip.setAttribute("data-assignee-id", a.id);
            if (canEdit) {
                chip.style.cursor = "pointer";
                chip.innerHTML = escapeHtml(a.name) + ' <span style="opacity:0.7">&times;</span>';
                chip.addEventListener("click", function () { toggleAssignee(a.id); });
            } else {
                chip.textContent = a.name;
            }
            chips.appendChild(chip);
        });
        var addBtn = document.getElementById("assignee-add-btn");
        if (addBtn) addBtn.style.display = (list || []).length > 0 ? "none" : "";
    }

    function initAssignees() {
        var addBtn = document.getElementById("assignee-add-btn");
        var dropdown = document.getElementById("assignee-dropdown");
        var searchInput = document.getElementById("assignee-search");
        if (!addBtn || !dropdown) return;

        addBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            var isVisible = dropdown.style.display !== "none";
            dropdown.style.display = isVisible ? "none" : "block";
            if (!isVisible) {
                if (searchInput) { searchInput.value = ""; searchInput.focus(); }
                searchUsers("");
            }
        });

        if (searchInput) {
            searchInput.addEventListener("input", makeDebounce(function () {
                searchUsers(searchInput.value);
            }, 300));
        }
    }

    function initAssignNewUserModal() {
        var addNewBtn = document.getElementById("assignee-add-new-user-btn");
        var modalEl = document.getElementById("assignNewUserOrgModal");
        if (!addNewBtn || !modalEl) return;

        window.__kanbanRefreshAssignees = function (assignees) {
            currentAssigneeIds = (assignees || []).map(function (a) { return a.id; });
            if (currentCardData) currentCardData.assignees = assignees;
            renderAssignees(assignees || []);
            updateCardOnBoard(currentCardData);
            setSaveStatus("saved");
        };
        window.__kanbanShowToast = showToast;

        addNewBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            var dropdown = document.getElementById("assignee-dropdown");
            if (dropdown) dropdown.style.display = "none";
            window.__currentCardIdForAssignNewUser = currentCardId;
            var modal = window.bootstrap && bootstrap.Modal.getOrCreateInstance(modalEl);
            if (modal) modal.show();
        });
    }

    function searchUsers(query) {
        var results = document.getElementById("assignee-results");
        if (!results) return;

        var url = "/api/kanban/users/search?query=" + encodeURIComponent(query);
        if (config.projectId) url += "&project_id=" + config.projectId;
        apiFetch(url)
            .then(function (users) {
                results.innerHTML = "";
                (users || []).forEach(function (u) {
                    var item = document.createElement("div");
                    item.className = "dropdown-item d-flex align-items-center justify-content-between";
                    item.style.cursor = "pointer";
                    var isSelected = currentAssigneeIds.indexOf(u.id) !== -1;
                    item.innerHTML =
                        "<span>" + escapeHtml(u.name) + "</span>" +
                        (isSelected ? '<i class="bi bi-check2 text-primary"></i>' : "");
                    item.addEventListener("click", function (e) {
                        e.stopPropagation();
                        toggleAssignee(u.id, u.name);
                    });
                    results.appendChild(item);
                });
            })
            .catch(function () {});
    }

    function toggleAssignee(userId) {
        if (!currentCardId) return;

        var newIds = currentAssigneeIds.indexOf(userId) !== -1 ? [] : [userId];

        setSaveStatus("saving");
        apiFetch("/api/kanban/cards/" + currentCardId + "/assignees", {
            method: "PUT",
            body: JSON.stringify({ user_ids: newIds })
        }).then(function (data) {
            setSaveStatus("saved");
            var assignees = data.assignees || [];
            currentAssigneeIds = assignees.map(function (a) { return a.id; });
            if (currentCardData) currentCardData.assignees = assignees;
            renderAssignees(assignees);
            updateCardOnBoard(currentCardData);
            var dropdown = document.getElementById("assignee-dropdown");
            if (dropdown) dropdown.style.display = "none";
        }).catch(function (err) {
            setSaveStatus("error");
            showToast(err.message);
        });
    }

    // ── Labels ──

    function renderLabels(list) {
        var chips = document.getElementById("labels-chips");
        if (!chips) return;
        chips.innerHTML = "";
        (list || []).forEach(function (lbl) {
            var chip = document.createElement("span");
            chip.className = "badge " + escapeHtml(lbl.color) + " me-1 mb-1";
            chip.textContent = lbl.name;
            chips.appendChild(chip);
        });
    }

    function initLabels() {
        var addBtn = document.getElementById("labels-add-btn");
        var dropdown = document.getElementById("label-dropdown");
        var createBtn = document.getElementById("create-label-btn");
        if (!addBtn || !dropdown) return;

        addBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            var isVisible = dropdown.style.display !== "none";
            dropdown.style.display = isVisible ? "none" : "block";
            if (!isVisible) loadBoardLabels();
        });

        if (createBtn) {
            createBtn.addEventListener("click", function () {
                var nameInput = document.getElementById("new-label-name");
                var colorSelect = document.getElementById("new-label-color");
                var name = nameInput ? nameInput.value.trim() : "";
                var color = colorSelect ? colorSelect.value : "bg-primary";
                if (!name || !currentCardData) return;

                apiFetch("/api/kanban/boards/" + currentCardData.boardId + "/labels", {
                    method: "POST",
                    body: JSON.stringify({ name: name, color: color })
                }).then(function () {
                    if (nameInput) nameInput.value = "";
                    loadBoardLabels();
                }).catch(function (err) { showToast(err.message); });
            });
        }
    }

    function loadBoardLabels() {
        if (!currentCardData) return;
        var listEl = document.getElementById("label-list");
        if (!listEl) return;

        apiFetch("/api/kanban/boards/" + currentCardData.boardId + "/labels")
            .then(function (allLabels) {
                listEl.innerHTML = "";
                var cardLabelIds = (currentCardData.labels || []).map(function (l) { return l.id; });

                (allLabels || []).forEach(function (lbl) {
                    var item = document.createElement("div");
                    item.className = "dropdown-item d-flex align-items-center justify-content-between";
                    item.style.cursor = "pointer";
                    var isActive = cardLabelIds.indexOf(lbl.id) !== -1;
                    item.innerHTML =
                        '<span class="badge ' + escapeHtml(lbl.color) + ' me-2">' + escapeHtml(lbl.name) + "</span>" +
                        (isActive ? '<i class="bi bi-check2 text-primary"></i>' : "");
                    item.addEventListener("click", function (e) {
                        e.stopPropagation();
                        toggleLabel(lbl);
                    });
                    listEl.appendChild(item);
                });
            })
            .catch(function () {});
    }

    function toggleLabel(lbl) {
        if (!currentCardId || !currentCardData) return;

        apiFetch("/api/kanban/boards/" + currentCardData.boardId + "/labels/cards/" + currentCardId + "/" + lbl.id, {
            method: "POST"
        }).then(function (resp) {
            if (resp.action === "attached") {
                currentCardData.labels = currentCardData.labels || [];
                currentCardData.labels.push(lbl);
            } else {
                currentCardData.labels = (currentCardData.labels || []).filter(function (l) { return l.id !== lbl.id; });
            }
            renderLabels(currentCardData.labels);
            updateCardOnBoard(currentCardData);
            loadBoardLabels();
        }).catch(function (err) { showToast(err.message); });
    }

    // ── Border Colour Picker ──

    function initBorderColor() {
        var picker = document.getElementById("card-border-color-picker");
        if (!picker || !config.canEdit) return;

        picker.addEventListener("click", function (e) {
            var btn = e.target.closest(".card-color-swatch");
            if (!btn || !currentCardId) return;

            var color = btn.getAttribute("data-color"); // "" means none

            picker.querySelectorAll(".card-color-swatch").forEach(function (s) {
                s.classList.remove("active");
            });
            btn.classList.add("active");

            autoSaveField("borderColor", function () { return color || null; });
        });
    }

    // ── Init ──

    function init(cfg) {
        config = cfg;
        initDragula();
        initCardCreation();
        initColumnCreation();
        initColumnColors();
        initCardSidebar();
    }

    return { init: init };
})();
