<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PlaylistManager;

final class PlaylistManagerTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(PlaylistManager::class));
    }

    public function testInstanceCreation(): void
    {
        $manager = new PlaylistManager();
        $this->assertInstanceOf(PlaylistManager::class, $manager);
        
        $urls = $manager->sourceUrls();
        $this->assertIsArray($urls);
        $this->assertNotEmpty($urls);
        $this->assertContains(DEFAULT_PLAYLIST_URL, $urls);
    }

    public function testDefaultUserAgentIsDefined(): void
    {
        $this->assertTrue(defined('DEFAULT_USER_AGENT'));
        $this->assertStringContainsString('Toffee', DEFAULT_USER_AGENT);
    }
}
