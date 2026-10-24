<?php

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/notifications_helper.php";

/* Balik: user row, o mo-response ug error. */
function requireNotifUser($conn, $userID)
{
    if (!$userID || intval($userID) <= 0) {
        response(false, "Authentication required.", null);
    }

    $stmt = $conn->prepare("
        SELECT u.UserID, u.UserStatus, u.IsArchived, r.RoleName
        FROM `user` u
        LEFT JOIN `role` r ON u.RoleID = r.RoleID
        WHERE u.UserID = :id
        LIMIT 1
    ");
    $stmt->execute([":id" => intval($userID)]);
    $user = $stmt->fetch();

    if (!$user) response(false, "User not found.", null);
    if (strtolower(trim($user["IsArchived"] ?? "")) === "yes") response(false, "Account archived.", null);
    if (strtolower(trim($user["UserStatus"] ?? "")) !== "active") response(false, "Account inactive.", null);

    return $user;
}

try {

    $currentUserID = getCurrentUserID($input);
    $user = requireNotifUser($conn, $currentUserID);

    /* Admin walay notifications */
    $role = strtolower(trim($user["RoleName"] ?? ""));
    if ($role === "admin" || $role === "administrator") {
        response(true, "Notifications disabled for admin.", [
            "disabled"      => true,
            "notifications" => [],
            "unread"        => 0
        ]);
    }

    /* ---------------- LIST + unread count ---------------- */
    if ($operation === "getNotifications") {

        syncScheduledNotifications($conn);

        $limit = max(1, min(100, intval($input["limit"] ?? 30)));

        $stmt = $conn->prepare("
            SELECT NotificationID, Type, Title, Message, RefType, RefID, IsRead,
                   TIMESTAMPDIFF(SECOND, CreatedAt, NOW()) AS AgeSeconds
            FROM notifications
            WHERE UserID = :uid
            ORDER BY NotificationID DESC
            LIMIT $limit
        ");
        $stmt->execute([":uid" => $currentUserID]);
        $list = $stmt->fetchAll();

        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE UserID = :uid AND IsRead = 0");
        $stmt->execute([":uid" => $currentUserID]);
        $unread = intval($stmt->fetch()["total"]);

        response(true, "Notifications loaded.", [
            "disabled"      => false,
            "notifications" => $list,
            "unread"        => $unread
        ]);
    }

    /* ---------------- MARK ONE READ ---------------- */
    if ($operation === "markNotificationRead") {
        $nid = intval($input["NotificationID"] ?? 0);
        $stmt = $conn->prepare("UPDATE notifications SET IsRead = 1 WHERE NotificationID = :id AND UserID = :uid");
        $stmt->execute([":id" => $nid, ":uid" => $currentUserID]);
        response(true, "Marked as read.", null);
    }

    /* ---------------- MARK ALL READ ---------------- */
    if ($operation === "markAllNotificationsRead") {
        $stmt = $conn->prepare("UPDATE notifications SET IsRead = 1 WHERE UserID = :uid AND IsRead = 0");
        $stmt->execute([":uid" => $currentUserID]);
        response(true, "All marked as read.", null);
    }

    response(false, "Invalid operation: " . $operation, null);

} catch (PDOException $e) {
    response(false, "DB error: " . $e->getMessage(), null);
} catch (Exception $e) {
    response(false, "Server error: " . $e->getMessage(), null);
}