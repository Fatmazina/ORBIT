<?php
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Mime\Email;
require __DIR__ . '/../vendor/autoload.php';
if (file_exists(__DIR__.'/../.env')) {
    (new Dotenv())->bootEnv(__DIR__.'/../.env');
}
$env = getenv('APP_ENV') ?: 'dev';
$debug = getenv('APP_DEBUG') !== '0';
$kernel = new Kernel($env, $debug);
$kernel->boot();
$container = $kernel->getContainer();
/** @var \Symfony\Component\Mailer\MailerInterface $mailer */
// Try getting the concrete mailer service (mailer is not public in compiled container)
$serviceIdCandidates = ['mailer.mailer', 'Symfony\\Component\\Mailer\\MailerInterface'];
$mailer = null;
foreach ($serviceIdCandidates as $id) {
    if ($container->has($id)) {
        $mailer = $container->get($id);
        break;
    }
}
if (!$mailer) {
    echo "Mailer service not available from container.\n";
    exit(1);
}
$to = $argv[1] ?? getenv('TEST_EMAIL') ?? 'you@example.com';
    ->from('noreply@orbit-forum.com')
    ->from('noreply@orbit-forum.com')
    ->subject('Test email from Orbit')
    ->text('This is a test email from Orbit application.');
try {
    $mailer->send($email);
    echo "Test email sent to $to\n";
} catch (\Throwable $e) {
    echo "Sending failed: " . $e->getMessage() . PHP_EOL;
}
