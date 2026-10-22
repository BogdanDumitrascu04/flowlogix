<?php

function flx_get_period(): string
{
    $allowed = ['today', '7d', '30d', 'month', 'year'];
    $period = $_GET['period'] ?? 'today';
    return in_array($period, $allowed, true) ? $period : 'today';
}

function flx_period_label(string $period): string
{
    $labels = [
        'today' => 'Azi',
        '7d'    => 'Ultimele 7 zile',
        '30d'   => 'Ultimele 30 zile',
        'month' => 'Luna curentă',
        'year'  => 'Anul curent',
    ];

    return $labels[$period] ?? 'Azi';
}

function flx_period_bounds(string $period): array
{
    $now = new DateTime('now');

    switch ($period) {
        case '7d':
            $start = (clone $now)->modify('-6 days')->setTime(0, 0, 0);
            $end   = (clone $now)->setTime(23, 59, 59);
            break;

        case '30d':
            $start = (clone $now)->modify('-29 days')->setTime(0, 0, 0);
            $end   = (clone $now)->setTime(23, 59, 59);
            break;

        case 'month':
            $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
            $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
            break;

        case 'year':
            $start = (clone $now)->setDate((int)$now->format('Y'), 1, 1)->setTime(0, 0, 0);
            $end   = (clone $now)->setDate((int)$now->format('Y'), 12, 31)->setTime(23, 59, 59);
            break;

        case 'today':
        default:
            $start = (clone $now)->setTime(0, 0, 0);
            $end   = (clone $now)->setTime(23, 59, 59);
            break;
    }

    return [$start, $end];
}

function flx_build_period_query(string $period, string $dateColumn): string
{
    [$start, $end] = flx_period_bounds($period);

    $startStr = $start->format('Y-m-d H:i:s');
    $endStr   = $end->format('Y-m-d H:i:s');

    return "`{$dateColumn}` BETWEEN '{$startStr}' AND '{$endStr}'";
}

function flx_get_db(): ?mysqli
{
    $configPath = __DIR__ . '/config.php';

    if (!file_exists($configPath)) {
        return null;
    }

    require_once $configPath;

    foreach (['conn', 'mysqli', 'connection', 'db'] as $varName) {
        if (isset($$varName) && $$varName instanceof mysqli) {
            return $$varName;
        }
    }

    return null;
}

function flx_table_exists(mysqli $db, string $table): bool
{
    $table = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$table}'");

    return $result && $result->num_rows > 0;
}

function flx_get_columns(mysqli $db, string $table): array
{
    $columns = [];

    if (!flx_table_exists($db, $table)) {
        return $columns;
    }

    $result = $db->query("SHOW COLUMNS FROM `{$table}`");
    if (!$result) {
        return $columns;
    }

    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    return $columns;
}

function flx_pick_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function flx_query_value(mysqli $db, string $sql, $default = 0)
{
    $result = $db->query($sql);
    if (!$result) {
        return $default;
    }

    $row = $result->fetch_row();
    return $row[0] ?? $default;
}

function flx_status_condition(?string $statusColumn, array $values): string
{
    if (!$statusColumn || empty($values)) {
        return '1=0';
    }

    $escaped = array_map(
        fn($v) => "'" . strtolower(trim($v)) . "'",
        $values
    );

    return "LOWER(`{$statusColumn}`) IN (" . implode(',', $escaped) . ")";
}

