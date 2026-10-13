<?php

use App\Models\ReadingListCourse;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
    configureReadingList();
    config()->set('calm-tube.refresh.retry_delay', 0);
});

it('is in the navigation', function (): void {
    $this->get(route('feed'))->assertSee(route('courses.index'));
});

it('says where the courses come from', function (): void {
    $this->get(route('courses.index'))
        ->assertOk()
        ->assertSee('Courses')
        ->assertSee('The courses on your reading list');
});

it('shows each course with its duration, progress and notes', function (): void {
    ReadingListCourse::factory()->create([
        'title' => 'Laravel from scratch',
        'estimated_minutes' => 390,
        'percent_complete' => 40,
        'notes' => 'Skip the first module',
    ]);

    $this->get(route('courses.index'))
        ->assertSee('Laravel from scratch')
        ->assertSee('6 h 30 m')
        ->assertSee('40%')
        ->assertSee('Skip the first module');
});

it('opens a course in a new tab', function (): void {
    ReadingListCourse::factory()->create(['url' => 'https://example.com/laravel-course']);

    $this->get(route('courses.index'))
        ->assertSee('Open course')
        ->assertSee('href="https://example.com/laravel-course"', escape: false)
        ->assertSee('target="_blank"', escape: false)
        ->assertSee('rel="noopener"', escape: false);
});

it('leaves out what it does not know', function (): void {
    ReadingListCourse::factory()->create(['url' => null, 'estimated_minutes' => null]);

    $this->get(route('courses.index'))
        ->assertDontSee('Open course')
        ->assertDontSee(' h ');
});

it('puts courses in progress first, then the most important and urgent', function (): void {
    ReadingListCourse::factory()->create(['title' => 'Low priority', 'important' => 2, 'urgent' => 2, 'position' => 0]);
    ReadingListCourse::factory()->create(['title' => 'High priority', 'important' => 9, 'urgent' => 8, 'position' => 1]);
    ReadingListCourse::factory()->create(['title' => 'Started', 'important' => 1, 'urgent' => 1, 'percent_complete' => 10, 'position' => 2]);
    ReadingListCourse::factory()->create(['title' => 'Also high', 'important' => 8, 'urgent' => 9, 'position' => 3]);

    $this->get(route('courses.index'))
        ->assertSeeInOrder(['Started', 'High priority', 'Also high', 'Low priority']);
});

it('collapses finished courses into a group of their own', function (int $percent): void {
    ReadingListCourse::factory()->create(['title' => 'Still going', 'important' => 1, 'urgent' => 1]);
    ReadingListCourse::factory()->create(['title' => 'All done', 'important' => 9, 'urgent' => 9, 'percent_complete' => $percent]);

    $this->get(route('courses.index'))
        ->assertSeeInOrder(['Still going', 'data-finished-courses', 'Finished', 'All done'], escape: false)
        ->assertDontSee('<details open', escape: false);
})->with([90, 95, 100]);

it('has no finished group when nothing is finished', function (): void {
    ReadingListCourse::factory()->create(['percent_complete' => 89]);

    $this->get(route('courses.index'))->assertDontSee('data-finished-courses', escape: false);
});

it('says when there are no courses', function (): void {
    $this->get(route('courses.index'))->assertSee('No courses in your reading list.');
});

describe('refreshing', function (): void {
    it('updates the courses when asked', function (): void {
        ReadingListCourse::factory()->create(['reading_list_id' => 1, 'title' => 'Old title']);
        fakeReadingList([
            readingListItem(1, 'https://example.com/course', ['title' => 'New title', 'category' => 'Course']),
        ]);

        Livewire::test('pages::courses')
            ->call('refresh')
            ->assertSee('New title')
            ->assertDontSee('Old title')
            ->assertSee('Reading list refreshed.');
    });

    it('shows the courses it has when Personal Assistant is down', function (): void {
        $this->travelTo('2026-10-13 09:00:00');
        Http::fake([
            readingListEndpoint() => Http::sequence()
                ->push([readingListItem(1, 'https://example.com/course', ['title' => 'Kept from last time', 'category' => 'Course'])])
                ->pushFailedConnection()
                ->pushFailedConnection(),
        ]);
        Livewire::test('pages::courses')->call('refresh');

        $this->travel(3)->hours();

        Livewire::test('pages::courses')
            ->call('refresh')
            ->assertSee('Kept from last time')
            ->assertSeeInOrder(['Last updated 3 hours ago', "(couldn't reach Personal Assistant)"]);
    });

    it('explains when Personal Assistant is down and nothing was ever fetched', function (): void {
        fakeReadingListDown();

        Livewire::test('pages::courses')
            ->call('refresh')
            ->assertSee("Couldn't reach Personal Assistant")
            ->assertSee('No courses in your reading list.');
    });

    it('refreshes by itself when the list is more than a day old', function (): void {
        fakeReadingList([]);

        Livewire::test('pages::courses')->call('autoRefresh');

        Http::assertSentCount(1);
    });

    it('shares its refresh with the videos page', function (): void {
        fakeReadingList([]);
        Livewire::test('pages::videos')->call('refresh');

        Livewire::test('pages::courses')->call('autoRefresh');

        Http::assertSentCount(1);
    });
});

it('explains what is missing before Personal Assistant is set up', function (): void {
    config()->set('services.reading_list.url');

    Livewire::test('pages::courses')
        ->assertSee('Personal Assistant is not set up')
        ->assertDontSee('Refresh')
        ->call('autoRefresh');

    Http::assertNothingSent();
});
