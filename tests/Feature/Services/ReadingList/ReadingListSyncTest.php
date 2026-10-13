<?php

use App\Exceptions\ReadingListException;
use App\Models\Channel;
use App\Models\ReadingListCourse;
use App\Models\ReadingListItem;
use App\Models\Video;
use App\Services\ReadingList\ReadingListSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    configureReadingList();
    config()->set('calm-tube.refresh.retry_delay', 0);
});

function sync(): ReadingListSync
{
    return app(ReadingListSync::class);
}

/**
 * A reading list video already archived from a channel you follow, so a
 * refresh has nothing to ask YouTube.
 */
function archivedReadingVideo(string $videoId = CALM_VIDEO_ID): Video
{
    return Video::factory()->for(calmChannel())->create(['youtube_video_id' => $videoId]);
}

describe('picking out the videos', function (): void {
    it('keeps YouTube videos and ignores every other link', function (): void {
        archivedReadingVideo();
        fakeReadingList([
            readingListItem(1, 'https://example.com/an-article'),
            readingListItem(2, 'https://www.youtube.com/watch?v='.CALM_VIDEO_ID),
            readingListItem(3, 'https://www.youtube.com/@practicalengineering'),
            readingListItem(4, 'https://www.youtube.com/playlist?list=PL123'),
            readingListItem(5, ''),
        ]);

        sync()->refresh();

        expect(ReadingListItem::query()->pluck('reading_list_id')->all())->toBe([2]);
    });

    it('recognises each kind of video link', function (string $url, string $videoId): void {
        Video::factory()->for(calmChannel())->create(['youtube_video_id' => $videoId]);
        fakeReadingList([readingListItem(1, $url)]);

        sync()->refresh();

        expect(ReadingListItem::query()->sole()->youtube_video_id)->toBe($videoId);
    })->with([
        'a watch page' => ['https://www.youtube.com/watch?v=abcDEF12345&t=42s', 'abcDEF12345'],
        'a mobile watch page' => ['https://m.youtube.com/watch?v=abcDEF12345', 'abcDEF12345'],
        'a short link' => ['https://youtu.be/abcDEF12345?si=share', 'abcDEF12345'],
        'a live link' => ['https://www.youtube.com/live/abcDEF12345', 'abcDEF12345'],
    ]);

    it('leaves Shorts out', function (): void {
        fakeReadingList([
            readingListItem(1, 'https://www.youtube.com/shorts/abcDEF12345'),
        ]);

        sync()->refresh();

        expect(ReadingListItem::query()->count())->toBe(0);
    });

    it('keeps the title, category and progress from the reading list', function (): void {
        archivedReadingVideo();
        fakeReadingList([
            readingListItem(7, 'https://youtu.be/'.CALM_VIDEO_ID, [
                'title' => 'Watch the bridge one',
                'category' => 'Engineering',
                'percent_complete' => 40,
            ]),
        ]);

        sync()->refresh();

        expect(ReadingListItem::query()->sole())
            ->title->toBe('Watch the bridge one')
            ->category->toBe('Engineering')
            ->percent_complete->toBe(40);
    });
});

