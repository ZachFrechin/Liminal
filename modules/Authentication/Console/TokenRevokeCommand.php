<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Hook\Triggers;
use Liminal\Module\Authentication\Administration\TokenAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Revokes one API token by id — an operator gesture, so no ownership guard:
 * whoever runs the console owns the database anyway. The next bearer request
 * with that token answers 401.
 */
#[AsCommand(
    name: 'authentication:token:revoke',
    description: 'Revoke an API token by id',
)]
final class TokenRevokeCommand extends Command
{
    public function __construct(
        private readonly TokenAdministration $tokens,
        private readonly DatabaseHealth $database,
        private readonly Triggers $triggers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The token id (see token:list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot reach the database: %s', $status->detail));

            return Command::FAILURE;
        }

        $idArgument = $input->getArgument('id');
        $id = is_numeric($idArgument) ? (int) $idArgument : 0;

        if ($id < 1) {
            $io->error('The token id must be a positive integer.');

            return Command::INVALID;
        }

        $ownerId = $this->tokens->revoke($id);

        if ($ownerId === null) {
            $io->error(sprintf('No token with the id %d.', $id));

            return Command::FAILURE;
        }

        $this->triggers->fire('TOKEN_REVOKED', ['token_id' => $id, 'user_id' => $ownerId]);
        $io->success(sprintf('Token #%d revoked.', $id));

        return Command::SUCCESS;
    }
}
