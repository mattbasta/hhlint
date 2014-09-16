<?php


class Token
{
    public $type;
    public $name;
    public $line;
    public $position;

    public function __construct($type, $name, $line, $position)
    {
        $this->type = $type;
        $this->name = $name;
        $this->line = $line;
        $this->position = $position;
    }
}
