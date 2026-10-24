<?php
/**
 * notifications_helper.php
 * Gamiton sa bisan unsang API:  require_once __DIR__ . "/notifications_helper.php";
 * Nanginahanglan og $conn gikan sa db.php
 *
 * Role stems (LIKE match, busa mo-match sa "Purchasing Staff", "Warehouse Manager", etc.)
 */
define('NOTIF_R_PURCHASING', 'purchas');
define('NOTIF_R_INVENTORY',  'inventory');
define('NOTIF_R_RECEIVING',  'receiv');
define('NOTIF_R_RELEASING',  'releas');
define('NOTIF_R_MANAGER',    'manager');

/* ---------- BASIC INSERTS ---------- */

/**
 * Himoan og notification ang TANANG active users gawas sa admin.
 * Dili mo-throw og error (dili mabuak ang main action kung mapakyas).
 */
function createNotificationForAll(
    PDO $conn, string $type, string $title, string $message = "",
    ?string $refType = null, ?int $refId = null, ?string $dedupeKey = null, int $excludeUserID = 0
) {
    try {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO notifications
                (UserID, Type, Title, Message, RefType, RefID, DedupeKey)
            SELECT u.UserID, :type, :title, :msg, :rtype, :rid, :dkey
            FROM `user` u
            LEFT JOIN `role` r ON u.RoleID = r.RoleID
            WHERE LOWER(TRIM(COALESCE(u.IsArchived, 'No'))) <> 'yes'
              AND LOWER(TRIM(COALESCE(u.UserStatus, ''))) = 'active'
              AND LOWER(TRIM(COALESCE(r.RoleName, ''))) NOT IN ('admin', 'administrator')
              AND u.UserID <> :ex
        ");
        $stmt->execute([
            ":type" => $type, ":title" => $title, ":msg" => $message,
            ":rtype" => $refType, ":rid" => $refId, ":dkey" => $dedupeKey, ":ex" => $excludeUserID
        ]);
    } catch (Throwable $e) {}
}