describe('the copy kept here', function (): void {
    it('is used when Personal Assistant is down', function (): void {
        ReadingListItem::factory()->create(['title' => 'Kept from last time']);
        fakeReadingListDown();

        expect(fn () => sync()->refresh())->toThrow(ReadingListException::class);

        expect(ReadingListItem::query()->sole()->title)->toBe('Kept from last time');
    });

    it('is kept when Personal Assistant refuses the request', function (): void {
        ReadingListItem::factory()->create();
        Http::fake([readingListEndpoint() => Http::response(['message' => 'Unauthorized'], 401)]);

        expect(fn () => sync()->refresh())->toThrow(ReadingListException::class);

        expect(ReadingListItem::query()->count())->toBeOne();
    });

    it('is replaced by what the list holds now', function (): void {
        archivedReadingVideo();
        ReadingListItem::factory()->create(['reading_list_id' => 1, 'title' => 'Since removed']);
        ReadingListItem::factory()->forVideo(CALM_VIDEO_ID)->create(['reading_list_id' => 2, 'title' => 'Old title']);
        fakeReadingList([
            readingListItem(2, 'https://youtu.be/'.CALM_VIDEO_ID, ['title' => 'New title']),
        ]);

        sync()->refresh();

        expect(ReadingListItem::query()->pluck('title', 'reading_list_id')->all())->toBe([2 => 'New title']);
    });

    it('remembers when it was last updated', function (): void {
        $this->travelTo('2026-10-13 09:00:00');
        fakeReadingList([]);

        expect(sync()->isStale())->toBeTrue();

        sync()->refresh();

        expect(sync()->lastRefreshedAt()?->toDateTimeString())->toBe('2026-10-13 09:00:00')
            ->and(sync()->isStale())->toBeFalse();

        $this->travel(25)->hours();

        expect(sync()->isStale())->toBeTrue();
    });

    it('is not marked updated by a refresh that failed', function (): void {
        fakeReadingListDown();

        rescue(fn () => sync()->refresh(), report: false);

        expect(sync()->lastRefreshedAt())->toBeNull();
    });
});

describe('picking out the courses', function (): void {
    it('keeps items in the Course category, whatever their case and spacing', function (?string $category, bool $kept): void {
        fakeReadingList([
            readingListItem(1, 'https://example.com/course', ['category' => $category]),
        ]);

        sync()->refresh();

        expect(ReadingListCourse::query()->exists())->toBe($kept);
    })->with([
        'as written' => ['Course', true],
        'lower case' => ['course', true],
        'upper case' => ['COURSE', true],
        'with spaces around it' => ['  Course ', true],
        'plural' => ['Courses', false],
        'another category' => ['Learning', false],
        'no category' => [null, false],
    ]);

    it('keeps what the card needs from the reading list', function (): void {
        fakeReadingList([
            readingListItem(1, 'https://example.com/an-article'),
            readingListItem(7, 'https://example.com/laravel-course', [
                'title' => 'Laravel from scratch',
                'category' => 'Course',
                'notes' => 'Skip the first module',
                'important' => 8,
                'urgent' => 3,
                'estimated_duration' => 390,
                'percent_complete' => 25,
            ]),
        ]);

        sync()->refresh();

        expect(ReadingListCourse::query()->sole())
            ->reading_list_id->toBe(7)
            ->title->toBe('Laravel from scratch')
            ->url->toBe('https://example.com/laravel-course')
            ->notes->toBe('Skip the first module')
            ->priority->toBe(24)
            ->estimated_minutes->toBe(390)
            ->percent_complete->toBe(25)
            ->position->toBe(1);
    });

    it('drops a course that has left the list', function (): void {
        ReadingListCourse::factory()->create(['reading_list_id' => 3]);
        fakeReadingList([]);

        sync()->refresh();

        expect(ReadingListCourse::query()->count())->toBe(0);
    });

    it('leaves the courses as they were when Personal Assistant is down', function (): void {
        ReadingListCourse::factory()->create(['title' => 'Kept']);
        fakeReadingListDown();

        expect(fn () => sync()->refresh())->toThrow(ReadingListException::class);

        expect(ReadingListCourse::query()->sole()->title)->toBe('Kept');
    });
});

