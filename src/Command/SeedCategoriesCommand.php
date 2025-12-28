<?php
namespace App\Command;

use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed-categories', description: 'Seed default categories if none exist')]
class SeedCategoriesCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->em->getRepository(Category::class);
        $count = $repo->count([]);
        if ($count > 0) {
            $output->writeln('<info>Categories already exist. Skipping.</info>');
            return Command::SUCCESS;
        }

        $defaults = [
            ['name' => 'General', 'description' => 'General discussions'],
            ['name' => 'News', 'description' => 'Latest updates and news'],
            ['name' => 'Tutorials', 'description' => 'Guides and tutorials'],
            ['name' => 'Discussion', 'description' => 'Open discussions'],
        ];

        foreach ($defaults as $d) {
            $c = new Category();
            $c->setName($d['name']);
            $c->setDescription($d['description']);
            $c->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($c);
        }
        $this->em->flush();

        $output->writeln('<info>Default categories seeded.</info>');
        return Command::SUCCESS;
    }
}
