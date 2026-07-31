<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Module\Authentication\Administration\TokenAdministration;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists a user's API tokens — ids, labels and usage, never a secret: the
 * raw values were shown once at minting and exist nowhere anymore.
 */
#[AsCommand(
    name: 'authentication:token:list',
    description: "List a user's API tokens",
)]
final class TokenListCommand extends Command
{
    public function __construct(
        private readonly UserAdministration $users,
        private readonly TokenAdministration $tokens,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The user whose tokens to list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot reach the database: %s', $status->detail));

            return Command::FAILURE;
        }

        $emailArgument = $input->getArgument('email');
        $email = is_string($emailArgument) ? trim($emailArgument) : '';

        $userId = $this->users->findUserIdByEmail($email);

        if ($userId === null) {
            $io->error(sprintf('No user with the email "%s".', $email));

            return Command::FAILURE;
        }

        $tokens = $this->tokens->listFor($userId);

        if ($tokens === []) {
            $io->writeln(sprintf('%s has no API token.', $email));

            return Command::SUCCESS;
        }

        $io->table(
            ['Id', 'Label', 'Created', 'Last used'],
            array_map(static fn(array $token): array => [
                (string) $token['id'],
                $token['label'],
                $token['created_at'],
                $token['last_used_at'] ?? 'never',
            ], $tokens),
        );

        return Command::SUCCESS;
    }
}
