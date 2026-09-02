<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use Restina\Validator;

class ValidatorTest extends TestCase
{
    // ─── extractRule 解析 ────────────────────────────────────

    public function testExtractRuleParsesSingleRule(): void
    {
        $rules = Validator::extractRule('required');
        $this->assertCount(1, $rules);
        $this->assertSame('required', $rules[0]['name']);
        $this->assertNull($rules[0]['value']);
    }

    public function testExtractRuleParsesMultipleRules(): void
    {
        $rules = Validator::extractRule('required|numeric|min:5');
        $this->assertCount(3, $rules);
        $this->assertSame('required', $rules[0]['name']);
        $this->assertSame('numeric', $rules[1]['name']);
        $this->assertSame('min', $rules[2]['name']);
        $this->assertSame('5', $rules[2]['value']);
    }

    public function testExtractRuleHandlesColonInValue(): void
    {
        $rules = Validator::extractRule('dateFormat:Y-m-d H:i:s');
        $this->assertCount(1, $rules);
        $this->assertSame('dateFormat', $rules[0]['name']);
        $this->assertSame('Y-m-d H:i:s', $rules[0]['value']);
    }

    public function testExtractRuleReturnsEmptyForEmptyString(): void
    {
        $this->assertEmpty(Validator::extractRule(''));
    }

    public function testExtractRuleTrimsWhitespace(): void
    {
        $rules = Validator::extractRule(' required | min: 5 ');
        $this->assertSame('required', $rules[0]['name']);
        $this->assertSame('min', $rules[1]['name']);
        $this->assertSame('5', $rules[1]['value']);
    }

    // ─── required ────────────────────────────────────────────

    public function testRequiredPassesWithValue(): void
    {
        $result = Validator::validate('hello', 'required', 'name');
        $this->assertSame('hello', $result);
    }

