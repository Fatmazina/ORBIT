<?php
// Drain messenger `async` transport messages one at a time with delay to avoid Mailtrap rate limits
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
require __DIR__ . '/../vendor/autoload.php';
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
$max = 50; // safety cap
$processed = 0;
for ($i = 0; $i < $max; $i++) {
    $count = $conn->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    if (!$count) {
        echo "No more messages in queue.\n";
        break;
    }
    echo "Messages remaining: $count. Processing one...\n";
    // consume one message (spawns a new process so the Symfony consumer runs normally)
    passthru(PHP_BINARY . ' bin/console messenger:consume async --limit=1 -vv', $exit);
    $processed++;
    // wait to avoid Mailtrap "too many emails per second" responses
    sleep(3);
}
if ($processed) {
    echo "Processed $processed messages.\n";
} else {
    echo "No messages processed.\n";
}