function flx_fetch_module_data(mysqli $db, string $table, string $period, array $moduleConfig): array
{
    $columns = flx_get_columns($db, $table);

    if (empty($columns)) {
        return [
            'ok' => false,
            'message' => "Tabelul {$table} nu există sau nu poate fi citit.",
        ];
    }

    $idColumn = flx_pick_column($columns, ['id', 'order_id', 'order_no', 'order_number', 'reference_no', 'reference']);
    $statusColumn = flx_pick_column($columns, ['status', 'order_status', 'state']);
    $dateColumn = flx_pick_column($columns, [
        'received_at', 'packed_at', 'shipped_at', 'completed_at', 'processed_at',
        'updated_at', 'created_at', 'planned_date', 'scheduled_date', 'order_date', 'date'
    ]);
    $plannedColumn = flx_pick_column($columns, [
        'planned_date', 'planned_ship_date', 'deadline', 'sla_date', 'target_date', 'promised_at'
    ]);
    $qtyColumn = flx_pick_column($columns, [
        'qty', 'quantity', 'units', 'items', 'item_count', 'total_items', 'lines_count'
    ]);
    $durationColumn = flx_pick_column($columns, [
        'duration_min', 'processing_minutes', 'dock_minutes', 'handling_minutes',
        'pick_minutes', 'pack_minutes', 'loading_minutes'
    ]);
    $customerColumn = flx_pick_column($columns, ['customer', 'customer_name', 'client', 'client_name']);
    $carrierColumn = flx_pick_column($columns, ['carrier', 'carrier_name', 'courier']);
    $exceptionCountColumn = flx_pick_column($columns, ['exception_count', 'error_count', 'issues_count']);

    $periodWhere = $dateColumn ? flx_build_period_query($period, $dateColumn) : '1=1';
    $doneCondition = flx_status_condition($statusColumn, $moduleConfig['done_statuses'] ?? []);
    $riskCondition = flx_status_condition($statusColumn, $moduleConfig['risk_statuses'] ?? []);

    $totalOrders = (int) flx_query_value(
        $db,
        "SELECT COUNT(*) FROM `{$table}` WHERE {$periodWhere}",
        0
    );

    $doneOrders = (int) flx_query_value(
        $db,
        "SELECT COUNT(*) FROM `{$table}` WHERE {$periodWhere} AND ({$doneCondition})",
        0
    );

    $volume = $qtyColumn
        ? (int) flx_query_value($db, "SELECT COALESCE(SUM(`{$qtyColumn}`), 0) FROM `{$table}` WHERE {$periodWhere}", 0)
        : $totalOrders;

    $avgDuration = $durationColumn
        ? (float) flx_query_value($db, "SELECT COALESCE(AVG(`{$durationColumn}`), 0) FROM `{$table}` WHERE {$periodWhere}", 0)
        : 0;

    $riskOrders = (int) flx_query_value(
        $db,
        "SELECT COUNT(*) FROM `{$table}` WHERE {$periodWhere} AND ({$riskCondition})",
        0
    );

    $exceptions = $exceptionCountColumn
        ? (int) flx_query_value($db, "SELECT COALESCE(SUM(`{$exceptionCountColumn}`), 0) FROM `{$table}` WHERE {$periodWhere}", 0)
        : $riskOrders;

    $completionRate = $totalOrders > 0 ? round(($doneOrders / $totalOrders) * 100, 1) : 0;

    $onTimeRate = 0;
    if ($plannedColumn && $dateColumn) {
        $onTimeCount = (int) flx_query_value(
            $db,
            "SELECT COUNT(*) FROM `{$table}` 
             WHERE {$periodWhere}
             AND `{$plannedColumn}` IS NOT NULL
             AND `{$dateColumn}` IS NOT NULL
             AND `{$dateColumn}` <= `{$plannedColumn}`",
            0
        );

        $plannedCount = (int) flx_query_value(
            $db,
            "SELECT COUNT(*) FROM `{$table}` 
             WHERE {$periodWhere}
             AND `{$plannedColumn}` IS NOT NULL
             AND `{$dateColumn}` IS NOT NULL",
            0
        );

        $onTimeRate = $plannedCount > 0 ? round(($onTimeCount / $plannedCount) * 100, 1) : 0;
    } else {
        $onTimeStatuses = $moduleConfig['on_time_statuses'] ?? [];
        $onTimeCondition = flx_status_condition($statusColumn, $onTimeStatuses);
        $onTimeCount = (int) flx_query_value(
            $db,
            "SELECT COUNT(*) FROM `{$table}` WHERE {$periodWhere} AND ({$onTimeCondition})",
            0
        );
        $onTimeRate = $totalOrders > 0 ? round(($onTimeCount / $totalOrders) * 100, 1) : 0;
    }

    $seriesLabels = [];
    $seriesValues = [];

    if ($dateColumn) {
        $seriesSql = "
            SELECT DATE(`{$dateColumn}`) AS day_key, COUNT(*) AS total_count
            FROM `{$table}`
            WHERE {$periodWhere}
            GROUP BY DATE(`{$dateColumn}`)
            ORDER BY DATE(`{$dateColumn}`) ASC
        ";
        $seriesResult = $db->query($seriesSql);

        if ($seriesResult) {
            while ($row = $seriesResult->fetch_assoc()) {
                $seriesLabels[] = date('d.m', strtotime($row['day_key']));
                $seriesValues[] = (int)$row['total_count'];
            }
        }
    }

    $statusLabels = [];
    $statusValues = [];

    if ($statusColumn) {
        $statusSql = "
            SELECT `{$statusColumn}` AS label_name, COUNT(*) AS total_count
            FROM `{$table}`
            WHERE {$periodWhere}
            GROUP BY `{$statusColumn}`
            ORDER BY total_count DESC
            LIMIT 6
        ";
        $statusResult = $db->query($statusSql);

        if ($statusResult) {
            while ($row = $statusResult->fetch_assoc()) {
                $statusLabels[] = $row['label_name'] ?: 'Nedefinit';
                $statusValues[] = (int)$row['total_count'];
            }
        }
    }

    $selectParts = [];
    foreach ([
        $idColumn,
        $statusColumn,
        $dateColumn,
        $plannedColumn,
        $qtyColumn,
        $customerColumn,
        $carrierColumn
    ] as $col) {
        if ($col && !in_array("`{$col}`", $selectParts, true)) {
            $selectParts[] = "`{$col}`";
        }
    }

    if (empty($selectParts)) {
        $selectParts[] = '*';
    }

    $recentRows = [];
    $recentSql = "
        SELECT " . implode(', ', $selectParts) . "
        FROM `{$table}`
        WHERE {$periodWhere}
        " . ($dateColumn ? "ORDER BY `{$dateColumn}` DESC" : '') . "
        LIMIT 8
    ";
    $recentResult = $db->query($recentSql);

    if ($recentResult) {
        while ($row = $recentResult->fetch_assoc()) {
            $recentRows[] = $row;
        }
    }

    return [
        'ok' => true,
        'table' => $table,
        'columns' => [
            'id' => $idColumn,
            'status' => $statusColumn,
            'date' => $dateColumn,
            'planned' => $plannedColumn,
            'qty' => $qtyColumn,
            'customer' => $customerColumn,
            'carrier' => $carrierColumn,
        ],
        'metrics' => [
            'total_orders' => $totalOrders,
            'done_orders' => $doneOrders,
            'volume' => $volume,
            'avg_duration' => round($avgDuration, 1),
            'risk_orders' => $riskOrders,
            'exceptions' => $exceptions,
            'completion_rate' => $completionRate,
            'on_time_rate' => $onTimeRate,
        ],
        'charts' => [
            'series_labels' => $seriesLabels,
            'series_values' => $seriesValues,
            'status_labels' => $statusLabels,
            'status_values' => $statusValues,
        ],
        'rows' => $recentRows,
    ];
}

