<?php
use App\Kernel;
require __DIR__ . '/../vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
if (file_exists(__DIR__.'/../.env')) {
    (new Dotenv())->bootEnv(__DIR__.'/../.env');
}
$env = getenv('APP_ENV') ?: 'dev';
$debug = getenv('APP_DEBUG') !== '0';
$kernel = new Kernel($env, $debug);
$kernel->boot();
$container = $kernel->getContainer();
/** @var \Doctrine\DBAL\Connection $conn */
$conn = $container->get('doctrine')->getConnection();
try {
    $rows = $conn->fetchAllAssociative('SELECT queue_name, COUNT(*) AS c FROM messenger_messages GROUP BY queue_name');
    if (!$rows) {
        echo "messenger_messages: 0\n";
    } else {
        foreach ($rows as $r) {
            echo sprintf("queue=%s count=%s\n", $r['queue_name'] ?: '<default>', $r['c']);
        }
    }
} catch (\Exception $e) {
    echo "Error querying DB: " . $e->getMessage() . PHP_EOL;
}
