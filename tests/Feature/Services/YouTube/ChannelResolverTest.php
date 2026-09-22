<?php

use App\Enums\ChannelInputType;
use App\Exceptions\ChannelNotFoundException;
use App\Exceptions\UnresolvableChannelException;
use App\Exceptions\UnsupportedChannelUrlException;
use App\Services\YouTube\ChannelResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->resolver = app(ChannelResolver::class);
});

describe('parse', function (): void {
    it('identifies what was pasted without making a request', function (string $input, ChannelInputType $type, string $value): void {
        $parsed = $this->resolver->parse($input);

        expect($parsed->type)->toBe($type)
            ->and($parsed->value)->toBe($value);

        Http::assertNothingSent();
    })->with(fn (): array => [
        'a bare channel id' => [CALM_CHANNEL_ID, ChannelInputType::ChannelId, CALM_CHANNEL_ID],
        'a channel url' => ['https://www.youtube.com/channel/'.CALM_CHANNEL_ID, ChannelInputType::ChannelId, CALM_CHANNEL_ID],
        'a channel url with a tab' => ['https://www.youtube.com/channel/'.CALM_CHANNEL_ID.'/videos', ChannelInputType::ChannelId, CALM_CHANNEL_ID],
        'a handle url' => ['https://www.youtube.com/@practicalengineering', ChannelInputType::Handle, '@practicalengineering'],
        'a handle url with a tab' => ['https://www.youtube.com/@practicalengineering/videos', ChannelInputType::Handle, '@practicalengineering'],
        'a bare handle' => ['@practicalengineering', ChannelInputType::Handle, '@practicalengineering'],
        'a bare word' => ['practicalengineering', ChannelInputType::Handle, '@practicalengineering'],
        'a legacy user url' => ['https://www.youtube.com/user/PracticalEng', ChannelInputType::Username, 'PracticalEng'],
        'a watch url' => ['https://www.youtube.com/watch?v='.CALM_VIDEO_ID, ChannelInputType::VideoId, CALM_VIDEO_ID],
        'a watch url with extra parameters' => ['https://www.youtube.com/watch?v='.CALM_VIDEO_ID.'&t=42s', ChannelInputType::VideoId, CALM_VIDEO_ID],
        'a short share link' => ['https://youtu.be/'.CALM_VIDEO_ID, ChannelInputType::VideoId, CALM_VIDEO_ID],
        'a shorts url' => ['https://www.youtube.com/shorts/'.CALM_VIDEO_ID, ChannelInputType::VideoId, CALM_VIDEO_ID],
        'a legacy custom url' => ['https://www.youtube.com/c/PracticalEngineering', ChannelInputType::LegacyCustom, 'PracticalEngineering'],
    ]);

    it('ignores surrounding whitespace', function (): void {
        expect($this->resolver->parse('  @practicalengineering  ')->value)->toBe('@practicalengineering');
    });

    it('rejects input that is not a YouTube channel', function (string $input): void {
        $this->resolver->parse($input);
    })->with([
        'empty' => [''],
        'whitespace' => ['   '],
        'a sentence' => ['please add practical engineering for me'],
        'another site' => ['https://vimeo.com/channels/staffpicks'],
        'a youtube url with no channel or video' => ['https://www.youtube.com/feed/subscriptions'],
        'a malformed channel id' => ['UCnotlongenough'],
    ])->throws(UnresolvableChannelException::class);
});

describe('resolve', function (): void {
    it('returns the canonical channel for a handle', function (): void {
        fakeChannelsList();

        $channel = $this->resolver->resolve('@practicalengineering');

        expect($channel->channelId)->toBe(CALM_CHANNEL_ID)
            ->and($channel->title)->toBe(CALM_CHANNEL_TITLE);
    });

    it('resolves a video url to the channel that owns it', function (): void {
        fakeVideosList('videos.list-channel-lookup.json');
        fakeChannelsList();

        $channel = $this->resolver->resolve('https://youtu.be/'.CALM_VIDEO_ID);

        expect($channel->channelId)->toBe(CALM_CHANNEL_ID);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/youtube/v3/videos'));
    });

    it('throws when the channel does not exist', function (): void {
        fakeChannelsList('empty-items.json');

        $this->resolver->resolve('@nobodyhere');
    })->throws(ChannelNotFoundException::class);

    it('throws a dedicated exception for a legacy custom url, without calling the API', function (): void {
        try {
            $this->resolver->resolve('https://www.youtube.com/c/PracticalEngineering');
        } finally {
            Http::assertNothingSent();
        }
    })->throws(UnsupportedChannelUrlException::class);
});

describe('resolve without an API key', function (): void {
    beforeEach(function (): void {
        config()->set('calm-tube.api_key', null);
    });

    it('resolves a channel id from the RSS feed', function (): void {
        fakeFeed('feed-single-entry.xml');

        $channel = $this->resolver->resolve(CALM_CHANNEL_ID);

        expect($channel->channelId)->toBe(CALM_CHANNEL_ID)
            ->and($channel->title)->toBe(CALM_CHANNEL_TITLE)
            ->and($channel->avatarUrl)->toBeNull();
    });

    it('throws for any input that needs the API', function (string $input): void {
        $this->resolver->resolve($input);
    })->with([
        'a handle' => ['@practicalengineering'],
        'a legacy username' => ['https://www.youtube.com/user/PracticalEng'],
        'a video url' => ['https://youtu.be/'.CALM_VIDEO_ID],
    ])->throws(UnresolvableChannelException::class);
});
