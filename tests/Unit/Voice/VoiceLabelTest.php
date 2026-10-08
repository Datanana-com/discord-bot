<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\VoiceLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VoiceLabelTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function voices(): array
    {
        return [
            'a Piper voice' => ['en_US-lessac-medium', 'Lessac (en_US, medium)'],
            'the same voice in another quality is told apart' => ['en_US-lessac-high', 'Lessac (en_US, high)'],
            'another region' => ['en_GB-cori-high', 'Cori (en_GB, high)'],
            'a language of three letters' => ['ckb_IQ-sorani-medium', 'Sorani (ckb_IQ, medium)'],
            'underscores are spaces' => ['en_US-hfc_female-medium', 'Hfc female (en_US, medium)'],
            'the quality with a space too' => ['en_US-amy-x_low', 'Amy (en_US, x low)'],
            'digits in the name' => ['vi_VN-vais1000-medium', 'Vais1000 (vi_VN, medium)'],
            'a name that starts with an accent' => ['pt_PT-édson-medium', 'Édson (pt_PT, medium)'],
            'a Kokoro voice, female' => ['af_heart', 'Heart (American English, female)'],
            'a Kokoro voice, male' => ['bm_george', 'George (British English, male)'],
            'a Kokoro voice of another language' => ['zf_xiaobei', 'Xiaobei (Mandarin, female)'],
            'a Kokoro language it does not know' => ['xf_nobody', 'xf_nobody'],
            'a name that is neither' => ['voice', 'voice'],
            'a Piper name without its quality' => ['pt_BR-faber', 'pt_BR-faber'],
            'a Piper name in capitals' => ['en_US-Lessac-medium', 'en_US-Lessac-medium'],
            'a Kokoro name with something after it' => ['af_heart_2', 'af_heart_2'],
            'a Kokoro name with something before it' => ['myaf_heart', 'myaf_heart'],
            'a Kokoro name without a voice' => ['af_', 'af_'],
            'a name with something before it' => ['my_en_US-lessac-medium', 'my_en_US-lessac-medium'],
            'a name with something after it' => ['en_US-lessac-medium-2', 'en_US-lessac-medium-2'],
        ];
    }

    #[DataProvider('voices')]
    public function testCallsAVoiceWhatSomeoneWouldCallIt(string $voice, string $label): void
    {
        $this->assertSame($label, VoiceLabel::of($voice));
    }
}
