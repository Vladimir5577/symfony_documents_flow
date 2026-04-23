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

    function escapeAttr(str) {
        if (str == null || str === "") {
            return "";
        }
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/"/g, "&quot;")
            .replace(/</g, "&lt;");
    }

    function kanbanAttachmentDownloadUrl(cardId, attId) {
        return "/api/kanban/cards/" + cardId + "/attachments/" + attId + "/download";
    }

    function kanbanAttachmentInlineUrl(cardId, attId) {
        return kanbanAttachmentDownloadUrl(cardId, attId) + "?inline=1";
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

        // ── Column drag & drop (admin only) ──
        if (config.isBoardAdmin) {
            var board = document.getElementById("kanban-board");
            if (board) {
                var columnDrake = dragula([board], {
                    direction: "horizontal",
                    moves: function (el, source, handle) {
                        if (!el.classList.contains("kanban-column")) return false;
                        var header = el.querySelector(".kanban-column-header");
                        return header && header.contains(handle);
                    },
                    invalid: function (el) {
                        return el.tagName === "BUTTON" || el.tagName === "INPUT" ||
                               el.tagName === "I" || el.closest && (el.closest("button") || el.closest("input"));
                    }
                });

                columnDrake.on("drop", function (el, target) {
                    if (!target) return;
                    var columnId = el.getAttribute("data-column-id");
                    var cols = Array.from(target.querySelectorAll(".kanban-column"));
                    var idx = cols.indexOf(el);
                    var position;

                    if (cols.length <= 1) {
                        position = 1.0;
                    } else if (idx === 0) {
                        var nextPos = parseFloat(cols[1].getAttribute("data-position") || "1");
                        position = nextPos / 2;
                    } else if (idx === cols.length - 1) {
                        var prevPos = parseFloat(cols[idx - 1].getAttribute("data-position") || "0");
                        position = prevPos + 1.0;
                    } else {
                        var prevP = parseFloat(cols[idx - 1].getAttribute("data-position") || "0");
                        var nextP = parseFloat(cols[idx + 1].getAttribute("data-position") || "0");
                        position = (prevP + nextP) / 2;
                    }

                    el.setAttribute("data-position", position);

                    apiFetch("/api/kanban/boards/" + config.boardId + "/columns/" + columnId, {
                        method: "PATCH",
                        body: JSON.stringify({ position: position })
                    }).then(function (data) {
                        if (data && data.position !== undefined) {
                            el.setAttribute("data-position", data.position);
                        }
                    }).catch(function (err) {
                        showToast(err.message);
                    });
                });
            }
        }
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
                (config.isBoardAdmin ? '<button type="button" class="kanban-card-dropdown-item rename-card-btn" data-card-id="' + data.id + '">' +
                '<i class="bi bi-pencil"></i> Переименовать' +
                '</button>' : '') +
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
                (config.isBoardAdmin ? '<button type="button" class="kanban-card-dropdown-item delete-card-btn" data-card-id="' + data.id + '">' +
                '<i class="bi bi-trash"></i> Удалить' +
                '</button>' : '') +
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

    // ── Column rename (admin only) ──

    function initColumnRename() {
        if (!config.isBoardAdmin) return;

        document.addEventListener("dblclick", function (e) {
            var titleSpan = e.target.closest(".column-title");
            if (!titleSpan) return;

            var header = titleSpan.closest(".kanban-column-header");
            var colEl = titleSpan.closest(".kanban-column");
            if (!header || !colEl) return;

            var columnId = colEl.getAttribute("data-column-id");
            var oldTitle = titleSpan.textContent.trim();

            var input = document.createElement("input");
            input.type = "text";
            input.className = "column-title-input";
            input.value = oldTitle;
            titleSpan.style.display = "none";
            titleSpan.parentNode.insertBefore(input, titleSpan.nextSibling);
            input.focus();
            input.select();

            var committed = false;

            function commit() {
                if (committed) return;
                committed = true;
                var newTitle = (input.value || "").trim();
                input.remove();
                titleSpan.style.display = "";

                if (!newTitle || newTitle === oldTitle) return;

                titleSpan.textContent = newTitle;
                apiFetch("/api/kanban/boards/" + config.boardId + "/columns/" + columnId, {
                    method: "PATCH",
                    body: JSON.stringify({ title: newTitle })
                }).catch(function (err) {
                    titleSpan.textContent = oldTitle;
                    showToast(err.message);
                });
            }

            input.addEventListener("blur", commit);
            input.addEventListener("keydown", function (ev) {
                if (ev.key === "Enter") { ev.preventDefault(); commit(); }
                if (ev.key === "Escape") { input.value = oldTitle; commit(); }
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
            } else {
                var newDesc = document.createElement("div");
                newDesc.className = "kanban-card-description";
                newDesc.textContent = preview;
                // Как в Twig: описание снаружи .kanban-card-header, не после .kanban-card-title (иначе попадает в flex-ряд с меню).
                var headerEl = cardEl.querySelector(".kanban-card-header");
                if (headerEl) {
                    headerEl.after(newDesc);
                } else if (titleEl) {
                    titleEl.after(newDesc);
                }
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
                    if (targetId === "pane-chat") {
                        scrollChatToBottom();
                        focusChatInput();
                    }
                    if (targetId === "pane-description") {
                        requestAnimationFrame(function () {
                            requestAnimationFrame(fitTaskDescriptionTextarea);
                        });
                    }
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
        initDescriptionAttachments();
        initDueAt();
        initAssignees();
        initAssignNewUserModal();
        initLabels();
        initBorderColor();
        initSidebarDropdownClose();
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
        // При открытии по клику на карточку фокус может теряться из-за анимации панели.
        // Ставим фокус с небольшой задержкой, чтобы курсор стабильно попадал в чат.
        setTimeout(focusChatInput, 0);
        setTimeout(focusChatInput, 120);

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

            // Description (высота под весь текст; без внутреннего скролла в textarea)
            var textarea = document.getElementById("task-description-textarea");
            if (textarea) {
                textarea.value = data.description || "";
                requestAnimationFrame(function () {
                    requestAnimationFrame(fitTaskDescriptionTextarea);
                });
            }

            // Priority
            var priSelect = document.getElementById("task-priority-select");
            if (priSelect) priSelect.value = data.priority || "";

            // Due date
            var dueInput = document.getElementById("task-due-at");
            if (dueInput) dueInput.value = data.dueDate ? data.dueDate.substring(0, 16) : "";

            // Author
            var authorEl = document.getElementById("task-author");
            if (authorEl) {
                authorEl.textContent = data.createdBy
                    ? ((data.createdBy.lastname || '') + ' ' + (data.createdBy.firstname || '')).trim() || '—'
                    : '—';
            }

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

            // Comments + chat attachments → unified chat stream
            var chatAttachments = (data.attachments || []).filter(function (a) { return a.context === 'chat'; });
            renderChatStream(data.comments || [], chatAttachments);
            startCommentPolling(cardId);

            // Подзадачи: синхронизация сайдбар + блок на карточке
            refreshSubtaskViews(currentCardId, data.subtasks || []);

            // Description attachments
            var descriptionAttachments = (data.attachments || []).filter(function (a) { return a.context === 'description'; });
            descriptionAttachments.sort(function (a, b) { return new Date(b.createdAt) - new Date(a.createdAt); });
            renderDescriptionAttachments(descriptionAttachments);

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

    function focusChatInput() {
        var input = document.getElementById("task-chat-input");
        if (!input || input.disabled || input.readOnly) return;
        requestAnimationFrame(function () {
            input.focus();
            var len = input.value ? input.value.length : 0;
            if (typeof input.setSelectionRange === "function") {
                input.setSelectionRange(len, len);
            }
        });
    }

    function renderChatStream(comments, chatAttachments) {
        var container = document.getElementById("chat-messages");
        if (!container) return;
        container.innerHTML = "";
        knownCommentIds.clear();

        // Merge comments and chat attachments into a single sorted stream
        var items = [];
        comments.forEach(function (c) {
            items.push({ type: 'comment', data: c, time: c.createdAt || '' });
        });
        (chatAttachments || []).forEach(function (a) {
            items.push({ type: 'attachment', data: a, time: a.createdAt || '' });
        });
        items.sort(function (a, b) {
            return a.time < b.time ? -1 : a.time > b.time ? 1 : 0;
        });

        if (!items.length) {
            container.innerHTML = '<div class="task-chat-msg task-chat-msg-placeholder"><div class="task-chat-msg-bubble"><div class="task-chat-msg-text">Сообщений пока нет.</div></div></div>';
            return;
        }

        items.forEach(function (item) {
            if (item.type === 'comment') {
                knownCommentIds.add(item.data.id);
                appendCommentBubble(item.data);
            } else {
                appendAttachmentBubble(item.data);
            }
        });
        scrollChatToBottom();
    }

    function getAvatarColor(name) {
        var hash = 0;
        for (var i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        var colors = ['#6366f1','#8b5cf6','#ec4899','#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#06b6d4','#3b82f6'];
        return colors[Math.abs(hash) % colors.length];
    }

    function isCommentEdited(comment) {
        if (!comment || !comment.updatedAt) return false;
        if (!comment.createdAt) return true;

        var createdTs = Date.parse(comment.createdAt);
        var updatedTs = Date.parse(comment.updatedAt);
        if (!Number.isNaN(createdTs) && !Number.isNaN(updatedTs)) {
            return updatedTs !== createdTs;
        }

        return String(comment.updatedAt) !== String(comment.createdAt);
    }

    function appendCommentBubble(c) {
        var container = document.getElementById("chat-messages");
        if (!container) return;

        var ph = container.querySelector(".task-chat-msg-placeholder");
        if (ph) ph.remove();

        var isOwn = String(c.authorId) === String(config.currentUserId);

        var actionsHtml = '';
        if (isOwn && config.canEdit) {
            actionsHtml =
                '<button type="button" class="btn btn-sm btn-link text-secondary p-0 ms-1 chat-msg-edit-btn" data-comment-id="' + c.id + '" title="Редактировать"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1 chat-msg-delete-btn" data-comment-id="' + c.id + '" title="Удалить"><i class="bi bi-trash"></i></button>';
        }

        var editedMark = isCommentEdited(c) ? ' <span class="text-muted fst-italic" style="font-size:0.75rem">(ред.)</span>' : '';
        var authorColor = getAvatarColor(c.authorName);
        var bgOpacity = isOwn ? '0.18' : '0.10';

        var msg = document.createElement("div");
        msg.className = "task-chat-msg" + (isOwn ? " task-chat-msg-own" : "");
        msg.setAttribute("data-comment-id", c.id);
        msg.innerHTML =
            '<div class="task-chat-msg-bubble" style="border-left-color:' + authorColor + ';border-right-color:' + (isOwn ? authorColor : 'transparent') + ';background:' + authorColor + bgOpacity + ';">' +
            '<span class="task-chat-msg-author-name" style="color:' + authorColor + '">' + escapeHtml(c.authorName) + '</span>' +
            '<div class="task-chat-msg-text">' + escapeHtml(c.body) + '</div>' +
            '<div class="task-chat-msg-meta">' +
            '<span class="task-chat-msg-time">' + formatShortDate(c.createdAt) + editedMark + '</span>' +
            actionsHtml + '</div></div>';
        container.appendChild(msg);

        var editBtn = msg.querySelector(".chat-msg-edit-btn");
        if (editBtn) {
            editBtn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                handleEditComment(parseInt(c.id), msg, c.body);
            });
        }
        var deleteBtn = msg.querySelector(".chat-msg-delete-btn");
        if (deleteBtn) {
            deleteBtn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                handleDeleteComment(parseInt(c.id), msg);
            });
        }
    }

    function handleEditComment(commentId, msgEl, currentBody) {
        var textEl = msgEl.querySelector(".task-chat-msg-text");
        if (!textEl) return;

        // Check if already editing
        var existingInput = msgEl.querySelector(".chat-msg-edit-input");
        if (existingInput) return;

        var saveBtn = msgEl.querySelector(".chat-msg-edit-btn");
        if (!saveBtn) return;

        var input = document.createElement("textarea");
        input.className = "form-control form-control-sm chat-msg-edit-input";
        input.value = currentBody;
        input.rows = 2;
        textEl.replaceWith(input);
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);

        // Replace pencil icon with save icon
        saveBtn.innerHTML = '<i class="bi bi-check-lg"></i>';
        saveBtn.classList.remove("text-secondary");
        saveBtn.classList.add("text-success");
        saveBtn.setAttribute("title", "Сохранить");

        function finishEdit() {
            var newBody = input.value.trim();
            if (!newBody || newBody === currentBody) {
                renderChatStreamFromCurrentData();
                return;
            }

            apiFetch("/api/kanban/cards/" + currentCardId + "/comments/" + commentId, {
                method: "PUT",
                body: JSON.stringify({ body: newBody })
            }).then(function (data) {
                if (currentCardData) {
                    var idx = (currentCardData.comments || []).findIndex(function (c) { return c.id == commentId; });
                    if (idx !== -1) currentCardData.comments[idx].body = newBody;
                }
                renderChatStreamFromCurrentData();
            }).catch(function (err) {
                showToast(err.message);
                renderChatStreamFromCurrentData();
            });
        }

        input.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                finishEdit();
            }
            if (e.key === "Escape") {
                renderChatStreamFromCurrentData();
            }
        });
        saveBtn.onclick = function (e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            finishEdit();
        };
    }

    function handleDeleteComment(commentId, msgEl) {
        if (!confirm("Удалить сообщение?")) return;

        apiFetch("/api/kanban/cards/" + currentCardId + "/comments/" + commentId, {
            method: "DELETE"
        }).then(function () {
            msgEl.remove();
            if (currentCardData) {
                currentCardData.comments = (currentCardData.comments || []).filter(function (c) { return c.id != commentId; });
            }
        }).catch(function (err) { showToast(err.message); });
    }

    function renderChatStreamFromCurrentData() {
        var chatAttachments = (currentCardData && currentCardData.attachments || []).filter(function (a) { return a.context === 'chat'; });
        renderChatStream(currentCardData && currentCardData.comments || [], chatAttachments);
    }

    function appendAttachmentBubble(att) {
        var container = document.getElementById("chat-messages");
        if (!container) return;

        var ph = container.querySelector(".task-chat-msg-placeholder");
        if (ph) ph.remove();

        var sizeKb = Math.round((att.sizeBytes || 0) / 1024);
        var downloadUrl = kanbanAttachmentDownloadUrl(currentCardId, att.id);
        var inlineUrl = kanbanAttachmentInlineUrl(currentCardId, att.id);
        var isImage = att.contentType && att.contentType.indexOf("image/") === 0;

        var deleteBtn = config.canEdit
            ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2 chat-att-delete-btn" data-att-id="' + att.id + '" title="Удалить"><i class="bi bi-trash"></i></button>'
            : '';

        var bodyHtml;
        if (isImage) {
            var previewSrc = att.previewUrl || inlineUrl;
            bodyHtml =
                '<button type="button" class="btn btn-link p-0 border-0 d-block text-start kanban-att-img-modal-trigger" data-bs-toggle="modal" data-bs-target="#imagePreviewModal" data-image-url="' + escapeAttr(inlineUrl) + '" aria-label="Просмотр изображения">' +
                '<img src="' + escapeAttr(previewSrc) + '" class="chat-img-preview" alt="' + escapeHtml(att.filename) + '">' +
                '</button>' +
                '<small class="text-muted d-block mt-1">' +
                '<a href="' + escapeAttr(downloadUrl) + '" class="text-muted text-decoration-none"><i class="bi bi-paperclip me-1"></i>' + escapeHtml(att.filename) + '</a> · ' + sizeKb + ' KB' +
                deleteBtn +
                '</small>';
        } else {
            bodyHtml =
                '<a href="' + downloadUrl + '" class="text-decoration-none">' +
                '<i class="bi bi-paperclip me-1"></i>' + escapeHtml(att.filename) +
                '</a>' +
                '<small class="text-muted ms-2">' + sizeKb + ' KB</small>' +
                deleteBtn;
        }

        var authorName = att.authorName || config.currentUserName;
        var isOwn = String(att.authorId) === String(config.currentUserId);
        var authorColor = getAvatarColor(authorName);
        var bgOpacity = isOwn ? '0.18' : '0.10';

        var msg = document.createElement("div");
        msg.className = "task-chat-msg" + (isOwn ? " task-chat-msg-own" : "");
        msg.setAttribute("data-attachment-id", att.id);
        msg.innerHTML =
            '<div class="task-chat-msg-bubble" style="border-left-color:' + authorColor + ';border-right-color:' + (isOwn ? authorColor : 'transparent') + ';background:' + authorColor + bgOpacity + ';">' +
            '<span class="task-chat-msg-author-name" style="color:' + authorColor + '">' + escapeHtml(authorName) + '</span>' +
            '<div class="task-chat-msg-text">' + bodyHtml + '</div>' +
            '<div class="task-chat-msg-meta">' +
            '<span class="task-chat-msg-time">' + formatShortDate(att.createdAt || new Date().toISOString()) +
            "</span></div></div>";
        container.appendChild(msg);

        var delBtn = msg.querySelector(".chat-att-delete-btn");
        if (delBtn) {
            delBtn.addEventListener("click", function () {
                if (!confirm("Удалить вложение?")) return;
                apiFetch("/api/kanban/cards/" + currentCardId + "/attachments/" + att.id, {
                    method: "DELETE"
                }).then(function () {
                    msg.remove();
                    if (currentCardData) {
                        currentCardData.attachments = (currentCardData.attachments || []).filter(function (a) { return a.id !== att.id; });
                    }
                }).catch(function (err) { showToast(err.message); });
            });
        }
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
                authorId: config.currentUserId,
                createdAt: new Date().toISOString()
            };
            knownCommentIds.add(tempId);
            appendCommentBubble(tempComment);
            scrollChatToBottom();
            focusChatInput();

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
                // [KANBAN: валидация расширения — ВРЕМЕННО ОТКЛЮЧЕНА]
                /*
                var ext = file.name.split(".").pop().toLowerCase();
                var allowed = ["pdf", "png", "jpg", "jpeg", "webp", "docx", "xlsx"];
                if (allowed.indexOf(ext) === -1) {
                    showToast("Тип файла ." + ext + " не поддерживается.");
                    fileInput.value = "";
                    return;
                }
                */

                var formData = new FormData();
                formData.append("file", file);
                formData.append("context", "chat");

                apiFetch("/api/kanban/cards/" + currentCardId + "/attachments", {
                    method: "POST",
                    body: formData
                }).then(function (att) {
                    fileInput.value = "";
                    att.context = "chat";
                    appendAttachmentBubble(att);
                    scrollChatToBottom();
                    if (currentCardData && att) {
                        currentCardData.attachments = currentCardData.attachments || [];
                        currentCardData.attachments.push(att);
                        renderDescriptionAttachments(currentCardData.attachments.filter(function (a) { return a.context === 'description'; }));
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
        }, 180000);
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

    function fitTaskDescriptionTextarea() {
        var textarea = document.getElementById("task-description-textarea");
        if (!textarea) return;
        textarea.style.height = "auto";
        textarea.style.height = textarea.scrollHeight + "px";
    }

    function initDescription() {
        var textarea = document.getElementById("task-description-textarea");
        if (!textarea) return;

        function onInput() {
            requestAnimationFrame(fitTaskDescriptionTextarea);
        }
        textarea.addEventListener("input", onInput);
        textarea.addEventListener("paste", function () {
            requestAnimationFrame(function () {
                requestAnimationFrame(fitTaskDescriptionTextarea);
            });
        });

        if (!config.canEdit) return;

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
                    if (!confirm("Удалить подзадачу?")) return;
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

    // ── Description Attachments ──

    function isImageAttachment(att) {
        if (att.contentType && att.contentType.indexOf("image/") === 0) {
            return true;
        }
        var ext = (att.filename || "").split(".").pop().toLowerCase();
        return ["png", "jpg", "jpeg", "webp"].indexOf(ext) !== -1;
    }

    function renderDescriptionAttachments(attachments) {
        var container = document.getElementById("description-attachments-list");
        if (!container) return;
        container.innerHTML = "";

        if (!attachments.length) {
            container.innerHTML = '<div class="small text-muted">Нет вложений.</div>';
            return;
        }

        attachments.forEach(function (att) {
            var wrapper = document.createElement("div");
            wrapper.className = "mb-2";

            var downloadUrl = kanbanAttachmentDownloadUrl(currentCardId, att.id);
            var inlineUrl = kanbanAttachmentInlineUrl(currentCardId, att.id);
            var sizeKb = Math.round(att.sizeBytes / 1024);

            if (isImageAttachment(att)) {
                var previewSrc = att.previewUrl || inlineUrl;
                var imgWrap = document.createElement("div");
                imgWrap.style.position = "relative";
                imgWrap.style.display = "inline-block";
                imgWrap.innerHTML =
                    '<button type="button" class="btn btn-link p-0 border-0 mb-0 text-start kanban-att-img-modal-trigger" data-bs-toggle="modal" data-bs-target="#imagePreviewModal" data-image-url="' + escapeAttr(inlineUrl) + '" aria-label="Просмотр изображения">' +
                        '<img src="' + escapeAttr(previewSrc) + '" class="chat-img-preview" alt="' + escapeHtml(att.filename) + '" style="max-width:240px;">' +
                    "</button>" +
                    (config.canEdit
                        ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 att-delete-btn" data-att-id="' + att.id + '" style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,0.6);border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-trash" style="color:#fff;"></i></button>'
                        : "");
                wrapper.appendChild(imgWrap);

                var caption = document.createElement("div");
                caption.className = "small text-muted mt-1";
                caption.innerHTML =
                    '<a href="' + escapeAttr(downloadUrl) + '" class="text-muted text-decoration-none">' + escapeHtml(att.filename) + "</a> (" + sizeKb + " KB)";
                wrapper.appendChild(caption);
            } else {
                var item = document.createElement("div");
                item.className = "attachment-item";
                item.innerHTML =
                    "<div>" +
                    '<a href="' + downloadUrl + '" class="text-decoration-none">' +
                    '<i class="bi bi-paperclip me-1"></i>' + escapeHtml(att.filename) +
                    "</a>" +
                    '<small class="text-muted ms-2">' + sizeKb + " KB</small>" +
                    "</div>" +
                    (config.canEdit ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 att-delete-btn" data-att-id="' + att.id + '"><i class="bi bi-trash"></i></button>' : "");
                wrapper.appendChild(item);
            }

            container.appendChild(wrapper);

            var delBtn = wrapper.querySelector(".att-delete-btn");
            if (delBtn) {
                delBtn.addEventListener("click", function () {
                    if (!confirm("Удалить вложение?")) return;
                    apiFetch("/api/kanban/cards/" + currentCardId + "/attachments/" + att.id, {
                        method: "DELETE"
                    }).then(function () {
                        wrapper.remove();
                        if (currentCardData) {
                            currentCardData.attachments = (currentCardData.attachments || []).filter(function (a) { return a.id !== att.id; });
                        }
                    }).catch(function (err) { showToast(err.message); });
                });
            }
        });
    }

    function initDescriptionAttachments() {
        var input = document.getElementById("description-attachment-input");
        var triggerBtn = document.getElementById("description-attachment-trigger");
        var fileNameEl = document.getElementById("description-attachment-filename");
        if (!input || !config.canEdit) return;

        if (triggerBtn) {
            triggerBtn.addEventListener("click", function () {
                input.click();
            });
        }

        input.addEventListener("change", function () {
            if (!input.files.length || !currentCardId) return;

            var file = input.files[0];
            if (fileNameEl) fileNameEl.textContent = file.name || "Файл не выбран";
            // [KANBAN: валидация расширения — ВРЕМЕННО ОТКЛЮЧЕНА]
            /*
            var ext = file.name.split(".").pop().toLowerCase();
            var allowed = ["pdf", "png", "jpg", "jpeg", "webp", "docx", "xlsx"];
            if (allowed.indexOf(ext) === -1) {
                showToast("Тип файла ." + ext + " не поддерживается.");
                input.value = "";
                if (fileNameEl) fileNameEl.textContent = "Файл не выбран";
                return;
            }
            */

            var formData = new FormData();
            formData.append("file", file);
            formData.append("context", "description");

            apiFetch("/api/kanban/cards/" + currentCardId + "/attachments", {
                method: "POST",
                body: formData
            }).then(function (newAtt) {
                input.value = "";
                if (fileNameEl) fileNameEl.textContent = "Файл не выбран";
                newAtt.context = "description";
                if (currentCardData && newAtt) {
                    currentCardData.attachments = currentCardData.attachments || [];
                    currentCardData.attachments.push(newAtt);
                    var desc = currentCardData.attachments.filter(function (a) { return a.context === 'description'; });
                    desc.sort(function (a, b) { return new Date(b.createdAt) - new Date(a.createdAt); });
                    renderDescriptionAttachments(desc);
                }
            }).catch(function (err) {
                showToast(err.message);
                input.value = "";
                if (fileNameEl) fileNameEl.textContent = "Файл не выбран";
            });
        });
    }

    // ── Close sidebar dropdowns ──

    function closeSidebarDropdowns(except) {
        var ids = ["assignee-dropdown", "label-dropdown"];
        ids.forEach(function (id) {
            if (id === except) return;
            var el = document.getElementById(id);
            if (el) el.style.display = "none";
        });
    }

    function initSidebarDropdownClose() {
        document.addEventListener("click", function (e) {
            var adrop = document.getElementById("assignee-dropdown");
            var ldrop = document.getElementById("label-dropdown");
            if (adrop && adrop.style.display !== "none" &&
                !e.target.closest("#assignee-dropdown") &&
                !e.target.closest("#assignee-add-btn")) {
                adrop.style.display = "none";
            }
            if (ldrop && ldrop.style.display !== "none" &&
                !e.target.closest("#label-dropdown") &&
                !e.target.closest("#labels-add-btn")) {
                ldrop.style.display = "none";
            }
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
            closeSidebarDropdowns("assignee-dropdown");
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

        results.innerHTML = '<div class="text-center text-muted py-2"><span class="spinner-border spinner-border-sm me-1"></span>Загрузка…</div>';

        var url = "/api/kanban/users/search?query=" + encodeURIComponent(query);
        if (config.projectId) url += "&project_id=" + config.projectId;
        apiFetch(url)
            .then(function (users) {
                results.innerHTML = "";
                if (!(users || []).length) {
                    results.innerHTML = '<div class="text-muted text-center py-2">Не найдено</div>';
                    return;
                }
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
        var canRemove = config.canEdit;
        (list || []).forEach(function (lbl) {
            var chip = document.createElement("span");
            chip.className = "badge " + escapeHtml(lbl.color) + " me-1 mb-1";
            if (canRemove) {
                chip.style.cursor = "pointer";
                chip.innerHTML = escapeHtml(lbl.name) + ' <span style="opacity:0.7">&times;</span>';
                chip.addEventListener("click", function () { toggleLabel(lbl); });
            } else {
                chip.textContent = lbl.name;
            }
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
            closeSidebarDropdowns("label-dropdown");
            var isVisible = dropdown.style.display !== "none";
            dropdown.style.display = isVisible ? "none" : "block";
            if (!isVisible) loadBoardLabels();
        });

        if (createBtn) {
            createBtn.addEventListener("click", function (e) {
                e.stopPropagation();
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
                    if (colorSelect) colorSelect.value = "bg-primary";
                    loadBoardLabels();
                }).catch(function (err) { showToast(err.message); });
            });
        }
    }

    function loadBoardLabels() {
        if (!currentCardData) return;
        var listEl = document.getElementById("label-list");
        if (!listEl) return;

        listEl.innerHTML = '<div class="text-center text-muted py-2"><span class="spinner-border spinner-border-sm me-1"></span>Загрузка…</div>';

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
        initColumnRename();
        initCardSidebar();

        var params = new URLSearchParams(window.location.search);
        var cardId = params.get("card");
        if (cardId) {
            setTimeout(function () { openSidebar(cardId); }, 100);
        }
    }

    return { init: init, apiFetch: apiFetch, showToast: showToast };
})();
