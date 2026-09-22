<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Turns an archived description into safe HTML with clickable links.
 *
 * Escaping happens first and markup is added afterwards, so nothing a
 * description contains can become markup of its own.
 */
class DescriptionRenderer
{
    private const string URL_PATTERN = '~\bhttps?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~i';

    public function toHtml(?string $description): HtmlString
    {
        if ($description === null || trim($description) === '') {
            return new HtmlString('');
        }

        $escaped = e($description);

        $linked = preg_replace_callback(
            self::URL_PATTERN,
            fn (array $match): string => sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">%s</a>',
                $match[0],
                $match[0],
            ),
            $escaped,
        );

        return new HtmlString($linked ?? $escaped);
    }
}
