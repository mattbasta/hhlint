<?php

namespace hhlint\Parsing;

class Token
{
    const T_UNSAFE_EXPR = 0;
    const T_FIXME_EXPR = 1;
    const T_DOUBLE_QUOTED_STRING = 2;
    const T_QUOTED_STRING = 3;
    const T_HEREDOC = 4;
    const T_INT = 5;
    const T_FLOAT = 6;
    const T_AT = 7;
    const T_CLOSE_PHP = 8;
    const T_WORD = 9;
    const T_LVAR = 10;
    const T_DOLLAR = 11;
    const T_BACKTICK = 12;
    const T_PHP = 13;
    const T_HH = 14;
    const T_LP = 15;
    const T_RP = 16;
    const T_SC = 17;
    const T_COL = 18;
    const T_COLCOL = 19;
    const T_COMMA = 20;
    const T_EQEQEQ = 21;
    const T_EQEQ = 22;
    const T_EQ = 23;
    const T_DIFF = 24;
    const T_DIFF2 = 25;
    const T_EM = 26;
    const T_BAREQ = 27;
    const T_PLUSEQ = 28;
    const T_STAREQ = 29;
    const T_SLASHEQ = 30;
    const T_DOTEQ = 31;
    const T_MINUSEQ = 32;
    const T_PERCENTEQ = 33;
    const T_XOREQ = 34;
    const T_AMPEQ = 35;
    const T_LSHIFTEQ = 36;
    const T_RSHIFTEQ = 37;
    const T_BARBAR = 38;
    const T_BAR = 39;
    const T_AMPAMP = 40;
    const T_AMP = 41;
    const T_LAMBDA = 42;
    const T_SARROW = 43;
    const T_LTLT = 44;
    const T_GTGT = 45;
    const T_LT = 46;
    const T_GT = 47;
    const T_LTE = 48;
    const T_GTE = 49;
    const T_PLUS = 50;
    const T_MINUS = 51;
    const T_STAR = 52;
    const T_SLASH = 53;
    const T_XOR = 54;
    const T_PERCENT = 55;
    const T_LCB = 56;
    const T_RCB = 57;
    const T_LB = 58;
    const T_RB = 59;
    const T_DOT = 60;
    const T_NSARROW = 61;
    const T_ARROW = 62;
    const T_QM = 63;
    const T_TILD = 64;
    const T_INCR = 65;
    const T_DECR = 66;
    const T_UNDERSCORE = 67;
    const T_REQUIRED = 68;
    const T_ELLIPSIS = 69;
    const T_UNSAFE = 70;
    const T_FALLTHROUGH = 71;

    const T_ABSTRACT = 72;
    const T_ARRAY = 73;
    const T_AS = 74;
    const T_BOOL_CAST = 75;
    const T_BREAK = 76;
    const T_CALLABLE = 77;
    const T_CASE = 78;
    const T_CATCH = 79;
    const T_CLASS = 80;
    const T_CLASS_C = 81;
    const T_CLONE = 82;
    const T_CONST = 83;
    const T_CONTINUE = 84;
    const T_DECLARE = 85;
    const T_DEFAULT = 86;
    const T_DIR = 87;
    const T_DO = 88;
    const T_ECHO = 89;
    const T_ELSE = 90;
    const T_ELSEIF = 91;
    const T_EMPTY = 92;
    const T_EXIT = 93;
    const T_EXTENDS = 94;
    const T_FILE = 95;
    const T_FINAL = 96;
    const T_FINALLY = 97;
    const T_FOR = 98;
    const T_FOREACH = 99;
    const T_FUNCTION = 100;
    const T_FUNC_C = 101;
    const T_GLOBAL = 102;
    const T_IF = 103;
    const T_IMPLEMENTS = 104;
    const T_INCLUDE = 105;
    const T_INCLUDE_ONCE = 106;
    const T_INSTANCEOF = 107;
    const T_INSTEADOF = 108;
    const T_INTERFACE = 109;
    const T_ISSET = 110;
    const T_LINE = 111;
    const T_LIST = 112;
    const T_NAMESPACE = 113;
    const T_NS_C = 114;
    const T_NEW = 115;
    const T_PRINT = 116;
    const T_PRIVATE = 117;
    const T_PUBLIC = 118;
    const T_PROTECTED = 119;
    const T_REQUIRE = 120;
    const T_REQUIRE_ONCE = 121;
    const T_RETURN = 122;
    const T_STATIC = 123;
    const T_SWITCH = 124;
    const T_THROW = 125;
    const T_TRAIT = 126;
    const T_TRAIT_C = 127;
    const T_TRY = 128;
    const T_UNSET = 129;
    const T_USE = 130;
    const T_VAR = 131;
    const T_WHILE = 132;
    const T_YIELD = 133;

    const T_NOWDOC = 134;

    const T_ASYNC = 135;
    const T_AWAIT = 136;

    const T_XHP_NAME = 137;
    const T_XHP_CLOSING = 138;
    const T_XHP_COMMENT = 139;

    private static $mapping = array();

    public $type;
    public $value;
    public $line;
    public $start;
    public $end;

    public function __construct($type, $value, $line, $start, $end)
    {
        $this->type = $type;
        $this->value = $value;
        $this->line = $line;
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * @return array A lookup table for token names
     */
    public static function getMapping()
    {
        if (self::$mapping) {
            return self::$mapping;
        }

        $reflector = new \ReflectionClass('hhlint\\Parsing\\Token');
        $results = $reflector->getConstants();

        $results = \array_flip($results);

        self::$mapping = $results;
        return $results;
    }

}
