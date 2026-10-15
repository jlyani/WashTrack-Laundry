<?php
// includes/sms.php - texts the customer when their laundry is ready.
// A text is sent to the customer (e.g. when Ready for Pickup). It does not block the workflow —
// if the text fails, the order is still Ready and the staff are told to call instead.
//
// Provider: TextBee (free). Your own Android phone, with the TextBee app and a SIM, sends the text.  https://textbee.dev
// (A paid Semaphore gateway, https://semaphore.co, is still supported as an optional alternative: SMS_PROVIDER = 'semaphore'.
//  It is not used in this shop.)
//
// Setup (once): copy includes/sms_config.sample.php to includes/sms_config.php and paste your TextBee API key.
// Without a key SMS stays OFF and the shop simply calls the customer instead: Mark Ready still works and says "SMS is off".
//
// Rules:
//   - A text is sent when an order is marked Ready for Pickup, and can be re-sent from the order list.
//   - The text never blocks the workflow: if it fails, the order is still Ready and the staff are told to call.
//   - Every attempt is written to sms_log (who, what, result), so a failed or double text is always traceable.
//   - Messages are plain ASCII ("PHP 225.00", not the peso sign) so one text stays one SMS (160 characters).
require_once __DIR__ . '/config.php';

if (is_file(__DIR__ . '/sms_config.php')) require_once __DIR__ . '/sms_config.php';   // defines the keys below

if (!defined('SMS_API_KEY'))        define('SMS_API_KEY',        (string) getenv('SMS_API_KEY'));        // Semaphore (optional provider)
if (!defined('SMS_SENDER_NAME'))    define('SMS_SENDER_NAME',    (string) getenv('SMS_SENDER_NAME'));    // '' = default Semaphore sender name
if (!defined('TEXTBEE_API_KEY'))    define('TEXTBEE_API_KEY',    (string) getenv('TEXTBEE_API_KEY'));    // TextBee (main provider)
if (!defined('TEXTBEE_DEVICE_ID'))  define('TEXTBEE_DEVICE_ID',  (string) getenv('TEXTBEE_DEVICE_ID'));  // '' = default TextBee phone
if (!defined('SHOP_NAME'))          define('SHOP_NAME',          (getenv('SHOP_NAME') ?: 'Sparkling Spin'));
if (!defined('SMS_EVENTS'))         define('SMS_EVENTS',         (string) (getenv('SMS_EVENTS') ?: 'received,started,ready,cancelled'));   // which status changes are texted
if (!defined('SMS_PROVIDER'))       define('SMS_PROVIDER',       (string) (getenv('SMS_PROVIDER') ?: 'textbee'));
if (!defined('SMS_API_URL'))        define('SMS_API_URL',        'https://api.semaphore.co/api/v4/messages');
if (!defined('TEXTBEE_API_URL'))    define('TEXTBEE_API_URL',    'https://api.textbee.dev/api/v1/gateway/send-sms');

/** SMS is on only when a key is set for the chosen provider. */
// SMS is on only when an API key is set. If none, "SMS is off" and the staff just call instead.
function sms_enabled(): bool {
    return trim((string) (SMS_PROVIDER === 'textbee' ? TEXTBEE_API_KEY : SMS_API_KEY)) !== '';
}

