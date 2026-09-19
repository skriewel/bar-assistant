<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\TestCase;
use Kami\Cocktail\Models\ValueObjects\UnitValueObject;
use Kami\Cocktail\Models\ValueObjects\AmountValueObject;

class AmountValueObjectTest extends TestCase
{
    public function testToString(): void
    {
        $result = new AmountValueObject(30.0, new UnitValueObject('ml'));

        $this->assertSame('30 ml', (string) $result);

        $result = new AmountValueObject(30.0, new UnitValueObject('ml'), 45.0);

        $this->assertSame('30 ml - 45 ml', (string) $result);
    }

    public function testConvertTo(): void
    {
        $ml = new AmountValueObject(30.0, new UnitValueObject('ml'));
        $result = $ml->convertTo(new UnitValueObject('oz'));

        $this->assertSame('1 oz', (string) $result);

        $ml = new AmountValueObject(30.0, new UnitValueObject('ml'));
        $result = $ml->convertTo(new UnitValueObject('unknown'));

        $this->assertSame('30 ml', (string) $result);

        $ml = new AmountValueObject(30.0, new UnitValueObject('unknown'));
        $result = $ml->convertTo(new UnitValueObject('oz'));

        $this->assertSame('30 unknown', (string) $result);

        $ml = new AmountValueObject(30.0, new UnitValueObject('ml'), 45.0);
        $result = $ml->convertTo(new UnitValueObject('oz'));

        $this->assertSame('1 oz - 1.5 oz', (string) $result);
    }

    public function testSmallVolumeUnits(): void
    {
        $tsp = new AmountValueObject(1.0, new UnitValueObject('tsp'));
        $this->assertSame('5 ml', (string) $tsp->convertTo(new UnitValueObject('ml')));

        $tbsp = new AmountValueObject(1.0, new UnitValueObject('tbsp'));
        $this->assertSame('15 ml', (string) $tbsp->convertTo(new UnitValueObject('ml')));

        $barspoon = new AmountValueObject(1.0, new UnitValueObject('barspoon'));
        $this->assertSame('5 ml', (string) $barspoon->convertTo(new UnitValueObject('ml')));

        $threeTsp = new AmountValueObject(3.0, new UnitValueObject('tsp'));
        $this->assertSame('1 tbsp', (string) $threeTsp->convertTo(new UnitValueObject('tbsp')));

        $liter = new AmountValueObject(1.0, new UnitValueObject('l'));
        $this->assertSame('200 tsp', (string) $liter->convertTo(new UnitValueObject('tsp')));
    }
}
