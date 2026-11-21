<?php

declare(strict_types=1);

// Router script for `php -S`, started by IntegrationTest: verifies every webhook with the SDK's own verifier and
// appends accepted events (one JSON object per line) to HANSHO_TEST_EVENTS, rejected ones to HANSHO_TEST_REJECTED.

require __DIR__ . '/../../vendor/autoload.php';

use Recodive\Hansho\Exception\WebhookException;
use Recodive\Hansho\Webhook;

$body = file_get_contents('php://input');
try {
    $event = Webhook::verify(
        (string) getenv('HANSHO_WEBHOOK_SECRET'),
        $_SERVER['HTTP_X_HANSHO_WEBHOOK_SIGNATURE'] ?? null,
        $body === false ? '' : $body,
    );
    file_put_contents((string) getenv('HANSHO_TEST_EVENTS'), json_encode([
        'type' => $event->type,
        'id' => $event->id,
        'name' => $event->name,
        'data' => $event->data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(200);
} catch (WebhookException $e) {
    file_put_contents((string) getenv('HANSHO_TEST_REJECTED'), $e->reason . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(400);
}
