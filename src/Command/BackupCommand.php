<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\BackupSettingsRepository;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:backup', description: 'Sauvegarde la base et les fichiers paramétrés (Administration › Sauvegarde) dans une archive zip')]
final class BackupCommand extends Command
{
    public function __construct(
        private readonly BackupSettingsRepository $settings,
        private readonly BackupManager $backups,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('destination', 'd', InputOption::VALUE_REQUIRED, 'Répertoire de destination (sinon celui des paramètres)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Copie : l'option --destination ne modifie pas les paramètres enregistrés.
        $settings = clone $this->settings->getSettings();
        if (null !== $input->getOption('destination')) {
            $settings->setDestination((string) $input->getOption('destination'));
        }
        try {
            $result = $this->backups->run($settings);
        } catch (BackupException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }
        $io->success(\sprintf('Sauvegarde créée : %s (%d octets, base : %s, %d fichier(s)).', $result->file, $result->size, $result->databaseFormat, $result->fileCount()));
        if ([] !== $result->deleted) {
            $io->note('Supprimées (au-delà du nombre conservé) : '.implode(', ', $result->deleted));
        }

        return Command::SUCCESS;
    }
}
