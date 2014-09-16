<?php

class Lexer
{

    private $symbols = null;

    private $line = 0;
    private $position = 0;

    public function __construct()
    {
        $this->constructSymbols();
    }

    /**
     * Constructs the table of regexp symbols to use in lexing.
     * @return void
     */
    private function constructSymbols()
    {
        $symbols = $this->symbols = [];

        $digit = $symbols['digit'] = '[0-9]';
        $letter = $symbols['letter'] = '[a-zA-Z_]';
        $alphanumeric = $symbols['alphanumeric'] = '(' . $digit . '|' . $letter . ')';
        $varname = $symbols['varname'] = $letter . $alphanumeric . '*';
        $word_part = $symbols['word_part'] = sprintf("(%s%s*)|([a-z](%s|-)*%s)", $letter, $alphanumeric, $alphanumeric, $alphanumeric);
        $symbols['word'] = sprintf("(\\\\|%s)+", $word_part);
        $symbols['xhpname'] = sprintf("(%%)?%s(%s|:[^:>]|-)*", $letter, $alphanumeric);
        $symbols['otag'] = sprintf("<[a-zA-Z](%s|:|-)*", $alphanumeric);
        $symbols['ctag'] = sprintf("</(%s|:|-)+>", $alphanumeric);
        $symbols['lvar'] = '\\$' . $varname;
        $symbols['reflvar'] = '&\\$' . $varname;
        $ws = $symbols['ws'] = '[ \\t\\r\\x0c]';
        $symbols['wsnl'] = '[ \\t\\r\\x0c\\n]';
        $hex = $symbols['hex'] = $digit . '|[a-fA-F]';
        $hex_number = $symbols['hex_number'] = '0x(' . $hex . ')+';
        $bin_number = $symbols['bin_number'] = '0b[01]+';
        $decimal_number = $symbols['decimal_number'] = '(0|[1-9]' . $digit . '+)';
        $octal_number = $symbols['octal_number'] = '0[0-7]+';
        $symbols['int'] = sprintf('(%s|%s|%s|%s)', $decimal_number, $hex_number, $bin_number, $octal_number);
        $symbols['float'] = sprintf('(%s*(\.%s+)([eE](\+?|-)%s+)?)|(%s+(\.%s*)([eE](\+?|-)%s+)?)|(%s+[eE](\+?|-)%s+)', $digit, $digit, $digit, $digit, $digit, $digit, $digit, $digit);
        $symbols['unsafe'] = '//' . $ws . '*UNSAFE[^\\n]*';
        $symbols['unsafeexpr_start'] = '//' . $ws . '*UNSAFE_EXPR';
        $symbols['fixme_start'] = '/\*' . $ws . '*HH_FIXME';
        $symbols['fallthrough'] = '/.' . $ws . '*FALLTHROUGH[^\\n]*';
    }

    /**
     * Perfoms lexing on a given input string and fires a callback with tokens.
     * @param {string} $input The string of data to lex
     * @param {function} $tokenCB A callback to fire when a token is found
     * @return void
     */
    public function lex($input, $tokenCB)
    {
        $this->line = 0;
        $position = 0;
        $length = strlen($input); // TODO: change to mb version?
        while ($position < $length) {
            $position = $this->findToken($input, $position, $cb);
        }
    }

    /**
     * Finds and fires the next token in the token stream
     * @param {string} $input The string of data to lex
     * @param {int} $start The start index to search from
     * @param {function} $cb The callback to fire when a token is found
     * @return int The start position of the next token.
     */
    private function findToken($input, $start, $cb)
    {
        switch (1) {
            case preg_match($this->formatSym('ws', '+'), $input, $matches):
                return $start + strlen($matches[0]);
            case preg_match('/^\\n/', $input, $matches):
                $this->line++;
                return $start + strlen($matches[0]);
            case preg_match($this->formatSym('unsafeexpr_start'), $input, $matches):

        }
    }

    private function formatSym($name, $extra = '')
    {
        return '/^' . $this->symbol[$name] . $extra . '/';
    }
}
