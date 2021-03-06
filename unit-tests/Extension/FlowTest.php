<?php

/*
 * This file is part of the Zephir.
 *
 * (c) Zephir Team <team@zephir-lang.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Extension;

use PHPUnit\Framework\TestCase;

class FlowTest extends TestCase
{
    public function testIf()
    {
        $t = new \Test\Flow();

        $this->assertSame($t->testIf1(), 1);
        $this->assertSame($t->testIf2(), 0);
        $this->assertSame($t->testIf3(), 1);
        $this->assertSame($t->testIf4(), 0);
        $this->assertSame($t->testIf5(), 1);
        $this->assertSame($t->testIf6(), 0);
        $this->assertSame($t->testIf7(), 1);
        $this->assertSame($t->testIf8(), 0);
        $this->assertSame($t->testIf9(), 1);
        $this->assertSame($t->testIf10(), 654);
        $this->assertSame($t->testIf12(), 987);
        $this->assertSame($t->testIf13(), -12);
        $this->assertSame($t->testIf14(), 74);
        $this->assertSame($t->testIf15(), 89);
        $this->assertTrue($t->testIf16([]));
        $this->assertTrue($t->testIf16(''));
        $this->assertTrue($t->testIf16(null));
        $this->assertFalse($t->testIf16(' '));
    }

    public function testLoop()
    {
        $t = new \Test\Flow();
        $this->assertTrue($t->testLoop1());
        $this->assertSame($t->testLoop2(), 5);
        $this->assertSame($t->testLoop3(), 5);
    }

    public function testWhile()
    {
        $t = new \Test\Flow();
        $this->assertSame($t->testWhile1(), 0);
        $this->assertSame($t->testWhile2(), 0);
        $this->assertSame($t->testWhile3(), 0.0);
        $this->assertSame($t->testWhile4(), 0.0);
        $this->assertSame($t->testWhile5(), 0);
        $this->assertSame($t->testWhile6(), 0);
        $this->assertSame($t->testWhile7(), 0.0);
        $this->assertSame($t->testWhile8(), 0.0);
        $this->assertSame($t->testWhile9(), 0.0);
        $this->assertSame($t->testWhile10(10), 0.0);
        $this->assertSame($t->testWhile11(1, 10), 0.0);
        $this->assertSame($t->testWhile12(), 5);
        $this->assertSame($t->testWhile13(), 5);

        $this->assertSame(
            $t->testWhileNextTest([0, 1, 2, 3, 4, 5, 6, 7, 8, 9]),
            [1, 2, 3, 4, 5, 6, 7, 8, 9]
        );

        $this->assertSame(
            $t->testWhileDoNextTest([0, 1, 2, 3, 4, 5, 6, 7, 8, 9]),
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]
        );
    }

    public function testFor()
    {
        $t = new \Test\Flow();
        $this->assertSame($t->testFor1(), 10);
        $this->assertSame($t->testFor2(), 6.0);
        $this->assertSame($t->testFor3(), [4, 3, 2, 1]);
        $this->assertSame($t->testFor4(), 55);
        $this->assertSame($t->testFor5(), 55);
        $this->assertSame($t->testFor6(), 55);
        $this->assertSame($t->testFor7(), 55);
        $this->assertSame($t->testFor8(), 55);
        $this->assertSame($t->testFor9(), 55);
        $this->assertSame($t->testFor10(), 55);
        $this->assertSame($t->testFor11(), 'abcdefghijklmnopqrstuvwxyz');
        $this->assertSame($t->testFor12(), 'zyxwvutsrqponmlkjihgfedcba');
        $this->assertSame($t->testFor13(), '0123456789');
        $this->assertSame($t->testFor14(), '9876543210');
        $this->assertSame($t->testFor15(1, 10), 55);

        $this->assertSame(
            $t->testFor16(),
            [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 8, 8 => 9, 9 => 10]
        );

        $this->assertSame(
            $t->testFor17(),
            [0 => 10, 1 => 9, 2 => 8, 3 => 7, 4 => 6, 5 => 5, 6 => 4, 7 => 3, 8 => 2, 9 => 1]
        );

        $this->assertSame(
            $t->testFor18(),
            [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 8, 8 => 9, 9 => 10]
        );

        $this->assertSame($t->testFor19(), 25);
        $this->assertSame($t->testFor20(), 25);
        $this->assertSame($t->testFor21(), 0);
        $this->assertSame($t->testFor22(), 0);
        $this->assertSame($t->testFor23(), 'zxvtrpnljhfdb');
    }
}
