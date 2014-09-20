<?php

require 'token.php';


class Lexer
{

    const STATE_DEFAULT = 0;
    const STATE_FIXME = 1;

    private $data = null;

    private $symbols = null;
    private $regexp = null;

    private $state = 0;
    private $line = 0;

    private $inner_pos = 0;

    private $comment_list = array();

    /**
     * @param {string} $data
     */
    public function __construct($data)
    {
        $this->data = $data;

        $this->constructSymbols();
        $this->constructRegexp();
    }

    /**
     * Constructs the table of regexp symbols to use in lexing.
     * @return void
     */
    private function constructSymbols()
    {
        $symbols = [];

        $digit = $symbols['digit'] = '[0-9]';
        $letter = $symbols['letter'] = '[a-zA-Z_]';
        $alphanumeric = $symbols['alphanumeric'] = '(?:' . $digit . '|' . $letter . ')';
        $varname = $symbols['varname'] = $letter . $alphanumeric . '*';
        $word_part = $symbols['word_part'] = sprintf("(?:%s%s*)|(?:[a-z](?:%s|-)*%s)", $letter, $alphanumeric, $alphanumeric, $alphanumeric);
        $symbols['word'] = sprintf("(?:\\\\|%s)+", $word_part);
        $symbols['xhpname'] = sprintf("%%?%s(?:%s|:[^:>]|-)*", $letter, $alphanumeric);
        $symbols['otag'] = sprintf("<[a-zA-Z](?:%s|:|-)*", $alphanumeric);
        $symbols['ctag'] = sprintf("</(?:%s|:|-)+>", $alphanumeric);
        $symbols['lvar'] = '\\$' . $varname;
        $symbols['reflvar'] = '&\\$' . $varname;
        $ws = $symbols['ws'] = '[ \\t\\r\\x0c]';
        $symbols['wsnl'] = '[ \\t\\r\\x0c\\n]';

        $hex_number = '0x(?:' . $digit . '|[a-fA-F])+';
        $bin_number = '0b[01]+';
        $decimal_number = '0|[1-9]' . $digit . '*';
        $octal_number = '0[0-7]+';
        $symbols['int'] = sprintf('%s|%s|%s|%s', $decimal_number, $hex_number, $bin_number, $octal_number);
        $symbols['float'] = sprintf('(?:%s*(?:\.%s+)(?:[eE](?:\+?|-)%s+)?)|(?:%s+(?:\.%s*)(?:[eE](?:\+?|-)%s+)?)|(?:%s+[eE](?:\+?|-)%s+)', $digit, $digit, $digit, $digit, $digit, $digit, $digit, $digit);
        $symbols['unsafe'] = '//' . $ws . '*UNSAFE[^\\n]*';
        $symbols['unsafeexpr_start'] = '//' . $ws . '*UNSAFE_EXPR';
        $symbols['fixme_start'] = '/\*' . $ws . '*HH_FIXME';
        $symbols['fallthrough'] = '/.' . $ws . '*FALLTHROUGH[^\\n]*';

        $this->symbols = $symbols;
    }