/** True if the sms_log table exists (an older database keeps working without it, it just does not log). */
function sms_log_exists(mysqli $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (int) $conn->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_log'")->fetch_assoc()['n'] === 1;
    } catch (Throwable $e) {
        error_log('[sms] table check failed: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/** SQL expression (alias o = orders): status of the latest text for the order ('Sent' / 'Failed'), or NULL if none. */
function sms_status_sql(mysqli $conn, string $a = 'o'): string {
    if (!sms_log_exists($conn)) return 'NULL';
    return "(SELECT l.status FROM sms_log l WHERE l.order_id = {$a}.order_id ORDER BY l.sms_id DESC LIMIT 1)";
}

/** '09171234567' / '+639171234567' / '639171234567' -> '09171234567', or null for a non-Philippine mobile number. */
// Normalizes the number for SMS (international/local format); null if not a valid mobile number.
function sms_normalize_phone(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if (strpos($d, '63') === 0 && strlen($d) === 12) $d = '0' . substr($d, 2);
    return preg_match('/^09\d{9}$/', $d) ? $d : null;
}

/** First name, ASCII only ("Hi Ana,"), or '' if none. */
function sms_first_name(string $customer): string {
    $first = trim(explode(' ', trim($customer))[0] ?? '');
    return trim(preg_replace('/[^A-Za-z0-9 .\'-]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $first) ?: $first));
}

/** The statuses that are texted: 'ready' is always on (it is the pickup call); the others follow SMS_EVENTS. */
function sms_event_on(string $event): bool {
    if ($event === 'ready') return true;
    $on = array_map('trim', explode(',', strtolower((string) SMS_EVENTS)));
    return in_array($event, $on, true);
}

/** The text for one status change. ASCII only, kept under 160 characters (one SMS). */
function sms_status_message(string $event, string $customer, string $short, float $amount): string {
    $first = sms_first_name($customer);
    $hi    = $first !== '' ? "Hi {$first}, your" : 'Your';
    $amt   = 'PHP ' . number_format($amount, 2);
    switch ($event) {
        case 'received':  $t = "{$hi} laundry {$short} was received. Total {$amt}, payable at pickup. We will text you updates."; break;
        case 'started':   $t = "{$hi} laundry {$short} is now being washed. We will text you when it is ready."; break;
        case 'released':  $t = "Thank you" . ($first !== '' ? ", {$first}" : '') . "! Laundry {$short} released, {$amt} paid. See you again!"; break;
        case 'cancelled': $t = "{$hi} laundry order {$short} has been cancelled. Please contact us if this is a mistake."; break;
        default:          $t = "{$hi} laundry {$short} is ready for pickup. Amount due: {$amt}. Thank you!";
    }
    return SHOP_NAME . ': ' . $t;
}

/** One HTTPS POST request. Returns [http code, body, curl error]. Tests replace it by defining SMS_TEST_TRANSPORT (a callable). */
function sms_http_post(string $url, string $body, array $headers, array $fields): array {
    if (defined('SMS_TEST_TRANSPORT')) return (SMS_TEST_TRANSPORT)($fields, $url);
    if (!function_exists('curl_init')) return [0, false, 'PHP cURL is not enabled (enable extension=curl in php.ini)'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $reply = curl_exec($ch);
    $err   = $reply === false ? curl_error($ch) : '';
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $reply, $err];
}

/**
 * Sends one text with the chosen provider. $phone is a local number (09xxxxxxxxx).
 * Returns ['ok' => bool, 'id' => provider message id or '', 'error' => reason]. Never throws.
 * 'ok' means the provider ACCEPTED the text (TextBee: queued on your phone, which sends it right away).
 */
function sms_send(string $phone, string $message): array {
    $fail = function ($why) { return ['ok' => false, 'id' => '', 'error' => $why]; };
    if (!sms_enabled()) return $fail('SMS is off (no API key set)');

    $textbee = (SMS_PROVIDER === 'textbee');
    if ($textbee) {
        // TextBee wants international format: +639171234567. The phone with the TextBee app sends it from its SIM.
        $fields = ['recipients' => ['+63' . substr($phone, 1)], 'message' => $message];
        if (trim((string) TEXTBEE_DEVICE_ID) !== '') $fields['deviceId'] = TEXTBEE_DEVICE_ID;
        [$code, $body, $err] = sms_http_post(TEXTBEE_API_URL, json_encode($fields), ['Content-Type: application/json', 'x-api-key: ' . TEXTBEE_API_KEY], $fields);
    } else {
        $fields = ['apikey' => SMS_API_KEY, 'number' => $phone, 'message' => $message];
        if (trim((string) SMS_SENDER_NAME) !== '') $fields['sendername'] = SMS_SENDER_NAME;
        [$code, $body, $err] = sms_http_post(SMS_API_URL, http_build_query($fields), [], $fields);
    }

    if ($err !== '' || $body === false || $body === null) return $fail('Could not reach the SMS service: ' . ($err ?: 'no reply'));
    $json = json_decode((string) $body, true);

    if ($code >= 200 && $code < 300 && is_array($json)) {
        if ($textbee) {
            // {"data": {"success": true, "smsBatchId": "...", "message": "SMS added to queue for processing"}} (sample reply)
            $d = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
            if (($d['success'] ?? $json['success'] ?? true) !== false) return ['ok' => true, 'id' => (string) ($d['smsBatchId'] ?? ''), 'error' => ''];
        } elseif (isset($json[0]['message_id'])) {
            // Semaphore: a JSON list with one message whose status is Queued / Pending / Sent.
            $status = (string) ($json[0]['status'] ?? '');
            if (!in_array($status, ['Failed', 'Refunded'], true)) return ['ok' => true, 'id' => (string) $json[0]['message_id'], 'error' => ''];
            return $fail('The SMS service rejected the message (' . $status . ')');
        }
    }
    $why = '';
    if (is_array($json)) { array_walk_recursive($json, function ($v) use (&$why) { if (is_scalar($v)) $why .= ($why === '' ? '' : ' ') . $v; }); }
    else { $why = trim(strip_tags((string) $body)); }
    return $fail('SMS service said: ' . mb_substr($why !== '' ? $why : "HTTP {$code}", 0, 230));
}

/**
 * Texts the customer about a status change (received / started / ready / released / cancelled) and logs the attempt.
 * A status switched off in SMS_EVENTS is skipped silently (note '').
 * Returns ['ok' => bool, 'note' => short sentence for the staff].
 */
function sms_notify_status(mysqli $conn, int $order_id, int $user_id, string $event): array {
    if (!sms_event_on($event) || !sms_enabled()) {
        return ['ok' => false, 'skipped' => true, 'note' => ''];   // off: no text, nothing to tell the staff (Ready handles its own message below)
    }
    try {
        $st = $conn->prepare("SELECT o.order_number, o.total_amount, c.full_name, c.phone FROM orders o JOIN customers c ON c.customer_id = o.customer_id WHERE o.order_id = ?");
        $st->bind_param('i', $order_id);
        $st->execute();
        $o = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$o) return ['ok' => false, 'note' => 'SMS not sent.'];
        $phone = sms_normalize_phone((string) $o['phone']);
        if ($phone === null) return ['ok' => false, 'note' => 'SMS not sent: not a mobile number.' . ($event === 'ready' ? ' Call the customer.' : '')];

        $short = function_exists('order_short_code') ? order_short_code($o['order_number']) : substr($o['order_number'], -3);
        $msg   = sms_status_message($event, $o['full_name'], $short, (float) $o['total_amount']);
        $res   = sms_send($phone, $msg);

        if (sms_log_exists($conn)) {
            $status = $res['ok'] ? 'Sent' : 'Failed';
            $mid    = $res['id'] !== '' ? $res['id'] : null;
            $err    = $res['ok'] ? null : $res['error'];
            $ins = $conn->prepare("INSERT INTO sms_log (order_id, phone, message, status, provider_id, error, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param('isssssi', $order_id, $phone, $msg, $status, $mid, $err, $user_id);
            $ins->execute();
            $ins->close();
        }
        if ($res['ok']) return ['ok' => true, 'note' => ''];   // sent: nothing to tell the staff (only a failure needs a note)
        error_log('[sms] order ' . $o['order_number'] . ': ' . $res['error']);
        return ['ok' => false, 'note' => "SMS failed." . ($event === 'ready' ? " Call {$phone}." : '')];
    } catch (Throwable $e) {
        error_log('[sms] ' . $e->getMessage());
        return ['ok' => false, 'note' => 'SMS failed.' . ($event === 'ready' ? ' Call the customer.' : '')];
    }
}

/** "Ready for Pickup" text (Mark Ready and Resend). When SMS is off, tells the staff to call. */
// The "Ready for Pickup" text (used by Mark Ready and Resend). When SMS is off,
// the staff are told to just call the customer.
function sms_notify_ready(mysqli $conn, int $order_id, int $user_id): array {
    if (!sms_enabled()) {
        $r = $conn->query("SELECT c.phone FROM orders o JOIN customers c ON c.customer_id = o.customer_id WHERE o.order_id = " . (int) $order_id)->fetch_assoc();
        return ['ok' => false, 'note' => 'SMS is off. Call ' . ($r['phone'] ?? 'the customer') . '.'];
    }
    return sms_notify_status($conn, $order_id, $user_id, 'ready');
}

/** Under the status badge of a Ready for Pickup order: only when the customer could NOT be texted, so staff know to call. '' otherwise. */
function ot_sms_html($status, $sms_status): string {
    if (trim((string) $status) !== 'Ready for Pickup' || $sms_status !== 'Failed') return '';
    return '<span class="ot-sub text-danger" style="font-size:.68rem"><i class="bi bi-exclamation-circle me-1"></i>SMS failed, call customer</span>';
}