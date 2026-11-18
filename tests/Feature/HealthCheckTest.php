<?php

it('exposes an unauthenticated deployment health endpoint', function () {
    $this->get('/up')->assertOk();
});