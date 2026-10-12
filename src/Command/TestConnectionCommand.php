<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ExternalConnectionRepository;
use App\Service\ConnectionTestService;
use App\Service\Http\HttpClientOptionsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:connection:test', description: 'Teste une ou toutes les connexions Jira/Confluence (diagnostic réseau / proxy)')]
final class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly ExternalConnectionRepository $connections,
        private readonly ConnectionTestService $tester,
        private readonly HttpClientOptionsProvider $httpOptions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'Identifiant de la connexion (toutes si absent)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->comment('Configuration HTTP : ' . $this->httpOptions->describe());

        $id = $input->getArgument('id');
        $list = null !== $id ? array_filter([$this->connections->find((int) $id)]) : $this->connections->findAllOrdered();
        if ([] === $list) {
            $io->warning('Aucune connexion trouvée.');

            return Command::FAILURE;
        }

        $failures = 0;
        foreach ($list as $connection) {
            $result = $this->tester->test($connection);
            if ($result['ok']) {
                $io->writeln(sprintf('<info>✔</info> [%d] %s : %s', $connection->getId(), $connection->getName(), $result['message']));
            } else {
                ++$failures;
                $io->writeln(sprintf('<error>✖</error> [%d] %s : %s', $connection->getId(), $connection->getName(), $result['message']));
            }
        }

        return 0 === $failures ? Command::SUCCESS : Command::FAILURE;
    }
}
