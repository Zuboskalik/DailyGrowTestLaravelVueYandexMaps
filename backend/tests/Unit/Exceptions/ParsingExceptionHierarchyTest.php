<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\Parsing\OrganizationNotFoundException;
use App\Exceptions\Parsing\ParsingException;
use App\Exceptions\Parsing\ParsingStructureChangedException;
use App\Exceptions\Parsing\SourceBannedException;
use App\Exceptions\Parsing\SourceTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParsingExceptionHierarchyTest extends TestCase
{
    /**
     * @return array<string, array{class-string<ParsingException>, string, bool}>
     */
    public static function exceptions(): array
    {
        return [
            'structure changed' => [ParsingStructureChangedException::class, 'structure_changed', false],
            'not found' => [OrganizationNotFoundException::class, 'not_found', false],
            'banned' => [SourceBannedException::class, 'banned', true],
            'timeout' => [SourceTimeoutException::class, 'timeout', true],
        ];
    }

    #[DataProvider('exceptions')]
    public function test_each_exception_extends_the_base_and_reports_its_type(
        string $class,
        string $expectedErrorType,
        bool $expectedRetryable,
    ): void {
        $exception = new $class('boom');

        $this->assertInstanceOf(ParsingException::class, $exception);
        $this->assertSame($expectedErrorType, $exception->errorType());
        $this->assertSame($expectedRetryable, $exception->isRetryable());
    }
}
