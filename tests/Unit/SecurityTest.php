<?php
namespace KdvLeads\Tests\Unit;

use KdvLeads\Http\AntiSpam;
use KdvLeads\Http\Validator;
use KdvLeads\Mail\MessageBuilder;
use KdvLeads\Security\Secrets;
use KdvLeads\Storage\ImageProcessor;
use PHPUnit\Framework\TestCase;

/** Regression tests for the security audit findings (see docs/AUDIT-SECURITE.md). */
final class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['kdv_test_options'] = [];
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    /* ---- P6: wrong types ---- */
    public function testNonScalarFieldsBecomeEmptyInsteadOfArray(): void
    {
        $out = Validator::normalise(['nom_complet' => ['x'], 'telephone' => ['a' => 'b'], 'email' => null, 'marque' => 'BMW']);
        $this->assertSame('', $out['nom_complet']);
        $this->assertSame('', $out['telephone']);
        $this->assertSame('', $out['email']);
        $this->assertSame('BMW', $out['marque']);

        [$errors] = Validator::estimation(['nom_complet' => ['x']]);
        $this->assertArrayHasKey('nom_complet', $errors, 'an array is no longer stored as the name "Array"');
    }

    public function testListFieldsKeepOnlyScalarEntries(): void
    {
        $out = Validator::normalise(['equipements' => [['gps'], 'cuir', 5, null], 'marques_modeles' => 'oops']);
        $this->assertSame(['cuir', '5'], $out['equipements']);
        $this->assertSame([], $out['marques_modeles']);
    }

    /* ---- P7: control / invisible characters ---- */
    public function testControlAndBidiCharactersAreRemoved(): void
    {
        $this->assertSame('JeanDupont', Validator::clean("\u{202E}Jean\u{200B}Dupont\0", false));
        $this->assertSame("ligne1\nligne2", Validator::clean("ligne1\r\nligne\u{202E}2", true), 'newlines survive only in multi-line fields');
        $this->assertSame('ab', Validator::clean("a\nb", false));
        $this->assertSame(5000, mb_strlen(Validator::clean(str_repeat('A', 100000), false)), 'capped');
    }

    /* ---- P5: subject spoofing ---- */
    public function testSubjectTextCannotImitateTagsOrEmoji(): void
    {
        $this->assertSame('URGENT KDV ESTIMATION', MessageBuilder::safe_subject_text('[URGENT] 🔴 KDV <ESTIMATION>'));
        $this->assertSame("Jean-Luc O'Brien", MessageBuilder::safe_subject_text("Jean-Luc O'Brien"));
        $this->assertSame(40, mb_strlen(MessageBuilder::safe_subject_text(str_repeat('a', 200))));
    }

    /* ---- P14: secrets at rest ---- */
    public function testSecretsAreEncryptedAndRoundTrip(): void
    {
        $enc = Secrets::encrypt('0x4AAAA-secret');
        $this->assertStringStartsWith('enc1:', $enc);
        $this->assertStringNotContainsString('0x4AAAA', $enc);
        $this->assertSame('0x4AAAA-secret', Secrets::decrypt($enc));
        $this->assertSame($enc, Secrets::encrypt($enc), 'never encrypted twice');
        $this->assertSame('legacy-plain', Secrets::decrypt('legacy-plain'), 'old plain-text values still readable');
        $this->assertSame('', Secrets::decrypt('enc1:' . base64_encode(str_repeat('x', 40))), 'tampered value is rejected');
        $this->assertSame('', Secrets::encrypt(''));
    }

    /* ---- P4: image memory guard ---- */
    public function testHugeImageIsRefusedBeforeDecoding(): void
    {
        $previous = ini_set('memory_limit', '256M');
        try {
            $this->expectException(\RuntimeException::class);
            ImageProcessor::assert_memory(6000, 6000, IMAGETYPE_JPEG, true);   // the 549 KB JPEG that crashed PHP
        } finally {
            ini_set('memory_limit', (string) $previous);
        }
    }

    public function testReasonableImageIsAccepted(): void
    {
        $previous = ini_set('memory_limit', '256M');
        try {
            ImageProcessor::assert_memory(4000, 3000, IMAGETYPE_JPEG, true);   // a normal 12 MP phone photo
            $this->addToAssertionCount(1);
        } finally {
            ini_set('memory_limit', (string) $previous);
        }
    }

    public function testPixelCeilingIsLowered(): void
    {
        $this->assertSame(24000000, ImageProcessor::MAX_PIXELS);
    }

    /* ---- P9: visitor IP behind a proxy ---- */
    public function testIpIsTheServerAddressByDefaultEvenIfHeadersAreForged(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.99';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.98';
        $this->assertSame('203.0.113.7', AntiSpam::client_ip(), 'headers are ignored unless the administrator opts in');
    }

    public function testCloudflareHeaderIsUsedOnlyWhenEnabled(): void
    {
        $GLOBALS['kdv_test_options'] = ['kdv_settings' => ['proxy_mode' => 'cloudflare']];
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.99';
        $this->assertSame('198.51.100.99', AntiSpam::client_ip());
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
        $this->assertSame('203.0.113.7', AntiSpam::client_ip(), 'an invalid header falls back to the server address');
    }

    public function testForwardedForUsesTheLastEntry(): void
    {
        $GLOBALS['kdv_test_options'] = ['kdv_settings' => ['proxy_mode' => 'forwarded']];
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.50';   // the first entry is client-supplied
        $this->assertSame('198.51.100.50', AntiSpam::client_ip());
    }
    /* ---- P19: ad links are checked without any DNS lookup ---- */
    public function testAdLinksAreValidatedWithoutDnsAndPhishingTricksRefused(): void
    {
        foreach (['https://www.lacentrale.fr/auto-occasion-annonce-1.html?x=1&y=2', 'http://exemple-inconnu.example.test/voiture'] as $ok) {
            $this->assertTrue(Validator::is_safe_link($ok), $ok);
        }
        foreach (['https://banque.com@evil.example/login', 'javascript:alert(1)', 'data:text/html,x', 'ftp://example.com/x', 'https:///nohost', 'https://localhost/x', 'https://' . str_repeat('a', 300) . '.com'] as $bad) {
            $this->assertFalse(Validator::is_safe_link($bad), $bad);
        }
    }
}