/* Para sa specific roles lang. Admin kanunay gi-exclude. */
function createNotificationForRoles(
    PDO $conn, array $roles, string $type, string $title, string $message = "",
    ?string $refType = null, ?int $refId = null, ?string $dedupeKey = null, int $excludeUserID = 0
) {
    if (empty($roles)) return;
    try {
        $params = [
            ":type" => $type, ":title" => $title, ":msg" => $message,
            ":rtype" => $refType, ":rid" => $refId, ":dkey" => $dedupeKey, ":ex" => $excludeUserID
        ];
        $likes = [];
        foreach (array_values($roles) as $i => $r) {
            $likes[] = "LOWER(r.RoleName) LIKE :role$i";
            $params[":role$i"] = "%" . strtolower(trim($r)) . "%";
        }
        $stmt = $conn->prepare("
            INSERT IGNORE INTO notifications
                (UserID, Type, Title, Message, RefType, RefID, DedupeKey)
            SELECT u.UserID, :type, :title, :msg, :rtype, :rid, :dkey
            FROM `user` u
            LEFT JOIN `role` r ON u.RoleID = r.RoleID
            WHERE LOWER(TRIM(COALESCE(u.IsArchived, 'No'))) <> 'yes'
              AND LOWER(TRIM(COALESCE(u.UserStatus, ''))) = 'active'
              AND LOWER(TRIM(COALESCE(r.RoleName, ''))) NOT IN ('admin', 'administrator')
              AND (" . implode(" OR ", $likes) . ")
              AND u.UserID <> :ex
        ");
        $stmt->execute($params);
    } catch (Throwable $e) {}
}

/* Para sa isa ka user (e.g. "submitted by you") */
function createNotificationForUser(
    PDO $conn, int $userID, string $type, string $title, string $message = "",
    ?string $refType = null, ?int $refId = null, ?string $dedupeKey = null
) {
    if ($userID <= 0) return;
    try {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO notifications
                (UserID, Type, Title, Message, RefType, RefID, DedupeKey)
            VALUES (:uid, :type, :title, :msg, :rtype, :rid, :dkey)
        ");
        $stmt->execute([
            ":uid" => $userID, ":type" => $type, ":title" => $title, ":msg" => $message,
            ":rtype" => $refType, ":rid" => $refId, ":dkey" => $dedupeKey
        ]);
    } catch (Throwable $e) {}
}

function notifUserName(PDO $conn, int $userID): string
{
    try {
        $stmt = $conn->prepare("
            SELECT TRIM(CONCAT(COALESCE(FirstName,''), ' ', COALESCE(LastName,''))) AS N,
                   UserName
            FROM `user` WHERE UserID = :id LIMIT 1
        ");
        $stmt->execute([":id" => $userID]);
        $r = $stmt->fetch();
        if (!$r) return "someone";
        return $r["N"] !== "" ? $r["N"] : $r["UserName"];
    } catch (Throwable $e) { return "someone"; }
}

/* Throttle aron dili mabug-at ang DB (polling kada 30s kada user) */
function notifThrottle(string $name, int $seconds): bool
{
    $f = sys_get_temp_dir() . "/gw_notif_" . $name . ".txt";
    $last = @filemtime($f);
    if ($last && (time() - $last) < $seconds) return false;
    @touch($f);
    return true;
}

/* ---------- STOCK (parehas sa manager: approved/completed ra ang maihap) ---------- */

function notifStockJoins(): string
{
    return "
        LEFT JOIN (
            SELECT rl.ProductID AS pid, SUM(rl.ReceivedQuantity) AS qty
            FROM receiving_line rl
            INNER JOIN receiving h ON h.ReceivingID = rl.ReceivingID
            LEFT JOIN status st ON h.StatusID = st.StatusID
            WHERE rl.IsArchived = 'No' AND h.IsArchived = 'No'
              AND LOWER(TRIM(COALESCE(st.StatusName, ''))) IN ('approved','completed','received')
            GROUP BY rl.ProductID
        ) rin ON rin.pid = p.ProductID
        LEFT JOIN (
            SELECT l.ProductID AS pid, SUM(l.QuantityReleased) AS qty
            FROM stock_release_line l
            INNER JOIN stock_release h ON h.ReleaseID = l.ReleaseID
            LEFT JOIN status st ON h.Status_ID = st.StatusID
            WHERE l.IsArchived = 'No' AND h.IsArchived = 'No'
              AND LOWER(TRIM(COALESCE(st.StatusName, ''))) IN ('approved','completed','released')
            GROUP BY l.ProductID
        ) rel ON rel.pid = p.ProductID
        LEFT JOIN (
            SELECT l.Product_ID AS pid, SUM(l.Quantity) AS qty
            FROM stock_return_line l
            INNER JOIN stock_return h ON h.Stock_Return_ID = l.Stock_Return_ID
            LEFT JOIN status st ON h.Status_ID = st.StatusID
            WHERE l.IsArchived = 'No' AND h.IsArchived = 'No'
              AND LOWER(TRIM(COALESCE(st.StatusName, ''))) IN ('approved','completed')
            GROUP BY l.Product_ID
        ) sret ON sret.pid = p.ProductID
        LEFT JOIN (
            SELECT l.Product_ID AS pid, SUM(l.Quantity) AS qty
            FROM purchase_return_line l
            INNER JOIN purchase_return h ON h.Purchase_Return_ID = l.Purchase_Return_ID
            LEFT JOIN status st ON h.Status_ID = st.StatusID
            WHERE l.IsArchived = 'No' AND h.IsArchived = 'No'
              AND LOWER(TRIM(COALESCE(st.StatusName, ''))) IN ('approved','completed')
            GROUP BY l.Product_ID
        ) pret ON pret.pid = p.ProductID
        LEFT JOIN (
            SELECT l.Product_ID AS pid,
                   SUM(CASE
                       WHEN LOWER(TRIM(l.Adjustment_Type)) = 'increase' THEN  l.Adjustment_Quantity
                       WHEN LOWER(TRIM(l.Adjustment_Type)) = 'decrease' THEN -l.Adjustment_Quantity
                       ELSE 0 END) AS qty
            FROM inventory_adjustment_line l
            INNER JOIN inventory_adjustment h ON h.Adjustment_ID = l.Adjustment_ID
            LEFT JOIN status st ON h.Status_ID = st.StatusID
            WHERE l.IsArchived = 'No' AND h.IsArchived = 'No'
              AND LOWER(TRIM(COALESCE(st.StatusName, ''))) IN ('approved','completed')
            GROUP BY l.Product_ID
        ) adj ON adj.pid = p.ProductID
    ";
}

function notifStockExpr(): string
{
    return "(COALESCE(rin.qty,0) - COALESCE(rel.qty,0) + COALESCE(sret.qty,0)
             - COALESCE(pret.qty,0) + COALESCE(adj.qty,0))";
}

/**
 * Out of stock / low stock -> Purchasing, Inventory, Manager, Releasing.
 * Out-of-stock = naay history sa stock pero 0 na karon.
 * (Ang bag-ong product nga wala pa gyud stock gi-notify na sa pag-add ni admin.)
 * DedupeKey nagbag-o kung mausab ang qty, aron dili spam.
 */
function syncStockNotifications(PDO $conn)
{
    try {
        $rows = $conn->query("
            SELECT p.ProductID, p.ProductName, p.MinStockLevel,
                   " . notifStockExpr() . " AS OnHand,
                   COALESCE(rin.qty, 0) AS Received,
                   COALESCE(rel.qty, 0) AS Released
            FROM product p " . notifStockJoins() . "
            WHERE p.IsArchived = 'No'
            HAVING (OnHand <= 0 AND Received > 0)
                OR (OnHand > 0 AND OnHand < p.MinStockLevel)
            LIMIT 100
        ")->fetchAll();

        $roles = [NOTIF_R_PURCHASING, NOTIF_R_INVENTORY, NOTIF_R_MANAGER, NOTIF_R_RELEASING];

        foreach ($rows as $r) {
            $pid  = intval($r["ProductID"]);
            $name = $r["ProductName"];
            $on   = intval($r["OnHand"]);
            $min  = intval($r["MinStockLevel"]);

            if ($on <= 0) {
                createNotificationForRoles($conn, $roles, "stock_depleted",
                    "$name is out of stock", "On hand: 0 · Min level: $min",
                    "product", $pid, "out_{$pid}_" . intval($r["Released"]));
            } else {
                createNotificationForRoles($conn, $roles, "low_stock",
                    "Low stock: $name", "On hand: $on · Min level: $min",
                    "product", $pid, "low_{$pid}_{$on}");
            }
        }
    } catch (Throwable $e) {}
}

/* ---------- TIME-BASED (gitawag sa getNotifications) ---------- */

function syncScheduledNotifications(PDO $conn)
{
    if (!notifThrottle("sync", 60)) return;

    syncStockNotifications($conn);

    try {
        $rows = $conn->query("
            SELECT po.PO_ID, po.Expected_Date, s.SupplierName,
                   DATEDIFF(po.Expected_Date, CURDATE()) AS DaysLeft,
                   (SELECT COUNT(*) FROM purchase_order_line l
                     WHERE l.PO_ID = po.PO_ID AND l.IsArchived = 'No') AS Items
            FROM purchase_order po
            INNER JOIN status st ON po.Status_ID = st.StatusID
            LEFT JOIN supplier s ON po.Supplier_ID = s.SupplierID
            WHERE po.IsArchived = 'No'
              AND LOWER(TRIM(st.StatusName)) IN ('pending', 'approved')
              AND po.Expected_Date IS NOT NULL
              AND DATEDIFF(po.Expected_Date, CURDATE()) BETWEEN -30 AND 1
        ")->fetchAll();

        $roles = [NOTIF_R_PURCHASING, NOTIF_R_RECEIVING, NOTIF_R_MANAGER];

        foreach ($rows as $r) {
            $id       = intval($r["PO_ID"]);
            $days     = intval($r["DaysLeft"]);
            $supplier = $r["SupplierName"] ?: "Unknown supplier";
            $items    = intval($r["Items"]);
            $dateTxt  = date("M j", strtotime($r["Expected_Date"]));

            if ($days === 0) {
                createNotificationForRoles($conn, $roles, "po_today",
                    "PO #$id expected today", "$supplier · $items items", "po", $id, "po_today_$id");
            } elseif ($days === 1) {
                createNotificationForRoles($conn, $roles, "po_tomorrow",
                    "PO #$id expected tomorrow", "$supplier · $items items", "po", $id, "po_tomorrow_$id");
            } elseif ($days < 0) {
                createNotificationForRoles($conn, $roles, "po_overdue",
                    "PO #$id delivery overdue", "Expected $dateTxt · $supplier", "po", $id, "po_overdue_$id");
            }
        }
    } catch (Throwable $e) {}
}