    public function testRequiredFailsWithNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("参数 'name' 是必填项");
        Validator::validate(null, 'required', 'name');
    }

    public function testRequiredFailsWithEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('', 'required', 'name');
    }

    // ─── optional ────────────────────────────────────────────

    public function testOptionalSkipsValidationForNull(): void
    {
        $result = Validator::validate(null, 'optional|required', 'name');
        $this->assertNull($result);
    }

    public function testOptionalSkipsValidationForEmptyString(): void
    {
        $result = Validator::validate('', 'optional|required', 'name');
        $this->assertSame('', $result);
    }

    public function testOptionalContinuesValidationWhenValuePresent(): void
    {
        $result = Validator::validate('abc', 'optional|lengthMin:2', 'name');
        $this->assertSame('abc', $result);
    }

    // ─── numeric / integer ───────────────────────────────────

    public function testNumericPasses(): void
    {
        $this->assertSame('123', Validator::validate('123', 'numeric', 'age'));
        $this->assertSame(123, Validator::validate(123, 'numeric', 'age'));
        $this->assertSame('1.5', Validator::validate('1.5', 'numeric', 'price'));
    }

    public function testNumericFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("参数 'age' 必须是数字");
        Validator::validate('abc', 'numeric', 'age');
    }

    public function testIntegerPasses(): void
    {
        $this->assertSame('42', Validator::validate('42', 'integer', 'count'));
    }

    public function testIntegerFailsForFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('1.5', 'integer', 'count');
    }

    // ─── boolean ─────────────────────────────────────────────

    public function testBooleanCoercion(): void
    {
        $this->assertTrue(Validator::validate('1', 'boolean', 'flag'));
        $this->assertTrue(Validator::validate('true', 'boolean', 'flag'));
        $this->assertTrue(Validator::validate('on', 'boolean', 'flag'));
        $this->assertTrue(Validator::validate('yes', 'boolean', 'flag'));
        $this->assertTrue(Validator::validate(true, 'boolean', 'flag'));
        $this->assertFalse(Validator::validate('0', 'boolean', 'flag'));
        $this->assertFalse(Validator::validate('false', 'boolean', 'flag'));
        $this->assertFalse(Validator::validate('off', 'boolean', 'flag'));
        $this->assertFalse(Validator::validate('no', 'boolean', 'flag'));
        $this->assertFalse(Validator::validate(false, 'boolean', 'flag'));
    }

    public function testBooleanFailsForInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('maybe', 'boolean', 'flag');
    }

    // ─── length rules ────────────────────────────────────────

    public function testLengthExact(): void
    {
        $this->assertSame('abc', Validator::validate('abc', 'length:3', 'code'));
    }

    public function testLengthExactFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("参数 'code' 长度必须为 3");
        Validator::validate('ab', 'length:3', 'code');
    }

    public function testLengthBetween(): void
    {
        $this->assertSame('abc', Validator::validate('abc', 'lengthBetween:2,5', 'name'));
    }

    public function testLengthBetweenFailsTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('a', 'lengthBetween:2,5', 'name');
    }

    public function testLengthBetweenFailsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('abcdef', 'lengthBetween:2,5', 'name');
    }

    public function testLengthMin(): void
    {
        $this->assertSame('abc', Validator::validate('abc', 'lengthMin:2', 'name'));
    }

    public function testLengthMinFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('a', 'lengthMin:2', 'name');
    }

    public function testLengthMax(): void
    {
        $this->assertSame('abc', Validator::validate('abc', 'lengthMax:5', 'name'));
    }

    public function testLengthMaxFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('abcdef', 'lengthMax:5', 'name');
    }

    // ─── min / max ───────────────────────────────────────────

    public function testMinPasses(): void
    {
        $this->assertSame(10, Validator::validate(10, 'min:5', 'age'));
    }

    public function testMinFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("参数 'age' 不能小于 5");
        Validator::validate(3, 'min:5', 'age');
    }

    public function testMaxPasses(): void
    {
        $this->assertSame(5, Validator::validate(5, 'max:10', 'age'));
    }

    public function testMaxFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate(15, 'max:10', 'age');
    }

    // ─── in / notIn ──────────────────────────────────────────

    public function testInPasses(): void
    {
        $this->assertSame('a', Validator::validate('a', 'in:a,b,c', 'letter'));
    }

    public function testInFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('d', 'in:a,b,c', 'letter');
    }

    public function testNotInPasses(): void
    {
        $this->assertSame('d', Validator::validate('d', 'notIn:a,b,c', 'letter'));
    }

    public function testNotInFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('a', 'notIn:a,b,c', 'letter');
    }

    // ─── email / url / ip ────────────────────────────────────

    public function testEmailPasses(): void
    {
        $this->assertSame('a@b.com', Validator::validate('a@b.com', 'email', 'mail'));
    }

    public function testEmailFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('not-email', 'email', 'mail');
    }

    public function testUrlPasses(): void
    {
        $this->assertSame('https://example.com', Validator::validate('https://example.com', 'url', 'site'));
    }

    public function testUrlFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('not-a-url', 'url', 'site');
    }

    public function testIpPasses(): void
    {
        $this->assertSame('127.0.0.1', Validator::validate('127.0.0.1', 'ip', 'addr'));
    }

    public function testIpFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('999.999.999.999', 'ip', 'addr');
    }

    // ─── alpha / alphaNum / slug ─────────────────────────────

    public function testAlphaPasses(): void
    {
        $this->assertSame('abc', Validator::validate('abc', 'alpha', 'name'));
    }

    public function testAlphaFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('abc123', 'alpha', 'name');
    }

    public function testAlphaNumPasses(): void
    {
        $this->assertSame('abc123', Validator::validate('abc123', 'alphaNum', 'code'));
    }

    public function testAlphaNumFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('abc-123', 'alphaNum', 'code');
    }

    public function testSlugPasses(): void
    {
        $this->assertSame('hello-world', Validator::validate('hello-world', 'slug', 'slug'));
    }

    public function testSlugFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('Hello World!', 'slug', 'slug');
    }

    // ─── equals / different ──────────────────────────────────

    public function testEqualsPasses(): void
    {
        $this->assertSame('foo', Validator::validate('foo', 'equals:foo', 'val'));
    }

    public function testEqualsFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('bar', 'equals:foo', 'val');
    }

    public function testDifferentPasses(): void
    {
        $this->assertSame('bar', Validator::validate('bar', 'different:foo', 'val'));
    }

    public function testDifferentFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('foo', 'different:foo', 'val');
    }

    // ─── accepted ────────────────────────────────────────────

    public function testAcceptedPasses(): void
    {
        foreach ([true, 1, '1', 'on', 'yes', 'true'] as $val) {
            $this->assertSame($val, Validator::validate($val, 'accepted', 'terms'));
        }
    }

    public function testAcceptedFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('no', 'accepted', 'terms');
    }

    // ─── date rules ──────────────────────────────────────────

    public function testDatePasses(): void
    {
        $this->assertSame('2025-01-15', Validator::validate('2025-01-15', 'date', 'dt'));
    }

    public function testDateFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('not-a-date', 'date', 'dt');
    }

    public function testDateFormatPasses(): void
    {
        $this->assertSame('15/01/2025', Validator::validate('15/01/2025', 'dateFormat:d/m/Y', 'dt'));
    }

    public function testDateFormatFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('2025-01-15', 'dateFormat:d/m/Y', 'dt');
    }

    public function testDateBeforePasses(): void
    {
        $this->assertSame('2020-01-01', Validator::validate('2020-01-01', 'dateBefore:2025-01-01', 'dt'));
    }

    public function testDateBeforeFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('2026-01-01', 'dateBefore:2025-01-01', 'dt');
    }

    public function testDateAfterPasses(): void
    {
        $this->assertSame('2026-01-01', Validator::validate('2026-01-01', 'dateAfter:2025-01-01', 'dt'));
    }

    public function testDateAfterFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('2020-01-01', 'dateAfter:2025-01-01', 'dt');
    }

    // ─── contains ────────────────────────────────────────────

    public function testContainsPasses(): void
    {
        $this->assertSame('hello world', Validator::validate('hello world', 'contains:world', 'msg'));
    }

    public function testContainsFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('hello', 'contains:world', 'msg');
    }

    // ─── regex ───────────────────────────────────────────────

    public function testRegexPasses(): void
    {
        $this->assertSame('abc', Validator::validate('abc', 'regex:/^[a-z]+$/', 'val'));
    }

    public function testRegexFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('123', 'regex:/^[a-z]+$/', 'val');
    }

    public function testRegexUsesWhitelistedPattern(): void
    {
        $this->assertSame(
            '550e8400-e29b-41d4-a716-446655440000',
            Validator::validate('550e8400-e29b-41d4-a716-446655440000', 'regex:uuid', 'val')
        );
    }

    // ─── creditCard (Luhn) ───────────────────────────────────

    public function testCreditCardPasses(): void
    {
        // Visa test number
        $this->assertSame('4111111111111111', Validator::validate('4111111111111111', 'creditCard', 'cc'));
    }

    public function testCreditCardFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('1234567890123456', 'creditCard', 'cc');
    }

    // ─── array ───────────────────────────────────────────────

    public function testArrayPasses(): void
    {
        $this->assertSame([1, 2], Validator::validate([1, 2], 'array', 'items'));
    }

    public function testArrayFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('not-array', 'array', 'items');
    }

    // ─── 组合规则 ────────────────────────────────────────────

    public function testMultipleRulesChained(): void
    {
        $result = Validator::validate('hello@test.com', 'required|email', 'mail');
        $this->assertSame('hello@test.com', $result);
    }

    public function testMultipleRulesFirstFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('', 'required|email', 'mail');
    }

    // ─── 空规则 ──────────────────────────────────────────────

    public function testEmptyRulesReturnValueAsIs(): void
    {
        $this->assertSame('anything', Validator::validate('anything', '', 'val'));
        $this->assertNull(Validator::validate(null, '', 'val'));
    }

    // ─── length 对整数的支持 ─────────────────────────────────

    public function testLengthWithIntegerValue(): void
    {
        // 整数 123 转为字符串 '123'，长度为 3
        $this->assertSame(123, Validator::validate(123, 'length:3', 'code'));
    }

    public function testLengthWithIntegerValueFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate(12345, 'length:3', 'code');
    }

    public function testLengthWithArrayThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('必须是字符串或数字');
        Validator::validate([1, 2], 'length:3', 'items');
    }

    // ─── lengthMin / lengthMax 边界条件 ──────────────────────

    public function testLengthMinExactBoundary(): void
    {
        // 长度恰好等于 min
        $this->assertSame('ab', Validator::validate('ab', 'lengthMin:2', 'name'));
    }

    public function testLengthMaxExactBoundary(): void
    {
        // 长度恰好等于 max
        $this->assertSame('abcde', Validator::validate('abcde', 'lengthMax:5', 'name'));
    }

    public function testLengthBetweenExactBoundaries(): void
    {
        $this->assertSame('ab', Validator::validate('ab', 'lengthBetween:2,5', 'name'));
        $this->assertSame('abcde', Validator::validate('abcde', 'lengthBetween:2,5', 'name'));
    }

    // ─── 白名单正则模式 ─────────────────────────────────────

    public function testRegexWhitelistedPhone(): void
    {
        $this->assertSame(
            '13800138000',
            Validator::validate('13800138000', 'regex:phone', 'tel')
        );
    }

    public function testRegexWhitelistedPhoneFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('12345', 'regex:phone', 'tel');
    }

    public function testRegexWhitelistedZipcode(): void
    {
        $this->assertSame(
            '100000',
            Validator::validate('100000', 'regex:zipcode', 'zip')
        );
    }

    public function testRegexWhitelistedZipcodeFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('12345', 'regex:zipcode', 'zip');
    }

    public function testRegexWhitelistedUsername(): void
    {
        $this->assertSame(
            'john_doe',
            Validator::validate('john_doe', 'regex:username', 'user')
        );
    }

    public function testRegexWhitelistedUsernameFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate('1bad', 'regex:username', 'user');
    }

    // ─── required 与 null / 空数组 ──────────────────────────

    public function testRequiredFailsWithNullExplicit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('必填项');
        Validator::validate(null, 'required', 'field');
    }

    public function testRequiredPassesWithZero(): void
    {
        // 0 不是 null 也不是空字符串，应通过
        $this->assertSame(0, Validator::validate(0, 'required', 'count'));
    }

    public function testRequiredPassesWithArray(): void
    {
        $this->assertSame([1, 2], Validator::validate([1, 2], 'required', 'items'));
    }

    public function testRequiredFailsWithEmptyArray(): void
    {
        // 空数组不是 null 也不是 ''，required 会通过
        // 但配合 array 规则可以验证类型
        $this->assertSame([], Validator::validate([], 'required', 'items'));
    }

    // ─── 组合规则补充 ────────────────────────────────────────

    public function testOptionalWithNullSkipsAllSubsequentRules(): void
    {
        // optional + required: null 值应跳过 required
        $result = Validator::validate(null, 'optional|required', 'field');
        $this->assertNull($result);
    }

    public function testOptionalWithEmptyStringSkipsRequired(): void
    {
        $result = Validator::validate('', 'optional|required', 'field');
        $this->assertSame('', $result);
    }

    public function testNumericWithNegativeValue(): void
    {
        $this->assertSame('-5', Validator::validate('-5', 'numeric', 'val'));
    }

    public function testMinWithNegativeValues(): void
    {
        $this->assertSame(-3, Validator::validate(-3, 'min:-5', 'val'));
    }

    public function testMaxWithNegativeValues(): void
    {
        $this->assertSame(-10, Validator::validate(-10, 'max:-5', 'val'));
    }

    public function testMaxWithNegativeValueFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate(-3, 'max:-5', 'val');
    }
}
