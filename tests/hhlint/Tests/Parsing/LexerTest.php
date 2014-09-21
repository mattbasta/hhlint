<?php

namespace hhlint\Tests\Parsing;

use hhlint\Parsing\Lexer;

// require 'Lexer.php';

$data = <<<'DATA'
<?hh
/**
 * Copyright (c) 2014, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the "hack" directory of this source tree. An additional grant
 * of patent rights can be found in the PATENTS file in the same directory.
 *
 */

echo <<<TEST
{$foo["x
TEST;"]}";
TEST;

function f() {
  return array(
    'a' => 1,
  );
}

async function g() {
  return array(
    'a' => 1,
  );
}

function h() {
  return array(1);
}

function i() {
  return array(
    'a' => 1,
    'b' => false,
  );
}

function j() {
  return array(1, false);
}

DATA;
$x = new Lexer($data);

while ($tok = $x->getNext()) {
    echo $tok->value, "\n";
}
