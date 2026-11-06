<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    public function testHomepageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Conventio');
    }

    public function testHomepageHasLoginAndRegisterLinks(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $links = $crawler->filter('a')->extract(['href']);
        self::assertContains('/login', $links);
        self::assertContains('/register', $links);
    }

    public function testHomepageHasStimulusDemoController(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller~="demo"]'));
        self::assertCount(1, $crawler->filter('[data-demo-target="output"]'));
        self::assertCount(1, $crawler->filter('[data-demo-target="log"]'));
        self::assertGreaterThanOrEqual(4, $crawler->filter('[data-action^="click->demo#"]')->count());
    }
}
