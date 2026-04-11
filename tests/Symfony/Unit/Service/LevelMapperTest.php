<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Service\LevelMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LevelMapperTest extends TestCase
{
    private LevelMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LevelMapper();
    }

    // ========== mapException Tests ==========

    public function testMapHttpException500ReturnsFatal(): void
    {
        $exception = new HttpException(500, 'Internal Server Error');

        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapException($exception));
    }

    public function testMapHttpException503ReturnsFatal(): void
    {
        $exception = new HttpException(503, 'Service Unavailable');

        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapException($exception));
    }

    public function testMapHttpException404ReturnsInfo(): void
    {
        $exception = new NotFoundHttpException('Page not found');

        $this->assertSame(LevelMapper::LEVEL_INFO, $this->mapper->mapException($exception));
    }

    public function testMapHttpException400ReturnsWarning(): void
    {
        $exception = new HttpException(400, 'Bad Request');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapHttpException403ReturnsWarning(): void
    {
        $exception = new AccessDeniedHttpException('Forbidden');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapHttpException200ReturnsInfo(): void
    {
        $exception = new HttpException(200, 'OK');

        $this->assertSame(LevelMapper::LEVEL_INFO, $this->mapper->mapException($exception));
    }

    public function testMapParseErrorReturnsFatal(): void
    {
        $exception = new \ParseError('Syntax error');

        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapException($exception));
    }

    public function testMapTypeErrorReturnsFatal(): void
    {
        $exception = new \TypeError('Type error');

        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapException($exception));
    }

    public function testMapArithmeticErrorReturnsFatal(): void
    {
        $exception = new \ArithmeticError('Division by zero');

        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapException($exception));
    }

    public function testMapDivisionByZeroErrorReturnsFatal(): void
    {
        $exception = new \DivisionByZeroError('Division by zero');

        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapException($exception));
    }

    public function testMapGenericErrorReturnsError(): void
    {
        $exception = new class extends \Error {
            public function __construct()
            {
                parent::__construct('Generic error');
            }
        };

        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapException($exception));
    }

    public function testMapOverflowExceptionReturnsError(): void
    {
        $exception = new \OverflowException('Buffer overflow');

        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapException($exception));
    }

    public function testMapUnderflowExceptionReturnsError(): void
    {
        $exception = new \UnderflowException('Buffer underflow');

        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapException($exception));
    }

    public function testMapInvalidArgumentExceptionReturnsWarning(): void
    {
        $exception = new \InvalidArgumentException('Invalid argument');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapRangeExceptionReturnsWarning(): void
    {
        $exception = new \RangeException('Value out of range');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapOutOfRangeExceptionReturnsWarning(): void
    {
        $exception = new \OutOfRangeException('Index out of range');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapOutOfBoundsExceptionReturnsWarning(): void
    {
        $exception = new \OutOfBoundsException('Index out of bounds');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapLengthExceptionReturnsWarning(): void
    {
        $exception = new \LengthException('Invalid length');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapDomainExceptionReturnsWarning(): void
    {
        $exception = new \DomainException('Invalid domain');

        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapException($exception));
    }

    public function testMapRuntimeExceptionReturnsError(): void
    {
        $exception = new \RuntimeException('Runtime error');

        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapException($exception));
    }

    public function testMapLogicExceptionReturnsInfo(): void
    {
        $exception = new \LogicException('Logic error');

        $this->assertSame(LevelMapper::LEVEL_INFO, $this->mapper->mapException($exception));
    }

    public function testMapGenericExceptionReturnsError(): void
    {
        $exception = new \Exception('Generic exception');

        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapException($exception));
    }

    // ========== mapPsr3Level Tests ==========

    public function testMapPsr3EmergencyReturnsFatal(): void
    {
        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapPsr3Level(LogLevel::EMERGENCY));
    }

    public function testMapPsr3AlertReturnsFatal(): void
    {
        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapPsr3Level(LogLevel::ALERT));
    }

    public function testMapPsr3CriticalReturnsFatal(): void
    {
        $this->assertSame(LevelMapper::LEVEL_FATAL, $this->mapper->mapPsr3Level(LogLevel::CRITICAL));
    }

    public function testMapPsr3ErrorReturnsError(): void
    {
        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapPsr3Level(LogLevel::ERROR));
    }

    public function testMapPsr3WarningReturnsWarning(): void
    {
        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapPsr3Level(LogLevel::WARNING));
    }

    public function testMapPsr3NoticeReturnsInfo(): void
    {
        $this->assertSame(LevelMapper::LEVEL_INFO, $this->mapper->mapPsr3Level(LogLevel::NOTICE));
    }

    public function testMapPsr3InfoReturnsInfo(): void
    {
        $this->assertSame(LevelMapper::LEVEL_INFO, $this->mapper->mapPsr3Level(LogLevel::INFO));
    }

    public function testMapPsr3DebugReturnsDebug(): void
    {
        $this->assertSame(LevelMapper::LEVEL_DEBUG, $this->mapper->mapPsr3Level(LogLevel::DEBUG));
    }

    public function testMapPsr3UnknownLevelReturnsError(): void
    {
        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapPsr3Level('unknown'));
    }

    public function testMapPsr3CaseInsensitive(): void
    {
        $this->assertSame(LevelMapper::LEVEL_ERROR, $this->mapper->mapPsr3Level('ERROR'));
        $this->assertSame(LevelMapper::LEVEL_WARNING, $this->mapper->mapPsr3Level('Warning'));
    }

    // ========== Static Methods Tests ==========

    public function testGetValidLevelsReturnsAllLevels(): void
    {
        $levels = LevelMapper::getValidLevels();

        $this->assertContains(LevelMapper::LEVEL_FATAL, $levels);
        $this->assertContains(LevelMapper::LEVEL_ERROR, $levels);
        $this->assertContains(LevelMapper::LEVEL_WARNING, $levels);
        $this->assertContains(LevelMapper::LEVEL_INFO, $levels);
        $this->assertContains(LevelMapper::LEVEL_DEBUG, $levels);
        $this->assertCount(5, $levels);
    }

    public function testIsValidLevelReturnsTrueForValidLevels(): void
    {
        $this->assertTrue(LevelMapper::isValidLevel(LevelMapper::LEVEL_FATAL));
        $this->assertTrue(LevelMapper::isValidLevel(LevelMapper::LEVEL_ERROR));
        $this->assertTrue(LevelMapper::isValidLevel(LevelMapper::LEVEL_WARNING));
        $this->assertTrue(LevelMapper::isValidLevel(LevelMapper::LEVEL_INFO));
        $this->assertTrue(LevelMapper::isValidLevel(LevelMapper::LEVEL_DEBUG));
    }

    public function testIsValidLevelReturnsFalseForInvalidLevels(): void
    {
        $this->assertFalse(LevelMapper::isValidLevel('critical'));
        $this->assertFalse(LevelMapper::isValidLevel('notice'));
        $this->assertFalse(LevelMapper::isValidLevel(''));
    }
}
