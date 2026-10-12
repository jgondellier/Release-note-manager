<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Security\SecretEncryptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:encryption:generate-key', description: 'Génère une clé APP_ENCRYPTION_KEY (à placer dans .env.local, jamais dans le dépôt)')]
final class GenerateEncryptionKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln('APP_ENCRYPTION_KEY=' . SecretEncryptionService::generateKey());
        $io->note('Sauvegardez cette clé de façon sûre : sans elle, les tokens enregistrés sont irrécupérables. Pour changer de clé sur une base existante, utilisez app:encryption:rotate-key.');

        return Command::SUCCESS;
    }
}
