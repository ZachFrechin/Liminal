<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Hook\Triggers;
use Liminal\Module\Authentication\Administration\TokenAdministration;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mints an API token for an existing user and prints the raw value ONCE —
 * the one-time-secret posture: only the sha256 reaches storage, so there is
 * no "show it again" and never will be. The email rides argv (it is an
 * identifier, not a secret); the token itself only ever travels stdout.
 */
#[AsCommand(
    name: 'authentication:token:create',
    description: 'Mint an API token for a user (shown once)',
)]
final class TokenCreateCommand extends Command
{
    public function __construct(
        private readonly UserAdministration $users,
        private readonly TokenAdministration $tokens,
        private readonly DatabaseHealth $database,
        private readonly Triggers $triggers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The user the token authenticates');
        $this->addOption('label', null, InputOption::VALUE_REQUIRED, 'What this token is for', 'api');
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
        $labelOption = $input->getOption('label');
        $label = is_string($labelOption) && trim($labelOption) !== '' ? mb_substr(trim($labelOption), 0, 100) : 'api';

        $userId = $this->users->findUserIdByEmail($email);

        if ($userId === null) {
            $io->error(sprintf('No user with the email "%s".', $email));

            return Command::FAILURE;
        }

        $minted = $this->tokens->mint($userId, $label);

        $this->triggers->fire('TOKEN_CREATED', [
            'token_id' => $minted->id,
            'user_id' => $userId,
            'label' => $label,
        ]);

        $io->success(sprintf('Token #%d ("%s") minted for %s.', $minted->id, $label, $email));
        $io->writeln('  ' . $minted->raw);
        $io->newLine();
        $io->caution('Copy it now — only its hash is stored, it will never be shown again.');

        return Command::SUCCESS;
    }
}
