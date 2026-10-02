<?php

namespace Tests\Feature;

use App\PublicSites\HostnameNormalizer;
use App\Rules\AllowedWebsitePageSlug;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteDomainArchitectureTest extends TestCase
{
    #[DataProvider('validHostnames')]
    public function test_hostname_normalization_is_canonical(string $input, string $expected): void
    {
        $this->assertSame($expected, app(HostnameNormalizer::class)->normalize($input));
    }

    public static function validHostnames(): array
    {
        return [
            [' Shop.Customer.COM. ', 'shop.customer.com'],
            ['www.customer.com', 'www.customer.com'],
        ];
    }

    #[DataProvider('invalidHostnames')]
    public function test_hostname_normalization_rejects_unsafe_values(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(HostnameNormalizer::class)->normalize($input);
    }

    public static function invalidHostnames(): array
    {
        return [
            ['https://shop.customer.com/path'],
            ['shop.customer.com:443'],
            ['*.customer.com'],
            ['127.0.0.1'],
            ['localhost'],
            ['metadata.internal'],
            ['-bad.customer.com'],
        ];
    }

    public function test_platform_and_central_domains_cannot_be_claimed(): void
    {
        config()->set('website_domains.central_hosts', ['example.com', 'noovaerp.example.com']);
        config()->set('website_domains.cname_target', 'sites.noovaerp.example.com');

        foreach ([
            'example.com',
            'shop.example.com',
            'noovaerp.example.com',
            'admin.noovaerp.example.com',
            'sites.noovaerp.example.com',
        ] as $hostname) {
            try {
                app(HostnameNormalizer::class)->normalize($hostname);
                $this->fail("Expected {$hostname} to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('cannot be claimed', $exception->getMessage());
            }
        }
    }

    public function test_unicode_form_of_the_central_domain_is_rejected_when_intl_is_available(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('The PHP intl extension is not installed.');
        }

        config()->set('website_domains.central_hosts', ['example.com']);
        $this->expectException(InvalidArgumentException::class);
        app(HostnameNormalizer::class)->normalize('acenõva.com');
    }

    public function test_custom_domain_routes_are_registered_with_permissions(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $verify = $routes->first(fn ($route) => $route->uri() === 'api/v1/website-builder/websites/{website}/domains/{hostname}/verify');
        $this->assertNotNull($verify);
        $this->assertStringContainsString('website.domains.verify', implode('|', $verify->gatherMiddleware()));
    }

    public function test_shared_uuid_route_is_registered(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri();
        $this->assertContains('site/{websiteUuid}', $uris);
        $this->assertContains('site/{websiteUuid}/{pageSlug}', $uris);
    }

    public function test_reserved_public_page_prefixes_are_rejected(): void
    {
        $validator = validator(['slug' => 'api/private'], ['slug' => [new AllowedWebsitePageSlug]]);
        $this->assertTrue($validator->fails());
        $this->assertFalse(validator(['slug' => 'products/sale'], ['slug' => [new AllowedWebsitePageSlug]])->fails());
    }
}
