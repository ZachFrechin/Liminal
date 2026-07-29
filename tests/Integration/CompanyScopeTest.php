<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Liminal\Lib\Database\EntityManagerFactory;
use Liminal\Lib\Database\Exception\CompanyReassignmentException;
use Liminal\Lib\Database\Exception\CrossCompanyAccessException;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Registry\EntityRegistry;
use Liminal\Support\Env;
use Liminal\Tests\Integration\Fixtures\Entity\Gadget;
use Liminal\Tests\Integration\Fixtures\Entity\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionProperty;
use Throwable;

/**
 * The multi-company non-regression set. Two entities, two companies — this is the
 * data set every later phase replays.
 */
#[CoversNothing]
final class CompanyScopeTest extends IntegrationTestCase
{
    private const COMPANY_A = 1;
    private const COMPANY_B = 2;

    private CompanyContext $context;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping multi-company test.');
        }

        // Prove the connection is usable before building the whole ORM stack.
        $this->connection()->close();

        $registry = new EntityRegistry();
        $registry->add('Liminal\Tests\Integration\Fixtures\Entity', __DIR__ . '/Fixtures/Entity');

        $this->context = new CompanyContext(self::COMPANY_A, self::COMPANY_A);
        $this->em = (new EntityManagerFactory($registry, $this->context))->create($dsn);

        $this->resetSchema();
        $this->seed();
    }

    public function testDqlOnlyReturnsTheCurrentCompanyRows(): void
    {
        $widgets = $this->em->createQuery(
            'SELECT w FROM ' . Widget::class . ' w ORDER BY w.label',
        )->getResult();

        self::assertIsArray($widgets);

        $labels = [];

        foreach ($widgets as $widget) {
            self::assertInstanceOf(Widget::class, $widget);
            $labels[] = $widget->getLabel();
        }

        self::assertSame(['a-one', 'a-two'], $labels);
    }

    public function testTheFilterAppliesToEveryScopedEntityNotJustOne(): void
    {
        $gadgets = $this->em->getRepository(Gadget::class)->findAll();

        self::assertCount(1, $gadgets);
        self::assertSame('gadget-a', $gadgets[0]->getReference());
    }

    /**
     * The gap the SQL filter cannot close on its own: find() short-circuits on the
     * identity map before the persister — and therefore before the filter — runs.
     * Clearing on switch plus the postLoad guard is what makes this safe.
     */
    public function testFindCannotReachAnotherCompanyRow(): void
    {
        $foreignId = $this->idOfWidgetLabelled('b-one');

        self::assertNull($this->em->find(Widget::class, $foreignId));
    }

    public function testSwitchingCompanyEvictsRowsHydratedUnderThePreviousScope(): void
    {
        $ownId = $this->idOfWidgetLabelled('a-one');

        self::assertNotNull($this->em->find(Widget::class, $ownId));

        $this->context->switchTo(self::COMPANY_B, self::COMPANY_B);

        // Without the clear() in switchTo this would still hand back company A's row.
        self::assertNull($this->em->find(Widget::class, $ownId));
    }

    public function testPersistStampsTheCurrentCompany(): void
    {
        $widget = new Widget('a-three');
        $this->em->persist($widget);
        $this->em->flush();

        self::assertSame(self::COMPANY_A, $widget->getCompanyId());
    }

    public function testPersistingIntoAnUnreachableCompanyIsRefused(): void
    {
        $widget = new Widget('smuggled');
        $widget->assignCompanyId(self::COMPANY_B);

        $this->expectException(CrossCompanyAccessException::class);

        $this->em->persist($widget);
        $this->em->flush();
    }

    public function testCreatingIntoAnotherAccessibleCompanySucceeds(): void
    {
        $this->context->switchTo(self::COMPANY_A, self::COMPANY_A, self::COMPANY_B);

        $widget = new Widget('cross-created');
        $widget->assignCompanyId(self::COMPANY_B);

        $this->em->persist($widget);
        $this->em->flush();

        self::assertEquals(
            self::COMPANY_B,
            $this->em->getConnection()->fetchOne(
                'SELECT company_id FROM test_widget WHERE label = ?',
                ['cross-created'],
            ),
        );
    }

    public function testAnActorWithTwoCompaniesSeesBoth(): void
    {
        $this->context->switchTo(self::COMPANY_A, self::COMPANY_A, self::COMPANY_B);

        self::assertCount(3, $this->em->getRepository(Widget::class)->findAll());
    }

    public function testAssignCompanyIdIsWriteOnce(): void
    {
        $widget = new Widget('write-once');
        $widget->assignCompanyId(self::COMPANY_A);
        $widget->assignCompanyId(self::COMPANY_A); // Same id is idempotent.

        $this->expectException(LogicException::class);

        $widget->assignCompanyId(self::COMPANY_B);
    }

    /**
     * The exploit this suite exists to keep closed: load a row, force its
     * company id to another company, flush. The interface has no setter any
     * more, so reflection stands in for whatever bypass an attacker finds.
     */
    public function testFlushingAReassignedEntityIsRefusedBeforeAnySqlRuns(): void
    {
        $ownId = $this->idOfWidgetLabelled('a-one');
        $widget = $this->em->find(Widget::class, $ownId);

        self::assertInstanceOf(Widget::class, $widget);

        new ReflectionProperty(Widget::class, 'companyId')->setValue($widget, self::COMPANY_B);

        $thrown = null;

        try {
            $this->em->flush();
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(
            CompanyReassignmentException::class,
            $thrown,
            'Cross-company reassignment must not reach the database.',
        );

        // onFlush fires before the transaction opens: nothing was written and
        // the EntityManager survived.
        self::assertTrue($this->em->isOpen());
        self::assertEquals(
            self::COMPANY_A,
            $this->em->getConnection()->fetchOne('SELECT company_id FROM test_widget WHERE id = ?', [$ownId]),
        );
    }

    public function testReassignmentIsRefusedEvenTowardsAnAccessibleCompany(): void
    {
        $this->context->switchTo(self::COMPANY_A, self::COMPANY_A, self::COMPANY_B);

        $widget = $this->em->find(Widget::class, $this->idOfWidgetLabelled('a-one'));

        self::assertInstanceOf(Widget::class, $widget);

        new ReflectionProperty(Widget::class, 'companyId')->setValue($widget, self::COMPANY_B);

        $this->expectException(CompanyReassignmentException::class);

        $this->em->flush();
    }

    public function testAnOrdinaryUpdateStillFlushes(): void
    {
        $ownId = $this->idOfWidgetLabelled('a-one');
        $widget = $this->em->find(Widget::class, $ownId);

        self::assertInstanceOf(Widget::class, $widget);

        $widget->relabel('a-one-renamed');
        $this->em->flush();

        self::assertEquals(
            'a-one-renamed',
            $this->em->getConnection()->fetchOne('SELECT label FROM test_widget WHERE id = ?', [$ownId]),
        );
    }

    private function idOfWidgetLabelled(string $label): int
    {
        $id = $this->em->getConnection()
            ->executeQuery('SELECT id FROM test_widget WHERE label = ?', [$label])
            ->fetchOne();

        self::assertIsNumeric($id, sprintf('Fixture widget "%s" is missing.', $label));

        return (int) $id;
    }

    private function resetSchema(): void
    {
        $tool = new SchemaTool($this->em);
        $classes = [
            $this->em->getClassMetadata(Widget::class),
            $this->em->getClassMetadata(Gadget::class),
        ];

        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }

    /**
     * Seeded through raw SQL on purpose: the ORM would refuse to write company B's
     * rows while the scope is company A, which is exactly the guarantee under test.
     */
    private function seed(): void
    {
        $connection = $this->em->getConnection();

        foreach ([['a-one', self::COMPANY_A], ['a-two', self::COMPANY_A], ['b-one', self::COMPANY_B]] as [$l, $e]) {
            $connection->insert('test_widget', ['label' => $l, 'company_id' => $e]);
        }

        foreach ([['gadget-a', self::COMPANY_A], ['gadget-b', self::COMPANY_B]] as [$r, $e]) {
            $connection->insert('test_gadget', ['reference' => $r, 'company_id' => $e]);
        }
    }
}
