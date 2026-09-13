<?php

test('the root url redirects into the admin panel', function () {
    $response = $this->get('/');

    $response->assertRedirect('/admin');
});
