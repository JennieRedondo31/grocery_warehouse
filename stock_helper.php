<?php
// stock_helper.php — usa ra nga source of truth sa QOH
function stockJoins()
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
                       WHEN LOWER(TRIM(l.Adjustment_Type)) = 'increase' THEN  ABS(l.Adjustment_Quantity)
                       WHEN LOWER(TRIM(l.Adjustment_Type)) = 'decrease' THEN -ABS(l.Adjustment_Quantity)
                       ELSE 0
                   END) AS qty
            FROM inventory_adjustment_line l
            INNER JOIN inventory_adjustment h ON h.Adjustment_ID = l.Adjustment_ID
            LEFT JOIN status st ON h.Status_ID = st.StatusID
            WHERE l.IsArchived = 'No' AND h.IsArchived = 'No'
              AND LOWER(TRIM(COALESCE(st.StatusName, ''))) IN ('approved','completed')
            GROUP BY l.Product_ID
        ) adj ON adj.pid = p.ProductID
    ";
}

function stockExpression()
{
    return "(
        COALESCE(rin.qty, 0) - COALESCE(rel.qty, 0)
        + COALESCE(sret.qty, 0) - COALESCE(pret.qty, 0)
        + COALESCE(adj.qty, 0)
    )";
}