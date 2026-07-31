<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Thirdparty;

use Liminal\Module\Thirdparty\Http\ThirdpartyForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the widest form in the tree — fourteen fields, four
 * validations, and three coercions nobody had written down: blank optionals
 * become null, the country code is upper-cased before it is checked, and the
 * three booleans do NOT share a default. That last one is the trap: `active`
 * defaults to TRUE when the field is absent (the create screen never renders
 * it), while `customer` and `supplier` default to false. The update screen
 * pairs the checkbox with a hidden `active=0` precisely because of that
 * asymmetry — a reader that "normalised" the three would make every edit
 * reactivate the record.
 */
#[CoversClass(ThirdpartyForm::class)]
final class ThirdpartyFormTest extends TestCase
{
    public function testAnEmptyBodyFailsOnTheTwoRequiredFieldsInDeclarationOrder(): void
    {
        $form = ThirdpartyForm::fromBody([]);

        self::assertSame(
            ['thirdparty.form.code_invalid', 'thirdparty.form.name_required'],
            $form->errors,
        );
        self::assertSame('thirdparty.form.code_invalid', $form->firstError());
    }

    public function testEveryValidationWrongAtOnceReportsFourErrorsInFieldOrder(): void
    {
        $form = ThirdpartyForm::fromBody([
            'code' => '',
            'name' => '',
            'email' => 'x',
            'country_code' => 'xyz',
        ]);

        self::assertSame([
            'thirdparty.form.code_invalid',
            'thirdparty.form.name_required',
            'thirdparty.form.email_invalid',
            'thirdparty.form.country_invalid',
        ], $form->errors);
    }

    public function testAValidFormReportsNothing(): void
    {
        $form = ThirdpartyForm::fromBody([
            'code' => 'ACME',
            'name' => 'Acme Corporation',
            'email' => 'contact@acme.test',
            'country_code' => 'FR',
        ]);

        self::assertSame([], $form->errors);
        self::assertNull($form->firstError());
        self::assertSame('ACME', $form->code);
        self::assertSame('Acme Corporation', $form->name);
    }

    public function testTheCodeBoundaryIsThirtyTwoCharacters(): void
    {
        self::assertSame(
            [],
            ThirdpartyForm::fromBody(['code' => str_repeat('c', 32), 'name' => 'N'])->errors,
        );
        self::assertSame(
            ['thirdparty.form.code_invalid'],
            ThirdpartyForm::fromBody(['code' => str_repeat('c', 33), 'name' => 'N'])->errors,
        );
    }

    public function testTheNameBoundaryIsOneHundredAndNinetyOneCharacters(): void
    {
        // 191, not 255: the column is indexed under utf8mb4.
        self::assertSame(
            [],
            ThirdpartyForm::fromBody(['code' => 'C', 'name' => str_repeat('é', 191)])->errors,
        );
        self::assertSame(
            ['thirdparty.form.name_required'],
            ThirdpartyForm::fromBody(['code' => 'C', 'name' => str_repeat('é', 192)])->errors,
        );
    }

    public function testTheCodeHasNoImposedGrammarUnlikeCompanyAndRoleCodes(): void
    {
        // A business reference is whatever the customer prints on their
        // paperwork; commands never anchor on it.
        $form = ThirdpartyForm::fromBody(['code' => 'cli-2026/07 #4', 'name' => 'N']);

        self::assertSame([], $form->errors);
        self::assertSame('cli-2026/07 #4', $form->code);
    }

    public function testBlankOptionalFieldsBecomeNullSoTheDatabaseHasOneWayToSayAbsent(): void
    {
        $form = ThirdpartyForm::fromBody([
            'code' => 'C',
            'name' => 'N',
            'alias' => '   ',
            'phone' => '',
            'notes' => "\t",
        ]);

        self::assertSame([], $form->errors);
        self::assertNull($form->alias);
        self::assertNull($form->phone);
        self::assertNull($form->notes);
    }

    public function testOptionalFieldsAreTrimmedWhenPresent(): void
    {
        $form = ThirdpartyForm::fromBody([
            'code' => 'C',
            'name' => 'N',
            'phone' => '  +33 1 23 45 67 89  ',
        ]);

        self::assertSame('+33 1 23 45 67 89', $form->phone);
    }

    public function testANeverPostedOptionalFieldIsNullRatherThanEmpty(): void
    {
        $form = ThirdpartyForm::fromBody(['code' => 'C', 'name' => 'N']);

        self::assertNull($form->alias);
        self::assertNull($form->email);
        self::assertNull($form->countryCode);
        self::assertNull($form->vatNumber);
    }

    public function testTheCountryCodeIsUpperCasedBeforeItIsValidated(): void
    {
        $form = ThirdpartyForm::fromBody(['code' => 'C', 'name' => 'N', 'country_code' => ' fr ']);

        self::assertSame([], $form->errors);
        self::assertSame('FR', $form->countryCode);
    }

    public function testTheThreeBooleansDoNotShareADefault(): void
    {
        $absent = ThirdpartyForm::fromBody(['code' => 'C', 'name' => 'N']);

        self::assertFalse($absent->customer);
        self::assertFalse($absent->supplier);
        // The asymmetry the class docblock warns about: a record is born
        // active, and only an explicit '0' deactivates it.
        self::assertTrue($absent->active);
    }

    public function testOnlyTheExactStringOneIsTrue(): void
    {
        $form = ThirdpartyForm::fromBody([
            'code' => 'C',
            'name' => 'N',
            'customer' => '1',
            'supplier' => 'yes',
            'active' => '0',
        ]);

        self::assertTrue($form->customer);
        self::assertFalse($form->supplier);
        self::assertFalse($form->active);
    }

    #[DataProvider('emails')]
    public function testTheEmailGrammar(string $email, bool $accepted): void
    {
        $form = ThirdpartyForm::fromBody(['code' => 'C', 'name' => 'N', 'email' => $email]);

        self::assertSame($accepted ? [] : ['thirdparty.form.email_invalid'], $form->errors);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function emails(): iterable
    {
        yield 'an ordinary address' => ['contact@acme.test', true];
        yield 'upper case' => ['A@B.CO', true];
        yield 'a plus tag' => ['a+tag@b.co', true];
        yield 'no top-level domain' => ['a@b', false];
        yield 'an embedded space' => ['a b@c.co', false];
        yield 'no at sign' => ['acme.test', false];
        // Blank is absent, and absent skips the check entirely.
        yield 'blank' => ['   ', true];
    }

    #[DataProvider('countryCodes')]
    public function testTheCountryGrammarIsTwoLettersAndNothingElse(string $code, bool $accepted): void
    {
        $form = ThirdpartyForm::fromBody(['code' => 'C', 'name' => 'N', 'country_code' => $code]);

        self::assertSame($accepted ? [] : ['thirdparty.form.country_invalid'], $form->errors);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function countryCodes(): iterable
    {
        yield 'two upper-case letters' => ['FR', true];
        yield 'two lower-case letters' => ['fr', true];
        yield 'three letters' => ['FRA', false];
        yield 'one letter' => ['F', false];
        yield 'digits' => ['33', false];
        yield 'blank' => ['  ', true];
    }
}
