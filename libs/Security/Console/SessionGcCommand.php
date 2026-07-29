<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Security\Session\SessionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sweeps expired sessions. The request-time lottery already does this
 * opportunistically; this command is what a cron uses when the lottery is
 * turned off (LIMINAL_SESSION_GC_PERCENT=0).
 */
#[AsCommand(name: 'session:gc', description: 'Delete expired sessions')]
final class SessionGcCommand extends Command
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot collect sessions: %s', $status->detail));

            return Command::FAILURE;
        }

        $io->success(sprintf('Deleted %d expired session(s).', $this->sessions->gc()));

        return Command::SUCCESS;
    }
}
