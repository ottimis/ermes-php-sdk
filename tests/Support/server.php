<?php

/**
 * Router del server di prova usato da HttpClientTest: rimanda indietro quello che riceve,
 * così il test può verificare corpo, header e stato senza dipendere da un servizio esterno.
 */

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/status') {
    http_response_code((int) ($_GET['code'] ?? 200));
}

if ($path === '/empty') {
    http_response_code(204);

    return true;
}

header('Content-Type: application/json');
echo json_encode([
    'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
    'body'    => file_get_contents('php://input'),
    'auth'    => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'accept'  => $_SERVER['HTTP_ACCEPT'] ?? null,
    'query'   => $_GET,
]);

return true;
