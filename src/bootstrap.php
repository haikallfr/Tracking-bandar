<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const STORAGE_DIR = APP_ROOT . '/storage';
const DB_PATH = STORAGE_DIR . '/app.sqlite';
const NEXT_DAY_RUN_DIR = STORAGE_DIR . '/next-day-runs';

$composerAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (!is_dir(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0775, true);
}

if (!is_dir(NEXT_DAY_RUN_DIR)) {
    mkdir(NEXT_DAY_RUN_DIR, 0775, true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS broker_cache (
            symbol TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS broker_daily_history (
            symbol TEXT NOT NULL,
            trade_date TEXT NOT NULL,
            payload TEXT NOT NULL,
            market_value REAL NOT NULL DEFAULT 0,
            market_volume REAL NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, trade_date)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS price_feed_cache (
            symbol TEXT NOT NULL,
            timeframe TEXT NOT NULL,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, timeframe)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS symbol_reference (
            symbol TEXT PRIMARY KEY,
            company_name TEXT NOT NULL DEFAULT "",
            listing_board TEXT NOT NULL DEFAULT "",
            listed_shares REAL NOT NULL DEFAULT 0,
            sector TEXT NOT NULL DEFAULT "",
            subsector TEXT NOT NULL DEFAULT "",
            source TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ownership_reference (
            symbol TEXT NOT NULL,
            owner_name TEXT NOT NULL,
            owner_type TEXT NOT NULL DEFAULT "",
            ownership_pct REAL NOT NULL DEFAULT 0,
            ownership_shares REAL NOT NULL DEFAULT 0,
            effective_date TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, owner_name, effective_date)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS corporate_action_reference (
            symbol TEXT NOT NULL,
            action_type TEXT NOT NULL,
            action_date TEXT NOT NULL,
            headline TEXT NOT NULL DEFAULT "",
            source TEXT NOT NULL DEFAULT "",
            raw_payload TEXT NOT NULL DEFAULT "{}",
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, action_type, action_date, headline)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS next_day_symbol_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            symbol TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "single_search",
            passed INTEGER NOT NULL DEFAULT 0,
            score REAL NOT NULL DEFAULT 0,
            scanned_at TEXT NOT NULL,
            payload TEXT NOT NULL DEFAULT "{}"
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS broker_historical_lookup (
            symbol TEXT NOT NULL,
            broker_code TEXT NOT NULL,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, broker_code)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ai_analysis_cache (
            symbol TEXT NOT NULL,
            mode TEXT NOT NULL,
            context_hash TEXT NOT NULL,
            model TEXT NOT NULL DEFAULT "",
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY(symbol, mode, context_hash)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS external_context_cache (
            symbol TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    seed_defaults($pdo);

    return $pdo;
}

function seed_defaults(PDO $pdo): void
{
    $defaults = [
        'stockbit_token' => '',
        'stockbit_refresh_token' => '',
        'stockbit_token_expiry' => '',
        'stockbit_refresh_token_expiry' => '',
        'stockbit_user_access' => '',
        'stockbit_imported_at' => '',
        'watchlist' => json_encode([], JSON_THROW_ON_ERROR),
        'auto_refresh_minutes' => '15',
        'period' => '',
        'date_from' => '',
        'date_to' => '',
        'transaction_type' => 'TRANSACTION_TYPE_NET',
        'market_board' => 'MARKET_BOARD_REGULER',
        'investor_type' => 'INVESTOR_TYPE_ALL',
        'repeat_screen_results' => '[]',
        'repeat_screen_meta' => '{}',
        'repeat_screen_status' => 'idle',
        'repeat_screen_started_at' => '',
        'repeat_screen_finished_at' => '',
        'repeat_screen_first_hit_at' => '',
        'repeat_screen_pid' => '',
        'repeat_screen_scanned' => '0',
        'repeat_screen_total' => '0',
        'repeat_screen_matched' => '0',
        'repeat_screen_errors' => '0',
        'repeat_screen_current_symbol' => '',
        'radar_full_results' => '[]',
        'radar_full_status' => 'idle',
        'radar_full_started_at' => '',
        'radar_full_finished_at' => '',
        'radar_full_pid' => '',
        'radar_full_scanned' => '0',
        'radar_full_total' => '0',
        'radar_full_matched' => '0',
        'radar_full_errors' => '0',
        'radar_full_current_symbol' => '',
        'radar_full_meta' => '{}',
        'radar_full_cancel_requested' => '0',
        'radar_basic_results' => '[]',
        'radar_basic_status' => 'idle',
        'radar_basic_started_at' => '',
        'radar_basic_finished_at' => '',
        'radar_basic_pid' => '',
        'radar_basic_scanned' => '0',
        'radar_basic_total' => '0',
        'radar_basic_matched' => '0',
        'radar_basic_errors' => '0',
        'radar_basic_current_symbol' => '',
        'radar_basic_meta' => '{}',
        'radar_basic_cancel_requested' => '0',
        'radar_basic_error_log' => '[]',
        'next_day_results' => '[]',
        'next_day_status' => 'idle',
        'next_day_started_at' => '',
        'next_day_finished_at' => '',
        'next_day_pid' => '',
        'next_day_scanned' => '0',
        'next_day_total' => '0',
        'next_day_matched' => '0',
        'next_day_errors' => '0',
        'next_day_current_symbol' => '',
        'next_day_meta' => '{}',
        'next_day_cancel_requested' => '0',
        'next_day_dataset_latest_file' => '',
        'next_day_dataset_latest_generated_at' => '',
        'next_day_dataset_latest_count' => '0',
        'next_day_swing_dataset_latest_file' => '',
        'next_day_swing_dataset_latest_generated_at' => '',
        'next_day_swing_dataset_latest_count' => '0',
        'next_day_fast_dataset_latest_file' => '',
        'next_day_fast_dataset_latest_generated_at' => '',
        'next_day_fast_dataset_latest_count' => '0',
        'next_day_fast_v2_dataset_latest_file' => '',
        'next_day_fast_v2_dataset_latest_generated_at' => '',
        'next_day_fast_v2_dataset_latest_count' => '0',
        'broker_history_status' => 'idle',
        'broker_history_started_at' => '',
        'broker_history_finished_at' => '',
        'broker_history_pid' => '',
        'broker_history_symbol' => '',
        'broker_history_broker_code' => '',
        'broker_history_scanned' => '0',
        'broker_history_total' => '0',
        'broker_history_current_range' => '',
        'broker_history_errors' => '0',
        'broker_history_meta' => '{}',
        'broker_history_cancel_requested' => '0',
        'broker_history_error_log' => '[]',
        'gemini_api_key' => '',
        'gemini_model' => 'gemini-2.5-flash',
    ];

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES (:key, :value)');

    foreach ($defaults as $key => $value) {
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }
}

function setting(string $key, mixed $default = null): mixed
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();

    return $row['value'] ?? $default;
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings(key, value) VALUES (:key, :value)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );

    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function append_worker_error(string $settingKey, string $symbol, Throwable $error, int $limit = 25): void
{
    $entries = json_decode((string) setting($settingKey, '[]'), true);
    $entries = is_array($entries) ? $entries : [];
    $entries[] = [
        'symbol' => strtoupper($symbol),
        'message' => $error->getMessage(),
        'time' => gmdate(DATE_ATOM),
    ];

    if (count($entries) > $limit) {
        $entries = array_slice($entries, -$limit);
    }

    save_setting($settingKey, json_encode($entries, JSON_THROW_ON_ERROR));
}

function process_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    $output = shell_exec('ps -p ' . (int) $pid . ' -o pid=');
    return trim((string) $output) !== '';
}

function terminate_process(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 15);
    }

    shell_exec('kill ' . (int) $pid);
    usleep(200000);
    return !process_alive($pid);
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function request_post(): array
{
    return is_array($_POST) ? $_POST : [];
}

function normalize_symbols(string $symbols): array
{
    $parts = preg_split('/[\s,]+/', strtoupper(trim($symbols))) ?: [];
    $parts = array_filter($parts, static fn ($symbol) => $symbol !== '');
    $parts = array_values(array_unique($parts));

    return array_slice($parts, 0, 30);
}
