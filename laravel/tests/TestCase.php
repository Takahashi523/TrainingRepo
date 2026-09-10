<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // php artisan test 経由で起動すると Env::$repository が local でキャッシュされる。
        // Docker が APP_ENV=local を OS 環境変数に設定しているため force="true" でも上書きできない。
        // テスト用アプリを生成する前にリポジトリをリセットし .env.testing を確実に読み込む。
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV']    = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        $reflection = new \ReflectionProperty(Env::class, 'repository');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);

        return parent::createApplication();
    }
}
