<?php
/**
 * notifications_helper.php
 * Gamiton sa bisan unsang API:  require_once __DIR__ . "/notifications_helper.php";
 * Nanginahanglan og $conn gikan sa db.php
 */

/**
 * Himoan og notification ang TANANG active users gawas sa admin.
 * Dili mo-throw og error (dili mabuak ang main action kung mapakyas).
 */
function createNotificationForAll(
    PDO $conn,
    string $type,
    string $title,
    string $message = "",
    ?string $refType = null,
    ?int $refId = null,
    ?string $dedupeKey = null,
    int $excludeUserID = 0
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
            ":type"  => $type,
            ":title" => $title,
            ":msg"   => $message,
            ":rtype" => $refType,
            ":rid"   => $refId,
            ":dkey"  => $dedupeKey,
            ":ex"    => $excludeUserID
        ]);
    } catch (Throwable $e) {
        // tuyo nga gi-ignore
    }
}

/**
 * Time-based notifications (PO expected today / tomorrow / overdue).
 * Gitawag sa matag getNotifications; ang DedupeKey nagpugong sa duplicates.
 */
function syncScheduledNotifications(PDO $conn)
{
    try {
        $rows = $conn->query("
            SELECT
                po.PO_ID,
                po.Expected_Date,
                s.SupplierName,
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

        foreach ($rows as $r) {
            $id       = intval($r["PO_ID"]);
            $days     = intval($r["DaysLeft"]);
            $supplier = $r["SupplierName"] ?: "Unknown supplier";
            $items    = intval($r["Items"]);
            $dateTxt  = date("M j", strtotime($r["Expected_Date"]));

            if ($days === 0) {
                createNotificationForAll($conn, "po_today",
                    "PO #$id expected today",
                    "$supplier · $items items", "po", $id, "po_today_$id");
            } elseif ($days === 1) {
                createNotificationForAll($conn, "po_tomorrow",
                    "PO #$id expected tomorrow",
                    "$supplier · $items items", "po", $id, "po_tomorrow_$id");
            } elseif ($days < 0) {
                createNotificationForAll($conn, "po_overdue",
                    "PO #$id delivery overdue",
                    "Expected $dateTxt · $supplier", "po", $id, "po_overdue_$id");
            }
        }
    } catch (Throwable $e) {
        // tuyo nga gi-ignore
    }
}