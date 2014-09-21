<?php

namespace hhlint\Tests\Parsing;

use hhlint\Parsing\Lexer;
use hhlint\Parsing\Token;


class LexerTest extends \PHPUnit_Framework_TestCase
{
    public function data_provider_expectations()
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
                "%s%s%d, %d",
                \str_pad($mapping[$tok->type], 32),
                \str_pad(\var_export($tok->value, true), 32),
                $tok->start,
                $tok->end
            );
        }
        return \implode("\n", $output);
    }

    /**
     * @dataProvider data_provider_expectations
     */
    public function testExpectations($path, $raw, $expectation)
    {
        $lexed = $this->lex($raw);
        $this->assertEquals($expectation, $lexed, "$path should match its expectation");
    }
}