describe('videos from channels you do not follow', function (): void {
    it('archives the video so it can play here', function (): void {
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.READING_VIDEO_ID)]);
        fakeReadingVideoArchive();

        sync()->refresh();

        expect(Video::query()->firstWhere('youtube_video_id', READING_VIDEO_ID))
            ->title->toBe('How Bridges Hold Up')
            ->duration_seconds->toBe(1122)
            ->thumbnail_path->not->toBeNull();
    });

    it('never adds the channel to the feed', function (): void {
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.READING_VIDEO_ID)]);
        fakeReadingVideoArchive();

        sync()->refresh();

        $channel = Channel::query()->sole();

        expect($channel)
            ->youtube_channel_id->toBe(READING_CHANNEL_ID)
            ->title->toBe('Quiet Rooms')
            ->is_followed->toBeFalse()
            ->is_enabled->toBeFalse()
            ->and(Channel::query()->followed()->exists())->toBeFalse()
            ->and(Video::query()->inFeed()->exists())->toBeFalse();
    });

    it('keeps the archived title when the video is seen again', function (): void {
        $channel = Channel::factory()->unfollowed()->create(['youtube_channel_id' => READING_CHANNEL_ID]);
        Video::factory()->for($channel)->create([
            'youtube_video_id' => READING_VIDEO_ID,
            'title' => 'The title I first saw',
        ]);
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.READING_VIDEO_ID)]);

        sync()->refresh();

        expect(Video::query()->sole()->title)->toBe('The title I first saw');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com'));
    });

    it('files the video under a channel you already follow', function (): void {
        $followed = Channel::factory()->create(['youtube_channel_id' => READING_CHANNEL_ID]);
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.READING_VIDEO_ID)]);
        fakeReadingVideoArchive();

        sync()->refresh();

        expect(Video::query()->sole()->channel_id)->toBe($followed->id)
            ->and(Channel::query()->count())->toBeOne()
            ->and($followed->fresh()?->is_followed)->toBeTrue();
    });

    it('keeps a video from a channel you follow out of the feed', function (): void {
        Channel::factory()->create(['youtube_channel_id' => READING_CHANNEL_ID]);
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.READING_VIDEO_ID)]);
        fakeReadingVideoArchive();

        sync()->refresh();

        expect(Video::query()->sole()->on_reading_list_at)->not->toBeNull()
            ->and(Video::query()->inFeed()->exists())->toBeFalse();
    });

    it('waits for an API key before archiving', function (): void {
        config()->set('calm-tube.api_key');
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.READING_VIDEO_ID)]);

        sync()->refresh();

        expect(ReadingListItem::query()->count())->toBeOne()
            ->and(Video::query()->count())->toBe(0);
    });
});

describe('a video the feed already had', function (): void {
    it('leaves the feed once it is on the reading list', function (): void {
        archivedReadingVideo();
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.CALM_VIDEO_ID)]);

        sync()->refresh();

        expect(Video::query()->inFeed()->exists())->toBeFalse();
    });

    it('leaves the feed even when it is already finished', function (): void {
        archivedReadingVideo();
        fakeReadingList([readingListItem(1, 'https://www.youtube.com/watch?v='.CALM_VIDEO_ID, ['percent_complete' => 90])]);

        sync()->refresh();

        expect(Video::query()->inFeed()->exists())->toBeFalse();
    });

    it('stays out of the feed once it is off the reading list', function (): void {
        Video::factory()->for(calmChannel())->onReadingList()->create(['youtube_video_id' => CALM_VIDEO_ID]);
        fakeReadingList([]);

        sync()->refresh();

        expect(Video::query()->inFeed()->exists())->toBeFalse();
    });
});

describe('marking a video watched', function (): void {
    it('sends 90 percent to the right item', function (): void {
        fakeReadingListProgress();
        $item = ReadingListItem::factory()->create(['reading_list_id' => 42]);

        sync()->markWatched($item);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === readingListEndpoint('/42')
            && $request['percent_complete'] === 90);

        expect($item->fresh()?->percent_complete)->toBe(90)
            ->and(ReadingListItem::query()->pending()->exists())->toBeFalse();
    });

    it('changes nothing here when Personal Assistant does not take it', function (): void {
        fakeReadingListProgress(500);
        $item = ReadingListItem::factory()->create(['percent_complete' => 30]);

        expect(fn () => sync()->markWatched($item))->toThrow(ReadingListException::class);

        expect($item->fresh()?->percent_complete)->toBe(30);
    });
});

it('asks nothing of Personal Assistant until it is set up', function (): void {
    config()->set('services.reading_list.token');

    expect(sync()->isConfigured())->toBeFalse()
        ->and(fn () => sync()->refresh())->toThrow(ReadingListException::class);

    Http::assertNothingSent();
});
