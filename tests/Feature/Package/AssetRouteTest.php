<?php

/**
 * The built JS/CSS are served from a route rather than a publish step, so an
 * adopter can never run a stale copy after upgrading the package. That makes the
 * caching headers load-bearing: the response is immutable and far-future cached,
 * so a broken ETag would pin a stale asset in every editor's browser.
 */
it('serves the built javascript with the right content type', function () {
    $response = $this->get(twillAiUrl('asset/twill-ai.iife.js'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

    // Asserted per directive: Symfony normalises Cache-Control into
    // alphabetical order, so pinning the literal string would be testing
    // Symfony's formatting rather than the caching policy.
    $directives = array_map('trim', explode(',', $response->headers->get('Cache-Control')));

    expect($directives)->toContain('public', 'immutable', 'max-age=31536000');
});

it('serves the built stylesheet with the right content type', function () {
    $this->get(twillAiUrl('asset/twill-ai.css'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=utf-8');
});

it('revalidates with an ETag', function () {
    $etag = $this->get(twillAiUrl('asset/twill-ai.iife.js'))
        ->assertOk()
        ->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders(['If-None-Match' => $etag])
        ->get(twillAiUrl('asset/twill-ai.iife.js'))
        ->assertStatus(304);
});

it('refuses any file outside the two it ships', function () {
    // The route pattern constrains this, so a traversal attempt never reaches
    // the controller's own allow-list.
    $this->get(twillAiUrl('asset/../../.env'))->assertNotFound();
    $this->get(twillAiUrl('asset/twill-ai.iife.js.map'))->assertNotFound();
});

it('serves assets without an authenticated admin', function () {
    // Deliberate: the assets are public static files, and gating them behind the
    // admin session would break caching for no benefit. Nothing sensitive is in
    // them — everything private goes through the authenticated JSON endpoints.
    $this->get(twillAiUrl('asset/twill-ai.css'))->assertOk();
});
