$db = $argv[1] ?? '';
$pdo = new PDO("sqlite:$db");
$count = $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','sending','spooled','needs_attention','paused')")->fetchColumn();
echo (int)$count;
