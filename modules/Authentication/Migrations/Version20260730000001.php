<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Liminal\Lib\Database\Migration\Migration;

/**
 * The 5b upgrade path: grants the new authentication.role.manage permission to
 * the role coded `admin` where it exists.
 *
 * Without this, every pre-5b instance would ship role screens nobody can
 * reach: the bootstrap command minted the admin role when user.manage was the
 * only declared code, and ensureRole deliberately never repairs an existing
 * role. Adding a code that DID NOT EXIST before this phase is safe by the
 * drift-report's own philosophy — a permission that never existed cannot have
 * been deliberately revoked.
 *
 * A fresh install is a no-op by construction: no admin role exists at migrate
 * time (user:create runs after), and the command grants every declared code
 * itself.
 */
final class Version20260730000001 extends Migration
{
    private const string PERMISSION = 'authentication.role.manage';

    public function getDescription(): string
    {
        return 'Grant the new role.manage permission to the existing admin role';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO core_role_permission (role_id, permission_code)
             SELECT r.id, '" . self::PERMISSION . "'
             FROM core_role r
             WHERE r.code = 'admin'
               AND NOT EXISTS (
                   SELECT 1 FROM core_role_permission rp
                   WHERE rp.role_id = r.id AND rp.permission_code = '" . self::PERMISSION . "'
               )",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rp FROM core_role_permission rp
             JOIN core_role r ON r.id = rp.role_id
             WHERE r.code = 'admin' AND rp.permission_code = '" . self::PERMISSION . "'",
        );
    }
}
