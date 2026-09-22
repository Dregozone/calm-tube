<?php

use App\Support\DescriptionRenderer;

beforeEach(function (): void {
    $this->renderer = new DescriptionRenderer;
});

it('turns a bare url into a link that cannot reach back', function (): void {
    $html = (string) $this->renderer->toHtml('Notes at https://example.com/paper.pdf for reference.');

    expect($html)->toContain('href="https://example.com/paper.pdf"')
        ->and($html)->toContain('rel="noopener noreferrer"')
        ->and($html)->toContain('target="_blank"');
});

it('escapes markup before it adds any of its own', function (string $description, string $forbidden): void {
    expect((string) $this->renderer->toHtml($description))->not->toContain($forbidden);
})->with([
    'a script tag' => ['Watch out <script>alert("xss")</script> here.', '<script>'],
    'an image with a handler' => ['<img src=x onerror=alert(1)>', '<img'],
    'an anchor of its own' => ['<a href="https://evil.test">click</a>', 'href="https://evil.test"'],
]);

it('leaves the surrounding text alone', function (): void {
    $html = (string) $this->renderer->toHtml('Read https://example.com/a then stop.');

    expect($html)->toStartWith('Read ')->toEndWith(' then stop.');
});

it('does not swallow the punctuation after a link', function (): void {
    $html = (string) $this->renderer->toHtml('See https://example.com/paper.pdf, page 4.');

    expect($html)->toContain('href="https://example.com/paper.pdf"')
        ->and($html)->toContain(', page 4.');
});

it('returns nothing for a description that is not there', function (?string $description): void {
    expect((string) $this->renderer->toHtml($description))->toBe('');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ["  \n "],
]);
