<?php
// local/swayam/webhook.php — Swayam Plus webhook receiver.
//
// Register this URL as the Receiver URL on the Swayam Plus Integration page:
//   https://your-moodle-domain/local/swayam/webhook.php
//
// Responses:
//   200  event applied, duplicate, stale, ping or unknown enrollment
//   400  body is not a usable JSON event
//   401  signature missing / invalid / too old, or no signing secret configured
//   405  not a POST
//   413  body too large
//   500  processing failed — Swayam Plus retries the delivery

define('NO_MOODLE_COOKIES', true);   // Server-to-server: no session.
define('NO_DEBUG_DISPLAY', true);    // Never leak debug output into the response.

require_once(__DIR__ . '/../../config.php');

use local_swayam\logger;
use local_swayam\webhook;

$respond = function (int $code, string $status): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => $status]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, 'method_not_allowed');
}

// The signature is computed over the body exactly as received.
$raw = file_get_contents('php://input', false, null, 0, webhook::MAX_BODY + 1);
if ($raw === false) {
    $raw = '';
}
if (strlen($raw) > webhook::MAX_BODY) {
    $respond(413, 'payload_too_large');
}

$secret    = trim((string)get_config('local_swayam', 'webhooksecret'));
$signature = (string)($_SERVER['HTTP_X_SWAYAM_SIGNATURE'] ?? '');

if ($secret === '') {
    logger::error('WEBHOOK_NOT_CONFIGURED', [
        'fix' => 'Set the Webhook signing secret: Site Admin > Plugins > Local plugins > Swayam Plus Integration',
    ]);
    $respond(401, 'not_configured');
}

if (!webhook::verify_signature($signature, $raw, $secret)) {
    logger::error('WEBHOOK_SIGNATURE_INVALID', [
        'ip'         => getremoteaddr(),
        'has_header' => $signature !== '' ? 'yes' : 'no',
        'fix'        => 'Check the signing secret matches Swayam Plus, and that the server clock is correct',
    ]);
    $respond(401, 'invalid_signature');
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    logger::error('WEBHOOK_BAD_BODY', ['reason' => 'Body is not a JSON object']);
    $respond(400, 'invalid_json');
}

$deliveryid = trim((string)($_SERVER['HTTP_X_SWAYAM_DELIVERY'] ?? ''));
if ($deliveryid === '') {
    $deliveryid = trim((string)($event['id'] ?? ''));
}
if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $deliveryid)) {
    logger::error('WEBHOOK_BAD_BODY', ['reason' => 'Missing or malformed delivery ID']);
    $respond(400, 'invalid_delivery_id');
}

try {
    $outcome = webhook::handle($deliveryid, $event);
} catch (\Throwable $e) {
    logger::error('WEBHOOK_EXCEPTION', [
        'delivery' => $deliveryid,
        'type'     => (string)($event['type'] ?? ''),
        'error'    => $e->getMessage(),
        'where'    => $e->getFile() . ':' . $e->getLine(),
        'next'     => 'Swayam Plus will retry this delivery',
    ]);
    $respond(500, 'error');
}

$respond(200, $outcome);