    /**
     * Constructs the regexp needed to lex the HHVM document
     * @return void
     */
    private function constructRegexp()
    {
        $symbols = $this->symbols;
        $this->regexp = <<<LEX
~
    ({$symbols['ws']}+) |
    (\\n) |
    ({$symbols['unsafeexpr_start']}) |
    ({$symbols['fixme_start']}) |
    (/\\*) |
    (//) |
    (\\#) |
    (") |
    (') |
    (<<<) |
    ({$symbols['int']}) |
    ({$symbols['float']}) |
    (@required) |
    (@) |
    (\\?>) |
    ({$symbols['word']}\b) |
    ({$symbols['lvar']}) |
    (\\$) |
    (`) |
    (<\\?php) |
    (<\\?hh) |
    (\\() |
    (\\)) |
    (;) |
    (::) |
    (:) |
    (,) |
    (===) |
    (==>) |
    (==) |
    (=>) |
    (=) |
    (!==) |
    (!=) |
    (!) |
    (\\|=) |
    (\\+=) |
    (\\*=) |
    (/=) |
    (\\.=) |
    (-=) |
    (%=) |
    (\\^=) |
    (&=) |
    (<<=) |
    (>>=) |
    (\\|\\|) |
    (\\|) |
    (&&) |
    (&) |
    (\\+\\+) |
    (\\+) |
    (--) |
    (->) |
    (-) |
    (<<) |
    (<=) |
    (<) |
    (>>) |
    (>=) |
    (>) |
    (\\*) |
    (/) |
    (\\^) |
    (%) |
    (\\{) |
    (\\}) |
    (\\[) |
    (\\]) |
    (\\.\\.\\.) |
    (\\.) |
    (\\?->) |
    (\\?) |
    (\\~) |
    (_)
~xA
LEX;
        echo $this->regexp;

    }

    /**
     * Perfoms lexing on a given input string and fires a callback with tokens.
     * @return Token|null
     * @throws Exception
     */
    public function getNext()
    {
        if ($this->inner_pos === strlen($this->data)) {
            return null;
        }

        list($matches, $i, $start, $end) = $this->getMatches($this->regexp);

        $value = $matches[0];

        switch ($i) {
            // In the case of whitespace, simply skip it and recursively continue.
            case 1:
                return $this->getNext();

            // Line breaks increment line count and are skipped.
            case 2:
                $this->line++;
                return $this->getNext();

            case 3: // Unsafe expression start
                return new Token(Token::T_UNSAFE_EXPR, $value, $this->line, $start, $end);

            case 4: // FIXME expression start
                return new Token(Token::T_FIXME_EXPR, $value, $this->line, $start, $end);

            case 5: // /*
                $comment = $value . $this->getComment();
                $comment_list[] = array($comment, $start, $this->inner_pos);
                return $this->getNext();

            case 6: // //
            case 7: // #
                $comment = $value . $this->getLineComment();
                $comment_list[] = array($comment, $start, $this->inner_pos);
                return $this->getNext();

            case 8: // "
                return new Token(Token::T_DOUBLE_QUOTE, $value, $this->line, $start, $end);

            case 9: // 'string'
                $data = $this->getSingleQuotedString();
                return new Token(Token::T_QUOTED_STRING, $data, $this->line, $start, $this->inner_pos);

            case 10: // <<<HEREDOC ...
                return $this->getHeredoc($start);

            case 11: // int
                return new Token(Token::T_INT, $value, $this->line, $start, $end);

            case 12: // float
                return new Token(Token::T_FLOAT, $value, $this->line, $start, $end);

            case 13: // @required
                return new Token(Token::T_REQUIRED, $value, $this->line, $start, $end);

            case 14: // @
                return new Token(Token::T_AT, $value, $this->line, $start, $end);

            case 15: // ? >
                return new Token(Token::T_CLOSE_PHP, $value, $this->line, $start, $end);

            case 16:
                return new Token($this->getWord($value), $value, $this->line, $start, $end);

            case 17:
                return new Token(Token::T_LVAR, $value, $this->line, $start, $end);

            case 18: // $
                return new Token(Token::T_DOLLAR, $value, $this->line, $start, $end);

            case 19: // `
                return new Token(Token::T_BACKTICK, $value, $this->line, $start, $end);

            case 20: // <?php
                return new Token(Token::T_PHP, $value, $this->line, $start, $end);

            case 21: // <?hh
                return new Token(Token::T_HH, $value, $this->line, $start, $end);

            case 22: // (
                return new Token(Token::T_LP, $value, $this->line, $start, $end);

            case 23: // )
                return new Token(Token::T_RP, $value, $this->line, $start, $end);

            case 24: // ;
                return new Token(Token::T_SC, $value, $this->line, $start, $end);

            case 25: // ::
                return new Token(Token::T_COLCOL, $value, $this->line, $start, $end);

            case 26: // :
                return new Token(Token::T_COL, $value, $this->line, $start, $end);

            case 27: // ,
                return new Token(Token::T_COMMA, $value, $this->line, $start, $end);

            case 28: // ===
                return new Token(Token::T_EQEQEQ, $value, $this->line, $start, $end);

            case 29: // ==>
                return new Token(Token::T_LAMBDA, $value, $this->line, $start, $end);

            case 30: // ==
                return new Token(Token::T_EQEQ, $value, $this->line, $start, $end);

            case 31: // =>
                return new Token(Token::T_SARROW, $value, $this->line, $start, $end);

            case 32: // =
                return new Token(Token::T_EQ, $value, $this->line, $start, $end);

            case 33: // !==
                return new Token(Token::T_DIFF2, $value, $this->line, $start, $end);

            case 34: // !=
                return new Token(Token::T_DIFF, $value, $this->line, $start, $end);

            case 35: // !
                return new Token(Token::T_EM, $value, $this->line, $start, $end);

            case 36: // |=
                return new Token(Token::T_BAREQ, $value, $this->line, $start, $end);

            case 37: // +=
                return new Token(Token::T_PLUSEQ, $value, $this->line, $start, $end);

            case 38: // *=
                return new Token(Token::T_STAREQ, $value, $this->line, $start, $end);

            case 39: // /=
                return new Token(Token::T_SLASHEQ, $value, $this->line, $start, $end);

            case 40: // .=
                return new Token(Token::T_DOTEQ, $value, $this->line, $start, $end);

            case 41: // -=
                return new Token(Token::T_MINUSEQ, $value, $this->line, $start, $end);

            case 42: // %=
                return new Token(Token::T_PERCENTEQ, $value, $this->line, $start, $end);

            case 43: // ^=
                return new Token(Token::T_XOREQ, $value, $this->line, $start, $end);

            case 44: // &=
                return new Token(Token::T_AMPEQ, $value, $this->line, $start, $end);

            case 45: // <<=
                return new Token(Token::T_LSHIFTEQ, $value, $this->line, $start, $end);

            case 46: // >>=
                return new Token(Token::T_RSHIFTEQ, $value, $this->line, $start, $end);

            case 47: // ||
                return new Token(Token::T_BARBAR, $value, $this->line, $start, $end);

            case 48: // |
                return new Token(Token::T_BAR, $value, $this->line, $start, $end);

            case 49: // &&
                return new Token(Token::T_AMPAMP, $value, $this->line, $start, $end);

            case 50: // &
                return new Token(Token::T_AMP, $value, $this->line, $start, $end);

            case 51: // ++
                return new Token(Token::T_INCR, $value, $this->line, $start, $end);

            case 52: // +
                return new Token(Token::T_PLUS, $value, $this->line, $start, $end);

            case 53: // --
                return new Token(Token::T_DECR, $value, $this->line, $start, $end);

            case 54: // ->
                return new Token(Token::T_ARROW, $value, $this->line, $start, $end);

            case 55: // -
                return new Token(Token::T_MINUS, $value, $this->line, $start, $end);

            case 56: // <<
                return new Token(Token::T_LTLT, $value, $this->line, $start, $end);

            case 57: // <=
                return new Token(Token::T_LTE, $value, $this->line, $start, $end);

            case 58: // <
                return new Token(Token::T_LT, $value, $this->line, $start, $end);

            case 59: // >>
                return new Token(Token::T_GTGT, $value, $this->line, $start, $end);

            case 60: // >=
                return new Token(Token::T_GTE, $value, $this->line, $start, $end);

            case 61: // >
                return new Token(Token::T_GT, $value, $this->line, $start, $end);

            case 62: // *
                return new Token(Token::T_STAR, $value, $this->line, $start, $end);

            case 63: // /
                return new Token(Token::T_SLASH, $value, $this->line, $start, $end);

            case 64: // ^
                return new Token(Token::T_XOR, $value, $this->line, $start, $end);

            case 65: // %
                return new Token(Token::T_PERCENT, $value, $this->line, $start, $end);

            case 66: // {
                return new Token(Token::T_LCB, $value, $this->line, $start, $end);

            case 67: // }
                return new Token(Token::T_RCB, $value, $this->line, $start, $end);

            case 68: // [
                return new Token(Token::T_LB, $value, $this->line, $start, $end);

            case 69: // ]
                return new Token(Token::T_RB, $value, $this->line, $start, $end);

            case 70: // ...
                return new Token(Token::T_ELLIPSIS, $value, $this->line, $start, $end);

            case 71: // .
                return new Token(Token::T_DOT, $value, $this->line, $start, $end);

            case 72: // ?->
                return new Token(Token::T_NSARROW, $value, $this->line, $start, $end);

            case 73: // ?
                return new Token(Token::T_QM, $value, $this->line, $start, $end);

            case 74: // ~
                return new Token(Token::T_TILD, $value, $this->line, $start, $end);

            case 75: // _
                return new Token(Token::T_UNDERSCORE, $value, $this->line, $start, $end);

        }

        throw new Exception('Unknown tokenization error');
    }

    /**
     * @return array
     * @throws Exception
     */
    private function getMatches($pattern)
    {

        $found = preg_match($this->regexp, $this->data, $matches, null, $this->inner_pos);
        if ($found !== 1) {
            throw new Exception('Unknown token encountered');
        }

        for ($i = 1; '' === $matches[$i]; ++$i);

        $start = $this->inner_pos;
        $this->inner_pos += strlen($matches[0]);
        $end = $this->inner_pos;

        return array($matches, $i, $start, $end);
    }

    /**
     * @return string
     */
    private function getComment()
    {
        $buffer = '';

        $lastWasStar = false;
        while (true) {
            if ($this->inner_pos === strlen($this->data)) {
                throw new Exception('Unterminated comment');
            }

            $next = $this->data[$this->inner_pos++];

            if ($next === "\n") {
                $this->line++;
            }

            $buffer .= $next;

            if ($next === '*') {
                $lastWasStar = true;
            } else {
                if ($lastWasStar && $next === '/') {
                    return $buffer;
                }
                $lastWasStar = false;
            }

        }

        $buffer = '';

        while (true) {
            list($matches, $i, $start, $end) = $this->getMatches($this->regexp_comment);

            $value = $matches[0];

            switch ($i) {
                case 1: // Line breaks increment line count and are skipped.
                    $this->line++;
                    $buffer .= $value;
                    continue;

                case 2: // */
                    $buffer .= $value;
                    return $buffer;;

                case 3: // .
                    $buffer .= $value;

                case 4: // $
                    throw new Exception('Unterminated comment');

            }

        }

    }

    /**
     * @return string
     */
    private function getLineComment()
    {
        $buffer = '';

        while (true) {
            if ($this->inner_pos === strlen($this->data)) {
                return $buffer;
            }

            $next = $this->data[$this->inner_pos++];

            if ($next === "\n") {
                $this->line++;
                return $buffer;
            }

            $buffer .= $next;

        }

    }

    /**
     * @return string
     */
    private function getSingleQuotedString()
    {
        $buffer = '';

        while (true) {
            if ($this->inner_pos === strlen($this->data)) {
                throw new Exception('Unterminated string');
            }

            $next = $this->data[$this->inner_pos++];

            if ($next === '\\') {
                $peek = $this->data[$this->inner_pos + 1];
                if ($peek === "'" || $peek === '\\') {
                    $this->inner_pos++;
                    $buffer .= $peek;
                    continue;
                }
            } else if ($next === "'") {
                return $buffer;
            }

            $buffer .= $next;

            if ($next === "\n") {
                $this->line++;
            }

        }

    }

    /**
     * @param int $start The starting index of the heredoc
     * @return Token
     */
    private function getHeredoc($start)
    {
        $buffer = '';

        $is_nowdoc = false;
        if ($this->data[$this->inner_pos] === "'") {
            $this->inner_pos += 2;
            $is_nowdoc = true;
            $result = preg_match('/[A-Z][A-Z0-9_]+/', $this->data, $match, null, $this->inner_pos + 1);
        } else {
            $result = preg_match('/[A-Z][A-Z0-9_]+/', $this->data, $match, null, $this->inner_pos);
        }

        if ($result !== 1) {
            throw new Exception('Invalid doc string syntax');
        }

        $name = $match[0];
        $namelen = strlen($name);

        $this->inner_pos += $namelen + 1;

        while (true) {
            if ($this->inner_pos === strlen($this->data)) {
                if ($is_nowdoc) {
                    throw new Exception('Unterminated nowdoc');
                } else {
                    throw new Exception('Unterminated heredoc');
                }
            }

            $next = $this->data[$this->inner_pos++];

            if ($next === '\\') {
                $peek = $this->data[++$this->inner_pos];
                $unescaped = $this->getEscape($peek);
                $buffer .= $unescaped;
                if ($unescaped === "\n") {
                    $this->line++;
                }
                continue;
            }

            if ($next === "\n") {
                $this->line++;

                if (substr($this->data, $this->inner_pos, $namelen + 1) === $name . ';') {
                    $this->inner_pos += $namelen + 1;
                    break;
                }
            }

            $buffer .= $next;

        }

        if ($is_nowdoc) {
            return new Token(Token::T_NOWDOC, $buffer, $this->line, $start, $end);
        } else {
            return new Token(Token::T_HEREDOC, $buffer, $this->line, $start, $end);
        }

    }

    /**
     * @param string $code The escape code to lookup
     * @return string
     */
    private function getEscape($code)
    {
        switch ($code) {
            case 'n': return "\n";
            case 'r': return "\r";
            case 't': return "\t";
            case 'v': return "\v";
            case 'e': return "\e";
            case 'f': return "\f";
            case '\\': return "\\";
            case '$': return '$';
            case '"': return '"';
        }

        // TODO: Implement unicode escapes

        return '\\' . $code;
    }

    /**
     * @param string $word
     * @return int
     */
    private function getWord($word)
    {
        switch ($word) {
            case 'abstract': return Token::T_ABSTRACT;
            case 'as': return Token::T_AS;
            case 'bool':
            case 'boolean': return Token::T_BOOL_CAST;
            case 'break': return Token::T_BREAK;
            case 'callable': return Token::T_CALLABLE;
            case 'case': return Token::T_CASE;
            case 'catch': return Token::T_CATCH;
            case 'class': return Token::T_CLASS;
            case '__CLASS__': return Token::T_CLASS_C;
            case 'clone': return Token::T_CLONE;
            case 'const': return Token::T_CONST;
            case 'continue': return Token::T_CONTINUE;
            case 'declare': return Token::T_DECLARE;
            case 'default': return Token::T_DEFAULT;
            case '__DIR__': return Token::T_DIR;
            case 'do': return Token::T_DO;
            case 'echo': return Token::T_ECHO;
            case 'else': return Token::T_ELSE;
            case 'elseif': return Token::T_ELSEIF;
            case 'empty': return Token::T_EMPTY;
            case 'exit': return Token::T_EXIT;
            case 'extends': return Token::T_EXTENDS;
            case '__FILE__': return Token::T_FILE;
            case 'final': return Token::T_FINAL;
            case 'finally': return Token::T_FINALLY;
            case 'for': return Token::T_FOR;
            case 'foreach': return Token::T_FOREACH;
            case 'function': return Token::T_FUNCTION;
            case '__FUNCTION__': return Token::T_FUNC_C;
            case 'global': return Token::T_GLOBAL;
            case 'if': return Token::T_IF;
            case 'implements': return Token::T_IMPLEMENTS;
            case 'include': return Token::T_INCLUDE;
            case 'include_once': return Token::T_INCLUDE_ONCE;
            case 'instanceof': return Token::T_INSTANCEOF;
            case 'insteadof': return Token::T_INSTEADOF;
            case 'interface': return Token::T_INTERFACE;
            case 'isset': return Token::T_ISSET;
            case '__LINE__': return Token::T_LINE;
            case 'list': return Token::T_LIST;
            case 'namespace': return Token::T_NAMESPACE;
            case '__NAMESPACE__': return Token::T_NS_C;
            case 'new': return Token::T_NEW;
            case 'print': return Token::T_PRINT;
            case 'private': return Token::T_PRIVATE;
            case 'public': return Token::T_PUBLIC;
            case 'protected': return Token::T_PROTECTED;
            case 'require': return Token::T_REQURE;
            case 'require_once': return Token::T_REQUIRE_ONCE;
            case 'return': return Token::T_RETURN;
            case 'static': return Token::T_STATIC;
            case 'switch': return Token::T_SWITCH;
            case 'throw': return Token::T_THROW;
            case 'trait': return Token::T_TRAIT;
            case '__TRAIT__': return Token::T_TRAIT_C;
            case 'try': return Token::T_TRY;
            case 'unset': return Token::T_UNSET;
            case 'use': return Token::T_USE;
            case 'var': return Token::T_VAR;
            case 'while': return Token::T_WHILE;
            case 'yield': return Token::T_YIELD;

            case 'async': return Token::T_ASYNC;
        }
        return Token::T_WORD;

    }

}
