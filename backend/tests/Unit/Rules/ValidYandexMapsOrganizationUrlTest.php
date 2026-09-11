<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidYandexMapsOrganizationUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidYandexMapsOrganizationUrlTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function urls(): array
    {
        return [
            'canonical org page' => ['https://yandex.ru/maps/org/kafe-romashka/1234567890/', true],
            'org page without trailing slash' => ['https://yandex.ru/maps/org/kafe-romashka/1234567890', true],
            'org page over http' => ['http://yandex.ru/maps/org/kafe-romashka/1234567890/', true],
            'city map with oid query param' => ['https://yandex.ru/maps/213/moscow/?oid=1234567890&ll=1,1', true],
            'short link' => ['https://yandex.ru/maps/-/CDczAbCd', true],
            'www subdomain' => ['https://www.yandex.ru/maps/org/kafe-romashka/1234567890/', true],
            'regional yandex domain' => ['https://yandex.com/maps/org/kafe-romashka/1234567890/', true],

            'empty string' => ['', false],
            'not a url' => ['not a url at all', false],
            'non yandex domain' => ['https://maps.google.com/maps/org/kafe-romashka/1234567890/', false],
            'yandex but not maps' => ['https://yandex.ru/search/?text=kafe', false],
            'yandex maps directions, not an org' => ['https://yandex.ru/maps/213/moscow/?rtext=1,1~2,2&mode=routes', false],
            'yandex maps metro scheme, not an org' => ['https://yandex.ru/maps/213/moscow/metro/', false],
            'org path missing numeric id' => ['https://yandex.ru/maps/org/kafe-romashka/', false],
            'lookalike host' => ['https://yandex.ru.evil.com/maps/org/kafe-romashka/1234567890/', false],
        ];
    }

    #[DataProvider('urls')]
    public function test_it_validates_yandex_maps_organization_urls(string $url, bool $expectedValid): void
    {
        $rule = new ValidYandexMapsOrganizationUrl();
        $failed = false;

        $rule->validate('url', $url, function () use (&$failed) {
            $failed = true;
        });

        $this->assertSame($expectedValid, ! $failed, "Unexpected validation result for: {$url}");
    }
}
