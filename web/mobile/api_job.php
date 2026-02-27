<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    respond_json(['error' => 'invalid_job_id'], 400);
}

$stmt = app_pdo()->prepare('SELECT status, error FROM print_jobs WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$job = $stmt->fetch();

if (!$job) {
    respond_json(['error' => 'not_found'], 404);
}

respond_json([
    'status' => $job['status'],
    'error' => $job['error'],
]);
