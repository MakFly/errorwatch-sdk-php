<?php

namespace ErrorWatch\Symfony\Tests\Unit\Doctrine;

use ErrorWatch\Symfony\Doctrine\TraceConnection;
use PHPUnit\Framework\TestCase;

final class TraceConnectionTest extends TestCase
{
    public function testSanitizeStripsStringLiterals(): void
    {
        $sql = "SELECT * FROM users WHERE name = 'John Doe'";
        $sanitized = TraceConnection::sanitize($sql);

        $this->assertSame('SELECT * FROM users WHERE name = ?', $sanitized);
    }

    public function testSanitizeStripsNumericLiterals(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 42';
        $sanitized = TraceConnection::sanitize($sql);

        $this->assertSame('SELECT * FROM users WHERE id = ?', $sanitized);
    }

    public function testSanitizeStripsMultipleValues(): void
    {
        $sql = "INSERT INTO users (name, age, email) VALUES ('Alice', 30, 'alice@test.com')";
        $sanitized = TraceConnection::sanitize($sql);

        $this->assertSame('INSERT INTO users (name, age, email) VALUES (?, ?, ?)', $sanitized);
    }

    public function testSanitizeHandlesEmptyStrings(): void
    {
        $sql = "SELECT * FROM users WHERE name = ''";
        $sanitized = TraceConnection::sanitize($sql);

        $this->assertSame('SELECT * FROM users WHERE name = ?', $sanitized);
    }

    public function testSanitizePreservesTableAndColumnNames(): void
    {
        $sql = 'SELECT id, name FROM users LIMIT 10';
        $sanitized = TraceConnection::sanitize($sql);

        // 'id' and 'name' are not word-boundary numbers, so they stay
        // '10' is numeric and gets replaced
        $this->assertSame('SELECT id, name FROM users LIMIT ?', $sanitized);
    }

    public function testSanitizeHandlesComplexQuery(): void
    {
        $sql = "SELECT u.id, u.name FROM users u WHERE u.age > 18 AND u.status = 'active' ORDER BY u.created_at LIMIT 50 OFFSET 100";
        $sanitized = TraceConnection::sanitize($sql);

        $this->assertStringNotContainsString("'active'", $sanitized);
        $this->assertStringNotContainsString('18', $sanitized);
        $this->assertStringNotContainsString('50', $sanitized);
        $this->assertStringNotContainsString('100', $sanitized);
    }
}
