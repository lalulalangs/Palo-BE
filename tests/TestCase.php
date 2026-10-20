<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Variabel environment yang di-export shell (mis. dari .env) bisa
     * menimpa konfigurasi phpunit.xml karena Dotenv bersifat immutable.
     * Bersihkan $_SERVER agar <env force="true"> benar-benar dipakai dan
     * test tidak pernah menyentuh database dev.
     */
    public function createApplication()
    {
        $keys = [
            'APP_ENV',
            'APP_MAINTENANCE_DRIVER',
            'BCRYPT_ROUNDS',
            'BROADCAST_CONNECTION',
            'CACHE_STORE',
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_URL',
            'MAIL_MAILER',
            'QUEUE_CONNECTION',
            'SESSION_DRIVER',
            'PULSE_ENABLED',
            'TELESCOPE_ENABLED',
            'NIGHTWATCH_ENABLED',
        ];

        foreach ($keys as $key) {
            unset($_SERVER[$key]);
        }

        return parent::createApplication();
    }
}
