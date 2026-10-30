<?php

// Bounds for open-ended ranges such as "last 7 days".
const FLX_NO_LOWER_BOUND = '1000-01-01 00:00:00';
const FLX_NO_UPPER_BOUND = '9999-12-31 23:59:59';

/**
 * Returns the two values for a `column BETWEEN ? AND ?` period filter.
 * 'today' and 'month' are closed ranges; '7d' and '30d' start N days ago
 * and have no upper bound.
 */
function flx_period_params(string $period): array
{
    $now = new DateTime('now');

    switch ($period) {
        case 'today':
            return [$now->format('Y-m-d 00:00:00'), $now->format('Y-m-d 23:59:59')];

        case '7d':
            return [(clone $now)->modify('-7 days')->format('Y-m-d H:i:s'), FLX_NO_UPPER_BOUND];

        case '30d':
            return [(clone $now)->modify('-30 days')->format('Y-m-d H:i:s'), FLX_NO_UPPER_BOUND];

        case 'month':
            return [$now->format('Y-m-01 00:00:00'), $now->format('Y-m-t 23:59:59')];

        case 'all':
            return [FLX_NO_LOWER_BOUND, FLX_NO_UPPER_BOUND];
    }

    throw new InvalidArgumentException("Perioadă necunoscută: {$period}");
}

/**
 * Runs $sql as a prepared statement, binding $params to the `?` placeholders in order.
 * All values are bound as strings; MySQL converts them to the column type.
 */
function flx_query(mysqli $db, string $sql, array $params = []): mysqli_result|false
{
    if (substr_count($sql, '?') !== count($params)) {
        throw new LogicException('Numărul de valori nu corespunde cu numărul de semne ? din interogare.');
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }

    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Writes one CSV row with ';' as separator, guarded against CSV injection:
 * text starting with = + - @, tab or CR gets a leading apostrophe, so Excel
 * shows it as text instead of evaluating it. Numeric values are left as they are.
 */
function flx_csv_row($handle, array $row): void
{
    $safeRow = array_map(function ($value) {
        if (is_string($value) && $value !== '' && !is_numeric($value) && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
    }, $row);

    fputcsv($handle, $safeRow, ';');
}

// Status rules derived from the order dates. Pure functions: they never query the database.

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