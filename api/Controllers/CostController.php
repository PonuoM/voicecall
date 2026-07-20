<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';

/**
 * Cost dashboard API — live spend straight from the OpenRouter account plus per-day processing
 * stats from our own DB. Super-admin only (same policy as prompt settings): spend data covers
 * every company on the platform.
 *
 *   GET cost/summary
 */
function handle_cost(PDO $pdo, array $currentUser, ?string $id, ?string $action): void
{
    if (empty($currentUser['erp_is_super_admin'])) {
        json_response(['ok' => false, 'error' => 'FORBIDDEN', 'message' => 'Super admin only'], 403);
    }

    if ($id === 'summary' && method() === 'GET') {
        cost_summary($pdo);
        return;
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}

function cost_summary(PDO $pdo): void
{
    // Live account data — if OpenRouter is unreachable we still return DB stats so the page
    // degrades instead of dying.
    $usage = null;
    $credits = null;
    $openrouterError = null;
    try {
        $usage = OpenRouterClient::keyUsage();
        $credits = OpenRouterClient::credits();
    } catch (Throwable $e) {
        $openrouterError = $e->getMessage();
    }

    $totals = fetch_one($pdo, "
        SELECT COUNT(*)                          AS completed_total,
               SUM(created_at >= CURDATE())      AS completed_today,
               ROUND(AVG(duration_seconds))      AS avg_duration_sec
        FROM conversations WHERE status = 'completed'
    ", []);

    // Empirical average cost per call: this key's all-time spend over every completed pipeline
    // run. Approximate by design (dev runs share the key) — labeled as estimate in the UI.
    $completedTotal = (int) ($totals['completed_total'] ?? 0);
    $avgCostPerCall = ($usage && $completedTotal > 0) ? $usage['usage'] / $completedTotal : null;

    $daily = fetch_all($pdo, "
        SELECT DATE(created_at) AS day, COUNT(*) AS calls, ROUND(SUM(duration_seconds)/60) AS minutes
        FROM conversations
        WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day DESC
    ", []);

    $sampledToday = fetch_one($pdo, "
        SELECT COUNT(*) AS c FROM audit_log
        WHERE action = 'smart_sample' AND created_at >= CURDATE()
    ", []);

    // Volume context for projections (same numbers the sampling decision was based on).
    $volume = fetch_one($pdo, "
        SELECT ROUND(COUNT(*)/14) AS calls_per_day,
               ROUND(SUM(duration_seconds >= 40)/14) AS answered_per_day
        FROM gdrive_file_index
        WHERE call_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ", []);

    json_response([
        'ok' => true,
        'openrouter' => [
            'error' => $openrouterError,
            'usage' => $usage,           // usage / usage_daily / usage_weekly / usage_monthly (USD, this key)
            'credits' => $credits,       // total_credits / total_usage / remaining (USD, whole account)
        ],
        'sampling' => [
            'budget_usd_per_day' => SAMPLING_BUDGET_USD_PER_DAY,
            'max_calls_per_run' => SAMPLING_MAX_CALLS_PER_RUN,
            'min_duration_seconds' => SAMPLING_MIN_DURATION_SECONDS,
            'random_share' => SAMPLING_RANDOM_SHARE,
            'sampled_today' => (int) ($sampledToday['c'] ?? 0),
        ],
        'stats' => [
            'completed_total' => $completedTotal,
            'completed_today' => (int) ($totals['completed_today'] ?? 0),
            'avg_duration_sec' => (int) ($totals['avg_duration_sec'] ?? 0),
            'avg_cost_per_call_usd' => $avgCostPerCall,
            'volume_calls_per_day' => (int) ($volume['calls_per_day'] ?? 0),
            'volume_answered_per_day' => (int) ($volume['answered_per_day'] ?? 0),
        ],
        'daily' => $daily,
    ]);
}
