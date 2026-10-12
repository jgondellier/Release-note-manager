<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ExternalConnectionRepository;
use App\Service\Security\SecretEncryptionException;
use App\Service\Security\SecretEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-chiffre tous les secrets avec une nouvelle clé, dans une transaction (tout ou rien).
 */
#[AsCommand(name: 'app:encryption:rotate-key', description: 'Re-chiffre les secrets des connexions avec une nouvelle clé')]
final class RotateEncryptionKeyCommand extends Command
{
    public function __construct(
        private readonly ExternalConnectionRepository $connections,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('old-key', null, InputOption::VALUE_REQUIRED, 'Clé actuelle (base64)')
            ->addOption('new-key', null, InputOption::VALUE_REQUIRED, 'Nouvelle clé (base64)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Vérifie le déchiffrement sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $old = new SecretEncryptionService((string) $input->getOption('old-key'));
        $new = new SecretEncryptionService((string) $input->getOption('new-key'));
        if (!$old->isConfigured() || !$new->isConfigured()) {
            $io->error('Les options --old-key et --new-key doivent contenir des clés base64 de 32 octets.');

            return Command::INVALID;
        }

        $count = 0;
        $this->em->beginTransaction();
        try {
            foreach ($this->connections->findAll() as $connection) {
                if (!$connection->hasSecret()) {
                    continue;
                }
                $plain = $old->decrypt((string) $connection->getEncryptedSecret());
                $connection->setEncryptedSecret($new->encrypt($plain));
                ++$count;
            }
            if ($input->getOption('dry-run')) {
                $this->em->rollback();
                $io->success(sprintf('%d secret(s) déchiffrable(s) avec l\'ancienne clé. Aucune écriture (dry-run).', $count));

                return Command::SUCCESS;
            }
            $this->em->flush();
            $this->em->commit();
        } catch (SecretEncryptionException $e) {
            $this->em->rollback();
            $io->error('Rotation annulée, aucune donnée modifiée : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%d secret(s) re-chiffré(s). Remplacez maintenant APP_ENCRYPTION_KEY par la nouvelle clé puis videz le cache.', $count));

        return Command::SUCCESS;
    }
}
