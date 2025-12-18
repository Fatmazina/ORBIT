<?php
namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create default admin account')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->em->getRepository(User::class);
        $existing = $repo->findOneBy(['email' => 'admin@gmail.com']);
        if ($existing) {
            $output->writeln('<info>Admin account already exists.</info>');
            return Command::SUCCESS;
        }

        $user = new User();
        $user->setEmail('admin@gmail.com');
        $user->setUsername('admin');
        $user->setPassword($this->hasher->hashPassword($user, 'admin123'));
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $user->setIsActive(true);
        $user->setIsEmailVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln('<info>Admin account created: admin@gmail.com / admin123</info>');
        return Command::SUCCESS;
    }
}
