<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Swayam Plus webhook receiver.
 *
 * Register this URL as the Receiver URL on the Swayam Plus Integration page:
 *   https://your-moodle-domain/local/swayam/webhook.php
 *
 * Server-to-server endpoint: no session, so no login or sesskey. Every
 * request is authenticated by its HMAC-SHA256 signature instead.
 *
 * Responses:
 *   200  event applied, duplicate, stale, ping or unknown enrollment
 *   400  body is not a usable JSON event
 *   401  signature missing / invalid / too old, or no signing secret configured
 *   405  not a POST
 *   413  body too large
 *   500  processing failed; Swayam Plus retries the delivery
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing
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
        'error'    => logger::describe($e),
        'where'    => $e->getFile() . ':' . $e->getLine(),
        'next'     => 'Swayam Plus will retry this delivery',
    ]);
    $respond(500, 'error');
}

$respond(200, $outcome);
