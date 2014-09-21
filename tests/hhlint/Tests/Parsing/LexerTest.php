<?php

namespace hhlint\Tests\Parsing;

use hhlint\Parsing\Lexer;
use hhlint\Parsing\Token;


class LexerTest extends \PHPUnit_Framework_TestCase
{
    public function dataProviderExpectations()
    {
        $resources = \dirname(__DIR__) . '/Parsing/lexer_resources/*.php';
        $result = [];

        foreach (glob($resources) as $pathname) {
            $testData = \file_get_contents($pathname);

            $expectationPath = $pathname . '.exp';
            if (!\file_exists($expectationPath)) {
                echo "Could not find expectation for '$expectationPath'.\n";
                echo "Lexed:\n----------\n";
                echo $this->lex($testData);
                echo "\n----------\n";
                continue;
            }

            $result[] = [
                $pathname,
                $testData,
                \trim(\file_get_contents($expectationPath))
            ];
        }

        return $result;
    }

    private function lex($data)
    {
        $mapping = Token::getMapping();

        $lexer = new Lexer($data);
        $output = [];
        while ($tok = $lexer->getNext()) {
            $output[] = sprintf(
                "%s%s%s%d, %d",
                \str_pad($mapping[$tok->type], 32),
                \str_pad(\var_export($tok->value, true), 32),
                \str_pad((string) $tok->line, 4),
                $tok->start,
                $tok->end
            );
        }
        return \implode("\n", $output);
    }

    /**
     * @dataProvider dataProviderExpectations
     */
    public function testExpectations($path, $raw, $expectation)
    {
        $lexed = $this->lex($raw);
        $this->assertEquals($expectation, $lexed, "$path should match its expectation");
    }

    public function dataProviderUnterminatedSyntax()
    {
        return [
            ['<?php /*'],
            ['<?php $foo = "'],
            ['<?php $foo = \''],
            ['<?php $foo = <<<HEREDOC'],
            ['<?php $foo = <<<\'NOWDOC\''],
        ];
    }

    /**
     * @dataProvider dataProviderUnterminatedSyntax
     * @expectedException hhlint\Parsing\SyntaxError
     */
    public function testUnterminatedSyntax($data)
    {
        $this->lex($data);
    }

    public function dataProviderEOF()
    {
        return [
            ['<?php //'],
            ['<?php #'],
        ];
    }

    /**
     * @dataProvider dataProviderEOF
     */
    public function testEOFHandling($data)
    {
        $this->lex($data);
    }

    /**
     * @expectedException hhlint\Parsing\SyntaxError
     * @expectedExceptionMessage Invalid doc string
     */
    public function testInvalidHeredoc()
    {
        $this->lex('<?hh $foo = <<<123');
    }

    public function testComments()
    {
        $lexer = new Lexer(\file_get_contents(dirname(__DIR__) . '/Parsing/lexer_resources/comments.php'));
        while ($tok = $lexer->getNext());
        $this->assertEquals(
            $lexer->getComments(),
            [
                ['// Line comment 1', 6, 24],
                ['# Line comment 2', 25, 42],
                ["/*\nBlock comment\n***/", 43, 64],
            ],
            'Comments should be collected properly'
        );
    }

    /**
     * @expectedException hhlint\Parsing\SyntaxError
     * @expectedExceptionMessage Unknown token encountered
     */
    public function testInvalidToken()
    {
        $this->lex('<?hh ಠ_ಠ');
    }
}
