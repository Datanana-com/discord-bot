<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Download;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use RuntimeException;
use Tests\Fixtures\FakeCdn;

use function React\Async\await;

final class DownloadTest extends TestCase
{
    private FakeCdn $cdn;

    private string $path;

    protected function setUp(): void
    {
        $this->cdn = new FakeCdn();
        $this->cdn->install();
        $this->path = sys_get_temp_dir() . '/download-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->cdn->close();
        @unlink($this->path);
    }

    public function testSavesTheFileFromDiscordsAttachmentHost(): void
    {
        $this->cdn->response = new Response(200, [], 'the audio');

        await(Download::toFile('https://cdn.discordapp.com/attachments/1/2/voice-message.ogg?ex=abc&hm=def', $this->path));

        $this->assertSame('the audio', file_get_contents($this->path));
        $this->assertSame([['host' => 'cdn.discordapp.com', 'target' => '/attachments/1/2/voice-message.ogg?ex=abc&hm=def']], $this->cdn->requests);
    }

    public function testAlsoDownloadsFromDiscordsMediaProxy(): void
    {
        await(Download::toFile('https://MEDIA.discordapp.net/attachments/1/2/voice-message.ogg', $this->path));

        $this->assertSame('media.discordapp.net', $this->cdn->requests[0]['host']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignUrls(): array
    {
        return [
            'another host' => ['https://example.com/voice.ogg'],
            'a lookalike host' => ['https://cdn.discordapp.com.example.com/voice.ogg'],
            'a host after the credentials' => ['https://cdn.discordapp.com@example.com/voice.ogg'],
            'a subdomain' => ['https://evil.cdn.discordapp.com/voice.ogg'],
            'plain http' => ['http://cdn.discordapp.com/voice.ogg'],
            'another scheme' => ['ftp://cdn.discordapp.com/voice.ogg'],
            'no host' => ['/attachments/voice.ogg'],
            'nothing' => [''],
        ];
    }

    #[DataProvider('foreignUrls')]
    public function testRefusesAnythingButHttpsOnDiscordsAttachmentHosts(string $url): void
    {
        try {
            await(Download::toFile($url, $this->path));
            $this->fail('The download should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Not downloading from ', $e->getMessage());
        }

        $this->assertSame([], $this->cdn->requests, 'Nothing was requested.');
        $this->assertFileDoesNotExist($this->path);
    }

    public function testDoesNotPutTheUrlsSignatureInTheError(): void
    {
        try {
            await(Download::toFile('https://example.com/voice.ogg?ex=SECRET&hm=SECRET', $this->path));
            $this->fail('The download should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertSame("Not downloading from example.com: it isn't one of Discord's attachment hosts, or it isn't https.", $e->getMessage());
        }
    }

    public function testFailsWhenDiscordAnswersWithAnError(): void
    {
        $this->cdn->response = new Response(404, [], 'Not found');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code 404');

        try {
            await(Download::toFile('https://cdn.discordapp.com/voice.ogg', $this->path));
        } finally {
            $this->assertFileDoesNotExist($this->path);
        }
    }

    public function testDoesNotFollowRedirects(): void
    {
        $this->cdn->response = new Response(302, ['Location' => 'https://example.com/voice.ogg'], '');

        try {
            await(Download::toFile('https://cdn.discordapp.com/voice.ogg', $this->path));
            $this->fail('A redirect should not be followed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Discord answered the download with HTTP status 302.', $e->getMessage());
        }

        $this->assertCount(1, $this->cdn->requests, 'Only the first URL was requested.');
        $this->assertFileDoesNotExist($this->path);
    }

    public function testRefusesAFileTooBigToKeepInMemory(): void
    {
        $this->cdn->response = new Response(200, [], str_repeat('a', 10 * 1024 * 1024 + 1));

        try {
            await(Download::toFile('https://cdn.discordapp.com/voice.ogg', $this->path));
            $this->fail('The file is too big.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceeds maximum', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->path);
    }

    public function testFailsWhenTheFileCannotBeSaved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The download could not be saved.');

        await(Download::toFile('https://cdn.discordapp.com/voice.ogg', '/this/folder/does/not/exist/voice.ogg'));
    }
}
