<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Registry\PermissionRegistry;

/**
 * What the two role forms share: the declared catalogue grouped for display,
 * and the write-side filter that keeps stored permissions inside it.
 */
final readonly class RoleFormSupport
{
    public function __construct(private PermissionRegistry $permissions) {}

    /**
     * Declared permissions grouped for the checkbox list.
     *
     * @return array<string, list<array{code: string, label: string}>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->permissions->all() as $permission) {
            $groups[$permission->group][] = [
                'code' => $permission->code,
                'label' => $permission->label,
            ];
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Submitted checkbox values reduced to DECLARED codes: a tampered form
     * must not seed core_role_permission with arbitrary strings. (Stale rows
     * that predate a module's removal are inert at runtime — the resolver
     * only joins — but nothing new gets to be born stale.)
     *
     * @return list<string>
     */
    public function declaredOnly(mixed $submitted): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $codes = [];

        foreach ($submitted as $code) {
            if (is_string($code) && $this->permissions->has($code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * The stored codes the registry no longer declares — shown as "unknown"
     * on the edit screen, and never passed through Gate::allows (which throws
     * on undeclared codes by design; the screen compares sets directly).
     *
     * @param list<string> $stored
     *
     * @return list<string>
     */
    public function unknownAmong(array $stored): array
    {
        return array_values(array_filter(
            $stored,
            fn(string $code): bool => !$this->permissions->has($code),
        ));
    }
}
