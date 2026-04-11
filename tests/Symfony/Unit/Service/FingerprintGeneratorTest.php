<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Service\FingerprintGenerator;
use PHPUnit\Framework\TestCase;

final class FingerprintGeneratorTest extends TestCase
{
    private FingerprintGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FingerprintGenerator();
    }

    public function testGeneratesCorrectFingerprint(): void
    {
        $result = $this->generator->generate(
            'Test error message',
            '/path/to/file.php',
            42
        );

        $expected = sha1('Test error message|/path/to/file.php|42');

        $this->assertSame($expected, $result);
    }

    public function testDifferentInputsGenerateDifferentFingerprints(): void
    {
        $fp1 = $this->generator->generate('Error 1', '/file.php', 10);
        $fp2 = $this->generator->generate('Error 2', '/file.php', 10);
        $fp3 = $this->generator->generate('Error 1', '/file.php', 20);

        $this->assertNotSame($fp1, $fp2);
        $this->assertNotSame($fp1, $fp3);
    }

    public function testSameInputsGenerateSameFingerprints(): void
    {
        $fp1 = $this->generator->generate('Error', '/file.php', 10);
        $fp2 = $this->generator->generate('Error', '/file.php', 10);

        $this->assertSame($fp1, $fp2);
    }

    public function testFingerprintLength(): void
    {
        $result = $this->generator->generate('Test', '/file.php', 1);

        $this->assertSame(40, strlen($result));
    }
}
