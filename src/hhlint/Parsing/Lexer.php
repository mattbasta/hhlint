<?php

namespace hhlint\Parsing;


class Lexer
{

    private $data = null;

    private $symbols = null;
    private $regexp = null;

    private $state = 0;
    private $line = 0;

    private $inner_pos = 0;

    private $comment_list = array();

    private $queue = array();

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

        $digit = '[0-9]';
        $letter = '[a-zA-Z_]';
        $alphanumeric = '(?:' . $digit . '|' . $letter . ')';
        $varname = $letter . $alphanumeric . '*';
        $word_part = \sprintf("(?:%s%s*)|(?:[a-z](?:%s|-)*%s)", $letter, $alphanumeric, $alphanumeric, $alphanumeric);
        $symbols['word'] = \sprintf("(?:\\\\|%s)+", $word_part);
        $symbols['xhpname'] = \sprintf("%%?%s(?:%s|:(?:%s|-)|-)*", $letter, $alphanumeric, $letter);
        $symbols['lvar'] = '\\$' . $varname;
        $ws = $symbols['ws'] = '[ \\t\\r\\x0c]';

        $hex_number = '0x(?:' . $digit . '|[a-fA-F])+';
        $bin_number = '0b[01]+';
        $decimal_number = '0|[1-9]' . $digit . '*';
        $octal_number = '0[0-7]+';
        $symbols['int'] = \sprintf('%s|%s|%s|%s', $decimal_number, $hex_number, $bin_number, $octal_number);
        $symbols['float'] = \sprintf('(?:%s*(?:\.%s+)(?:[eE](?:\+?|-)%s+)?)|(?:%s+(?:\.%s*)(?:[eE](?:\+?|-)%s+)?)|(?:%s+[eE](?:\+?|-)%s+)', $digit, $digit, $digit, $digit, $digit, $digit, $digit, $digit);
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
    (<{$symbols['xhpname']}) |
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
        // '"
        // The above line fixes syntax highlighting on github.

    }

    /**
     * Perfoms lexing on a given input string and fires a callback with tokens.
     * @return Token|null
     * @throws Exception
     */
    public function getNext()
    {
        if ($this->queue) {
            return \array_shift($this->queue);
        }

        if ($this->inner_pos === \strlen($this->data)) {
            return null;
        }

        list($matches, $i, $start, $end) = $this->getMatches($this->regexp);

        $value = $matches[0];

        $x = 0;

        switch ($i) {
            // In the case of whitespace, simply skip it and recursively continue.
            case ++$x:
                return $this->getNext();

            // Line breaks increment line count and are skipped.
            case ++$x:
                $this->line++;
                return $this->getNext();

            case ++$x: // Unsafe expression start
                return new Token(Token::T_UNSAFE_EXPR, $value, $this->line, $start, $end);

            case ++$x: // FIXME expression start
                return new Token(Token::T_FIXME_EXPR, $value, $this->line, $start, $end);

            case ++$x: // /*
                $comment = $value . $this->getComment();
                $this->comment_list[] = array($comment, $start, $this->inner_pos);
                return $this->getNext();

            case ++$x: // //
            case ++$x: // #
                $comment = $value . $this->getLineComment();
                $this->comment_list[] = array($comment, $start, $this->inner_pos);
                return $this->getNext();

            case ++$x: // "string"
                $data = $this->getDoubleQuotedString();
                return new Token(Token::T_DOUBLE_QUOTED_STRING, $data, $this->line, $start, $end);

            case ++$x: // 'string'
                $data = $this->getSingleQuotedString();
                return new Token(Token::T_QUOTED_STRING, $data, $this->line, $start, $this->inner_pos);

            case ++$x: // <<<HEREDOC ...
                return $this->getHeredoc($start);

            case ++$x: // int
                return new Token(Token::T_INT, $value, $this->line, $start, $end);

            case ++$x: // float
                return new Token(Token::T_FLOAT, $value, $this->line, $start, $end);

            case ++$x: // @required
                return new Token(Token::T_REQUIRED, $value, $this->line, $start, $end);

            case ++$x: // @
                return new Token(Token::T_AT, $value, $this->line, $start, $end);

            case ++$x: // ? >
                return new Token(Token::T_CLOSE_PHP, $value, $this->line, $start, $end);

            case ++$x:
                return new Token($this->getWord($value), $value, $this->line, $start, $end);

            case ++$x:
                return new Token(Token::T_LVAR, $value, $this->line, $start, $end);

            case ++$x: // $
                return new Token(Token::T_DOLLAR, $value, $this->line, $start, $end);

            case ++$x: // `
                return new Token(Token::T_BACKTICK, $value, $this->line, $start, $end);

            case ++$x: // <?php
                return new Token(Token::T_PHP, $value, $this->line, $start, $end);

            case ++$x: // <?hh
                return new Token(Token::T_HH, $value, $this->line, $start, $end);

            case ++$x: // XHP
                $this->queueXHP($value, $start);
                return $this->getNext();

            case ++$x: // (
                return new Token(Token::T_LP, $value, $this->line, $start, $end);

            case ++$x: // )
                return new Token(Token::T_RP, $value, $this->line, $start, $end);

            case ++$x: // ;
                return new Token(Token::T_SC, $value, $this->line, $start, $end);

            case ++$x: // ::
                return new Token(Token::T_COLCOL, $value, $this->line, $start, $end);

            case ++$x: // :
                return new Token(Token::T_COL, $value, $this->line, $start, $end);

            case ++$x: // ,
                return new Token(Token::T_COMMA, $value, $this->line, $start, $end);

            case ++$x: // ===
                return new Token(Token::T_EQEQEQ, $value, $this->line, $start, $end);

            case ++$x: // ==>
                return new Token(Token::T_LAMBDA, $value, $this->line, $start, $end);

            case ++$x: // ==
                return new Token(Token::T_EQEQ, $value, $this->line, $start, $end);

            case ++$x: // =>
                return new Token(Token::T_SARROW, $value, $this->line, $start, $end);

            case ++$x: // =
                return new Token(Token::T_EQ, $value, $this->line, $start, $end);

            case ++$x: // !==
                return new Token(Token::T_DIFF2, $value, $this->line, $start, $end);

            case ++$x: // !=
                return new Token(Token::T_DIFF, $value, $this->line, $start, $end);

            case ++$x: // !
                return new Token(Token::T_EM, $value, $this->line, $start, $end);

            case ++$x: // |=
                return new Token(Token::T_BAREQ, $value, $this->line, $start, $end);

            case ++$x: // +=
                return new Token(Token::T_PLUSEQ, $value, $this->line, $start, $end);

            case ++$x: // *=
                return new Token(Token::T_STAREQ, $value, $this->line, $start, $end);

            case ++$x: // /=
                return new Token(Token::T_SLASHEQ, $value, $this->line, $start, $end);

            case ++$x: // .=
                return new Token(Token::T_DOTEQ, $value, $this->line, $start, $end);

            case ++$x: // -=
                return new Token(Token::T_MINUSEQ, $value, $this->line, $start, $end);

            case ++$x: // %=
                return new Token(Token::T_PERCENTEQ, $value, $this->line, $start, $end);

            case ++$x: // ^=
                return new Token(Token::T_XOREQ, $value, $this->line, $start, $end);

            case ++$x: // &=
                return new Token(Token::T_AMPEQ, $value, $this->line, $start, $end);

            case ++$x: // <<=
                return new Token(Token::T_LSHIFTEQ, $value, $this->line, $start, $end);

            case ++$x: // >>=
                return new Token(Token::T_RSHIFTEQ, $value, $this->line, $start, $end);

            case ++$x: // ||
                return new Token(Token::T_BARBAR, $value, $this->line, $start, $end);

            case ++$x: // |
                return new Token(Token::T_BAR, $value, $this->line, $start, $end);

            case ++$x: // &&
                return new Token(Token::T_AMPAMP, $value, $this->line, $start, $end);

            case ++$x: // &
                return new Token(Token::T_AMP, $value, $this->line, $start, $end);

            case ++$x: // ++
                return new Token(Token::T_INCR, $value, $this->line, $start, $end);

            case ++$x: // +
                return new Token(Token::T_PLUS, $value, $this->line, $start, $end);

            case ++$x: // --
                return new Token(Token::T_DECR, $value, $this->line, $start, $end);

            case ++$x: // ->
                return new Token(Token::T_ARROW, $value, $this->line, $start, $end);

            case ++$x: // -
                return new Token(Token::T_MINUS, $value, $this->line, $start, $end);

            case ++$x: // <<
                return new Token(Token::T_LTLT, $value, $this->line, $start, $end);

            case ++$x: // <=
                return new Token(Token::T_LTE, $value, $this->line, $start, $end);

            case ++$x: // <
                return new Token(Token::T_LT, $value, $this->line, $start, $end);

            case ++$x: // >>
                return new Token(Token::T_GTGT, $value, $this->line, $start, $end);

            case ++$x: // >=
                return new Token(Token::T_GTE, $value, $this->line, $start, $end);

            case ++$x: // >
                return new Token(Token::T_GT, $value, $this->line, $start, $end);

            case ++$x: // *
                return new Token(Token::T_STAR, $value, $this->line, $start, $end);

            case ++$x: // /
                return new Token(Token::T_SLASH, $value, $this->line, $start, $end);

            case ++$x: // ^
                return new Token(Token::T_XOR, $value, $this->line, $start, $end);

            case ++$x: // %
                return new Token(Token::T_PERCENT, $value, $this->line, $start, $end);

            case ++$x: // {
                return new Token(Token::T_LCB, $value, $this->line, $start, $end);

            case ++$x: // }
                return new Token(Token::T_RCB, $value, $this->line, $start, $end);

            case ++$x: // [
                return new Token(Token::T_LB, $value, $this->line, $start, $end);

            case ++$x: // ]
                return new Token(Token::T_RB, $value, $this->line, $start, $end);

            case ++$x: // ...
                return new Token(Token::T_ELLIPSIS, $value, $this->line, $start, $end);

            case ++$x: // .
                return new Token(Token::T_DOT, $value, $this->line, $start, $end);

            case ++$x: // ?->
                return new Token(Token::T_NSARROW, $value, $this->line, $start, $end);

            case ++$x: // ?
                return new Token(Token::T_QM, $value, $this->line, $start, $end);

            case ++$x: // ~
                return new Token(Token::T_TILD, $value, $this->line, $start, $end);

            case ++$x: // _
                return new Token(Token::T_UNDERSCORE, $value, $this->line, $start, $end);

        }

        throw new SyntaxError($this->line, 'Unknown tokenization error');
    }

    /**
     * @return array
     * @throws Exception
     */
    private function getMatches($pattern)
    {

        $found = preg_match($this->regexp, $this->data, $matches, null, $this->inner_pos);
        if ($found !== 1) {
            throw new SyntaxError($this->line, 'Unknown token encountered');
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
                throw new SyntaxError($this->line, 'Unterminated comment');
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
                    break;;
                }
                $lastWasStar = false;
            }

        }

        return $buffer;

    }

    /**
     * @return string
     */
    private function getLineComment()
    {
        $buffer = '';

        while (true) {
            if ($this->inner_pos === strlen($this->data)) {
                break;
            }

            $next = $this->data[$this->inner_pos++];

            if ($next === "\n") {
                $this->line++;
                break;
            }

            $buffer .= $next;

        }

        return $buffer;

    }

    /**
     * @return string
     */
    private function getSingleQuotedString()
    {
        $buffer = '';

        while (true) {
            if ($this->inner_pos === strlen($this->data)) {
                throw new SyntaxError($this->line, 'Unterminated string');
            }

            $next = $this->data[$this->inner_pos++];
            if ($next === '\\') {
                $peek = $this->data[$this->inner_pos];
                if ($peek === "'" || $peek === '\\') {
                    $this->inner_pos++;
                    $buffer .= $peek;
                    continue;
                }
            } else if ($next === "'") {
                break;
            }

            $buffer .= $next;

            if ($next === "\n") {
                $this->line++;
            }

        }

        return $buffer;

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
            $is_nowdoc = true;
            $result = preg_match('/[A-Z][A-Z0-9_]+/', $this->data, $match, null, $this->inner_pos + 1);
            $this->inner_pos += 2;
        } else {
            $result = preg_match('/[A-Z][A-Z0-9_]+/', $this->data, $match, null, $this->inner_pos);
        }

        if ($result !== 1) {
            throw new SyntaxError($this->line, 'Invalid doc string syntax');
        }

        $name = $match[0];
        $namelen = strlen($name);

        $this->inner_pos += $namelen + 1;

        while (true) {
            if ($this->inner_pos >= strlen($this->data) - 1) {
                if ($is_nowdoc) {
                    throw new SyntaxError($this->line, 'Unterminated nowdoc');
                } else {
                    throw new SyntaxError($this->line, 'Unterminated heredoc');
                }
            }

            $next = $this->data[$this->inner_pos++];

            if ($next === '\\') {
                $next .= $this->data[$this->inner_pos++];
            } elseif ($next === '{') {
                $next .= $this->eatComplexString();
            } elseif ($next === "\n") {
                $this->line++;

                if (substr($this->data, $this->inner_pos, $namelen) === $name) {
                    $this->inner_pos += $namelen;
                    break;
                }
            }

            $buffer .= $next;

        }

        if ($is_nowdoc) {
            return new Token(Token::T_NOWDOC, $buffer, $this->line, $start, $this->inner_pos);
        } else {
            return new Token(Token::T_HEREDOC, $buffer, $this->line, $start, $this->inner_pos);
        }

    }

    /**
     * @return string
     */
    private function getDoubleQuotedString()
    {
        $buffer = '';

        while (true) {
            if ($this->inner_pos === strlen($this->data)) {
                throw new SyntaxError($this->line, 'Unterminated double-quoted string');
            }

            $next = $this->data[$this->inner_pos++];

            if ($next === '\\') {
                $next .= $this->data[$this->inner_pos++];
            } elseif ($next === '{') {
                $next .= $this->eatComplexString();
            } elseif ($next === "\n") {
                $this->line++;
            } elseif ($next === '"') {
                break;
            }

            $buffer .= $next;

        }

        return $buffer;

    }

    /**
     * Consumes a balanced number of curly braces (assuming the opening curly
     * brace has already been consumed) ignoring all types of expressions.
     * @return string
     */
    private function eatComplexString()
    {
        $buffer = '';
        $braces = 0;
        while (true) {
            $token = $this->getNext();
            $buffer .= $token->value;

            if ($token->type === Token::T_LCB) {
                $braces++;
            } elseif ($token->type === Token::T_RCB) {
                $braces--;
                if ($braces === -1) {
                    break;
                }
            }
        }

        return $buffer;
    }

    /**
     * @param string $firstMatch The first part of an XHP structure
     * @param int $start The start index of the first match
     * @return void
     */
    private function queueXHP($firstMatch, $start)
    {
        // We need to keep a separate queue which gets merged into the main
        // queue at the end. Otherwise, we couldn't use getNext because it
        // would return tokens in the wrong order.
        $queue = [];

        $queue[] = new Token(
            Token::T_XHP_NAME,
            $firstMatch,
            $this->line,
            $start,
            $this->inner_pos
        );

        $name = substr($firstMatch, 1);

        // Push everything up until the first `>` to the queue
        do {
            $next = $this->getNext();
            $queue[] = $next;
        } while ($next->type !== Token::T_GT);

        // If this is a self-closing XHP tag, we can exit now.
        if ($queue[count($queue) - 2]->type === Token::T_SLASH) {
            $this->mergeQueue($queue);
            return;
        }

        while (true) {
            $next = $this->getNext();
            $queue[] = $next;

            $queueCount = count($queue);
            // Deal with comments
            if ($queueCount > 3 &&
                $next->type === Token::T_DECR &&
                $queue[$queueCount - 2]->type === Token::T_EM &&
                $queue[$queueCount - 3]->type === Token::T_LT) {

                \array_pop($queue); // Second hyphen
                \array_pop($queue); // First hyphen
                \array_pop($queue); // Bang
                $commentStart = \array_pop($queue); // Bang

                $commentBuffer = '';

                $state = 0;
                while (true) {
                    $char = $this->data[$this->inner_pos++];
                    if ($state < 2 && $char === '-') {
                        $state++;
                    } elseif ($state === 2 && $char === '>') {
                        break;
                    } else {
                        $state = 0;
                    }
                    $commentBuffer .= $char;
                    if ($char === "\n") {
                        $this->line++;
                    }
                }
                $commentBuffer = \substr($commentBuffer, 0, \strlen($commentBuffer) - 2);

                $queue[] = new Token(
                    Token::T_XHP_COMMENT,
                    $commentBuffer,
                    $this->line,
                    $commentStart->start,
                    $this->inner_pos
                );

                continue;
            }

            if ($next->type === Token::T_SLASH && $queue[\count($queue) - 2]->type === Token::T_LT) {

                // Pop off the last two
                $slash = \array_pop($queue);
                $bracket = \array_pop($queue);

                $nameBuffer = '';
                do {
                    $next = $this->getNext();
                    if ($next->type !== Token::T_GT) $nameBuffer .= $next->value;
                } while ($next->type !== Token::T_GT);

                $queue[] = new Token(
                    Token::T_XHP_CLOSING,
                    $nameBuffer,
                    $this->line,
                    $slash->start,
                    $next->end
                );

                break;
            }
        }

        $this->mergeQueue($queue);
    }

    /**
     * @param Token[] $queue
     * @return void
     */
    private function mergeQueue($queue)
    {
        foreach ($queue as $item) {
            $this->queue[] = $item;
        }
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

    /**
     * @returns array
     */
    public function getComments()
    {
        return $this->comment_list;
    }

}
