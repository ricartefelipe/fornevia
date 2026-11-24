<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\TestCase as DuskTestCase;
use Tests\Support\RefreshFornevia;

/**
 * Base dos testes de navegador (Chrome real, JavaScript, Livewire e Alpine).
 *
 * A aplicação roda num servidor separado (APP_URL) sobre o banco
 * fornevia_browser, o mesmo que o teste prepara: o banco é limpo por
 * TRUNCATE antes de cada teste (RefreshFornevia) e os dados são gravados
 * com commit, para o servidor enxergá-los. O navegador é o ChromeDriver ou
 * o Selenium em DUSK_DRIVER_URL.
 */
abstract class BrowserTestCase extends DuskTestCase
{
    use RefreshFornevia;

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments([
            '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage', '--window-size=1400,1000', '--lang=pt-BR',
        ]);

        return RemoteWebDriver::create(
            (string) (getenv('DUSK_DRIVER_URL') ?: 'http://localhost:9515'),
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
            connection_timeout_in_ms: 10_000,
            request_timeout_in_ms: 60_000,
        );
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
