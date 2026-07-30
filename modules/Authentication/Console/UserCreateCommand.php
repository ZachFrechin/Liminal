<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Console;

use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Health\DatabaseStatusKind;
use Liminal\Lib\Security\Password\PasswordHasher;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Registry\PermissionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bootstraps an administrator: a user, the `admin` role holding every declared
 * permission, and the grant binding the two in one company. The second half of
 * the two-command install (`install`, then this).
 *
 * The password has NO option and never will: argv lands in shell history and in
 * `ps` output on a shared host, which turns a bootstrap command into a
 * credential leak. It is prompted hidden, twice — and a non-interactive run is
 * refused outright, because the alternative is a command that invents secrets.
 *
 * An admin role that already exists is left exactly as found: silently
 * re-granting a permission an administrator revoked would be worse than
 * reporting the difference and letting a human decide.
 */
#[AsCommand(
    name: 'authentication:user:create',
    description: 'Create a user holding the admin role',
)]
final class UserCreateCommand extends Command
{
    private const string ROLE_CODE = 'admin';

    private const string ROLE_LABEL = 'Administrator';

    /** NIST SP 800-63B's floor for a user-chosen secret. */
    private const int MINIMUM_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserAdministration $users,
        private readonly PasswordHasher $hasher,
        private readonly PermissionRegistry $permissions,
        private readonly DatabaseHealth $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The email address the user signs in with');
        $this->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Defaults to the part before the @');
        $this->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company id the admin role is granted in', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('This command prompts for the password and cannot run non-interactively. Run it from a terminal.');

            return Command::FAILURE;
        }

        $email = $input->getArgument('email');
        $email = is_string($email) ? trim($email) : '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error(sprintf('"%s" is not a valid email address.', $email));

            return Command::FAILURE;
        }

        $status = $this->database->check();

        if ($status->kind !== DatabaseStatusKind::Ok) {
            $io->error(sprintf('Cannot reach the database: %s', $status->detail));

            return Command::FAILURE;
        }

        $companyId = (int) $this->stringOption($input, 'company');

        if (!$this->users->companyExists($companyId)) {
            $io->error(sprintf('Company %d does not exist. Run `install` first, or pass --company.', $companyId));

            return Command::FAILURE;
        }

        if ($this->users->findUserIdByEmail($email) !== null) {
            $io->error(sprintf('A user with the email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $password = $this->promptPassword($io);

        if ($password === null) {
            return Command::FAILURE;
        }

        $displayName = $this->stringOption($input, 'display-name')
            ?: explode('@', $email)[0];

        $userId = $this->users->createUser($email, $this->hasher->hash($password), $displayName);

        $role = $this->users->ensureRole(
            self::ROLE_CODE,
            self::ROLE_LABEL,
            array_keys($this->permissions->all()),
        );

        if ($role['created']) {
            $io->text(sprintf(
                'Created the "%s" role with %d declared permission(s).',
                self::ROLE_CODE,
                count($this->permissions->all()),
            ));
        } elseif ($role['missing'] !== []) {
            // Drift, reported rather than repaired: the gap may be a revocation.
            $io->warning(sprintf(
                'The existing "%s" role is missing declared permission(s): %s. Left untouched — grant them deliberately.',
                self::ROLE_CODE,
                implode(', ', $role['missing']),
            ));
        }

        $this->users->grant($userId, $companyId, $role['id']);

        $io->success(sprintf(
            'Created %s (%s, id %d) holding "%s" in company %d.',
            $displayName,
            $email,
            $userId,
            self::ROLE_CODE,
            $companyId,
        ));

        return Command::SUCCESS;
    }

    /**
     * Hidden prompt, confirmed by a second one; null means refusal, already
     * explained to the operator.
     */
    private function promptPassword(SymfonyStyle $io): ?string
    {
        $password = $io->askHidden('Password (min ' . self::MINIMUM_PASSWORD_LENGTH . ' characters)');

        if (!is_string($password) || mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $io->error(sprintf('The password must be at least %d characters long.', self::MINIMUM_PASSWORD_LENGTH));

            return null;
        }

        if ($io->askHidden('Repeat the password') !== $password) {
            $io->error('The passwords do not match.');

            return null;
        }

        return $password;
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? trim($value) : '';
    }
}
