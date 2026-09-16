<?php

/**
 * SearchFieldStatementResolver Field Name Validation Test
 *
 * A search field's column name is interpolated directly into SQL, so the
 * resolver must refuse anything that is not a bare identifier.  Otherwise a
 * caller that builds its search array from request data (query-parameter
 * names, for example) hands an attacker control of the WHERE expression.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Search;

use OpenEMR\Services\Search\DateSearchField;
use OpenEMR\Services\Search\FhirSearchWhereClauseBuilder;
use OpenEMR\Services\Search\ReferenceSearchField;
use OpenEMR\Services\Search\ReferenceSearchValue;
use OpenEMR\Services\Search\SearchFieldException;
use OpenEMR\Services\Search\SearchFieldStatementResolver;
use OpenEMR\Services\Search\SearchModifier;
use OpenEMR\Services\Search\StringSearchField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Services\Search\TokenSearchValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SearchFieldStatementResolverFieldNameTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function validFieldNameProvider(): array
    {
        return [
            'bare column' => ['fname'],
            'leading underscore' => ['_since'],
            'digits after first char' => ['street_line_2'],
            'table qualified' => ['patient.uuid'],
            'mixed case' => ['dateAdded'],
        ];
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidFieldNameProvider(): array
    {
        return [
            'subquery via parentheses' => ['(select group_concat(username,0x3a,password) from users)'],
            'tab-separated subquery' => ["(select\tgroup_concat(username)\tfrom\tusers)"],
            'trailing comment' => ['uuid -- '],
            'boolean tautology' => ['1=1 OR uuid'],
            'quote breakout' => ["uuid' OR '1'='1"],
            'backtick quoting' => ['`uuid`'],
            'whitespace' => ['uuid '],
            'two dots' => ['a.b.c'],
            'leading digit' => ['1uuid'],
            'hyphen' => ['questionnaire-code'],
            'empty string' => [''],
        ];
    }

    #[DataProvider('validFieldNameProvider')]
    public function testStringFieldWithValidNameResolves(string $fieldName): void
    {
        $field = new StringSearchField($fieldName, 'x', SearchModifier::EXACT);

        $fragment = SearchFieldStatementResolver::resolveStringSearchField($field);

        $this->assertSame("BINARY " . $fieldName . " = ?", $fragment->getFragment());
        $this->assertSame(['x'], $fragment->getBoundValues());
    }

    #[DataProvider('invalidFieldNameProvider')]
    public function testStringFieldWithInvalidNameIsRejected(string $fieldName): void
    {
        $field = new StringSearchField($fieldName, 'x', SearchModifier::EXACT);

        $this->expectException(SearchFieldException::class);
        SearchFieldStatementResolver::resolveStringSearchField($field);
    }

    #[DataProvider('invalidFieldNameProvider')]
    public function testDateFieldWithInvalidNameIsRejected(string $fieldName): void
    {
        $field = new DateSearchField($fieldName, ['2024-01-01'], DateSearchField::DATE_TYPE_DATE);

        $this->expectException(SearchFieldException::class);
        SearchFieldStatementResolver::resolveDateField($field);
    }

    #[DataProvider('invalidFieldNameProvider')]
    public function testTokenFieldWithInvalidNameIsRejected(string $fieldName): void
    {
        $field = new TokenSearchField($fieldName, [new TokenSearchValue(true)]);
        $field->setModifier(SearchModifier::MISSING);

        $this->expectException(SearchFieldException::class);
        SearchFieldStatementResolver::resolveTokenField($field);
    }

    #[DataProvider('invalidFieldNameProvider')]
    public function testReferenceFieldWithInvalidNameIsRejected(string $fieldName): void
    {
        $field = new ReferenceSearchField($fieldName, [new ReferenceSearchValue('23')]);

        $this->expectException(SearchFieldException::class);
        SearchFieldStatementResolver::resolveReferenceField($field);
    }

    /**
     * The REST search path: FhirSearchWhereClauseBuilder wraps a primitive
     * value as a StringSearchField whose column name is the array *key*.
     * The SEC-11 payload arrives exactly this way when a controller forwards
     * raw query parameters.
     */
    public function testWhereClauseBuilderRejectsInjectedParameterName(): void
    {
        $search = ['(select group_concat(username,0x3a,password) from users)' => 'x'];

        $this->expectException(SearchFieldException::class);
        FhirSearchWhereClauseBuilder::build($search, true);
    }

    public function testWhereClauseBuilderAcceptsPlainParameterName(): void
    {
        $fragment = FhirSearchWhereClauseBuilder::build(['drug' => 'aspirin'], true);

        $this->assertSame(" WHERE BINARY drug = ?", $fragment->getFragment());
        $this->assertSame(['aspirin'], $fragment->getBoundValues());
    }
}
