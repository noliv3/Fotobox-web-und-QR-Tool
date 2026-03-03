<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responseJson(['error' => 'method_not_allowed'], 405);
}

$jobId = (int) ($_GET['id'] ?? 0);
if ($jobId <= 0) {
    responseJson(['error' => 'invalid_job_id'], 400);
}

$stmt = pdo()->prepare('SELECT status, COALESCE(last_error, error) AS error FROM print_jobs WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $jobId]);
$job = $stmt->fetch();

if (!is_array($job)) {
    responseJson(['error' => 'not_found'], 404);
}

responseJson([
    'status' => (string) $job['status'],
    'error' => $job['error'] !== null ? (string) $job['error'] : null,
]);
