<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grants an existing role to an existing user in one company — the RBAC pivot
 * from the terminal, until phase 5b's role screens exist.
 *
 * It creates nothing: an unknown user, role or company is an error naming what
 * is absent, because a grant command that quietly invents its operands would
 * paper over typos with security state.
 */
#[AsCommand(
    name: 'authentication:role:grant',
    description: 'Grant a role to a user in a company',
)]
final class RoleGrantCommand extends Command
{
    public function __construct(
        private readonly UserAdministration $users,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The user receiving the role');
        $this->addArgument('role', InputArgument::REQUIRED, 'The role code to grant');
        $this->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company id the role applies in', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot reach the database: %s', $status->detail));

            return Command::FAILURE;
        }

        $email = $this->stringArgument($input, 'email');
        $roleCode = $this->stringArgument($input, 'role');
        $companyOption = $input->getOption('company');
        $companyId = (int) (is_string($companyOption) ? $companyOption : '1');

        $userId = $this->users->findUserIdByEmail($email);

        if ($userId === null) {
            $io->error(sprintf('No user with the email "%s".', $email));

            return Command::FAILURE;
        }

        $roleId = $this->users->findRoleIdByCode($roleCode);

        if ($roleId === null) {
            $io->error(sprintf('No role with the code "%s".', $roleCode));

            return Command::FAILURE;
        }

        if (!$this->users->companyExists($companyId)) {
            $io->error(sprintf('Company %d does not exist.', $companyId));

            return Command::FAILURE;
        }

        if (!$this->users->grant($userId, $companyId, $roleId)) {
            $io->success(sprintf('%s already holds "%s" in company %d; nothing changed.', $email, $roleCode, $companyId));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Granted "%s" to %s in company %d.', $roleCode, $email, $companyId));

        return Command::SUCCESS;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? trim($value) : '';
    }
}
