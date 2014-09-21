<?php

namespace hhlint\Parsing;


class HHSyntaxError extends \Exception
{
    protected $line = 0;
    protected $message = null;

    /**
     * @param int $line The line that the error occurred on
     * @param string|null $message The message to report
     */
    public function __construct($line, $message = null)
    {
        $this->line = $line;
        $this->message = $message;
    }

}
