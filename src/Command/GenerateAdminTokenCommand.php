<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:admin:generate-token', description: 'Génère un token d\'administration aléatoire (ADMIN_TOKEN)')]
final class GenerateAdminTokenCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln('ADMIN_TOKEN=' . bin2hex(random_bytes(32)));
        $io->note('Placez cette valeur dans .env.local (ou une variable d\'environnement du serveur), puis exécutez « php bin/console cache:clear ». Les sessions ouvertes restent valides jusqu\'à leur expiration : videz le répertoire de sessions pour forcer une reconnexion.');

        return Command::SUCCESS;
    }
}
