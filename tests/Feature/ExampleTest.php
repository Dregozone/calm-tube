<?php

test('the home route sends you to the feed', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('feed'));
});
