<?php

use App\Tenancy\SubdomainResolver;
use Illuminate\Http\Request;

beforeEach(function () {
    config(['avantpos.tenancy.base_domain' => 'avantpos.ph']);
    $this->resolver = app(SubdomainResolver::class);
});

it('extracts a tenant subdomain from the host', function () {
    expect($this->resolver->fromHost('arnelmotoshop.avantpos.ph'))->toBe('arnelmotoshop');
});

it('ignores reserved, nested and foreign hosts', function (string $host) {
    expect($this->resolver->fromHost($host))->toBeNull();
})->with([
    'app.avantpos.ph',
    'api.avantpos.ph',
    'a.b.avantpos.ph',
    'avantpos.ph',
    'evil-avantpos.ph',
    'arnelmotoshop.avantpos.ph.evil.com',
    'localhost',
]);

it('reads the hint from the Origin header', function () {
    $request = Request::create('/api/v1/auth/me', 'GET', server: ['HTTP_ORIGIN' => 'https://arnelmotoshop.avantpos.ph']);

    expect($this->resolver->fromRequest($request))->toBe('arnelmotoshop');
});

it('validates subdomain format', function (string $value, bool $valid) {
    expect($this->resolver->isValidFormat($value))->toBe($valid);
})->with([
    ['arnelmotoshop', true],
    ['aling-nena-store', true],
    ['ab', false],
    ['-store', false],
    ['store-', false],
    ['Store', false],
    ['my_store', false],
]);
