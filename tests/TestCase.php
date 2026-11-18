<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function withWebCaptcha(string $answer = '123456'): static
    {
        config()->set('captcha.disable', false);
        $key = \Illuminate\Support\Facades\Hash::make($answer);
        \Illuminate\Support\Facades\Cache::put('captcha_'.md5($key), $answer, 60);

        return $this->withSession([
            'captcha' => ['key' => $key, 'sensitive' => false, 'encrypt' => false],
        ]);
    }
}
