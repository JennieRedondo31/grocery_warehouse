/* ============================================================
   NOTIFICATIONS — bell + dropdown (reusable sa tanang dashboards)
   - Mo-inject sa bell sulod sa ".topbar-user"
   - Gamit sa axios nga naa na sa page (i-load ni DUHA-ON sa axios)
   - Walay bell kung admin
============================================================ */
(() => {
    const NOTIF_API = "http://localhost/grocery_warehouse/notifications_api.php";
    const POLL_MS = 30000;

    const getUser = () => {
        try { return JSON.parse(sessionStorage.getItem("user") || "null"); }
        catch (e) { return null; }
    };

    const user = getUser();
    if (!user) return;

    const roleKey = String(user.RoleName || "").toLowerCase().replace(/[^a-z]/g, "");
    if (roleKey === "admin" || roleKey === "administrator") return;

    const esc = (v) => String(v ?? "")
        .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;").replaceAll("'", "&#039;");

    const TYPE_META = {
        po_today:            { icon: "📦", cls: "n-amber" },
        po_tomorrow:         { icon: "🗓️", cls: "n-amber" },
        po_overdue:          { icon: "⏰", cls: "n-red" },
        po_pending:          { icon: "🧾", cls: "n-blue" },
        po_approved:         { icon: "✅", cls: "n-green" },
        pret_pending:        { icon: "↩️", cls: "n-blue" },
        sret_pending:        { icon: "↩️", cls: "n-blue" },
        adj_pending:         { icon: "🛠️", cls: "n-blue" },
        receiving_submitted: { icon: "🧾", cls: "n-blue" },
        receiving_completed: { icon: "✅", cls: "n-green" },
        receiving_approved:  { icon: "✅", cls: "n-green" },
        receiving_variance:  { icon: "⚠️", cls: "n-amber" },
        release_completed:   { icon: "🚚", cls: "n-green" },
        stock_updated:       { icon: "📈", cls: "n-green" },
        stock_depleted:      { icon: "🚫", cls: "n-red" },
        zero_stock:          { icon: "🚫", cls: "n-red" },
        low_stock:           { icon: "📉", cls: "n-amber" },
        product_new_zero:    { icon: "🆕", cls: "n-red" },
        product_new:         { icon: "🆕", cls: "n-blue" },
        supplier_new:        { icon: "🏭", cls: "n-blue" },
        tx_approved:         { icon: "✅", cls: "n-green" },
        tx_rejected:         { icon: "❌", cls: "n-red" },
        tx_cancelled:        { icon: "🚫", cls: "n-gray" }
    };
    const metaOf = (t) => TYPE_META[t] || { icon: "🔔", cls: "n-gray" };

    const timeAgo = (sec) => {
        sec = Number(sec) || 0;
        if (sec < 60) return "just now";
        if (sec < 3600) return Math.floor(sec / 60) + " min ago";
        if (sec < 86400) return Math.floor(sec / 3600) + " hr ago";
        if (sec < 172800) return "Yesterday";
        return Math.floor(sec / 86400) + " days ago";
    };

    async function call(operation, payload = {}) {
        const fd = new FormData();
        fd.append("operation", operation);
        fd.append("json", JSON.stringify({ ...payload, LoggedInUserID: user.UserID }));
        try {
            const res = await axios.post(NOTIF_API, fd);
            return res.data;
        } catch (e) {
            return { success: false, data: null };
        }
    }

    /* ---------- Navigation: ilis sa section/tab kung naa ang goToSection ---------- */
    const SECTION_MAP = {
        po:              ["section-expected-po", "section-po", "transactions-section"],
        receiving:       ["section-receivings", "transactions-section"],
        release:         ["releases-section", "transactions-section"],
        product:         ["section-product", "products-section", "product-section"],
        supplier:        ["section-supplier", "suppliers-section"],
        adjustment:      ["adjustments-section", "transactions-section"],
        stock_return:    ["returns-section", "transactions-section"],
        purchase_return: ["section-pr", "transactions-section"]
    };
    const TAB_MAP = {
        po: "tab-tx-po", receiving: "tab-tx-recv", release: "tab-tx-rel",
        adjustment: "tab-tx-adj", stock_return: "tab-tx-sret", purchase_return: "tab-tx-pret"
    };

    /* Type-based nga target (mudaog kaysa RefType).
       Ang una nga ID nga naa sa kasamtangan nga page ang gamiton:
         - Manager / Inventory : stock-section, lowstock-section
         - Releasing           : stock-section (walay lowstock page)
         - Purchasing          : section-stock (walay lowstock page)           */
    const TYPE_SECTION_MAP = {
        stock_depleted: ["stock-section", "section-stock"],
        zero_stock:     ["stock-section", "section-stock"],
        stock_updated:  ["stock-section", "section-stock"],
        low_stock:      ["lowstock-section", "stock-section", "section-stock"]
    };

    function navigateTo(refType, type) {
        if (typeof goToSection !== "function") return;

        const typeIds = TYPE_SECTION_MAP[type];
        if (typeIds) {
            const tid = typeIds.find(i => document.getElementById(i));
            if (tid) { goToSection(tid); return; }
        }

        const id = (SECTION_MAP[refType] || []).find(i => document.getElementById(i));
        if (id) goToSection(id, TAB_MAP[refType]);
    }

    let wrap, bell, badge, panel, listEl, titleCount, viewAllBtn;
    let showAll = false;
    let pollTimer = null;

    function build() {
        const host = document.querySelector(".topbar-user");
        if (!host) return false;

        wrap = document.createElement("div");
        wrap.className = "notif-wrap";
        wrap.innerHTML = `
            <button class="notif-bell" type="button" aria-label="Notifications" aria-expanded="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <span class="notif-badge" hidden>0</span>
            </button>
            <div class="notif-panel" hidden>
                <div class="notif-head">
                    <strong>Notifications <span class="notif-head-count">(0)</span></strong>
                    <button type="button" class="notif-markall">Mark all read</button>
                </div>
                <div class="notif-list"></div>
                <button type="button" class="notif-viewall">View all notifications</button>
            </div>
        `;
        host.insertBefore(wrap, host.firstChild);

        bell = wrap.querySelector(".notif-bell");
        badge = wrap.querySelector(".notif-badge");
        panel = wrap.querySelector(".notif-panel");
        listEl = wrap.querySelector(".notif-list");
        titleCount = wrap.querySelector(".notif-head-count");
        viewAllBtn = wrap.querySelector(".notif-viewall");

        bell.addEventListener("click", (e) => {
            e.stopPropagation();
            panel.hidden ? openPanel() : closePanel();
        });

        wrap.querySelector(".notif-markall").addEventListener("click", async (e) => {
            e.stopPropagation();
            await call("markAllNotificationsRead");
            refresh();
        });

        viewAllBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            showAll = !showAll;
            viewAllBtn.textContent = showAll ? "Show less" : "View all notifications";
            refresh();
        });

        listEl.addEventListener("click", async (e) => {
            const item = e.target.closest(".notif-item");
            if (!item) return;
            const id = Number(item.dataset.id);
            const refType = item.dataset.reftype;
            const type = item.dataset.type;

            if (item.classList.contains("unread")) {
                await call("markNotificationRead", { NotificationID: id });
            }
            closePanel();

            navigateTo(refType, type);
            refresh();
        });

        panel.addEventListener("click", (e) => e.stopPropagation());
        document.addEventListener("click", closePanel);
        document.addEventListener("keydown", (e) => { if (e.key === "Escape") closePanel(); });

        return true;
    }

    function openPanel() {
        panel.hidden = false;
        bell.setAttribute("aria-expanded", "true");
        refresh();
    }

    function closePanel() {
        if (!panel) return;
        panel.hidden = true;
        bell.setAttribute("aria-expanded", "false");
    }

    function render(data) {
        const unread = Number(data.unread || 0);

        badge.hidden = unread === 0;
        badge.textContent = unread > 99 ? "99+" : String(unread);
        titleCount.textContent = `(${unread})`;

        const rows = data.notifications || [];
        if (!rows.length) {
            listEl.innerHTML = `<div class="notif-empty">No notifications yet.</div>`;
            return;
        }

        listEl.innerHTML = rows.map(n => {
            const m = metaOf(n.Type);
            const unreadCls = Number(n.IsRead) === 0 ? "unread" : "";
            return `
                <div class="notif-item ${unreadCls}" data-id="${esc(n.NotificationID)}" data-reftype="${esc(n.RefType || "")}" data-type="${esc(n.Type || "")}">
                    <div class="notif-icon ${m.cls}">${m.icon}</div>
                    <div class="notif-body">
                        <div class="notif-title">${esc(n.Title)}</div>
                        <div class="notif-sub">${esc(n.Message || "")}${n.Message ? " · " : ""}${timeAgo(n.AgeSeconds)}</div>
                    </div>
                    ${unreadCls ? '<span class="notif-dot"></span>' : ""}
                </div>`;
        }).join("");
    }

    async function refresh() {
        const res = await call("getNotifications", { limit: showAll ? 100 : 30 });
        if (!res || !res.success || !res.data) return;

        if (res.data.disabled) {          // admin: tangtangon ang bell
            if (wrap) wrap.remove();
            clearInterval(pollTimer);
            return;
        }
        render(res.data);
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (!build()) return;
        refresh();
        pollTimer = setInterval(() => {
            if (!document.hidden) refresh();
        }, POLL_MS);
    });
})();