function flx_status_badge_class(?string $status): string
{
    $status = strtolower(trim((string)$status));

    $success = ['completed', 'done', 'packed', 'shipped', 'received', 'on_time', 'ok', 'finalizat'];
    $warning = ['pending', 'processing', 'in_progress', 'scheduled', 'queued', 'în curs'];
    $danger  = ['delayed', 'late', 'exception', 'error', 'cancelled', 'blocked', 'întârziat'];

    if (in_array($status, $success, true)) {
        return 'badge success';
    }

    if (in_array($status, $warning, true)) {
        return 'badge warning';
    }

    if (in_array($status, $danger, true)) {
        return 'badge danger';
    }

    return 'badge neutral';
}

// ============================================================
// DERIVED LOGIC HELPERS (migration step — do not query DB)
// ============================================================

/**
 * Parse a DATE or DATETIME string to a midnight DateTime.
 * Returns false if the string is empty or unparseable.
 */
function _flx_parse_date(mixed $value): DateTime|false
{
    if (empty($value)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', substr((string)$value, 0, 10));
    if ($d === false) {
        return false;
    }
    $d->setTime(0, 0, 0);
    return $d;
}

/**
 * Derived inbound status from finished_at and expected_finished_date.
 * Returns: 'completed' | 'backlog' | 'pending'
 */
function flx_inbound_status(array $row): string
{
    $finishedAt          = $row['finished_at']          ?? null;
    $expectedFinishedDate = $row['expected_finished_date'] ?? null;

    if (!empty($finishedAt)) {
        return 'completed';
    }

    $expected = _flx_parse_date($expectedFinishedDate);
    $today    = new DateTime('today');

    if ($expected !== false && $expected < $today) {
        return 'backlog';
    }

    return 'pending';
}

/**
 * Derived inbound priority from finished_at, created_at and expected_finished_date.
 * Returns: 'high' | 'medium' | 'low'
 */
function flx_inbound_priority(array $row): string
{
    $finishedAt          = $row['finished_at']          ?? null;
    $createdAt           = $row['created_at']           ?? null;
    $expectedFinishedDate = $row['expected_finished_date'] ?? null;

    if (!empty($finishedAt)) {
        return 'low';
    }

    $today    = new DateTime('today');
    $expected = _flx_parse_date($expectedFinishedDate);

    if ($expected !== false && $expected < $today) {
        return 'high';
    }

    $created = _flx_parse_date($createdAt);

    if ($created !== false && $created < $today) {
        return 'high';
    }

    if ($created !== false && $created == $today) {
        return 'medium';
    }

    return 'low';
}

/**
 * Derived pick/pack status from operator_name, finished_at and expected_finished_date.
 * Returns: 'completed' | 'backlog' | 'in_progress' | 'not_started'
 */
function flx_pickpack_status(array $row): string
{
    $finishedAt          = $row['finished_at']          ?? null;
    $operatorName        = $row['operator_name']        ?? null;
    $expectedFinishedDate = $row['expected_finished_date'] ?? null;

    if (!empty($finishedAt)) {
        return 'completed';
    }

    $expected = _flx_parse_date($expectedFinishedDate);
    $today    = new DateTime('today');

    if ($expected !== false && $expected < $today) {
        return 'backlog';
    }

    if (!empty($operatorName)) {
        return 'in_progress';
    }

    return 'not_started';
}

/**
 * Derived outbound status from planned_date and finished_at.
 * Returns: 'backlog' | 'not_shipped' | 'shipped_on_time' | 'shipped_late'
 */
function flx_outbound_status(array $row): string
{
    $finishedAt  = $row['finished_at']  ?? null;
    $plannedDate = $row['planned_date'] ?? null;

    $today   = new DateTime('today');
    $planned = _flx_parse_date($plannedDate);

    if (empty($finishedAt)) {
        if ($planned !== false && $planned < $today) {
            return 'backlog';
        }
        return 'not_shipped';
    }

    $finished = _flx_parse_date($finishedAt);

    if ($finished === false || $planned === false) {
        return 'shipped_on_time';
    }

    return $finished <= $planned ? 'shipped_on_time' : 'shipped_late';
}

/**
 * Derived outbound delay in whole days.
 * Returns a positive integer, or 0 when there is no delay.
 */
function flx_outbound_delay_days(array $row): int
{
    $finishedAt  = $row['finished_at']  ?? null;
    $plannedDate = $row['planned_date'] ?? null;

    $planned = _flx_parse_date($plannedDate);
    if ($planned === false) {
        return 0;
    }

    if (!empty($finishedAt)) {
        $finished = _flx_parse_date($finishedAt);
        if ($finished === false || $finished <= $planned) {
            return 0;
        }
        return (int)$finished->diff($planned)->days;
    }

    $today = new DateTime('today');
    if ($today > $planned) {
        return (int)$today->diff($planned)->days;
    }

    return 0;
}

/**
 * Returns true if the row is in backlog for the given module.
 * Modules: 'inbound' | 'pickpack' | 'outbound'
 */
function flx_is_backlog(string $module, array $row): bool
{
    $finishedAt = $row['finished_at'] ?? null;
    $today      = new DateTime('today');

    if (!empty($finishedAt)) {
        return false;
    }

    switch ($module) {
        case 'inbound':
        case 'pickpack':
            $expected = _flx_parse_date($row['expected_finished_date'] ?? null);
            return $expected !== false && $expected < $today;

        case 'outbound':
            $planned = _flx_parse_date($row['planned_date'] ?? null);
            return $planned !== false && $planned < $today;
    }

    return false;
}